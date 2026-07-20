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
 *      consistent with this).
 *   2. "Claim integrity defects" — EnterpriseWikiClaim rows with content_origin
 *      internal_error/unsupported_generated_content on the run's pages' CURRENT versions. These
 *      are exactly what EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects() checks to
 *      decide qa_status=repair_required, but they are computed live and never persisted as a
 *      finding row — so today's lint-only "Funn" count silently excludes the very things that
 *      can block a run. A source_based claim missing its source reference is deliberately NOT
 *      duplicated here: EnterpriseWikiAppliedRunLintService already writes a real
 *      CODE_CLAIM_MISSING_SOURCE lint finding for that exact case (see
 *      resolveClaimMissingSourceFinding()/reopenClaimMissingSourceFindingIfStillMissing()), so
 *      folding it in again here would count the same underlying problem twice.
 *   3. "Best-practice suggestions" — EnterpriseWikiClaim rows with content_origin best_practice on
 *      the run's pages' CURRENT versions, in any approval_status. Unlike (2), this is
 *      deliberately NEVER blocking and NEVER "critical" — it is a legitimate, human-reviewable
 *      suggestion beyond the source document, not a defect. findClaimIntegrityDefects() already
 *      excludes best_practice from QA gating entirely (see its own doc comment); this class must
 *      never contradict that by treating it as a quality error.
 *
 * Blocking is a SUGGESTION the system computes (EnterpriseWikiClaimFindingExplainer), never a
 * silent redecision — an authorized user's recorded override (EnterpriseWikiClaim::blocking_override)
 * always wins when present. EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects() applies
 * the exact same effective-blocking rule, so this panel and the QA gate can never disagree about
 * whether a given claim is actually holding the run back. Lint findings and best-practice
 * suggestions are unaffected by this — the former's isBlocking() is unconditional by design, and
 * the latter never blocks at all.
 */
