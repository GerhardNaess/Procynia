<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Collection;

/**
 * Structural lint for an applied maintainer decision run.
 *
 * Reads existing Enterprise Wiki data (pages, versions, claims, source references,
 * links) and writes EnterpriseWikiLintFinding rows describing any gaps or
 * inconsistencies found.
 *
 * Strategy:
 * - Idempotent: findings are upserted by a stable composite key that includes
 *   enterprise_wiki_ingest_run_id. Re-running opens resolved findings that
 *   still apply, skips findings that are already open, and resolves findings
 *   whose condition no longer holds.
 * - Stale resolution scope: only findings with enterprise_wiki_ingest_run_id
 *   equal to the current run are considered for resolution. Findings from
 *   wiki:lint (run_id = null) are never touched here.
 * - No OpenAI calls, no content generation, no link or claim creation.
 */
class EnterpriseWikiAppliedRunLintService
{
    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiPlannedSectionCoverageValidator $sectionCoverageValidator,
        private readonly EnterpriseWikiPlannedFigureCoverageValidator $figureCoverageValidator,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
    ) {}

    /** Expected reverse link type for each forward link type. */
    private const REVERSE_LINK_TYPES = [
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT => EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY => EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT => EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY => EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
    ];

    /**
     * @return array{pages_checked: int, claims_checked: int, source_refs_checked: int,
     *               links_checked: int, findings_created: int, findings_skipped: int,
     *               findings_resolved: int, errors: int, warnings: int, info: int}
     *
     * @throws \InvalidArgumentException if the run is not applied
     */
    public function lint(EnterpriseWikiIngestRun $run): array
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return [
                'pages_checked' => 0,
                'claims_checked' => 0,
                'source_refs_checked' => 0,
                'links_checked' => 0,
                'findings_created' => 0,
                'findings_skipped' => 0,
                'findings_resolved' => 0,
                'errors' => 0,
                'warnings' => 0,
                'info' => 0,
            ];
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can be linted."
            );
        }

        $touchedIds = [];
        $counts = [
            'pages_checked' => 0,
            'claims_checked' => 0,
            'source_refs_checked' => 0,
            'links_checked' => 0,
            'findings_created' => 0,
            'findings_skipped' => 0,
            'findings_resolved' => 0,
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
        ];

        // ----------------------------------------------------------------
        // Load pages from run
        // ----------------------------------------------------------------
        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $allPages = [];
        $articles = [];
        $summaries = [];

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            $entry = ['page' => $page, 'version' => $version];
            $allPages[] = $entry;

            match ($page->page_type) {
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $articles[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $summaries[] = $entry,
                default => null,
            };
        }

        // ----------------------------------------------------------------
        // Run completeness checks
        // ----------------------------------------------------------------
        $this->checkRunCompleteness($run, $allPages, $articles, $summaries, $touchedIds, $counts);

        // ----------------------------------------------------------------
        // Per-page checks
        // ----------------------------------------------------------------
        $runPageIds = array_map(fn (array $entry) => $entry['page']->id, $allPages);
        $sourceText = $this->sourceTextForRun($run);
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);
        $validFigureKeys = $this->figureSourceElementKeysForRun($run);

        foreach ($allPages as $entry) {
            $counts['pages_checked']++;
            $page = $entry['page'];
            $version = $entry['version'];

            $this->checkPageVersion($run, $page, $version, $touchedIds, $counts);

            if ($version !== null && trim((string) ($version->content_markdown ?? '')) !== '') {
                $this->checkPageClaims($run, $page, $version, $touchedIds, $counts);
                $this->checkPageLinks($run, $page, $touchedIds, $counts);
                $this->checkWikilinkIntegrity($run, $page, $version, $runPageIds, $touchedIds, $counts);
                $this->checkPlannedSectionCoverage($run, $page, $version, $decisionJson, $sourceText, $touchedIds, $counts);
                $this->checkPlannedFigureCoverage($run, $page, $version, $decisionJson, $validFigureKeys, $touchedIds, $counts);
            }
        }

        $this->checkPlannedFigureCrossPageAssignment($run, $allPages, $decisionJson, $touchedIds, $counts);

        // ----------------------------------------------------------------
        // Close stale open findings for this run that were not touched
        // ----------------------------------------------------------------
        $counts['findings_resolved'] = $this->closeStaleFindings($run->id, $run->customer_id, $touchedIds);

        return $counts;
    }

    /**
     * Resolve any open "claim has no source reference" finding(s) for this specific claim —
     * called immediately after the claim gains a real source reference (automatic
     * reconciliation) or a manual approval, so the UI reflects it without waiting for the next
     * full lint pass. A claim can have more than one such finding (one per lint mechanism/run
     * that touched it — wiki:lint and wiki:lint-applied-run key findings differently), so this
     * resolves all of them, not just one.
     */
    public function resolveClaimMissingSourceFinding(EnterpriseWikiClaim $claim): void
    {
        EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->update([
                'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);
    }

    /**
     * Re-open the "claim has no source reference" finding(s) for this claim if it still
     * genuinely lacks a source — called after a manual approval is undone. A no-op when the
     * claim already has a real source reference (nothing to warn about) or is still manually
     * approved. If no prior finding exists at all (lint never ran on this claim), creates one
     * using the same non-run-scoped shape wiki:lint itself would use.
     */
    public function reopenClaimMissingSourceFindingIfStillMissing(EnterpriseWikiClaim $claim): void
    {
        if (! $claim->needsSourceWarning()) {
            return;
        }

        $reopened = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
            ->update([
                'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
                'detected_at' => now(),
                'resolved_at' => null,
            ]);

        if ($reopened > 0) {
            return;
        }

        $claim->loadMissing('page');

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $claim->page->customer_id,
            'enterprise_wiki_ingest_run_id' => null,
            'enterprise_wiki_page_id' => $claim->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $claim->enterprise_wiki_page_version_id,
            'enterprise_wiki_claim_id' => $claim->id,
            'enterprise_wiki_document_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Claim has no source reference.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }

    /**
     * Purpose: Reset a claim's manual decision back to pending when its first real source
     * reference arrives after an approval or rejection already existed.
     * Inputs: The claim and whether it had no real source reference immediately before the new
     * reference was attached.
     * Returns: None.
     * Side effects: Clears approval fields when the claim was previously decided without a real
     * source basis and now has one.
     */
    public function resetClaimDecisionAfterFirstSourceReference(EnterpriseWikiClaim $claim, bool $wasMissingSourceBefore): void
    {
        if (! $wasMissingSourceBefore) {
            return;
        }

        if (! in_array($claim->approval_status, [
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ], true)) {
            return;
        }

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_comment' => null,
        ]);
    }

    // =========================================================================
    // Check groups
    // =========================================================================

    private function checkRunCompleteness(
        EnterpriseWikiIngestRun $run,
        array $allPages,
        array $articles,
        array $summaries,
        array &$touchedIds,
        array &$counts,
    ): void {
        if (empty($allPages)) {
            $this->upsertFinding(
                $this->runKey($run, EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'Applied run contains no pages.',
                $touchedIds,
                $counts,
            );
        }

        if (empty($articles)) {
            $this->upsertFinding(
                $this->runKey($run, EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_ARTICLE),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Applied run has no article page.',
                $touchedIds,
                $counts,
            );
        }

        if (empty($summaries)) {
            $this->upsertFinding(
                $this->runKey($run, EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_SUMMARY),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Applied run has no summary page.',
                $touchedIds,
                $counts,
            );
        }
    }

    private function checkPageVersion(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $version,
        array &$touchedIds,
        array &$counts,
    ): void {
        if ($version === null) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_MISSING_CURRENT_VERSION),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'Page has no current version.',
                $touchedIds,
                $counts,
            );

            return;
        }

        if (trim((string) ($version->content_markdown ?? '')) === '') {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'Current version has empty content_markdown.',
                $touchedIds,
                $counts,
            );
        }
    }

    /**
     * Wiki run-586: deterministically re-checks whatever content actually persisted against the
     * page's own owned_topics — a defense-in-depth layer behind
     * EnterpriseWikiGenerateAppliedPagesService's inline generation-time check (which normally
     * prevents an incomplete page from ever reaching this point at all). Severity error makes an
     * open finding blocking (EnterpriseWikiLintFinding::isBlocking()), so a run cannot reach
     * qa_status=passed while a planned section remains missing (with source grounding), empty,
     * or link-only — see EnterpriseWikiPostIngestQaService::findCriticalDefects().
     */
    private function checkPlannedSectionCoverage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        array $decisionJson,
        string $sourceText,
        array &$touchedIds,
        array &$counts,
    ): void {
        $plannedTopics = $this->plannedOwnedTopicsForPage($page, $decisionJson);

        if ($plannedTopics === []) {
            return;
        }

        $issues = $this->sectionCoverageValidator->validate($plannedTopics, (string) $version->content_markdown, $page->page_type, $sourceText);
        $blocking = array_filter($issues, [EnterpriseWikiPlannedSectionCoverageValidator::class, 'isBlocking']);

        // Grouped by code, one finding per (page, version, code) listing every affected planned
        // topic — the lint finding schema has no free-text differentiator column, so two
        // same-type issues on one page must share a row rather than silently overwrite each
        // other's message via a second upsertFinding() call with an identical key.
        $byCode = [];

        foreach ($blocking as $issue) {
            $code = match ($issue['type']) {
                EnterpriseWikiPlannedSectionCoverageValidator::TYPE_MISSING => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING,
                EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY,
                EnterpriseWikiPlannedSectionCoverageValidator::TYPE_ONLY_LINKS => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_ONLY_LINKS,
                default => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_BELOW_MINIMUM_SUBSTANCE,
            };

            $byCode[$code][] = $issue;
        }

        foreach ($byCode as $code => $codeIssues) {
            $description = $this->issueDescription($codeIssues[0]['type']);
            $topics = implode(', ', array_map(fn (array $i): string => '"'.$i['planned_topic'].'"', $codeIssues));

            $this->upsertFinding(
                $this->pageKey($run, $page, $version, $code),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                sprintf('Planned section(s) %s %s.', $topics, $description),
                $touchedIds,
                $counts,
            );
        }
    }

    private function issueDescription(string $issueType): string
    {
        return match ($issueType) {
            EnterpriseWikiPlannedSectionCoverageValidator::TYPE_MISSING => 'has no matching heading in the generated content',
            EnterpriseWikiPlannedSectionCoverageValidator::TYPE_EMPTY => 'has a heading with no body content',
            EnterpriseWikiPlannedSectionCoverageValidator::TYPE_ONLY_LINKS => 'has a heading whose body contains only wikilinks or punctuation',
            default => 'has a heading whose body falls below minimum substance',
        };
    }

    /**
     * Same owned_topics lookup as EnterpriseWikiGenerateAppliedPagesService::plannedOwnedTopicsForPage()
     * (duplicated rather than shared — the two call sites read from different inputs: a fresh
     * $run.maintainer_decision_json here vs. one already loaded during generation).
     *
     * @return list<string>
     */
    private function plannedOwnedTopicsForPage(EnterpriseWikiPage $page, array $decisionJson): array
    {
        if (in_array($page->page_type, [EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY], true)) {
            $entries = array_merge(
                (array) data_get($decisionJson, 'concept_pages', []),
                (array) data_get($decisionJson, 'entity_pages', []),
            );

            $match = collect($entries)->firstWhere('title', $page->title);

            return $match !== null ? $this->nonEmptyStringList($match['owned_topics'] ?? []) : [];
        }

        if ($page->page_type !== EnterpriseWikiPage::PAGE_TYPE_ARTICLE) {
            return [];
        }

        $entry = (array) data_get($decisionJson, 'source_article', []);

        return $this->nonEmptyStringList($entry['owned_topics'] ?? []);
    }

    /** @return list<string> */
    private function nonEmptyStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function sourceTextForRun(EnterpriseWikiIngestRun $run): string
    {
        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return '';
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        return (string) ($document->extracted_text ?? '');
    }

    /**
     * Wiki run-587: deterministically re-checks whatever image blocks actually persisted against
     * the page's own planned_figures — same defense-in-depth relationship to
     * EnterpriseWikiGenerateAppliedPagesService::ensurePlannedFigureCoverage()'s generation-time
     * check as checkPlannedSectionCoverage() has to its own generation-time counterpart.
     *
     * Unlike checkPlannedSectionCoverage() (which only ever reports blocking issues), every issue
     * type is reported here — a code is raised as SEVERITY_ERROR (blocking) when at least one of
     * its issues concerns a REQUIRED figure or is a duplicate (matching
     * EnterpriseWikiPlannedFigureCoverageValidator::isBlocking()'s own rule), otherwise
     * SEVERITY_WARNING — so an optional figure gap is still visible without blocking qa_status.
     */
    private function checkPlannedFigureCoverage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        array $decisionJson,
        array $validFigureKeys,
        array &$touchedIds,
        array &$counts,
    ): void {
        $plannedFigures = $this->plannedFiguresForPage($page, $decisionJson);

        if ($plannedFigures === []) {
            return;
        }

        $contentBlocks = (array) ($version->content_blocks_json ?? []);
        $issues = $this->figureCoverageValidator->validate($plannedFigures, (string) $version->content_markdown, $contentBlocks, $validFigureKeys);

        $byCode = [];

        foreach ($issues as $issue) {
            $code = match ($issue['type']) {
                EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING => EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING,
                EnterpriseWikiPlannedFigureCoverageValidator::TYPE_WRONG_SECTION => EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION,
                EnterpriseWikiPlannedFigureCoverageValidator::TYPE_SOURCE_MISSING => EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_SOURCE_MISSING,
                EnterpriseWikiPlannedFigureCoverageValidator::TYPE_DUPLICATE => EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_DUPLICATE,
                // caption/alt-text-missing are surfaced only via the bounded repair at generation
                // time (EnterpriseWikiGenerateAppliedPagesService), not their own standalone lint code.
                default => null,
            };

            if ($code === null) {
                continue;
            }

            $byCode[$code][] = $issue;
        }

        foreach ($byCode as $code => $codeIssues) {
            $hasBlocking = array_filter($codeIssues, [EnterpriseWikiPlannedFigureCoverageValidator::class, 'isBlocking']) !== [];
            $severity = $hasBlocking ? EnterpriseWikiLintFinding::SEVERITY_ERROR : EnterpriseWikiLintFinding::SEVERITY_WARNING;
            $keys = implode(', ', array_map(fn (array $i): string => '"'.$i['source_element_key'].'"', $codeIssues));

            $this->upsertFinding(
                $this->pageKey($run, $page, $version, $code),
                $severity,
                sprintf('Planned figure(s) %s %s.', $keys, $this->figureIssueDescription($codeIssues[0]['type'])),
                $touchedIds,
                $counts,
            );
        }
    }

    private function figureIssueDescription(string $issueType): string
    {
        return match ($issueType) {
            EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING => 'were planned for this page but no matching image block was found',
            EnterpriseWikiPlannedFigureCoverageValidator::TYPE_WRONG_SECTION => 'were planned under a specific section but the persisted image block is not there',
            EnterpriseWikiPlannedFigureCoverageValidator::TYPE_SOURCE_MISSING => 'reference a source_element_key that no longer exists in the document',
            default => 'appear more than once as an image block on this page',
        };
    }

    /**
     * Wiki run-587/run-593: cross-page counterpart to checkPlannedFigureCoverage() — that check
     * only ever sees one page's own planned_figures, so it cannot detect a figure materialized on
     * a page whose own plan never actually included it. Requires run-wide visibility across all
     * pages, so this runs once per run rather than once per page.
     *
     * Wiki run-593: a figure belongs to the source document, not to one "owner" page — the same
     * source_element_key may legitimately be planned (and therefore materialized) on several pages
     * at once (see the run-593 figure-placement simplification, commit 253db52). This check is
     * therefore never a single-owner comparison: a figure materialized on page X is valid whenever
     * X is one of the (possibly several) pages that key was actually planned onto. It still flags a
     * figure materialized on a page whose own plan never included that key at all, as long as the
     * key WAS planned somewhere in this run — a figure never planned anywhere in the run at all is
     * out of scope for this check (e.g. an article/summary page's pre-existing, unrelated freedom
     * to materialize any cited-but-unplanned image — EnterpriseWikiGenerateAppliedPagesService's
     * own generation-time gate already restricts concept/entity pages to their own planned_figures,
     * so this never applies there in practice).
     *
     * @param  list<array{page: EnterpriseWikiPage, version: ?EnterpriseWikiPageVersion}>  $allPages
     */
    private function checkPlannedFigureCrossPageAssignment(
        EnterpriseWikiIngestRun $run,
        array $allPages,
        array $decisionJson,
        array &$touchedIds,
        array &$counts,
    ): void {
        /** @var array<string, array<int, true>> $plannedPageIdsByKey source_element_key => set of page ids it is actually planned onto */
        $plannedPageIdsByKey = [];

        foreach ($allPages as $entry) {
            foreach ($this->plannedFiguresForPage($entry['page'], $decisionJson) as $figure) {
                $key = (string) ($figure['source_element_key'] ?? '');

                if ($key !== '') {
                    $plannedPageIdsByKey[$key][$entry['page']->id] = true;
                }
            }
        }

        if ($plannedPageIdsByKey === []) {
            return;
        }

        foreach ($allPages as $entry) {
            $page = $entry['page'];
            $version = $entry['version'];

            if ($version === null) {
                continue;
            }

            foreach ((array) ($version->content_blocks_json ?? []) as $block) {
                if (! is_array($block) || ($block['block_type'] ?? null) !== 'image') {
                    continue;
                }

                $key = (string) ($block['source_element_key'] ?? ($block['image_data']['source_image_key'] ?? ''));

                if ($key === '') {
                    continue;
                }

                $plannedPageIds = $plannedPageIdsByKey[$key] ?? null;

                // Never planned onto any page in this run at all — out of scope for this
                // cross-page check (e.g. an article/summary page's own, unrelated freedom to
                // materialize any cited image regardless of planning).
                if ($plannedPageIds === null) {
                    continue;
                }

                // Valid exactly when THIS page is one of the (possibly several) pages the figure
                // was actually planned onto — never compared against a single global owner.
                if (array_key_exists($page->id, $plannedPageIds)) {
                    continue;
                }

                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE),
                    EnterpriseWikiLintFinding::SEVERITY_ERROR,
                    sprintf(
                        'Figure "%s" was materialized on this page, but this page\'s own planned_figures does not include it (planned onto page(s) %s).',
                        $key,
                        implode(', ', array_keys($plannedPageIds)),
                    ),
                    $touchedIds,
                    $counts,
                );
            }
        }
    }

    /**
     * Same planned_figures lookup as
     * EnterpriseWikiGenerateAppliedPagesService::plannedFiguresForPage() (duplicated rather than
     * shared — same reason as plannedOwnedTopicsForPage() above: the two call sites read from
     * different inputs, a fresh $run.maintainer_decision_json here vs. one already loaded during
     * generation).
     *
     * @return list<array<string, mixed>>
     */
    private function plannedFiguresForPage(EnterpriseWikiPage $page, array $decisionJson): array
    {
        if (in_array($page->page_type, [EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY], true)) {
            $entries = array_merge(
                (array) data_get($decisionJson, 'concept_pages', []),
                (array) data_get($decisionJson, 'entity_pages', []),
            );

            $match = collect($entries)->firstWhere('title', $page->title);

            return $match !== null ? $this->validPlannedFigureList($match['planned_figures'] ?? []) : [];
        }

        $decisionKey = match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 'source_article',
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => 'source_summary',
            default => null,
        };

        if ($decisionKey === null) {
            return [];
        }

        $entry = (array) data_get($decisionJson, $decisionKey, []);

        return $this->validPlannedFigureList($entry['planned_figures'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function validPlannedFigureList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn (mixed $item): bool => is_array($item) && trim((string) ($item['source_element_key'] ?? '')) !== '',
        ));
    }

    /**
     * Every real, currently-extractable image source_element_key for this run's document — matches
     * EnterpriseWikiMaintainerDecisionService::figureCandidatesForDocument()'s same filter. Returns
     * [] for a non-document-sourced run (mirrors sourceTextForRun()), which
     * EnterpriseWikiPlannedFigureCoverageValidator treats as "unknown, skip the source_missing check"
     * rather than flagging every figure as unknown.
     *
     * @return list<string>
     */
    private function figureSourceElementKeysForRun(EnterpriseWikiIngestRun $run): array
    {
        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return [];
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            return [];
        }

        $elements = $this->sourceElementService->inspect($document)['elements'];

        return array_values(array_filter(array_map(
            fn (array $element): string => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE
                ? (string) ($element['source_element_key'] ?? '')
                : '',
            $elements,
        )));
    }

    private function checkPageClaims(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        array &$touchedIds,
        array &$counts,
    ): void {
        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->with('sourceReferences')
            ->get();

        // Wiki run-5: source_based and structural blocks never produce a claim, by design — only
        // a best_practice block (a persisted Procynia assertion) is ever expected to. A page built
        // purely from source_based + structural content correctly has zero claims; that must never
        // be reported as a defect (see CODE_PAGE_WITHOUT_CLAIMS below).
        $bestPracticeBlockKeys = $this->bestPracticeBlockKeysForVersion($version);
        $claimedBlockKeys = $claims->pluck('content_block_key')
            ->filter()
            ->map(static fn (mixed $key): string => trim((string) $key))
            ->unique();

        // A stronger, block-precise integrity signal than CODE_PAGE_WITHOUT_CLAIMS: a specific
        // best_practice block has no reviewable claim anchored to it at all.
        // EnterpriseWikiExtractPageClaimsService::persist() now guarantees this cannot happen for
        // a page generated after this fix (a deterministic fallback claim is always created); this
        // remains a real error for a legacy page version generated before that fix.
        if ($bestPracticeBlockKeys->diff($claimedBlockKeys)->isNotEmpty()) {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'Page has a best_practice content block with no reviewable claim.',
                $touchedIds,
                $counts,
            );
        }

        if ($claims->isEmpty()) {
            if ($bestPracticeBlockKeys->isNotEmpty()) {
                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Page has no claims extracted from its current version.',
                    $touchedIds,
                    $counts,
                );
            }

            return;
        }

        foreach ($claims as $claim) {
            $counts['claims_checked']++;

            // claim_missing_source (reuse existing code) — suppressed when the claim has
            // either a real source reference or a manual System Owner approval; see
            // EnterpriseWikiClaim::needsSourceWarning().
            if ($claim->needsSourceWarning()) {
                $this->upsertFinding(
                    $this->claimKey($run, $page, $version, $claim, EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Claim has no source reference.',
                    $touchedIds,
                    $counts,
                );
            }

            foreach ($claim->sourceReferences as $ref) {
                $counts['source_refs_checked']++;

                // source_reference_missing_excerpt (reuse existing code)
                if (($ref->excerpt ?? '') === '') {
                    $this->upsertFinding(
                        $this->claimKey($run, $page, $version, $claim, EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT),
                        EnterpriseWikiLintFinding::SEVERITY_WARNING,
                        'Source reference has no excerpt.',
                        $touchedIds,
                        $counts,
                    );
                }

                // source_reference_without_document / source_reference_customer_mismatch
                if ($ref->source_type === EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
                    $doc = EnterpriseWikiDocument::find($ref->source_id);

                    if ($doc === null) {
                        $this->upsertFinding(
                            $this->claimKey($run, $page, $version, $claim, EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT),
                            EnterpriseWikiLintFinding::SEVERITY_ERROR,
                            "Source reference points to document [{$ref->source_id}] which does not exist.",
                            $touchedIds,
                            $counts,
                        );
                    } elseif ($doc->customer_id !== $run->customer_id) {
                        $this->upsertFinding(
                            $this->claimKey($run, $page, $version, $claim, EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH),
                            EnterpriseWikiLintFinding::SEVERITY_ERROR,
                            "Source reference document [{$ref->source_id}] belongs to a different customer.",
                            $touchedIds,
                            $counts,
                        );
                    }
                }
            }
        }
    }

    /**
     * @return Collection<int, string> unique, non-empty block_key values for every best_practice
     *                                 block in the version's current content_blocks_json
     */
    private function bestPracticeBlockKeysForVersion(EnterpriseWikiPageVersion $version): Collection
    {
        return collect((array) ($version->content_blocks_json ?? []))
            ->filter(static fn (mixed $block): bool => is_array($block)
                && ($block['content_origin'] ?? null) === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)
            ->map(static fn (array $block): string => trim((string) ($block['block_key'] ?? '')))
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique()
            ->values();
    }

    private function checkPageLinks(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array &$touchedIds,
        array &$counts,
    ): void {
        $outgoing = EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where('from_page_id', $page->id)
            ->get();

        $counts['links_checked'] += $outgoing->count();

        // page_without_outgoing_links
        if ($outgoing->isEmpty()) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_OUTGOING_LINKS),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Page has no outgoing links.',
                $touchedIds,
                $counts,
            );
        }

        // page_without_incoming_links
        $hasIncoming = EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where('to_page_id', $page->id)
            ->exists();

        if (! $hasIncoming) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_INCOMING_LINKS),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Page has no incoming links (backlinks).',
                $touchedIds,
                $counts,
            );
        }

        // Type-specific link checks
        $outgoingTypes = $outgoing->pluck('link_type')->all();

        match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $this->checkArticleLinks($run, $page, $outgoingTypes, $touchedIds, $counts),
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $this->checkSummaryLinks($run, $page, $outgoingTypes, $touchedIds, $counts),
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $this->checkConceptLinks($run, $page, $outgoing, $touchedIds, $counts),
            EnterpriseWikiPage::PAGE_TYPE_ENTITY => $this->checkEntityLinks($run, $page, $outgoingTypes, $touchedIds, $counts),
            default => null,
        };

        // missing_reverse_link: for each outgoing link, check if reverse exists
        $missingReverses = [];

        foreach ($outgoing as $link) {
            $reverseType = self::REVERSE_LINK_TYPES[$link->link_type] ?? null;

            if ($reverseType === null) {
                continue;
            }

            $reverseExists = EnterpriseWikiPageLink::query()
                ->where('customer_id', $run->customer_id)
                ->where('from_page_id', $link->to_page_id)
                ->where('to_page_id', $page->id)
                ->where('link_type', $reverseType)
                ->exists();

            if (! $reverseExists) {
                $missingReverses[] = $reverseType;
            }
        }

        if (! empty($missingReverses)) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_MISSING_REVERSE_LINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Expected reverse link(s) are missing: '.implode(', ', $missingReverses).'.',
                $touchedIds,
                $counts,
            );
        }
    }

    /**
     * Canonical wikilink integrity (8I-6): every check here is derived deterministically from
     * content_markdown → parser → resolver → EnterpriseWikiPageLink, never from the graph or
     * maintainer decision as a separate source of truth.
     */
    private function checkWikilinkIntegrity(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        array $runPageIds,
        array &$touchedIds,
        array &$counts,
    ): void {
        $markdown = (string) $version->content_markdown;
        $parsed = $this->linkParser->parse($markdown);
        $rawOccurrences = $this->linkParser->countRawOccurrences($markdown);

        if ($rawOccurrences > count($parsed)) {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_MALFORMED_WIKILINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                sprintf('Content contains %d malformed wikilink attempt(s).', $rawOccurrences - count($parsed)),
                $touchedIds,
                $counts,
            );
        }

        $occurrences = $this->linkResolver->resolveOccurrences($run->customer_id, $page, $parsed);
        $brokenSlugs = [];
        $selfSlugs = [];

        foreach ($occurrences as $occurrence) {
            match ($occurrence['status']) {
                EnterpriseWikiLinkResolver::STATUS_BROKEN => $brokenSlugs[] = $occurrence['link']['target_slug'],
                EnterpriseWikiLinkResolver::STATUS_SELF_LINK => $selfSlugs[] = $occurrence['link']['target_slug'],
                default => null,
            };
        }

        if ($brokenSlugs !== []) {
            $brokenSlugs = array_values(array_unique($brokenSlugs));

            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Content contains broken wikilink(s): '.implode(', ', $brokenSlugs).'.',
                $touchedIds,
                $counts,
            );

            $crossCustomerSlugs = EnterpriseWikiPage::query()
                ->whereIn('slug', $brokenSlugs)
                ->where('customer_id', '!=', $run->customer_id)
                ->pluck('slug')
                ->all();

            if ($crossCustomerSlugs !== []) {
                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_CROSS_CUSTOMER_WIKILINK),
                    EnterpriseWikiLintFinding::SEVERITY_ERROR,
                    'Content links to (an)other customer\'s page(s): '.implode(', ', $crossCustomerSlugs).'.',
                    $touchedIds,
                    $counts,
                );
            }
        }

        if ($selfSlugs !== []) {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_SELF_WIKILINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Content contains (a) self-referencing wikilink(s).',
                $touchedIds,
                $counts,
            );
        }

        $resolution = $this->linkResolver->resolve($run->customer_id, $page, $parsed);
        $parsedValidTargetIds = array_map(fn (array $t) => $t['to_page']->id, $resolution['resolved']);

        $materializedTargetIds = EnterpriseWikiPageLink::query()
            ->where('customer_id', $run->customer_id)
            ->where('from_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->pluck('to_page_id')
            ->all();

        if ($parsedValidTargetIds !== [] && $materializedTargetIds === []) {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_MISSING_WIKILINK_MATERIALIZATION),
                EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'Current version contains valid wikilinks but no canonical EnterpriseWikiPageLink rows exist for this page.',
                $touchedIds,
                $counts,
            );
        } else {
            $underMaterialized = array_diff($parsedValidTargetIds, $materializedTargetIds);

            if ($underMaterialized !== []) {
                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Current version contains a valid wikilink not reflected in the materialized page-link projection.',
                    $touchedIds,
                    $counts,
                );
            }

            $overMaterialized = array_diff($materializedTargetIds, $parsedValidTargetIds);

            if ($overMaterialized !== []) {
                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'A materialized wikilink graph edge no longer has a matching link in the current version.',
                    $touchedIds,
                    $counts,
                );
            }
        }

        // Orphan concept/entity pages: no incoming canonical wikilink at all.
        if (in_array($page->page_type, [EnterpriseWikiPage::PAGE_TYPE_CONCEPT, EnterpriseWikiPage::PAGE_TYPE_ENTITY], true)) {
            $hasIncomingWikilink = EnterpriseWikiPageLink::query()
                ->where('customer_id', $run->customer_id)
                ->where('to_page_id', $page->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->exists();

            if (! $hasIncomingWikilink) {
                $code = $page->page_type === EnterpriseWikiPage::PAGE_TYPE_CONCEPT
                    ? EnterpriseWikiLintFinding::CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK
                    : EnterpriseWikiLintFinding::CODE_ENTITY_WITHOUT_INCOMING_WIKILINK;

                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, $code),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    ucfirst($page->page_type).' page has no incoming canonical wikilink from any page.',
                    $touchedIds,
                    $counts,
                );
            }
        }

        // Article/summary pages with other run pages available but zero outgoing wikilinks.
        if (in_array($page->page_type, [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY], true)
            && count($runPageIds) > 1
        ) {
            $hasOutgoingWikilink = EnterpriseWikiPageLink::query()
                ->where('customer_id', $run->customer_id)
                ->where('from_page_id', $page->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->exists();

            if (! $hasOutgoingWikilink) {
                $this->upsertFinding(
                    $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED),
                    EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'Other pages exist in this run but the page has no outgoing canonical wikilink.',
                    $touchedIds,
                    $counts,
                );
            }
        }
    }

    private function checkArticleLinks(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array $outgoingTypes,
        array &$touchedIds,
        array &$counts,
    ): void {
        if (! in_array(EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY, $outgoingTypes, true)) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Article page has no link to a summary page.',
                $touchedIds,
                $counts,
            );
        }

        $hasConceptOrEntity = in_array(EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT, $outgoingTypes, true)
            || in_array(EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY, $outgoingTypes, true);

        if (! $hasConceptOrEntity) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS),
                EnterpriseWikiLintFinding::SEVERITY_INFO,
                'Article page has no links to concept or entity pages.',
                $touchedIds,
                $counts,
            );
        }
    }

    private function checkSummaryLinks(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array $outgoingTypes,
        array &$touchedIds,
        array &$counts,
    ): void {
        if (! in_array(EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE, $outgoingTypes, true)) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_SUMMARY_WITHOUT_ARTICLE_LINK),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Summary page has no link back to its article page.',
                $touchedIds,
                $counts,
            );
        }
    }

    /**
     * A concept page counts as linked when it has an outgoing link to an article or summary
     * page — either the legacy structural link_type (concept_to_article/concept_to_summary,
     * built from run co-membership) or a canonical wikilink whose target page is actually an
     * article or summary. A wikilink to another concept page never counts.
     *
     * @param  Collection<int, EnterpriseWikiPageLink>  $outgoing
     */
    private function checkConceptLinks(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        Collection $outgoing,
        array &$touchedIds,
        array &$counts,
    ): void {
        $outgoingTypes = $outgoing->pluck('link_type')->all();

        $hasBacklink = in_array(EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE, $outgoingTypes, true)
            || in_array(EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY, $outgoingTypes, true);

        if (! $hasBacklink) {
            $wikilinkTargetIds = $outgoing
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->pluck('to_page_id')
                ->all();

            if ($wikilinkTargetIds !== []) {
                $hasBacklink = EnterpriseWikiPage::query()
                    ->whereIn('id', $wikilinkTargetIds)
                    ->whereIn('page_type', [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_SUMMARY])
                    ->exists();
            }
        }

        if (! $hasBacklink) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Concept page has no outgoing link to an article or summary page.',
                $touchedIds,
                $counts,
            );
        }
    }

    private function checkEntityLinks(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array $outgoingTypes,
        array &$touchedIds,
        array &$counts,
    ): void {
        $hasBacklink = in_array(EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE, $outgoingTypes, true)
            || in_array(EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY, $outgoingTypes, true);

        if (! $hasBacklink) {
            $this->upsertFinding(
                $this->pageKey($run, $page, null, EnterpriseWikiLintFinding::CODE_ORPHAN_ENTITY_PAGE),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Entity page has no links to article or summary pages.',
                $touchedIds,
                $counts,
            );
        }
    }

    // =========================================================================
    // Upsert + resolve helpers
    // =========================================================================

    /**
     * Upsert a lint finding by its composite key.
     *
     * - Not found:                 creates it (findings_created++)
     * - Found, was resolved:       re-opens it (findings_created++)
     * - Found, already open:       updates severity/message, no state change (findings_skipped++)
     */
    private function upsertFinding(
        array $key,
        string $severity,
        string $message,
        array &$touchedIds,
        array &$counts,
    ): void {
        $query = EnterpriseWikiLintFinding::query();

        foreach ($key as $col => $val) {
            $val === null ? $query->whereNull($col) : $query->where($col, $val);
        }

        $existing = $query->first();

        if ($existing !== null) {
            $alreadyOpen = $existing->status === EnterpriseWikiLintFinding::STATUS_OPEN;

            $existing->update([
                'severity' => $severity,
                'message' => $message,
                'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
                'detected_at' => now(),
                'resolved_at' => null,
            ]);

            $touchedIds[] = $existing->id;

            if ($alreadyOpen) {
                $counts['findings_skipped']++;
            } else {
                // Was resolved → re-opened counts as created
                $counts['findings_created']++;
                $this->incrementSeverity($severity, $counts);
            }

            return;
        }

        $created = EnterpriseWikiLintFinding::query()->create(array_merge($key, [
            'severity' => $severity,
            'message' => $message,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]));

        $touchedIds[] = $created->id;
        $counts['findings_created']++;
        $this->incrementSeverity($severity, $counts);
    }

    /**
     * Mark all open findings for this run that were not touched in this pass as resolved.
     */
    private function closeStaleFindings(int $runId, int $customerId, array $touchedIds): int
    {
        $query = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $customerId)
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN);

        if (! empty($touchedIds)) {
            $query->whereNotIn('id', $touchedIds);
        }

        return $query->update([
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    private function incrementSeverity(string $severity, array &$counts): void
    {
        match ($severity) {
            EnterpriseWikiLintFinding::SEVERITY_ERROR => $counts['errors']++,
            EnterpriseWikiLintFinding::SEVERITY_WARNING => $counts['warnings']++,
            EnterpriseWikiLintFinding::SEVERITY_INFO => $counts['info']++,
            default => null,
        };
    }

    // =========================================================================
    // Key builders
    // =========================================================================

    private function runKey(EnterpriseWikiIngestRun $run, string $code): array
    {
        return [
            'customer_id' => $run->customer_id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => null,
            'enterprise_wiki_page_version_id' => null,
            'enterprise_wiki_claim_id' => null,
            'code' => $code,
        ];
    }

    private function pageKey(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $version,
        string $code,
    ): array {
        return [
            'customer_id' => $run->customer_id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version?->id,
            'enterprise_wiki_claim_id' => null,
            'code' => $code,
        ];
    }

    private function claimKey(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        EnterpriseWikiClaim $claim,
        string $code,
    ): array {
        return [
            'customer_id' => $run->customer_id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => $code,
        ];
    }
}
