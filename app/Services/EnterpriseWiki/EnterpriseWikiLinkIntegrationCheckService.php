<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;

/**
 * The technical link check of post-ingest QA (checkLinkIntegration,
 * docs/enterprise-wiki-knowledge-integration-plan.md §7.1). Deterministic and read-only: no AI,
 * no writes.
 *
 * For every page of the run that has a current version — plus every existing page this run gave
 * an inbound link — it compares three sets that must describe the same relations:
 *
 *   M  the materializable [[wikilinks]] in the page's CURRENT markdown (parsed and resolved exactly
 *      as EnterpriseWikiBuildPageLinksService does it — broken links and links to incomplete shells
 *      are not relations);
 *   R  the page's link_type=wikilink rows in enterprise_wiki_page_links — the table the graph API
 *      (/app/wiki/graph-data, EnterpriseWikiGraphDataService) reads, synchronously;
 *   the run's own record that the page's links were assessed and materialized (link_assessment and
 *      links_assessed_at on the pivot).
 *
 * M ≠ R, a row pointing outside the customer or at a page without content, or a missing assessment
 * are technical defects. A materialized link intent whose link is no longer in the markdown (a later
 * step rewrote the text) is reported as a diagnostic only: the relation set is still consistent.
 *
 * Neo4j is deliberately NOT part of this check. Its projection (ProjectEnterpriseWikiPageToGraph) is
 * an asynchronous, optional secondary index used only by the graph focus view; it records no
 * per-page state, so "still queued" and "failed" cannot be told apart here. The check therefore
 * says so explicitly (neo4j_projection = not_verified) instead of claiming or denying it.
 *
 * "checked" means only that the stored structure is technically consistent with the text. It is not
 * subject-matter approval: that remains the ordinary review of the page version.
 */
class EnterpriseWikiLinkIntegrationCheckService
{
    public const STATUS_CHECKED = 'checked';

    public const STATUS_NEEDS_FOLLOWUP = 'needs_followup';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_NOT_COMPLETED = 'not_completed';

    public const GRAPH_SOURCE = 'enterprise_wiki_page_links';

