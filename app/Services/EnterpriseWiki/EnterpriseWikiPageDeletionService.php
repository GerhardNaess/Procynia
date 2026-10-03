<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiWithdrawalNotRepresentableException;
use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way a Wiki page is deleted.
 *
 * Deleting Wiki knowledge used to be reachable only sideways — delete the source document and the
 * pages only it produced went with it (EnterpriseWikiDocumentDeletionService). That is a document
 * operation that happens to remove pages; this is the page operation itself, for a page a user
 * looks at and decides should not be in the Wiki. Both end up in the same place for the pages they
 * remove, and deliberately so: the incoming-link withdrawal, the cascade map and the fail-closed
 * assertion are EnterpriseWikiDocumentWithdrawalService's, called from here rather than rebuilt.
 *
 * Page-scoped, and nothing beyond it. What a page's deletion takes:
 *
 *  - the page row, and by database cascade its versions, its claims (and their source references,
 *    decisions and reconciliation attempts), its page_links in BOTH directions, its review events,
 *    its ingest_run_pages rows, its document-owner approval rows, and its relink/link-QA attempts;
 *  - its lint findings, explicitly, because enterprise_wiki_lint_findings.enterprise_wiki_page_id is
 *    nullOnDelete — a finding would otherwise survive its page with every foreign key nulled out;
 *  - the `[[slug|anchor]]` text and link intents other pages used to reach it, rewritten out of
 *    their current versions before the edges cascade away;
 *  - its node and every edge touching it in the Neo4j projection.
 *
 * What it never takes: another Wiki page (a concept, entity or summary the page linked to was
 * knowledge in its own right and stays one), the source document behind it, the ingest run that
 * produced it, or the Quality process that was the source of it. A run and a process keep their
 * record of having produced something; what they no longer have is a page to point at, and the
 * rows that did point at it are gone rather than dangling.
 *
 * Generic across page types by construction — nothing here reads page_type. An article, a concept,
 * an entity and a summary are the same object to this service.
 *
 * deletePages() is the real entry point and takes a set, so a caller that has to remove several
 * pages at once — a Quality process handing Wiki the pages its activities produced, later — asks
 * Wiki to delete them rather than implementing any of this again. One transaction, one withdrawal,
 * one assertion.
 */
class EnterpriseWikiPageDeletionService
{
    public function __construct(
        private readonly EnterpriseWikiDocumentWithdrawalService $withdrawalService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $stalenessService,
    ) {}

    /**
     * @return array{pages_deleted: int, incoming_links_dematerialized: int, pages_rewritten: int, findings_deleted: int, stale_wiki_answers_marked: int}
     */
    public function deletePage(EnterpriseWikiPage $page, User $actor): array
    {
        return $this->deletePages((int) $page->customer_id, collect([(int) $page->id]), $actor);
    }

    /**
     * @param  Collection<int, int>  $pageIds
     * @return array{pages_deleted: int, incoming_links_dematerialized: int, pages_rewritten: int, findings_deleted: int, stale_wiki_answers_marked: int}
     *
     * @throws EnterpriseWikiWithdrawalNotRepresentableException
     */
    public function deletePages(int $customerId, Collection $pageIds, User $actor): array
    {
        $empty = [
            'pages_deleted' => 0,
            'incoming_links_dematerialized' => 0,
            'pages_rewritten' => 0,
            'findings_deleted' => 0,
            'stale_wiki_answers_marked' => 0,
        ];

        $requestedIds = $pageIds
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            return $empty;
        }

        // Customer scoping is applied here and not assumed from the caller: this is the only place
        // that deletes a page, so it is the place that has to refuse a page from another tenant.
        /** @var EloquentCollection<int, EnterpriseWikiPage> $pages */
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $requestedIds->all())
            ->get();

        if ($pages->isEmpty()) {
            return $empty;
        }

        $ids = $pages->pluck('id')->map(static fn (mixed $value): int => (int) $value)->values();
        $slugs = array_values(array_filter(array_map(
            static fn (mixed $slug): string => (string) $slug,
            $pages->pluck('slug')->all(),
        )));

        // Before the pages go: an answer's dependency on a page can only be resolved while the page
        // is still there to be matched.
        $staleAnswers = $this->stalenessService->markAnswersStaleForDeletedWikiPages($pages);

        // Also before: a page's graph node is built from its OWN outgoing links, so every page whose
        // text is about to be rewritten has to be reprojected afterwards. Asked once, here, and the
        // withdrawal below answers the same question the same way.
        $rewrittenPageIds = $this->withdrawalService
            ->pagesLinkingTo($ids, $slugs)
            ->diff($ids)
            ->values();

        $findingsDeleted = EnterpriseWikiLintFinding::query()
            ->whereIn('enterprise_wiki_page_id', $ids)
            ->count();

        $linksDematerialized = 0;
        $pagesRewritten = 0;

        DB::transaction(function () use ($ids, $slugs, &$linksDematerialized, &$pagesRewritten): void {
            // First, while the links still exist in both representations — the markdown and the
            // recorded edges. Fail-closed: anything that cannot be rewritten safely throws and the
            // deletion rolls back rather than leaving the Wiki pointing at a page that is gone.
            $withdrawal = $this->withdrawalService->dematerializeIncomingLinks($ids);
            $linksDematerialized = $withdrawal['links_dematerialized'];
            $pagesRewritten = $withdrawal['pages_rewritten'];

            // nullOnDelete, so the database would keep these as orphans with every key nulled out.
            EnterpriseWikiLintFinding::query()
                ->whereIn('enterprise_wiki_page_id', $ids)
                ->delete();

            // Everything else cascades — see the class docblock for the map.
            EnterpriseWikiPage::query()
                ->whereIn('id', $ids)
                ->delete();

            // The state the active Wiki must be in for this deletion to be allowed to commit. Last,
            // inside the transaction, so a violation rolls everything back.
            $this->withdrawalService->assertNoActiveReferencesToDeletedPages($ids, $slugs);
        });

        // After commit, and only then: the projection reads SQL, and on the sync queue driver it
        // would otherwise run against a transaction that has not landed.
        foreach ($ids as $pageId) {
            ProjectEnterpriseWikiPageToGraph::dispatch($pageId, $customerId)->afterCommit();
        }

        foreach ($rewrittenPageIds as $pageId) {
            ProjectEnterpriseWikiPageToGraph::dispatch($pageId)->afterCommit();
        }

        Log::info('[PROCYNIA][WIKI_PAGE] Deleted Wiki pages.', [
            'customer_id' => $customerId,
            'actor_user_id' => $actor->id,
            'page_ids' => $ids->all(),
            'incoming_links_dematerialized' => $linksDematerialized,
            'pages_rewritten' => $pagesRewritten,
            'findings_deleted' => $findingsDeleted,
            'stale_wiki_answers_marked' => $staleAnswers['stale_wiki_answer_count'],
        ]);

        return [
            'pages_deleted' => $ids->count(),
            'incoming_links_dematerialized' => $linksDematerialized,
            'pages_rewritten' => $pagesRewritten,
            'findings_deleted' => $findingsDeleted,
            'stale_wiki_answers_marked' => $staleAnswers['stale_wiki_answer_count'],
        ];
    }
}
