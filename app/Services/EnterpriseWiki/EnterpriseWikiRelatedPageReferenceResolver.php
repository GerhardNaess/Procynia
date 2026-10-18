<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Facades\Log;

/**
 * Turns the maintainer decision's references to EXISTING Wiki pages into page ids the backend has
 * checked (D5, docs/enterprise-wiki-knowledge-integration-plan.md §6.1).
 *
 * The planner sees the whole Wiki index, so it is the one step that can say "this new page relates
 * to that older page" — but what it returns is a suggestion, never authoritative data. A reference
 * becomes a page id only when it names a page that exists, belongs to the run's customer, is not
 * archived or superseded, has content (or is a page of this same run), and is not the page itself.
 * Anything else is dropped here, with a machine-readable reason, and the free-text guidance the
 * planner wrote stays untouched in the generation prompt.
 *
 * Being accepted here only makes a page a link CANDIDATE: it is offered in the catalog's planned
 * tier, and EnterpriseWikiLinkIntentMaterializer applies the same eligibility rules again before a
 * link is ever written.
 */
class EnterpriseWikiRelatedPageReferenceResolver
{
    public const REJECTED_NOT_FOUND = 'not_found';

    public const REJECTED_CROSS_CUSTOMER = 'cross_customer';

    public const REJECTED_INACTIVE_STATUS = 'inactive_status';

    public const REJECTED_NO_CONTENT = 'no_content';

    public const REJECTED_SELF = 'self';

    public const REJECTED_DUPLICATE = 'duplicate';

    public const REJECTED_UNRESOLVED_TITLE = 'unresolved_title';

    /** Statuses a page can never be a link target in, whatever the planner says. */
    public const INACTIVE_STATUSES = [
        EnterpriseWikiPage::STATUS_ARCHIVED,
        EnterpriseWikiPage::STATUS_SUPERSEDED,
    ];

    /**
     * @return array{
     *     page_ids: list<int>,
     *     rejected: list<array{page_id: ?int, page_title: ?string, source: string, reason: string}>,
     * }
     */
    public function resolveForPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): array
    {
        $decision = (array) ($run->maintainer_decision_json ?? []);
        $entry = self::plannedEntryForPage($decision, $page);
        $runPageIds = $this->eligibleRunPageIds($run);

        $accepted = [];
        $rejected = [];

        foreach ((array) ($entry['related_page_guidance'] ?? []) as $guidance) {
            if (! is_array($guidance)) {
                continue;
            }

            $title = trim((string) ($guidance['page_title'] ?? ''));
            $pageId = $guidance['page_id'] ?? null;
            $resolvedId = null;

            if (is_int($pageId)) {
                $reason = $this->rejectionReason($run, $page, $pageId, $runPageIds);

                if ($reason === null) {
                    $resolvedId = $pageId;
                } else {
                    // Only the id is discarded. A legitimately named page may still be found by its
                    // exact title below — customer-scoped, so a foreign id can never smuggle a
                    // foreign page in through the fallback.
                    $rejected[] = ['page_id' => $pageId, 'page_title' => $title !== '' ? $title : null, 'source' => 'related_page_guidance', 'reason' => $reason];
                }
            }

            if ($resolvedId === null && $title !== '') {
                $resolvedId = $this->resolveExactTitle($run, $page, $title, $runPageIds);
            }

            if ($resolvedId === null) {
                continue;
            }

            if (in_array($resolvedId, $accepted, true)) {
                $rejected[] = ['page_id' => $resolvedId, 'page_title' => $title !== '' ? $title : null, 'source' => 'related_page_guidance', 'reason' => self::REJECTED_DUPLICATE];

                continue;
            }

            $accepted[] = $resolvedId;
        }

        // A page the decision patches is, by definition, existing knowledge about the same subject.
        foreach (EnterpriseWikiPatchTargetResolver::targetPageIds($decision) as $targetPageId) {
            if (in_array($targetPageId, $accepted, true)) {
                continue;
            }

            $reason = $this->rejectionReason($run, $page, $targetPageId, $runPageIds);

            if ($reason === null) {
                $accepted[] = $targetPageId;
            } elseif ($reason !== self::REJECTED_SELF) {
                $rejected[] = ['page_id' => $targetPageId, 'page_title' => null, 'source' => 'patch_targets', 'reason' => $reason];
            }
        }

        if ($rejected !== []) {
            Log::info('[WIKI_LINK_CATALOG] Planner page reference(s) discarded.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'rejected' => $rejected,
            ]);

            foreach ($rejected as $rejection) {
                if ($rejection['reason'] === self::REJECTED_CROSS_CUSTOMER) {
                    Log::warning('[WIKI_LINK_CATALOG] Planner referenced a page belonging to another customer.', [
                        'run_id' => $run->id,
                        'page_id' => $page->id,
                        'referenced_page_id' => $rejection['page_id'],
                    ]);
                }
            }
        }

        return ['page_ids' => $accepted, 'rejected' => $rejected];
    }

    /**
     * The maintainer-decision entry that planned this page — the same lookup page generation uses
     * to build the page's responsibility guidance (article → source_article, summary →
     * source_summary, concept/entity → the concept_pages/entity_pages entry with the page's title).
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function plannedEntryForPage(array $decision, EnterpriseWikiPage $page): array
    {
        return match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => (array) ($decision['source_article'] ?? []),
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => (array) ($decision['source_summary'] ?? []),
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY => (array) (collect(array_merge(
                (array) ($decision['concept_pages'] ?? []),
                (array) ($decision['entity_pages'] ?? []),
            ))->first(fn (mixed $entry): bool => is_array($entry) && ($entry['title'] ?? null) === $page->title) ?? []),
            default => [],
        };
    }

    /**
     * @param  list<int>  $runPageIds
     */
    private function rejectionReason(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, int $candidateId, array $runPageIds): ?string
    {
        if ($candidateId === (int) $page->id) {
            return self::REJECTED_SELF;
        }

        $candidate = EnterpriseWikiPage::query()->find($candidateId);

        if ($candidate === null) {
            return self::REJECTED_NOT_FOUND;
        }

        if ((int) $candidate->customer_id !== (int) $run->customer_id) {
            return self::REJECTED_CROSS_CUSTOMER;
        }

        if (in_array($candidate->status, self::INACTIVE_STATUSES, true)) {
            return self::REJECTED_INACTIVE_STATUS;
        }

        // A page of this run may still be waiting for the deferred wave; any other page without
        // content is an incomplete shell and never existing knowledge.
        if (! in_array($candidateId, $runPageIds, true) && ! self::hasContent($candidateId)) {
            return self::REJECTED_NO_CONTENT;
        }

        return null;
    }

    /**
     * Legacy decisions (and a null page_id) name pages by title only. Resolved deterministically to
     * an exact title or slug match — never fuzzy — and only when exactly one eligible page of the
     * customer matches; an ambiguous title resolves to nothing rather than to a guess.
     *
     * @param  list<int>  $runPageIds
     */
    private function resolveExactTitle(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $title, array $runPageIds): ?int
    {
        $normalized = mb_strtolower(trim($title));

        $matches = EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->where(fn ($query) => $query
                ->whereRaw('lower(title) = ?', [$normalized])
                ->orWhere('slug', $normalized))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->rejectionReason($run, $page, $id, $runPageIds) === null)
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return list<int> */
    private function eligibleRunPageIds(EnterpriseWikiIngestRun $run): array
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('generation_status', '!=', EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED)
            ->pluck('enterprise_wiki_page_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public static function hasContent(int $pageId): bool
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->exists();
    }
}