class EnterpriseWikiRunFindingsService
{
    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
    ) {}

    /**
     * @return array{findings: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function buildForRun(EnterpriseWikiIngestRun $run, ?User $user, bool $includeTechnical): array
    {
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
            $items[] = $this->normalizeLintFinding($finding, $pagesById, $currentVersionIdByPageId, $user, $includeTechnical);
        }

        foreach ($claimDefects as $claim) {
            $items[] = $this->normalizeClaimDefect($claim, $pagesById, $user, $includeTechnical);
        }

        foreach ($bestPracticeSuggestions as $claim) {
            $items[] = $this->normalizeBestPracticeSuggestion($claim, $pagesById, $user, $includeTechnical);
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

        $url = $this->pageUrl($page, $claim?->id);
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
     * scopes $claim to the page's CURRENT version only (see buildForRun()), matching
     * EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()'s own scoping.
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
     * Severity and blocking are genuinely separate, and blocking itself is genuinely split into
     * the system's suggestion versus the user's actual decision
     * (EnterpriseWikiClaimFindingExplainer::blockingState()) — this item never shows a bare
     * "blocking" boolean as if it were a settled fact before an authorized user has actually
     * decided (CLAUDE.md: "Systemforslag er ikke brukerbeslutning"). `blocks_run`/`blocks_page`
     * remain the exact same gate-level value EnterpriseWikiPostIngestQaService::
     * findClaimIntegrityDefects() uses, so the QA gate and this panel can never disagree about
     * whether a given claim is actually holding the run back — but the UI must read
     * `system_recommends_blocking` and `user_decision` instead of `blocks_run` to decide what
     * text to show.
     *
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     */
    private function normalizeClaimDefect(
        EnterpriseWikiClaim $claim,
        Collection $pagesById,
        ?User $user,
        bool $includeTechnical,
    ): array {
        $page = $pagesById->get($claim->enterprise_wiki_page_id);
        $explanation = $this->claimFindingExplainer->explain($claim);
        $blockingState = $this->claimFindingExplainer->blockingState($claim);
        $severity = match ($explanation['category']) {
            EnterpriseWikiClaimFindingExplainer::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM => 'critical',
            default => 'warning',
        };

        $status = match (true) {
            $blockingState['user_decision'] === EnterpriseWikiClaimFindingExplainer::USER_DECISION_BLOCKING => 'user_blocking',
            $blockingState['requires_decision'] => 'requires_decision',
            default => 'open',
        };

        $canHandleClaim = $user instanceof User
            && $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $claim->version);

        $url = $this->pageUrl($page, $claim->id);
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
            'blocks_run' => $blockingState['blocks_gate'],
            'blocks_page' => $blockingState['blocks_gate'] && $page !== null,
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
            'created_at' => $claim->created_at?->toIso8601String(),
            'resolved_at' => null,
            'url' => $url,
            'can_handle' => $canHandleClaim,
            'action' => $actionKey,
            'action_label' => $this->actionLabel($actionKey),
        ];

        if ($includeTechnical) {
            $item['technical'] = [
                'source' => 'claim_integrity',
                'code' => $claim->content_origin,
                'generation_issue' => $claim->generation_issue,
                'raw_severity' => null,
                'raw_status' => null,
            ];
        }

        return $item;
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
     * "Åpne og vurder" link uses the exact same ?claim_id= deep link as everything else on this
     * panel, but WikiController::show() resolves it into a validated review_reference that scrolls
     * to and highlights the actual suggested text block, not just the top of the page.
     *
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     */
    private function normalizeBestPracticeSuggestion(
        EnterpriseWikiClaim $claim,
        Collection $pagesById,
        ?User $user,
        bool $includeTechnical,
    ): array {
        $page = $pagesById->get($claim->enterprise_wiki_page_id);
        $editedBeforeApproval = (bool) data_get($claim->review_metadata, 'edited_before_approval', false);

        $status = match (true) {
            $claim->isPending() => 'pending_review',
            $claim->isApproved() && $editedBeforeApproval => 'approved_edited',
            $claim->isApproved() => 'approved',
            default => 'rejected',
        };

        $isPending = $status === 'pending_review';
        $canHandle = $isPending && $user instanceof User
            && $this->documentOwnerApprovalService->canHandleClaim($claim, $user, $claim->version);

        $url = $this->pageUrl($page, $claim->id);
        $action = match (true) {
            $url === null => null,
            $isPending => $canHandle ? 'open_and_review' : 'view_page',
            default => 'view_page',
        };

        $item = [
            'id' => 'best-practice-'.$claim->id,
            'title' => $claim->claim_text,
            'explanation' => (string) ($claim->review_reason ?? __('procynia.wiki.runs_findings_best_practice_default_reason')),
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
            'page_version_id' => $claim->enterprise_wiki_page_version_id,
            'page_version_number' => $claim->version?->version_number,
            'claim_id' => $claim->id,
            'created_at' => $claim->created_at?->toIso8601String(),
            'resolved_at' => $claim->approved_at?->toIso8601String(),
            'decided_by_name' => $claim->approvedBy?->name,
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
                'raw_status' => $claim->approval_status,
            ];
        }

        return $item;
    }

    private function pageUrl(?EnterpriseWikiPage $page, ?int $claimId): ?string
    {
        if ($page === null) {
            return null;
        }

        $url = route('app.wiki.show', ['slug' => $page->slug]);

        return $claimId !== null ? $url.'?claim_id='.$claimId : $url;
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
            'user_blocking' => 0,
            'requires_decision' => 0,
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
        $openNonBlocking = 0;
        $resolved = 0;
        $informative = 0;
        $superseded = 0;
        $bestPracticePending = 0;

        // 'requires_action' (lint) / 'user_blocking' / 'requires_decision' (claim defects) are
        // the three statuses that gate the run (see normalizeLintFinding()/normalizeClaimDefect()),
        // so these six buckets are mutually exclusive and always sum to $total — that is the
        // invariant the "Funn" count in the main table must also honor. A decided best-practice
        // suggestion (approved/approved_edited/rejected) counts as $resolved — it is closed,
        // historical, and no longer needs a decision — while a pending one gets its own bucket so
        // the UI can visibly separate "waiting for a human suggestion decision" from "an actual
        // quality defect" (Del 1). 'requires_decision' is still counted as $openBlocking here —
        // an unhandled decision need still holds up final approval — but the UI must show it as
        // "awaiting decision", never as an already-decided block (CLAUDE.md).
        foreach ($items as $item) {
            match ($item['status']) {
                'requires_action', 'user_blocking', 'requires_decision' => $openBlocking++,
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
            'open_non_blocking' => $openNonBlocking,
            'resolved' => $resolved,
            'informative' => $informative,
            'superseded' => $superseded,
            'best_practice_pending' => $bestPracticePending,
            'explanation' => $this->buildExplanation($run, $total, $openBlocking),
        ];
    }

    /**
     * Del 5: never trust qa_status blindly — compare it against the ACTUAL open-blocking count
     * just computed, and say so plainly when they disagree instead of fabricating a reason. Never
     * triggers a reconciliation write from this read-only endpoint.
     */
    private function buildExplanation(EnterpriseWikiIngestRun $run, int $total, int $openBlockingCount): string
    {
        if ($openBlockingCount > 0) {
            if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
                return __('procynia.wiki.runs_findings_explanation_inconsistent_passed');
            }

            return trans_choice('procynia.wiki.runs_findings_explanation_has_blocking', $openBlockingCount, ['count' => $openBlockingCount]);
        }

        if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_PASSED) {
            return __('procynia.wiki.runs_findings_explanation_passed_no_blocking', ['count' => $total]);
        }

        if (in_array($run->qa_status, [
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ], true)) {
            return __('procynia.wiki.runs_findings_explanation_needs_resync');
        }

        return __('procynia.wiki.runs_findings_explanation_qa_pending');
    }
}
