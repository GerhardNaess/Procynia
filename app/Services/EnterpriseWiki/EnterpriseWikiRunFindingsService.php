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
 *
 * "Blocking" is never redecided here — every item's blocking flag is either
 * EnterpriseWikiLintFinding::isBlocking() (open lint findings) or the fixed fact that an active
 * claim-integrity defect always keeps qa_status below "passed" (claim defects) — the exact
 * predicates EnterpriseWikiPostIngestQaService already gates on.
 */
class EnterpriseWikiRunFindingsService
{
    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
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
                ->with('version')
                ->get();

        $items = [];

        foreach ($lintFindings as $finding) {
            $items[] = $this->normalizeLintFinding($finding, $pagesById, $currentVersionIdByPageId, $user, $includeTechnical);
        }

        foreach ($claimDefects as $claim) {
            $items[] = $this->normalizeClaimDefect($claim, $pagesById, $includeTechnical);
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
     * @param  Collection<int, EnterpriseWikiPage>  $pagesById
     */
    private function normalizeClaimDefect(
        EnterpriseWikiClaim $claim,
        Collection $pagesById,
        bool $includeTechnical,
    ): array {
        $page = $pagesById->get($claim->enterprise_wiki_page_id);
        $category = $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR
            ? 'internal_generation_error'
            : 'unsupported_generated_content';
        $copy = $this->qualityCheckCopy($category);
        $url = $this->pageUrl($page, $claim->id);

        $action = $url !== null ? 'view_page' : null;

        $item = [
            'id' => 'claim-defect-'.$claim->id,
            'title' => $copy['label'],
            'explanation' => $copy['description'],
            'category' => $category,
            'severity' => 'critical',
            'severity_label' => $this->severityLabel('critical'),
            'status' => 'requires_action',
            'status_label' => __('procynia.wiki.runs_findings_status_requires_action'),
            'blocks_run' => true,
            'blocks_page' => true,
            'scope' => $page !== null ? 'page' : 'run',
            'page_id' => $page?->id,
            'page_title' => $page?->title,
            'page_version_id' => $claim->enterprise_wiki_page_version_id,
            'page_version_number' => $claim->version?->version_number,
            'claim_id' => $claim->id,
            'created_at' => $claim->created_at?->toIso8601String(),
            'resolved_at' => null,
            'url' => $url,
            // Neither content_origin has a manual approve/reject workflow (unlike best_practice) —
            // this is always a technical regeneration/repair concern, never a task an ordinary
            // Document Owner can action from this panel (Del 10).
            'can_handle' => false,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
        ];

        if ($includeTechnical) {
            $item['technical'] = [
                'source' => 'claim_integrity',
                'code' => $claim->content_origin,
                'raw_severity' => null,
                'raw_status' => null,
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
     * rather than inventing a parallel one — 'critical' (claim-integrity defects only) is the one
     * genuinely new tier, since none of today's lint findings can reach it.
     */
    private function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => __('procynia.wiki.runs_findings_severity_critical'),
            'error' => __('procynia.wiki.lint_severity_error'),
            'warning' => __('procynia.wiki.lint_severity_warning'),
            default => __('procynia.wiki.lint_severity_info'),
        };
    }

    private function actionLabel(?string $action): ?string
    {
        return match ($action) {
            'open_and_handle' => __('procynia.wiki.runs_findings_action_open_and_handle'),
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
            'open' => 1,
            'resolved' => 2,
            'informative' => 3,
            'superseded' => 4,
        ];

        return function (array $a, array $b) use ($rank): int {
            if ($a['blocks_run'] !== $b['blocks_run']) {
                return $a['blocks_run'] ? -1 : 1;
            }

            $severityRank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
            $sa = $severityRank[$a['severity']] ?? 4;
            $sb = $severityRank[$b['severity']] ?? 4;

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

        // 'requires_action' is only ever assigned to a blocking item (see normalizeLintFinding()/
        // normalizeClaimDefect()), so these five buckets are mutually exclusive and always sum to
        // $total — that is the invariant the "Funn" count in the main table must also honor.
        foreach ($items as $item) {
            match ($item['status']) {
                'requires_action' => $openBlocking++,
                'open' => $openNonBlocking++,
                'resolved' => $resolved++,
                'informative' => $informative++,
                'superseded' => $superseded++,
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