    public const NEO4J_NOT_VERIFIED = 'not_verified';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     pages_checked: int,
     *     assessment_missing: list<int>,
     *     mismatches: list<array{page_id: int, kind: string, target_page_ids: list<int>}>,
     *     needs_followup: list<array{page_id: int, reason: string}>,
     *     outcomes: array<string, int>,
     *     diagnostics: list<array{page_id: int, kind: string, target_page_ids: list<int>}>,
     *     graph_source: string,
     *     neo4j_projection: string,
     * }
     */
    public function check(EnterpriseWikiIngestRun $run): array
    {
        $customerId = (int) $run->customer_id;
        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->orderBy('id')
            ->get();

        $assessmentMissing = [];
        $needsFollowup = [];
        $outcomes = [];
        $pagesToCheck = [];

        foreach ($pivots as $pivot) {
            $pageId = (int) $pivot->enterprise_wiki_page_id;

            if ($pivot->generation_status !== EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED) {
                $needsFollowup[] = ['page_id' => $pageId, 'reason' => 'page_generation_'.$pivot->generation_status];

                continue;
            }

            if ($pivot->page === null || ! EnterpriseWikiRelatedPageReferenceResolver::hasContent($pageId)) {
                $needsFollowup[] = ['page_id' => $pageId, 'reason' => 'page_without_content'];

                continue;
            }

            $outcome = is_array($pivot->link_assessment) ? ($pivot->link_assessment['outcome'] ?? null) : null;

            // Never assessed, or links never materialized for this version: the relation work was
            // not done, which is different from "done, no relations found".
            if ($outcome === null || $pivot->links_assessed_at === null) {
                $assessmentMissing[] = $pageId;
            } else {
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;

                if (! in_array($outcome, EnterpriseWikiLinkAssessmentRecorder::INTEGRATED_OUTCOMES, true)) {
                    $needsFollowup[] = ['page_id' => $pageId, 'reason' => $outcome];
                }
            }

            $pagesToCheck[$pageId] = $pivot->page;
        }

        // Existing pages this run gave an inbound link: their rows must match their new text too.
        $inboundCandidateIds = EnterpriseWikiPageRelinkAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED)
            ->pluck('candidate_page_id')
            ->map(static fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => isset($pagesToCheck[$id]))
            ->unique()
            ->all();

        foreach (EnterpriseWikiPage::query()->whereIn('id', $inboundCandidateIds)->get() as $candidate) {
            $pagesToCheck[(int) $candidate->id] = $candidate;
        }

        $mismatches = [];
        $diagnostics = [];

        foreach ($pagesToCheck as $pageId => $page) {
            [$pageMismatches, $pageDiagnostics] = $this->checkPage($customerId, $page);
            array_push($mismatches, ...$pageMismatches);
            array_push($diagnostics, ...$pageDiagnostics);
        }

        $status = match (true) {
            $assessmentMissing !== [] => self::STATUS_NOT_COMPLETED,
            $mismatches !== [] => self::STATUS_MISMATCH,
            $needsFollowup !== [] => self::STATUS_NEEDS_FOLLOWUP,
            default => self::STATUS_CHECKED,
        };

        return [
            'status' => $status,
            'pages_checked' => count($pagesToCheck),
            'assessment_missing' => $assessmentMissing,
            'mismatches' => $mismatches,
            'needs_followup' => $needsFollowup,
            'outcomes' => $outcomes,
            'diagnostics' => $diagnostics,
            'graph_source' => self::GRAPH_SOURCE,
            'neo4j_projection' => self::NEO4J_NOT_VERIFIED,
        ];
    }

    /**
     * @return array{0: list<array{page_id: int, kind: string, target_page_ids: list<int>}>, 1: list<array{page_id: int, kind: string, target_page_ids: list<int>}>}
     */
    private function checkPage(int $customerId, EnterpriseWikiPage $page): array
    {
        $pageId = (int) $page->id;
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->first();

        $markdown = (string) ($version?->content_markdown ?? '');
        $resolution = $this->linkResolver->resolve($customerId, $page, $this->linkParser->parse($markdown));
        $markdownTargets = $this->ids(array_map(
            static fn (array $target): int => (int) $target['to_page']->id,
            $this->linkResolver->materializableTargets($resolution['resolved']),
        ));

        $rows = EnterpriseWikiPageLink::query()
            ->where('from_page_id', $pageId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->get(['customer_id', 'to_page_id']);
        $rowTargets = $this->ids($rows->pluck('to_page_id')->map(static fn ($id): int => (int) $id)->all());

        $mismatches = [];

        $missingRows = array_values(array_diff($markdownTargets, $rowTargets));
        if ($missingRows !== []) {
            $mismatches[] = ['page_id' => $pageId, 'kind' => 'missing_rows', 'target_page_ids' => $missingRows];
        }

        $staleRows = array_values(array_diff($rowTargets, $markdownTargets));
        if ($staleRows !== []) {
            $mismatches[] = ['page_id' => $pageId, 'kind' => 'stale_rows', 'target_page_ids' => $staleRows];
        }

        $foreignRows = $this->ids($rows->filter(fn (EnterpriseWikiPageLink $row): bool => (int) $row->customer_id !== $customerId)->pluck('to_page_id')->map(static fn ($id): int => (int) $id)->all());
        $foreignTargets = $rowTargets === [] ? [] : $this->ids(EnterpriseWikiPage::query()
            ->whereIn('id', $rowTargets)
            ->where('customer_id', '!=', $customerId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all());
        $crossCustomer = $this->ids([...$foreignRows, ...$foreignTargets]);
        if ($crossCustomer !== []) {
            $mismatches[] = ['page_id' => $pageId, 'kind' => 'cross_customer_rows', 'target_page_ids' => $crossCustomer];
        }

        $diagnostics = [];
        $intentTargets = [];

        foreach ((array) ($version?->content_blocks_json ?? []) as $block) {
            foreach ((array) (is_array($block) ? ($block['link_intents'] ?? []) : []) as $intent) {
                if (is_array($intent) && is_int($intent['target_page_id'] ?? null)) {
                    $intentTargets[] = $intent['target_page_id'];
                }
            }
        }

        $intentsWithoutLink = array_values(array_diff($this->ids($intentTargets), $markdownTargets));
        if ($intentsWithoutLink !== []) {
            $diagnostics[] = ['page_id' => $pageId, 'kind' => 'intent_without_materialized_link', 'target_page_ids' => $intentsWithoutLink];
        }

        return [$mismatches, $diagnostics];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
