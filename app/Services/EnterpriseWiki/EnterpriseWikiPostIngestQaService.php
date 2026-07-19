<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-ingest QA for applied Enterprise Wiki runs — a minimal, deterministic end check.
 *
 * QA does not call OpenAI, does not generate or rewrite any page content, and does not
 * re-analyze content. It only checks facts that are already recorded by the pipeline:
 *
 *   1. Every page the run was supposed to produce has a finished (current, non-empty) version.
 *   2. Every continuation step (page generation, claim extraction, claim verification) is
 *      recorded complete for every page/claim belonging to this run.
 *   3. No active extraction/verification lease is held by another worker, and no checkpoint is
 *      left half-finished.
 *   4. No open critical lint finding (error severity, or a broken wikilink specifically) is
 *      registered for this run.
 *
 * Verdict:
 *   - everything above holds                       → passed
 *   - a concrete critical defect is found (1 or 4)  → failed
 *   - anything cannot be safely determined (2 or 3, or the run has no pages at all,
 *     or QA itself hits an unexpected technical error) → escalated
 *
 * A technical failure while running QA (an unexpected exception, a snapshot write failure) is
 * never recorded as qa_status=failed — that status is reserved for a concrete, understood
 * content/structure defect. Technical failures escalate instead, so a human can investigate
 * without the run being wrongly flagged as having a real content problem.
 *
 * evaluate() is pure and read-only — it claims nothing, writes nothing, and never calls lint
 * itself (it reads whatever EnterpriseWikiLintFinding rows the continuation pipeline's own lint
 * stage already wrote). This lets a caller (e.g. wiki:recover-document-flow --dry-run) predict
 * the verdict without any side effect, and lets the real run (executeQa()) reuse the exact same
 * logic for the value it actually persists.
 *
 * The status transition from null/pending to 'running' is done via an atomic DB update to
 * prevent parallel runs for the same run ID.
 */
class EnterpriseWikiPostIngestQaService
{
    /**
     * Main-flow `status` values that mean a run is still actively owned by
     * RunEnterpriseWikiDocumentFlow / ContinueEnterpriseWikiDocumentFlowAfterPages — i.e.
     * every non-terminal status except `qa` (the ordinary flow's own QA stage, which must
     * remain claimable by runForRun()) and `decision_only` (a distinct, valid run type that
     * never transitions through this state machine at all — see scopeToRunsReadyForQa()).
     *
     * Scheduled/explicit-retry discovery (findPendingRuns()/findRetryableRuns()) must never
     * return a run in one of these statuses: the ordinary document flow owns the first
     * post-ingest QA attempt for it, and letting the scheduler claim it first is exactly the
     * run-24 race (scheduler sets qa_status=passed while the continuation job is still mid
     * verification_linking, so the continuation job's own claim then fails).
     */
    private const ACTIVE_DOCUMENT_FLOW_STATUSES = [
        EnterpriseWikiIngestRun::STATUS_QUEUED,
        EnterpriseWikiIngestRun::STATUS_RUNNING,
        EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
        EnterpriseWikiIngestRun::STATUS_APPLYING,
        EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
        EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
    ];

    public function __construct(
        private readonly EnterpriseWikiCoverageService $coverageService,
        private readonly EnterpriseWikiQaSnapshotService $snapshotService,
    ) {}

