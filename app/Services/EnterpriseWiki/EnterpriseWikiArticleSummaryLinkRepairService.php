<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Safe repair for existing article/summary page pairs that are missing one or both mutual
 * [[wikilink]]s (and the structural article_to_summary/summary_to_article
 * EnterpriseWikiPageLink rows EnterpriseWikiAppliedRunLintService::checkArticleLinks()/
 * checkSummaryLinks() check for) — the same pairing rule and block shape
 * EnterpriseWikiArticleSummaryLinkService already applies at generation time, applied
 * retroactively to runs that predate it or whose generated content never included the link.
 *
 * Pairing is keyed purely by run co-membership, exactly like EnterpriseWikiBuildPageLinksService
 * — a run is only ever repaired when it has exactly one article page and exactly one summary
 * page; anything else (0, or 2+, of either type) is left completely untouched, and so is any
 * page that already has a conflicting article_to_summary/summary_to_article link to a
 * DIFFERENT page (a page reused across more than one run — see
 * EnterpriseWikiMaintainerDecisionApplyService::resolvePage()'s slug-reuse path — could
 * otherwise end up ambiguously paired).
 *
 * Only ever appends ONE new block (never rewrites or removes any existing block) and only when
 * EnterpriseWikiArticleSummaryLinkService::hasLinkToPage() finds no existing link to the target
 * — a user who already wrote their own link (in their own words) is never overwritten, and a
 * page that's already linked never gets a redundant new version. Reuses the same
 * "new immutable version" pattern as every other Enterprise Wiki repair service (bump
 * version_number, demote the previous is_current row, keep every other block byte-for-byte).
 *
 * Never regenerates full document content — but a new current version still invalidates the
 * page's existing claims (they stay attached to the superseded version), so every append also
 * re-syncs claims for the new version via EnterpriseWikiPageVersionClaimSyncService, exactly like
 * every other repair path that creates a new current EnterpriseWikiPageVersion.
 */
class EnterpriseWikiArticleSummaryLinkRepairService
{
    public function __construct(
        private readonly EnterpriseWikiArticleSummaryLinkService $articleSummaryLinkService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
    ) {}

    /**
     * @return array{
     *     runs_checked: int,
     *     runs_skipped_ambiguous: int,
     *     runs_skipped_no_pair: int,
     *     pages_linked: int,
     *     pages_already_linked: int,
     *     pages_skipped_conflicting_link: int,
     * }
     */
    public function repair(?EnterpriseWikiIngestRun $onlyRun, bool $apply): array
    {
        $result = [
            'runs_checked' => 0,
            'runs_skipped_ambiguous' => 0,
            'runs_skipped_no_pair' => 0,
            'pages_linked' => 0,
            'pages_already_linked' => 0,
            'pages_skipped_conflicting_link' => 0,
        ];

        $query = EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT);

        if ($onlyRun !== null) {
            $query->where('id', $onlyRun->id);
        }

        $query->orderBy('id')->chunkById(50, function ($runs) use (&$result, $apply): void {
            foreach ($runs as $run) {
                $this->repairRun($run, $apply, $result);
            }
        });

