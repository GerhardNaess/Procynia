<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Normalizes the Kjøringer "Funn" (quality findings) panel from two existing, separately-owned
 * sources of truth — never a new findings table, never a parallel quality engine:
 *
 *   1. EnterpriseWikiLintFinding rows for this run (all statuses — the panel is the one place
 *      that must show resolved/historical findings too, not just open ones; see
 *      buildSummary()/countsForItems() for how the run list's own displayed count is kept
 *      consistent with this). A genuine structural lint error is the one category here that can
 *      still legitimately gate qa_status=failed (EnterpriseWikiPostIngestQaService::
 *      findCriticalDefects()) — that is a real technical defect, not a claim/QA matter, and v0.10
 *      does not change it.
 *   2. "Claim QA signals" — EnterpriseWikiClaim rows with content_origin
 *      internal_error/unsupported_generated_content on the run's pages' CURRENT versions,
 *      grouped by canonical_fact_id (Del 4) so the same underlying fact repeated across several
 *      Wiki pages surfaces as ONE QA case listing every page it occurs on, not one case per page.
 *      These are exactly what EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals() reports
 *      — computed live, never persisted as a finding row.
 *   3. "Best-practice suggestions" — EnterpriseWikiClaim rows with content_origin best_practice on
 *      the run's pages' CURRENT versions, in any approval_status. Like (2), this is a voluntary
 *      QA opportunity, never a defect.
 *
 * **v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): claims and their QA
 * review are a voluntary, non-blocking quality loop.** `blocks_run`/`blocks_page` on a claim-based
 * item are therefore always `false` — claims never hold a run back, regardless of
 * `system_recommends_blocking`/`user_decision` (which remain informational: the system's own
 * suggestion, and any human decision recorded on the claim, both useful context for the voluntary
 * QA screen, never a gate). Lint findings are the one exception: a genuinely blocking lint error
 * (`blocks_run`/`blocks_page` true) reflects a real structural defect and is unrelated to claim QA.
 *
 * Reads content_origin directly and only ever as one of exactly four mutually-exclusive values —
 * never a heuristic reconstructed from a combination of other fields, and never both (2) and (3)
 * for the same claim, since a single content_origin value can only ever match one of the two
 * query filters above. EnterpriseWikiClaimClassificationService is what keeps that one value
 * trustworthy across a claim's whole lifecycle (extraction's provisional origin can be overwritten
 * by anything; verification's authoritative one cannot be silently overwritten by repair or
 * reevaluation) — this service does not itself need to know that history, only that content_origin
 * is always the current, authoritative answer.
 */
