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
    ) {}

    /** Expected reverse link type for each forward link type. */
    private const REVERSE_LINK_TYPES = [
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY  => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE  => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT  => EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE  => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
        EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY   => EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE,
        EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE   => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT  => EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY  => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
        EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY   => EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY,
        EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY   => EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
    ];

    /**
     * @return array{pages_checked: int, claims_checked: int, source_refs_checked: int,
     *               links_checked: int, findings_created: int, findings_skipped: int,
     *               findings_resolved: int, errors: int, warnings: int, info: int}
     * @throws \InvalidArgumentException if the run is not applied
     */
    public function lint(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can be linted."
            );
        }

        $touchedIds = [];
        $counts     = [
            'pages_checked'       => 0,
            'claims_checked'      => 0,
            'source_refs_checked' => 0,
            'links_checked'       => 0,
            'findings_created'    => 0,
            'findings_skipped'    => 0,
            'findings_resolved'   => 0,
            'errors'              => 0,
            'warnings'            => 0,
            'info'                => 0,
        ];

        // ----------------------------------------------------------------
        // Load pages from run
        // ----------------------------------------------------------------
        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $allPages  = [];
        $articles  = [];
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
                default                               => null,
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

        foreach ($allPages as $entry) {
            $counts['pages_checked']++;
            $page    = $entry['page'];
            $version = $entry['version'];

            $this->checkPageVersion($run, $page, $version, $touchedIds, $counts);

            if ($version !== null && trim((string) ($version->content_markdown ?? '')) !== '') {
                $this->checkPageClaims($run, $page, $version, $touchedIds, $counts);
                $this->checkPageLinks($run, $page, $touchedIds, $counts);
                $this->checkWikilinkIntegrity($run, $page, $version, $runPageIds, $touchedIds, $counts);
            }
        }

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

        if ($claims->isEmpty()) {
            $this->upsertFinding(
                $this->pageKey($run, $page, $version, EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS),
                EnterpriseWikiLintFinding::SEVERITY_WARNING,
                'Page has no claims extracted from its current version.',
                $touchedIds,
                $counts,
            );

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
            EnterpriseWikiPage::PAGE_TYPE_ENTITY  => $this->checkEntityLinks($run, $page, $outgoingTypes, $touchedIds, $counts),
            default                               => null,
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
                'Expected reverse link(s) are missing: ' . implode(', ', $missingReverses) . '.',
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
                'severity'    => $severity,
                'message'     => $message,
                'status'      => EnterpriseWikiLintFinding::STATUS_OPEN,
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
            'severity'    => $severity,
            'message'     => $message,
            'status'      => EnterpriseWikiLintFinding::STATUS_OPEN,
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
            'status'      => EnterpriseWikiLintFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    private function incrementSeverity(string $severity, array &$counts): void
    {
        match ($severity) {
            EnterpriseWikiLintFinding::SEVERITY_ERROR   => $counts['errors']++,
            EnterpriseWikiLintFinding::SEVERITY_WARNING => $counts['warnings']++,
            EnterpriseWikiLintFinding::SEVERITY_INFO    => $counts['info']++,
            default                                     => null,
        };
    }

    // =========================================================================
    // Key builders
    // =========================================================================

    private function runKey(EnterpriseWikiIngestRun $run, string $code): array
    {
        return [
            'customer_id'                     => $run->customer_id,
            'enterprise_wiki_ingest_run_id'   => $run->id,
            'enterprise_wiki_page_id'         => null,
            'enterprise_wiki_page_version_id' => null,
            'enterprise_wiki_claim_id'        => null,
            'code'                            => $code,
        ];
    }

    private function pageKey(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiPageVersion $version,
        string $code,
    ): array {
        return [
            'customer_id'                     => $run->customer_id,
            'enterprise_wiki_ingest_run_id'   => $run->id,
            'enterprise_wiki_page_id'         => $page->id,
            'enterprise_wiki_page_version_id' => $version?->id,
            'enterprise_wiki_claim_id'        => null,
            'code'                            => $code,
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
            'customer_id'                     => $run->customer_id,
            'enterprise_wiki_ingest_run_id'   => $run->id,
            'enterprise_wiki_page_id'         => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_claim_id'        => $claim->id,
            'code'                            => $code,
        ];
    }
}