    /**
     * Run post-ingest QA for a single applied run.
     *
     * Returns the QA result array, or null if the run was skipped (already
     * running, or failed/escalated/passed without $retry).
     *
     * @param  bool  $retry  When true, also claims runs in 'failed', 'escalated', or 'passed'
     *                       status — an explicit operator decision to re-evaluate, e.g. after a
     *                       QA-gating fix means a previously recorded 'passed' verdict is now
     *                       known to be wrong for content already generated (see the run-34 claim-
     *                       integrity gating fix). findPendingRuns()/findRetryableRuns() never
     *                       include 'passed', so this never causes a scheduled/bulk sweep to
     *                       reopen completed work — only a direct, single runForRun(..., retry:
     *                       true) call can.
     *
     * @throws \InvalidArgumentException if the run is not applied
     * @throws \Throwable on unexpected errors (run is escalated before re-throw)
     */
    public function runForRun(EnterpriseWikiIngestRun $run, bool $retry = false): ?array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can be QA-checked."
            );
        }

        // Atomic transition: set running only if eligible.
        // Without retry: null, pending, repair_required (stuck transient state).
        // With retry: also failed, escalated, passed (explicit operator decision to retry).
        $eligibleStatuses = [
            EnterpriseWikiIngestRun::QA_STATUS_PENDING,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ];

        if ($retry) {
            $eligibleStatuses[] = EnterpriseWikiIngestRun::QA_STATUS_FAILED;
            $eligibleStatuses[] = EnterpriseWikiIngestRun::QA_STATUS_ESCALATED;
            $eligibleStatuses[] = EnterpriseWikiIngestRun::QA_STATUS_PASSED;
        }

        $claimed = $this->scopeToRunsReadyForQa(
            DB::table('enterprise_wiki_ingest_runs')
                ->where('id', $run->id)
                ->where(function ($q) use ($eligibleStatuses): void {
                    $q->whereNull('qa_status')
                        ->orWhereIn('qa_status', $eligibleStatuses);
                })
        )->update([
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_RUNNING,
            'qa_started_at' => now(),
            'qa_attempt_count' => DB::raw('COALESCE(qa_attempt_count, 0) + 1'),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            // Already running or already passed — skip silently.
            return null;
        }

        $fresh = $run->fresh();

        try {
            return $this->executeQa($fresh);
        } catch (\Throwable $e) {
            // A technical failure here (e.g. an unexpected DB error) is not itself a verdict
            // about the run's content — never record it as qa_status=failed. Escalate instead,
            // so a human can investigate without the run being wrongly flagged as having a
            // real content defect.
            $fresh->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'qa_completed_at' => now(),
                'qa_last_error' => $e->getMessage(),
            ]);

            Log::error('[WIKI_QA] QA execution failed with an unexpected technical error — escalated, not failed.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            // Snapshot the escalated attempt. Errors here must not suppress the original exception.
            try {
                $this->snapshotService->capture($fresh, []);
            } catch (\Throwable $snapshotException) {
                Log::error('[WIKI_QA_SNAPSHOT] Failed to create snapshot for escalated run', [
                    'run_id' => $run->id,
                    'error' => $snapshotException->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Pure, read-only deterministic evaluation of a run's current artifacts — claims nothing,
     * writes nothing, calls no AI. Used internally by executeQa() for the value it persists,
     * and externally (e.g. wiki:recover-document-flow --dry-run) to predict the verdict without
     * any side effect.
     *
     * @return array{
     *     verdict: string,
     *     reason: ?string,
     *     incomplete_steps: list<string>,
     *     critical_defects: list<string>,
     *     claim_integrity_defects: list<string>,
     *     checks: array{article_exists: bool, summary_exists: bool, article_has_content: bool, summary_has_content: bool},
     * }
     */
    public function evaluate(EnterpriseWikiIngestRun $run): array
    {
        $checks = $this->runChecks($run);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->get();

        if ($pivotRows->isEmpty()) {
            return [
                'verdict' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'reason' => 'Run has no applied pages — cannot determine a QA result.',
                'incomplete_steps' => [],
                'critical_defects' => [],
                'claim_integrity_defects' => [],
                'checks' => $checks,
            ];
        }

        $pageIds = $pivotRows->pluck('enterprise_wiki_page_id');

        $incompleteSteps = $this->findIncompleteSteps($pivotRows, $pageIds);

        if ($incompleteSteps !== []) {
            return [
                'verdict' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'reason' => 'Run has unfinished continuation step(s) or an active reservation: '.implode(', ', $incompleteSteps).'.',
                'incomplete_steps' => $incompleteSteps,
                'critical_defects' => [],
                'claim_integrity_defects' => [],
                'checks' => $checks,
            ];
        }

        $criticalDefects = $this->findCriticalDefects($run, $pageIds, $checks);

        if ($criticalDefects !== []) {
            return [
                'verdict' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'reason' => 'Critical defect(s) found: '.implode(', ', $criticalDefects).'.',
                'incomplete_steps' => [],
                'critical_defects' => $criticalDefects,
                'claim_integrity_defects' => [],
                'checks' => $checks,
            ];
        }

        // Claims are guaranteed verified_at !== null at this point (findIncompleteSteps above
        // already escalated any run with a claim still pending verification), so every claim
        // has a final content_origin — this check distinguishes "finished and genuinely wrong"
        // claim content from the structural defects above.
        $claimIntegrityDefects = $this->findClaimIntegrityDefects($pageIds);

        if ($claimIntegrityDefects !== []) {
            return [
                'verdict' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
                'reason' => 'Unresolved claim-integrity defect(s) found: '.implode(', ', $claimIntegrityDefects).'.',
                'incomplete_steps' => [],
                'critical_defects' => [],
                'claim_integrity_defects' => $claimIntegrityDefects,
                'checks' => $checks,
            ];
        }

        return [
            'verdict' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'reason' => null,
            'incomplete_steps' => [],
            'critical_defects' => [],
            'claim_integrity_defects' => [],
            'checks' => $checks,
        ];
    }

    /**
     * Find applied runs eligible for scheduled QA polling (null, pending, repair_required).
     *
     * Does NOT include 'failed' or 'escalated' — those require an explicit --retry decision.
     * Does not include 'running' (in progress) or 'passed' (complete). Does not include a run
     * still owned by the ordinary document flow (see ACTIVE_DOCUMENT_FLOW_STATUSES).
     */
    public function findPendingRuns(): Collection
    {
        return $this->findEligibleRuns([
            EnterpriseWikiIngestRun::QA_STATUS_PENDING,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ], 'scheduler');
    }

    /**
     * Find applied runs eligible for explicit retry (null, pending, repair_required, failed, escalated).
     *
     * Use only when operator has explicitly requested a retry via --retry flag. Does not
     * include a run still owned by the ordinary document flow (see
     * ACTIVE_DOCUMENT_FLOW_STATUSES) — an explicit retry must never race a run's own
     * first-attempt continuation any more than scheduled polling may.
     */
    public function findRetryableRuns(): Collection
    {
        return $this->findEligibleRuns([
            EnterpriseWikiIngestRun::QA_STATUS_PENDING,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ], 'scheduler');
    }

    /**
     * Shared discovery logic for findPendingRuns()/findRetryableRuns(): applied runs whose
     * qa_status is one of $qaStatuses (or null), excluding any run still actively owned by the
     * ordinary document flow. Logs once per call (not per row) when at least one run was
     * excluded for being active, so a busy scheduler tick doesn't produce noisy per-row logs.
     */
    private function findEligibleRuns(array $qaStatuses, string $caller): Collection
    {
        $excludedActiveIds = $this->baseEligibleQuery($qaStatuses)
            ->whereIn('status', self::ACTIVE_DOCUMENT_FLOW_STATUSES)
            ->pluck('id');

        if ($excludedActiveIds->isNotEmpty()) {
            Log::info('[WIKI_POST_INGEST_QA] Active document run excluded from scheduled QA.', [
                'excluded_count' => $excludedActiveIds->count(),
                'run_ids' => $excludedActiveIds->take(50)->all(),
                'caller' => $caller,
            ]);
        }

        return $this->scopeToRunsReadyForQa(
            $this->baseEligibleQuery($qaStatuses)->whereNotIn('status', self::ACTIVE_DOCUMENT_FLOW_STATUSES)
        )
            ->orderBy('id')
            ->get();
    }

    private function baseEligibleQuery(array $qaStatuses): Builder
    {
        return EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where(function ($q) use ($qaStatuses): void {
                $q->whereNull('qa_status')->orWhereIn('qa_status', $qaStatuses);
            });
    }

    /**
     * Excludes the one run state QA retry/maintenance must never touch: main `status` =
     * `failed` while `qa_status` is still null. That combination means QA never even
     * started — the failure happened earlier in the ordinary document flow (maintainer
     * decision, apply, page generation, wikilink validation, or materialization), and the run
     * must stay failed with its own original, concrete error. QA retry/deep repair must never
     * be used as a hidden way to complete a run whose page generation never finished.
     *
     * Any run whose `qa_status` is already non-null (QA has legitimately started or reached a
     * terminal status) remains eligible regardless of main `status` — this also correctly
     * leaves decision-only runs unaffected, since their main `status` never transitions away
     * from `decision_only` (that transition machinery lives only in
     * EnterpriseWikiDocumentFlowService, which decision-only runs never go through), yet they
     * still rely on qa_status-based QA retry/maintenance exactly as before.
     *
     * Works for both an Eloquent builder and a DB query builder — both expose the same
     * where()/orWhere()/whereNotNull() fluent methods used here.
     */
    private function scopeToRunsReadyForQa(mixed $query): mixed
    {
        return $query->where(function ($q): void {
            $q->where('status', '!=', EnterpriseWikiIngestRun::STATUS_FAILED)
                ->orWhereNotNull('qa_status');
        });
    }

    // =========================================================================
    // Internal QA execution
    // =========================================================================

    private function executeQa(EnterpriseWikiIngestRun $run): array
    {
        $evaluation = $this->evaluate($run);

        $result = [
            'checks' => $evaluation['checks'],
            'repair_attempted' => false,
            'repair_result' => null,
            'coverage_summary' => $this->computeCoverageSummary($run),
            'lint_summary' => $this->computeLintSummary($run),
            'open_lint_errors' => $this->hasOpenLintErrors($run),
            'semantic_qa' => null,
            'semantic_repair_attempted' => false,
            'semantic_repair_result' => null,
            'semantic_qa_post_repair' => null,
            'incomplete_steps' => $evaluation['incomplete_steps'],
            'critical_defects' => $evaluation['critical_defects'],
            'claim_integrity_defects' => $evaluation['claim_integrity_defects'],
        ];

        $run->update([
            'qa_status' => $evaluation['verdict'],
            'qa_completed_at' => now(),
            'qa_last_error' => $evaluation['reason'],
            'qa_result' => $result,
        ]);

        $this->captureSnapshot($run, $result);

        return $result;
    }

    /**
     * Create a QA snapshot (8G-6).
     *
     * On failure the run is escalated (not failed — a snapshot write failure is a technical
     * problem, not a content verdict) and qa_last_error is set so that the run never appears
     * as completed without a recorded snapshot. qa_result is preserved (already saved before
     * this is called).
     */
    private function captureSnapshot(EnterpriseWikiIngestRun $run, array $result): void
    {
        try {
            $this->snapshotService->capture($run, $result);
        } catch (\Throwable $e) {
            Log::error('[WIKI_QA_SNAPSHOT] Failed to create snapshot', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'qa_last_error' => '[SNAPSHOT] Snapshot creation failed: '.$e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Deterministic checks
    // =========================================================================

    /**
     * Anything not yet finished — active leases or checkpoints not yet set. A non-empty result
     * means the run cannot be safely judged yet, regardless of what its content looks like.
     *
     * @return list<string>
     */
    private function findIncompleteSteps(Collection $pivotRows, Collection $pageIds): array
    {
        $reasons = [];

        if ($pivotRows->contains(fn (EnterpriseWikiIngestRunPage $row) => $row->generation_status !== EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED)) {
            $reasons[] = 'page_generation_incomplete';
        }

        if ($pivotRows->contains(fn (EnterpriseWikiIngestRunPage $row) => $row->claims_claimed_at !== null)) {
            $reasons[] = 'extraction_lease_active';
        }

        if ($pivotRows->contains(fn (EnterpriseWikiIngestRunPage $row) => $row->claims_extracted_at === null)) {
            $reasons[] = 'extraction_incomplete';
        }

        $claimsQuery = EnterpriseWikiClaim::query()->whereIn('enterprise_wiki_page_id', $pageIds);

        if ((clone $claimsQuery)->whereNotNull('verification_claimed_at')->exists()) {
            $reasons[] = 'verification_lease_active';
        }

        if ((clone $claimsQuery)->whereNull('verified_at')->exists()) {
            $reasons[] = 'verification_incomplete';
        }

        return $reasons;
    }

    /**
     * Concrete, understood content/structure defects — not "not finished yet", but "finished
     * and genuinely wrong".
     *
     * @return list<string>
     */
    private function findCriticalDefects(EnterpriseWikiIngestRun $run, Collection $pageIds, array $checks): array
    {
        $defects = [];

        if (($checks['article_exists'] ?? false) === false
            || ($checks['summary_exists'] ?? false) === false
            || ($checks['article_has_content'] ?? false) === false
            || ($checks['summary_has_content'] ?? false) === false
        ) {
            $defects[] = 'missing_article_or_summary';
        }

        $pagesWithContent = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->pluck('enterprise_wiki_page_id');

        if ($pageIds->diff($pagesWithContent)->isNotEmpty()) {
            $defects[] = 'missing_or_empty_page_version';
        }

        $hasCriticalLint = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->blocking()
            ->exists();

        if ($hasCriticalLint) {
            $defects[] = 'critical_lint_findings_or_broken_links';
        }

        return $defects;
    }

    /**
     * Claim-integrity defects — content that is "finished and genuinely wrong" at the claim
     * level rather than at the page-structure level findCriticalDefects() checks above. Scoped
     * to claims tied to the run's pages' CURRENT page versions only: a claim left over on a
     * superseded version (e.g. after a controlled block revision) is historical record, not an
     * active defect.
     *
     * A claim recorded as content_origin=unsupported_generated_content or internal_error is, by
     * construction (EnterpriseWikiVerifyPageClaimsService::persist()/markInternalGenerationError()),
     * text the run's own AI-verification step already determined is not supported by the source
     * document, or an internal anchoring/versioning inconsistency — this text must not reach
     * Document Owner approval as ordinary, presumed-correct Wiki content. A content_origin of
     * source_based without a real EnterpriseWikiSourceReference row is the same underlying
     * problem seen from the other side: the claim is presented as document-backed but the
     * evidence that made it so is missing (e.g. wiped without a reverification pass).
     *
     * Legitimate best_practice suggestions are deliberately excluded — those wait for an
     * explicit human decision (approve/edit-and-approve/reject) via the ordinary claim review
     * flow and must not, on their own, block technical QA from passing.
     *
     * @return list<string>
     */
    private function findClaimIntegrityDefects(Collection $pageIds): array
    {
        $currentVersionIds = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id');

        if ($currentVersionIds->isEmpty()) {
            return [];
        }

        $defects = [];

        $hasInternalError = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR)
            ->exists();

        if ($hasInternalError) {
            $defects[] = 'active_internal_error_claims';
        }

        $hasUnsupported = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT)
            ->exists();

        if ($hasUnsupported) {
            $defects[] = 'active_unsupported_generated_content_claims';
        }

        $hasUnprovenSourceBased = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED)
            ->whereDoesntHave('sourceReferences')
            ->exists();

        if ($hasUnprovenSourceBased) {
            $defects[] = 'source_based_claims_missing_provenance';
        }

        return $defects;
    }

    /**
     * Article/summary existence + content check — the run's two mandatory pages. Feeds both
     * EnterpriseWikiQaSnapshot's technical_qa_passed/structural_qa_passed fields (historical
     * meaning, unchanged) and findCriticalDefects() (a missing/empty article or summary is
     * always a critical defect, in addition to the broader all-pages check there).
     */
    private function runChecks(EnterpriseWikiIngestRun $run): array
    {
        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $pagesByType = $pivotPageIds->isNotEmpty()
            ? EnterpriseWikiPage::query()
                ->whereIn('id', $pivotPageIds)
                ->get()
                ->groupBy('page_type')
            : collect();

        $articlePages = $pagesByType->get(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, collect());
        $summaryPages = $pagesByType->get(EnterpriseWikiPage::PAGE_TYPE_SUMMARY, collect());

        $articleExists = $articlePages->isNotEmpty();
        $summaryExists = $summaryPages->isNotEmpty();

        $articleHasContent = $articleExists
            && $this->anyPageHasCurrentContent($articlePages->pluck('id'));

        $summaryHasContent = $summaryExists
            && $this->anyPageHasCurrentContent($summaryPages->pluck('id'));

        return [
            'article_exists' => $articleExists,
            'summary_exists' => $summaryExists,
            'article_has_content' => $articleHasContent,
            'summary_has_content' => $summaryHasContent,
        ];
    }

    private function anyPageHasCurrentContent(Collection $pageIds): bool
    {
        if ($pageIds->isEmpty()) {
            return false;
        }

        return DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->exists();
    }

    private function hasOpenLintErrors(EnterpriseWikiIngestRun $run): bool
    {
        return EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)
            ->exists();
    }

    // =========================================================================
    // Supplementary metrics (read-only, informational — never gate the verdict)
    // =========================================================================

    private function computeCoverageSummary(EnterpriseWikiIngestRun $run): array
    {
        try {
            $coverage = $this->coverageService->computeForCustomer($run->customer_id);
            $sc = $coverage['source_coverage'] ?? [];
            $cc = $coverage['claim_coverage'] ?? [];
            $lint = $coverage['lint'] ?? [];

            return [
                'gap_count' => count($sc['gaps'] ?? []),
                'claim_coverage_pct' => $cc['claim_coverage_pct'] ?? null,
                'open_errors' => (int) ($lint['open_errors'] ?? 0),
                'open_warnings' => (int) ($lint['open_warnings'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Reads whatever lint findings already exist for this run — does not trigger a fresh lint
     * pass. The continuation pipeline's own lint stage (performAppliedRunLint) always runs
     * before QA in the ordinary flow, so findings are already current by the time QA reads
     * them; re-triggering lint from within QA would make QA an active step again rather than a
     * pure end check.
     */
    private function computeLintSummary(EnterpriseWikiIngestRun $run): array
    {
        $openFindings = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->get(['severity']);

        return [
            'errors' => $openFindings->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)->count(),
            'warnings' => $openFindings->where('severity', EnterpriseWikiLintFinding::SEVERITY_WARNING)->count(),
        ];
    }
}