class EnterpriseWikiRunFindingsService
{
    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
        private readonly EnterpriseWikiBestPracticeSectionService $sectionService,
        private readonly EnterpriseWikiEscalatedRunRecoveryService $recoveryService,
    ) {}

    /**
     * @return array{findings: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function buildForRun(EnterpriseWikiIngestRun $run, ?User $user, bool $includeTechnical): array
    {
        $returnUrl = route('app.wiki.index', [
            'tab' => 'runs',
            'run_src' => $run->source_id,
        ]);

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $pagesById = EnterpriseWikiPage::query()
            ->whereIn('id', $pageIds)
            ->get(['id', 'title', 'slug'])
            ->keyBy('id');

        $currentVersionIdByPageId = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id', 'enterprise_wiki_page_id');

        $lintFindings = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with(['claim.version'])
            ->orderByDesc('detected_at')
            ->get();

        $claimDefects = $currentVersionIdByPageId->isEmpty()
            ? collect()
            : EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $currentVersionIdByPageId->values())
                ->whereIn('content_origin', [
                    EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                ])
                ->with(['version', 'sourceReferences', 'canonicalFact', 'blockingOverrideBy'])
                ->get();

        $bestPracticeSuggestions = $currentVersionIdByPageId->isEmpty()
            ? collect()
            : EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $currentVersionIdByPageId->values())
                ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)
                ->with(['version', 'approvedBy'])
                ->get();

        $items = [];

        foreach ($lintFindings as $finding) {
            $items[] = $this->normalizeLintFinding($finding, $pagesById, $currentVersionIdByPageId, $user, $includeTechnical, $returnUrl);
        }

        // v0.7 binding quality-strategy rule (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat —
        // v0.7"): an internal_error claim, or an unsupported_generated_content claim flagged only
        // by an internal comparison-mechanism signal (deterministic dimension mismatch, a
        // self-reported AI check mismatch, or "never actually checked" technical uncertainty), is
        // never a user-facing case — see EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()
        // for the single-source-of-truth predicate. It stays available as raw claim data for
        // technical diagnostics, just never surfaces here.
        $userFacingClaimDefects = $claimDefects->filter(
            fn (EnterpriseWikiClaim $claim): bool => $this->claimFindingExplainer->isUserFacingAddition($claim),
        );

        // Del 4 (v0.10): the same underlying fact repeated across several Wiki pages (article,
        // summary, concept/entity pages restating the same statement) is ONE QA case, not one per
        // page — reuses the existing EnterpriseWikiCanonicalFact link (canonical_fact_id) rather
        // than inventing a parallel dedup mechanism or comparing claim text. A claim not (yet)
        // linked to a canonical fact falls back to its own claim id, so it is never incorrectly
        // merged with an unrelated claim.
        $claimDefectGroups = $userFacingClaimDefects->groupBy(
            fn (EnterpriseWikiClaim $claim): string => $this->claimDefectGroupKey($claim),
        );

        foreach ($claimDefectGroups as $groupClaims) {
            $items[] = $this->normalizeClaimDefect($groupClaims, $pagesById, $user, $includeTechnical, $returnUrl);
        }

        // Grouped by faglig seksjon (heading block + its immediately-following best-practice
        // blocks, see EnterpriseWikiBestPracticeSectionService) rather than raw content_block_key
        // — several best-practice blocks that together form one coherent section (a heading plus
        // its paragraph(s)/list, not just one paragraph) are ONE user-facing QA case, never one
        // per paragraph or per claim. A claim with no resolvable section falls back to its own
        // claim id as the group key, so it is never incorrectly merged with an unrelated claim.
        $sectionMapsByVersionId = $bestPracticeSuggestions
            ->pluck('version')
            ->filter()
            ->unique('id')
            ->mapWithKeys(fn (EnterpriseWikiPageVersion $version): array => [
                $version->id => $this->sectionService->mapBlocksToSections($version),
            ]);

        $bestPracticeGroups = $bestPracticeSuggestions->groupBy(
            fn (EnterpriseWikiClaim $claim): string => $this->additionGroupKey($claim, $sectionMapsByVersionId),
        );

        foreach ($bestPracticeGroups as $groupClaims) {
            $items[] = $this->normalizeBestPracticeSuggestion($groupClaims, $pagesById, $sectionMapsByVersionId, $user, $includeTechnical, $returnUrl);
        }

        usort($items, $this->sortComparator());

        return [
            'findings' => array_values($items),
            'summary' => $this->buildSummary($items, $run),
        ];
    }

    /**
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     * @param  Collection<int, int>  $currentVersionIdByPageId
     */
    private function normalizeLintFinding(
        EnterpriseWikiLintFinding $finding,
        Collection $pagesById,
        Collection $currentVersionIdByPageId,
        ?User $user,
        bool $includeTechnical,
        ?string $returnUrl,
    ): array {
        $page = $finding->enterprise_wiki_page_id !== null ? $pagesById->get($finding->enterprise_wiki_page_id) : null;
        $currentVersionId = $page !== null ? $currentVersionIdByPageId->get($page->id) : null;
        $isSuperseded = $finding->enterprise_wiki_page_version_id !== null
            && $currentVersionId !== null
            && (int) $finding->enterprise_wiki_page_version_id !== (int) $currentVersionId;

        $copy = $this->qualityCheckCopy($finding->code);
        $isBlocking = ! $isSuperseded && $finding->isOpen() && $finding->isBlocking();

        [$status, $severity] = match (true) {
            $isSuperseded => ['superseded', $this->severityFor($finding->severity)],
            $finding->isResolved() => ['resolved', $this->severityFor($finding->severity)],
            $isBlocking => ['requires_action', $this->severityFor($finding->severity)],
            $finding->severity === EnterpriseWikiLintFinding::SEVERITY_INFO => ['informative', $this->severityFor($finding->severity)],
            default => ['open', $this->severityFor($finding->severity)],
        };

        $claim = $finding->claim;
        $canHandleClaim = $claim !== null && $user instanceof User && ! $isSuperseded && $finding->isOpen()
            && $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $claim->version);

        $url = $this->pageUrl($page, $claim?->id, $returnUrl, $claim === null ? $finding->id : null);
        $actionLabel = match (true) {
            $url === null => null,
            $claim !== null && $finding->isOpen() && ! $isSuperseded => $canHandleClaim ? 'open_and_handle' : 'view_source',
            default => 'view_page',
        };

        $item = [
            'id' => 'lint-'.$finding->id,
            'title' => $copy['label'],
            'explanation' => $copy['description'],
            'category' => $finding->code,
            'category_label' => $copy['label'],
            'severity' => $severity,
            'severity_label' => $this->severityLabel($severity),
            'status' => $status,
            'status_label' => __('procynia.wiki.runs_findings_status_'.$status),
            'blocks_run' => $isBlocking,
            'blocks_page' => $isBlocking && $page !== null,
            'scope' => $page !== null ? 'page' : 'run',
            'page_id' => $page?->id,
            'page_title' => $page?->title,
            'page_version_id' => $finding->enterprise_wiki_page_version_id,
            'page_version_number' => $claim?->version?->version_number,
            'claim_id' => $claim?->id,
            'created_at' => $finding->detected_at?->toIso8601String(),
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'url' => $url,
            'can_handle' => $canHandleClaim,
            'action' => $actionLabel,
            'action_label' => $this->actionLabel($actionLabel),
        ];

        if ($includeTechnical) {
            $item['technical'] = [
                'source' => 'lint_finding',
                'code' => $finding->code,
                'raw_severity' => $finding->severity,
                'raw_status' => $finding->status,
            ];
        }

        return $item;
    }

    /**
     * No supersession check is needed here — unlike lint findings, the caller's query already
     * scopes claims to the page's CURRENT version only (see buildForRun()), matching
     * EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals()'s own scoping.
     *
     * Every finding gets a concrete, per-case title/explanation/recommended action from
     * EnterpriseWikiClaimFindingExplainer — never the one-size-fits-all "unsupported_generated_content"/
     * "internal_generation_error" label the old version of this method used regardless of why the
     * claim actually failed. The category text is never a substitute for the concrete claim and
     * source: this item always carries the claim's own text and its own linked source excerpts
     * (or an honest "no confident source" flag when none exist) alongside the categorical
     * explanation, so the reader can see exactly what the Wiki text says versus what the source
     * says (CLAUDE.md: "Ikke bruk den generelle kategoriteksten som erstatning for claim og
     * kilde").
     *
     * **v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): claims never block.**
     * `blocks_run`/`blocks_page` are always `false` here — the system's own suggestion
     * (`system_recommends_blocking`) and any recorded human decision (`user_decision`) remain
     * informational context for the voluntary QA screen, never a gate. `status` reflects the same
     * distinction without blocking language: `flagged_for_review` (a human explicitly flagged it),
     * `open_for_qa_review` (system suggests review, no decision yet), or `open` (a plain, undecided
     * QA signal).
     *
     * Del 4: $claims is every claim sharing the same underlying fact (grouped by canonical_fact_id
     * in buildForRun(), usually exactly one). The PRIMARY claim (lowest page id, then lowest claim
     * id, for a stable/deterministic pick) drives title/explanation/url/claim_id; every claim in
     * the group is listed in `occurrences` so a QA specialist can see every page/text location the
     * same underlying fact appears on, without the group ever becoming more than one QA case.
     *
     * @param  Collection<int, EnterpriseWikiClaim>  $claims
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     */
    private function normalizeClaimDefect(
        Collection $claims,
        Collection $pagesById,
        ?User $user,
        bool $includeTechnical,
        ?string $returnUrl,
    ): array {
        $claim = $claims->sort(fn (EnterpriseWikiClaim $a, EnterpriseWikiClaim $b): int => [$a->enterprise_wiki_page_id, $a->id] <=> [$b->enterprise_wiki_page_id, $b->id]
        )->first();

        $page = $pagesById->get($claim->enterprise_wiki_page_id);
        $explanation = $this->claimFindingExplainer->explain($claim);
        $blockingState = $this->claimFindingExplainer->blockingState($claim);
        $severity = match ($explanation['category']) {
            EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM => 'critical',
            default => 'warning',
        };

        $status = match (true) {
            $blockingState['user_decision'] === EnterpriseWikiClaimFindingExplainer::USER_DECISION_BLOCKING => 'flagged_for_review',
            $blockingState['requires_decision'] => 'open_for_qa_review',
            default => 'open',
        };

        $canHandleClaim = $user instanceof User
            && $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $claim->version);

        $url = $this->pageUrl($page, $claim->id, $returnUrl);
        $actionKey = match (true) {
            $url === null => null,
            $canHandleClaim => 'open_and_handle',
            default => 'view_source',
        };

        $sourceExcerpts = $claim->sourceReferences
            ->map(fn ($ref) => [
                'label' => $ref->source_label,
                'excerpt' => $ref->excerpt,
                'page_reference' => $ref->page_reference,
            ])
            ->filter(fn (array $ref): bool => trim((string) $ref['excerpt']) !== '')
            ->values()
            ->all();

        $occurrences = $claims
            ->sort(fn (EnterpriseWikiClaim $a, EnterpriseWikiClaim $b): int => [$a->enterprise_wiki_page_id, $a->id] <=> [$b->enterprise_wiki_page_id, $b->id])
            ->map(function (EnterpriseWikiClaim $occurrence) use ($pagesById, $returnUrl): array {
                $occurrencePage = $pagesById->get($occurrence->enterprise_wiki_page_id);

                return [
                    'claim_id' => $occurrence->id,
                    'page_id' => $occurrencePage?->id,
                    'page_title' => $occurrencePage?->title,
                    'claim_text' => $occurrence->claim_text,
                    'url' => $this->pageUrl($occurrencePage, $occurrence->id, $returnUrl),
                ];
            })
            ->values()
            ->all();

        $item = [
            'id' => 'claim-defect-'.$claim->id,
            'title' => $explanation['title'],
            'explanation' => $explanation['explanation'],
            'recommended_action' => $explanation['recommended_action'],
            'category' => $explanation['category'],
            'category_label' => $explanation['category_label'],
            'severity' => $severity,
            'severity_label' => $this->severityLabel($severity),
            'status' => $status,
            'status_label' => __('procynia.wiki.runs_findings_status_'.$status),
            'blocks_run' => false,
            'blocks_page' => false,
            'system_recommends_blocking' => $blockingState['system_recommends_blocking'],
            'user_decision' => $blockingState['user_decision'],
            'requires_decision' => $blockingState['requires_decision'],
            'blocking_reason' => $this->blockingReasonText($claim),
            'blocking_override_by_name' => $claim->blockingOverrideBy?->name,
            'blocking_override_at' => $claim->blocking_override_at?->toIso8601String(),
            'claim_text' => $claim->claim_text,
            'page_excerpt' => $claim->page_excerpt,
            'source_excerpts' => $sourceExcerpts,
            'has_source_excerpt' => $sourceExcerpts !== [],
            'scope' => $page !== null ? 'page' : 'run',
            'page_id' => $page?->id,
            'page_title' => $page?->title,
            'page_version_id' => $claim->enterprise_wiki_page_version_id,
            'page_version_number' => $claim->version?->version_number,
            'claim_id' => $claim->id,
            'claim_count' => $claims->count(),
            'occurrences' => $occurrences,
            'created_at' => $claim->created_at?->toIso8601String(),
            'resolved_at' => null,
            'url' => $url,
            'can_handle' => $canHandleClaim,
            'action' => $actionKey,
            'action_label' => $this->actionLabel($actionKey),
        ];

        if ($includeTechnical) {
            $item['technical'] = [
                'source' => 'claim_qa_signal',
                'code' => $claim->content_origin,
                'generation_issue' => $claim->generation_issue,
                'raw_severity' => null,
                'raw_status' => null,
                'claim_ids' => $claims->pluck('id')->all(),
            ];
        }

        return $item;
    }

    /**
     * Grouping key for Del 4 (v0.10): several claims sharing the same underlying canonical fact
     * (EnterpriseWikiCanonicalFact, set by EnterpriseWikiVerifyPageClaimsService) — typically the
     * same statement repeated across article/summary/concept/entity pages — are one QA case, not
     * one per page/claim. A claim not (yet) linked to a canonical fact falls back to its own claim
     * id, so it is never incorrectly merged with an unrelated claim purely on text similarity.
     */
    private function claimDefectGroupKey(EnterpriseWikiClaim $claim): string
    {
        return $claim->canonical_fact_id !== null
            ? 'fact-'.$claim->canonical_fact_id
            : 'claim-'.$claim->id;
    }

    /**
     * Human-readable "why is/isn't this blocking" — the system's own suggestion until an
     * authorized user overrides it, after which it names who and when.
     */
    private function blockingReasonText(EnterpriseWikiClaim $claim): string
    {
        if ($claim->blocking_override === null) {
            return __('procynia.wiki.claim_blocking_reason_default');
        }

        return __('procynia.wiki.claim_blocking_reason_overridden', [
            'name' => $claim->blockingOverrideBy?->name ?? '—',
            'date' => $claim->blocking_override_at?->toIso8601String() ?? '',
        ]);
    }

    /**
     * A best-practice suggestion is never a defect — it is a deliberate recommendation beyond
     * the source document, always neutral severity, never blocking, with its own approve/edit-
     * and-approve/reject workflow (WikiClaimController), never the QA/repair path (2). The
     * "Gå til tekst" link uses the exact same ?claim_id= deep link as everything else on this
     * panel, but WikiController::show() resolves it into a validated review_reference that scrolls
     * to and highlights the actual suggested text block, not just the top of the page.
     *
     * Grouped by faglig seksjon (EnterpriseWikiBestPracticeSectionService), not raw
     * content_block_key: several claims anchored across the section's blocks — a heading plus its
     * paragraph(s)/list — are ONE case, not one per paragraph or per claim. $claims is every claim
     * in the group. The PRIMARY claim (lowest position_order, then lowest id — the same tie-break
     * WikiClaimController's cascade uses) drives id/url/claim_id — this is itself an existing,
     * stable domain id (Del 7): it never changes across a reload of the same run/claim set, and is
     * never an array index or a per-render random value. The group's status is "still pending" as
     * long as ANY claim in it is undecided (WikiClaimController::cascadeBlockDecision() keeps
     * siblings in sync when a decision is recorded, so this is normally never observed mid-way,
     * but the aggregate check is the honest source of truth regardless of whether the cascade
     * ran).
     *
     * `title` is the section's own heading text when EnterpriseWikiBestPracticeSectionService
     * found one (e.g. "Begrepsramme: ITIL og Incident management") — this is what makes a QA
     * specialist see one faglig seksjon, not the primary claim's own single, arbitrary sentence.
     * Falls back to the primary claim's text when no heading was detected (a single unheaded
     * best-practice block), matching the pre-existing behavior exactly. `section_text` is every
     * claim's text in the group, in reading order, for "et lesbart utdrag eller samlet tekst"
     * (Del 6) — approving/editing/rejecting still acts on the single primary claim only
     * (WikiClaimController is unchanged); the other claims in the section remain individually
     * traceable via `technical.claim_ids` and `block_keys`, exactly like claim-defect grouping's
     * `occurrences` (Del 4: "dagens behandling kan bare håndtere ett claim om gangen" — this is
     * the minimal extension needed to represent the section as one QA unit, not a new workflow).
     *
     * @param  Collection<int, EnterpriseWikiClaim>  $claims
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     * @param  Collection<int, array<string, array{section_key: string, heading_text: ?string, heading_block_key: ?string}>>  $sectionMapsByVersionId
     */
    private function normalizeBestPracticeSuggestion(
        Collection $claims,
        Collection $pagesById,
        Collection $sectionMapsByVersionId,
        ?User $user,
        bool $includeTechnical,
        ?string $returnUrl,
    ): array {
        $orderedClaims = $claims->sort(fn (EnterpriseWikiClaim $a, EnterpriseWikiClaim $b): int => [$a->position_order, $a->id] <=> [$b->position_order, $b->id]
        )->values();
        $primary = $orderedClaims->first();

        $page = $pagesById->get($primary->enterprise_wiki_page_id);
        $editedBeforeApproval = (bool) data_get($primary->review_metadata, 'edited_before_approval', false);
        $anyPending = $claims->contains(fn (EnterpriseWikiClaim $c): bool => $c->isPending());

        $status = match (true) {
            $anyPending => 'pending_review',
            $primary->isApproved() && $editedBeforeApproval => 'approved_edited',
            $primary->isApproved() => 'approved',
            default => 'rejected',
        };

        $isPending = $status === 'pending_review';
        $canHandle = $isPending && $user instanceof User
            && $this->documentOwnerApprovalService->canHandleClaim($primary, $user, $primary->version);

        $url = $this->pageUrl($page, $primary->id, $returnUrl);
        $action = match (true) {
            $url === null => null,
            $isPending => $canHandle ? 'open_and_review' : 'view_page',
            default => 'view_page',
        };

        $sectionMap = $sectionMapsByVersionId->get($primary->enterprise_wiki_page_version_id, []);
        $headingText = $sectionMap[trim((string) $primary->content_block_key)]['heading_text'] ?? null;
        $sectionText = $orderedClaims->pluck('claim_text')->implode(' ');
        $blockKeys = $orderedClaims
            ->map(fn (EnterpriseWikiClaim $c): string => trim((string) $c->content_block_key))
            ->filter(fn (string $key): bool => $key !== '')
            ->unique()
            ->values()
            ->all();

        $item = [
            'id' => 'best-practice-'.$primary->id,
            'title' => $headingText ?? $primary->claim_text,
            'section_text' => $sectionText,
            'explanation' => (string) ($primary->review_reason ?? __('procynia.wiki.runs_findings_best_practice_default_reason')),
            'category' => 'best_practice_suggestion',
            'category_label' => __('procynia.wiki.runs_findings_best_practice_category'),
            'severity' => 'suggestion',
            'severity_label' => $this->severityLabel('suggestion'),
            'status' => $status,
            'status_label' => __('procynia.wiki.runs_findings_status_'.$status),
            'blocks_run' => false,
            'blocks_page' => false,
            'scope' => $page !== null ? 'page' : 'run',
            'page_id' => $page?->id,
            'page_title' => $page?->title,
            'page_version_id' => $primary->enterprise_wiki_page_version_id,
            'page_version_number' => $primary->version?->version_number,
            'claim_id' => $primary->id,
            'claim_count' => $claims->count(),
            'block_keys' => $blockKeys,
            'created_at' => $primary->created_at?->toIso8601String(),
            'resolved_at' => $primary->approved_at?->toIso8601String(),
            'decided_by_name' => $primary->approvedBy?->name,
            'url' => $url,
            'can_handle' => $canHandle,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
        ];

        if ($includeTechnical) {
            $item['technical'] = [
                'source' => 'best_practice_suggestion',
                'code' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'raw_severity' => null,
                'raw_status' => $primary->approval_status,
                'claim_ids' => $claims->pluck('id')->all(),
            ];
        }

        return $item;
    }

    /**
     * Grouping key: every best-practice claim in the same faglig seksjon (heading block plus its
     * following best-practice blocks — EnterpriseWikiBestPracticeSectionService) is one case, not
     * one per paragraph/content_block_key (superseding the narrower v0.7 rule #4 grouping). A
     * claim whose block cannot be resolved to a section (e.g. legacy content_blocks_json with no
     * detectable heading structure at all) falls back to its raw content_block_key, and one with
     * no stable block anchor at all falls back to its own claim id — either way it is never
     * incorrectly merged with an unrelated claim.
     *
     * @param  Collection<int, array<string, array{section_key: string, heading_text: ?string, heading_block_key: ?string}>>  $sectionMapsByVersionId
     */
    private function additionGroupKey(EnterpriseWikiClaim $claim, Collection $sectionMapsByVersionId): string
    {
        $blockKey = trim((string) $claim->content_block_key);

        if ($blockKey === '') {
            return 'claim-'.$claim->id;
        }

        $sectionMap = $sectionMapsByVersionId->get($claim->enterprise_wiki_page_version_id, []);
        $sectionKey = $sectionMap[$blockKey]['section_key'] ?? null;

        return $sectionKey ?? $claim->enterprise_wiki_page_version_id.'|'.$blockKey;
    }

    private function pageUrl(
        ?EnterpriseWikiPage $page,
        ?int $claimId,
        ?string $returnUrl = null,
        ?int $structureFindingId = null,
    ): ?string {
        if ($page === null) {
            return null;
        }

        $parameters = ['slug' => $page->slug];

        if ($claimId !== null) {
            $parameters['claim_id'] = $claimId;
        } elseif ($structureFindingId !== null) {
            $parameters['finding_id'] = $structureFindingId;
        }

        if ($returnUrl !== null) {
            $parameters['back_url'] = $returnUrl;
        }

        return route('app.wiki.show', $parameters);
    }

    private function severityFor(string $rawSeverity): string
    {
        return match ($rawSeverity) {
            EnterpriseWikiLintFinding::SEVERITY_ERROR => 'error',
            EnterpriseWikiLintFinding::SEVERITY_WARNING => 'warning',
            default => 'info',
        };
    }

    /**
     * Reuses the Quality tab's existing severity vocabulary (lint_severity_error/warning/info)
     * rather than inventing a parallel one — 'critical' (claim-integrity defects) and
     * 'suggestion' (best-practice) are the two genuinely new tiers, since no lint finding can
     * reach them. 'suggestion' is deliberately neutral wording — never "low severity" — a
     * best-practice recommendation is not a diminished defect, it is not a defect at all (Del 1).
     */
    private function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => __('procynia.wiki.runs_findings_severity_critical'),
            'suggestion' => __('procynia.wiki.runs_findings_severity_suggestion'),
            'error' => __('procynia.wiki.lint_severity_error'),
            'warning' => __('procynia.wiki.lint_severity_warning'),
            default => __('procynia.wiki.lint_severity_info'),
        };
    }

    private function actionLabel(?string $action): ?string
    {
        return match ($action) {
            'open_and_handle' => __('procynia.wiki.runs_findings_action_open_and_handle'),
            'open_and_review' => __('procynia.wiki.runs_findings_action_open_and_review'),
            'view_source' => __('procynia.wiki.runs_findings_action_view_source'),
            'view_page' => __('procynia.wiki.runs_findings_action_open'),
            default => null,
        };
    }

    /**
     * @return array{label: string, description: string}
     */
    private function qualityCheckCopy(string $code): array
    {
        $label = __('procynia.wiki.quality_checks.'.$code.'.label');
        $description = __('procynia.wiki.quality_checks.'.$code.'.description');

        $unresolvedLabel = 'procynia.wiki.quality_checks.'.$code.'.label';
        $unresolvedDescription = 'procynia.wiki.quality_checks.'.$code.'.description';

        return [
            'label' => $label === $unresolvedLabel ? __('procynia.wiki.quality_check_unknown_label').': '.$code : $label,
            'description' => $description === $unresolvedDescription ? __('procynia.wiki.quality_check_unknown_description').' ('.$code.')' : $description,
        ];
    }

    /**
     * Sort order (Del 9): open blocking first, then open high-severity, then other open, then
     * in-progress/requires-action-but-not-blocking, then resolved/accepted, then
     * informative/historical — newest first within the same group.
     */
    private function sortComparator(): callable
    {
        $rank = [
            'requires_action' => 0,
            'flagged_for_review' => 1,
            'open_for_qa_review' => 1,
            'open' => 1,
            'pending_review' => 1,
            'resolved' => 2,
            'approved' => 2,
            'approved_edited' => 2,
            'rejected' => 2,
            'informative' => 3,
            'superseded' => 4,
        ];

        return function (array $a, array $b) use ($rank): int {
            if ($a['blocks_run'] !== $b['blocks_run']) {
                return $a['blocks_run'] ? -1 : 1;
            }

            $severityRank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'suggestion' => 3, 'info' => 4];
            $sa = $severityRank[$a['severity']] ?? 5;
            $sb = $severityRank[$b['severity']] ?? 5;

            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $ra = $rank[$a['status']] ?? 5;
            $rb = $rank[$b['status']] ?? 5;

            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function buildSummary(array $items, EnterpriseWikiIngestRun $run): array
    {
        $total = count($items);
        $openBlocking = 0;
        $openQaReview = 0;
        $openNonBlocking = 0;
        $resolved = 0;
        $informative = 0;
        $superseded = 0;
        $bestPracticePending = 0;

        // 'requires_action' is the ONLY status that gates the run (a genuinely blocking, open
        // lint finding — see normalizeLintFinding()/EnterpriseWikiPostIngestQaService::
        // findCriticalDefects()). 'flagged_for_review'/'open_for_qa_review' (claim QA signals,
        // see normalizeClaimDefect()) are voluntary QA opportunities, never a gate (v0.10,
        // docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10") — they get their own,
        // clearly non-blocking bucket. A decided best-practice suggestion (approved/
        // approved_edited/rejected) counts as $resolved — it is closed, historical, and no longer
        // needs a decision — while a pending one gets its own bucket so the UI can visibly
        // separate "waiting for a human suggestion decision" from "an actual quality defect"
        // (Del 1).
        foreach ($items as $item) {
            match ($item['status']) {
                'requires_action' => $openBlocking++,
                'flagged_for_review', 'open_for_qa_review' => $openQaReview++,
                'open' => $openNonBlocking++,
                'resolved', 'approved', 'approved_edited', 'rejected' => $resolved++,
                'informative' => $informative++,
                'superseded' => $superseded++,
                'pending_review' => $bestPracticePending++,
                default => null,
            };
        }

        return [
            'total' => $total,
            'open_blocking' => $openBlocking,
            'open_qa_review' => $openQaReview,
            'open_non_blocking' => $openNonBlocking,
            'resolved' => $resolved,
            'informative' => $informative,
            'superseded' => $superseded,
            'best_practice_pending' => $bestPracticePending,
            'explanation' => $this->buildExplanation($run, $total, $openBlocking, $openQaReview),
        ];
    }

    /**
     * Del 5: never trust qa_status blindly — compare it against the ACTUAL open-blocking count
     * just computed, and say so plainly when they disagree instead of fabricating a reason. Never
     * triggers a reconciliation write from this read-only endpoint.
     *
     * v0.10: open claim QA signals ($openQaReviewCount) never imply the run could not complete —
     * that language is reserved for $openBlockingCount, which now only reflects a genuinely
     * blocking (open, error-severity) lint finding.
     */
    private function buildExplanation(EnterpriseWikiIngestRun $run, int $total, int $openBlockingCount, int $openQaReviewCount): string
    {
        if ($openBlockingCount > 0) {
            if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
                return trans_choice('procynia.wiki.runs_findings_explanation_inconsistent_passed', $openBlockingCount, ['count' => $openBlockingCount]);
            }

            return trans_choice('procynia.wiki.runs_findings_explanation_has_blocking', $openBlockingCount, ['count' => $openBlockingCount]);
        }

        if ($openQaReviewCount > 0) {
            return trans_choice('procynia.wiki.runs_findings_explanation_qa_review_open', $openQaReviewCount, ['count' => $openQaReviewCount]);
        }

        if (in_array($run->qa_status, [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ], true)) {
            return __('procynia.wiki.runs_findings_explanation_passed_no_blocking', ['count' => $total]);
        }

        if (in_array($run->qa_status, [
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ], true)) {
            return $this->buildEscalatedOrFailedExplanation($run);
        }

        return __('procynia.wiki.runs_findings_explanation_qa_pending');
    }

    /**
     * Distinguishes a genuinely stale status (no open blocking findings, and — critically — no
     * actual incomplete technical step either, e.g. verification is really done and only the
     * status field lags behind) from a run that is still blocked by a real, non-finding-based
     * technical gate: EnterpriseWikiPostIngestQaService::findIncompleteSteps()'s
     * `verification_incomplete` (see the Wiki run-585 incident — the previous, single generic
     * "needs resync" message claimed no blockers remained even while 14 claims were still
     * unverified, because it only ever checked the lint findings table, never incomplete_steps).
     *
     * Uses EnterpriseWikiEscalatedRunRecoveryService::evaluate() (read-only, no lock, no
     * mutation) to also say whether the system will attempt to resume this automatically, or
     * whether it requires new/manual processing — never claims automatic recovery will happen
     * when it actually won't (e.g. a non-transient error, or a genuinely stale/already-terminal
     * status that recovery correctly declines to touch).
     */
    private function buildEscalatedOrFailedExplanation(EnterpriseWikiIngestRun $run): string
    {
        $recovery = $this->recoveryService->evaluate($run->id);

        if (! in_array('verification_incomplete', $recovery->incompleteSteps, true)) {
            return __('procynia.wiki.runs_findings_explanation_needs_resync');
        }

        return $recovery->outcome === EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED
            ? __('procynia.wiki.runs_findings_explanation_verification_incomplete_auto')
            : __('procynia.wiki.runs_findings_explanation_verification_incomplete_manual');
    }
}