        return $result;
    }

    /**
     * @param  array<string, int>  $result
     */
    private function repairRun(EnterpriseWikiIngestRun $run, bool $apply, array &$result): void
    {
        $result['runs_checked']++;

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $articles = $pivotRows
            ->map(fn (EnterpriseWikiIngestRunPage $row) => $row->page)
            ->filter(fn (?EnterpriseWikiPage $page): bool => $page?->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE)
            ->values();

        $summaries = $pivotRows
            ->map(fn (EnterpriseWikiIngestRunPage $row) => $row->page)
            ->filter(fn (?EnterpriseWikiPage $page): bool => $page?->page_type === EnterpriseWikiPage::PAGE_TYPE_SUMMARY)
            ->values();

        if ($articles->isEmpty() && $summaries->isEmpty()) {
            $result['runs_skipped_no_pair']++;

            return;
        }

        if ($articles->count() !== 1 || $summaries->count() !== 1) {
            $result['runs_skipped_ambiguous']++;

            return;
        }

        $article = $articles->first();
        $summary = $summaries->first();

        if ($this->hasConflictingLink($run, $article, $summary) || $this->hasConflictingLink($run, $summary, $article)) {
            $result['pages_skipped_conflicting_link']++;

            return;
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $affectedRunIds = [];

        $this->ensureLink($article, $summary, $languageCode, $apply, $result, $affectedRunIds);
        $this->ensureLink($summary, $article, $languageCode, $apply, $result, $affectedRunIds);

        if (! $apply) {
            return;
        }

        // Idempotent structural article_to_summary/summary_to_article rows — the exact ones
        // EnterpriseWikiAppliedRunLintService::checkArticleLinks()/checkSummaryLinks() require.
        $this->buildPageLinksService->build($run);

        // Appending the link block created a new current version for article and/or summary —
        // re-sync claims for it before re-linting, otherwise CODE_PAGE_WITHOUT_CLAIMS would open
        // for a page this very repair just replaced (see EnterpriseWikiPageVersionClaimSyncService).
        if ($affectedRunIds !== []) {
            $this->claimSyncService->syncRuns($affectedRunIds);
        }

        // Re-run the run's own quality check so a resolved "article/summary missing link"
        // finding closes immediately instead of waiting for an unrelated future QA pass.
        $this->lintService->lint($run);
    }

    /**
     * A page already carrying an article_to_summary/summary_to_article link to a DIFFERENT page
     * than the one this run would pair it with (possible when a page is reused across more than
     * one run) is left alone entirely — never silently repointed.
     */
    private function hasConflictingLink(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $from, EnterpriseWikiPage $to): bool
    {
        $linkType = $from->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE
            ? EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY
            : EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE;

        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where('from_page_id', $from->id)
            ->where('link_type', $linkType)
            ->where('to_page_id', '!=', $to->id)
            ->exists();
    }

    /**
     * @param  array<string, int>  $result
     * @param  list<int>  $affectedRunIds
     */
    private function ensureLink(EnterpriseWikiPage $page, EnterpriseWikiPage $target, string $languageCode, bool $apply, array &$result, array &$affectedRunIds): void
    {
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        if ($version === null) {
            return;
        }

        if ($this->articleSummaryLinkService->hasLinkToPage($page, (string) $version->content_markdown, $target)) {
            $result['pages_already_linked']++;

            return;
        }

        // Counted whether or not this is a dry run — 'pages_linked' means "needs a link" in a
        // dry run (no version is created) and "was linked" once --apply actually persists it.
        $result['pages_linked']++;

        if (! $apply) {
            return;
        }

        $this->appendLinkVersion($page, $version, $target, $languageCode);
        $affectedRunIds = array_merge($affectedRunIds, $this->claimSyncService->markPageForResync($page));
    }

    private function appendLinkVersion(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, EnterpriseWikiPage $target, string $languageCode): void
    {
        $blocks = (array) ($version->content_blocks_json ?? []);

        // Legacy version with no content_blocks_json at all — represent the existing whole-page
        // markdown as its own block first, so content_blocks_json stays a faithful reconstruction
        // of content_markdown (never silently dropping the original text from the block list).
        if ($blocks === []) {
            $blocks[] = [
                'block_key' => 'legacy-content',
                'position' => 0,
                'markdown' => (string) $version->content_markdown,
            ];
        }

        $linkBlock = $this->articleSummaryLinkService->buildLinkBlock($target, count($blocks), $languageCode);
        $blocks[] = $linkBlock;

        $markdown = trim(implode("\n\n", array_map(
            static fn (array $block): string => (string) ($block['markdown'] ?? ''),
            $blocks,
        )));

        $this->versionWriter->writeNewCurrentVersion($page, [
            'content_markdown' => $markdown,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'deterministic/article-summary-link-repair',
        ]);

        $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($page->id);

        $this->buildPageLinksService->materializeWikilinksForPage($page->fresh());
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
