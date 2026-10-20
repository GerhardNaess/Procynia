<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
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
 * **v0.10 binding product strategy (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"):
 * claims and their QA review are a voluntary, non-blocking quality loop, never a completion
 * gate.** Open claim-level QA signals (findOpenClaimQaSignals()) are therefore never a reason
 * this method returns anything other than `passed` — they are computed and reported purely as
 * informational data (`claim_qa_signals` in the result array) for the voluntary QA workflow, the
 * same way `coverage_summary`/`lint_summary` below are informational and never gate the verdict.
 * `QA_STATUS_REPAIR_REQUIRED` is kept as a valid historical enum value for rows already recorded
 * with it before this change (see EnterpriseWikiDocumentFlowService::finalizeFromExistingQaResult()
 * for how such a historical row is now treated) — evaluate() itself never produces it going
 * forward.
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
    /** Plan §18 (P0-3): escalating quality findings, in qa_result.quality_findings. */
    public const FINDING_CONTROL_NOT_COMPLETED_SEMANTIC_LINK_QA = 'control_not_completed:semantic_link_qa';

    public const FINDING_CONTROL_NOT_COMPLETED_SOURCE_COVERAGE = 'control_not_completed:source_coverage';

    public const FINDING_NORMATIVE_STRENGTHENING = 'normative_strengthening';

    public const FINDING_SOURCE_SECTION_UNCOVERED = 'source_section_uncovered';

    public const QUALITY_FINDINGS = [
        self::FINDING_CONTROL_NOT_COMPLETED_SEMANTIC_LINK_QA,
        self::FINDING_CONTROL_NOT_COMPLETED_SOURCE_COVERAGE,
        self::FINDING_NORMATIVE_STRENGTHENING,
        self::FINDING_SOURCE_SECTION_UNCOVERED,
    ];

    private const CONTROL_COMPLETED = 'completed';

    private const CONTROL_NOT_RUN = 'not_run';

    private const CONTROL_NOT_COMPLETED = 'not_completed';

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
        EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS,
        EnterpriseWikiIngestRun::STATUS_POST_CLAIM_VERIFICATION,
    ];

    public function __construct(
        private readonly EnterpriseWikiCoverageService $coverageService,
        private readonly EnterpriseWikiQaSnapshotService $snapshotService,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
        private readonly EnterpriseWikiLinkIntegrationCheckService $linkIntegrationCheck,
        private readonly EnterpriseWikiSourceCoverageValidator $sourceCoverageValidator,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
    ) {}

    /**
     * Run post-ingest QA for a single applied run.
     *
     * Returns the QA result array, or null if the run was skipped (terminal,
     * already running, or failed/escalated/passed without $retry).
     *
     * @param  bool  $retry  When true, also claims non-terminal runs whose QA result is
     *                       'failed', 'escalated', or 'passed' — an explicit operator decision to re-evaluate, e.g. after a
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
        if (($run->fresh() ?? $run)->isTerminal()) {
            return null;
        }

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
                ->whereNotIn('status', EnterpriseWikiIngestRun::TERMINAL_STATUSES)
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
            $persisted = DB::transaction(function () use ($fresh, $e): bool {
                $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($fresh->id);

                if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                    return false;
                }

                $lockedRun->update([
                    'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                    'qa_completed_at' => now(),
                    'qa_last_error' => $e->getMessage(),
                ]);

                try {
                    $this->snapshotService->capture($lockedRun, []);
                } catch (\Throwable $snapshotException) {
                    Log::error('[WIKI_QA_SNAPSHOT] Failed to create snapshot for escalated run', [
                        'run_id' => $lockedRun->id,
                        'error' => $snapshotException->getMessage(),
                    ]);
                }

                return true;
            });

            if (! $persisted) {
                return null;
            }

            Log::error('[WIKI_QA] QA execution failed with an unexpected technical error — escalated, not failed.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

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
     *     claim_qa_signals: list<string>,
     *     checks: array{article_exists: bool, summary_exists: bool, article_has_content: bool, summary_has_content: bool, link_integration?: array<string, mixed>},
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
                'claim_qa_signals' => [],
                'checks' => $checks,
            ];
        }

        $pageIds = $pivotRows->pluck('enterprise_wiki_page_id');

        // Arbeidspakke B (§7.1): the run's relations must be technically consistent with its text
        // before it may complete. Recorded whatever the verdict, so a result view can show it.
        $checks['link_integration'] = $this->linkIntegrationCheck->check($run);

        $incompleteSteps = [
            ...$this->findIncompleteSteps($pivotRows, $pageIds),
            ...$this->findIncompleteLinkSteps($checks['link_integration']),
        ];

        if ($incompleteSteps !== []) {
            return [
                'verdict' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                'reason' => 'Run has unfinished continuation step(s) or an active reservation: '.implode(', ', $incompleteSteps).'.'
                    .$this->failedPageGenerationReason($pivotRows),
                'incomplete_steps' => $incompleteSteps,
                'critical_defects' => [],
                'claim_qa_signals' => [],
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
                'claim_qa_signals' => [],
                'checks' => $checks,
            ];
        }

        // v0.10: claims are a voluntary QA loop, never a completion gate. Claims are guaranteed
        // verified_at !== null at this point (findIncompleteSteps above already escalated any run
        // with a claim still pending verification), so every claim has a final content_origin —
        // findOpenClaimQaSignals() reports open QA-review opportunities purely as informational
        // data for the voluntary QA workflow. It does not change the verdict on its own; the
        // three narrow quality rules below (plan §18) are the only content-level exceptions.
        $claimQaSignals = $this->findOpenClaimQaSignals($pageIds);

        // Plan §18 (P0-3): "passed" means every mandatory control ran and none found a serious
        // defect. A control that could not run is not a control that passed, a recommendation
        // turned into a requirement is not a faithful page, and knowledge that never reached a
        // page is not covered — each escalates (a person decides), none fails (the content exists).
        $checks['semantic_link_qa'] = $this->semanticLinkQaCheck($run, $pageIds);
        $checks['normative_fidelity'] = $this->normativeFidelityCheck($pageIds);
        $checks['source_coverage'] = $this->sourceCoverageCheck($run);

        [$qualityFindings, $qualityNotes] = $this->qualityFindings($checks);

        return [
            'verdict' => $qualityFindings === []
                ? EnterpriseWikiIngestRun::QA_STATUS_PASSED
                : EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'reason' => $qualityFindings === []
                ? null
                : 'Quality finding(s) need a decision: '.implode(', ', $qualityFindings).'.',
            'incomplete_steps' => [],
            'critical_defects' => [],
            'claim_qa_signals' => $claimQaSignals,
            'quality_findings' => $qualityFindings,
            'quality_notes' => $qualityNotes,
            'checks' => $checks,
        ];
    }

    /**
     * Escalating codes and non-escalating notes, both derived only from the checks above.
     *
     * @param  array<string, mixed>  $checks
     * @return array{0: list<string>, 1: list<string>}
     */
    private function qualityFindings(array $checks): array
    {
        $findings = [];
        $notes = [];

        if ($checks['semantic_link_qa']['status'] === self::CONTROL_NOT_COMPLETED) {
            $findings[] = self::FINDING_CONTROL_NOT_COMPLETED_SEMANTIC_LINK_QA;
        } elseif ($checks['semantic_link_qa']['status'] === self::CONTROL_NOT_RUN) {
            $notes[] = 'semantic_link_qa_not_run';
        }

        if ($checks['normative_fidelity']['open_strengthened_claim_ids'] !== []) {
            $findings[] = self::FINDING_NORMATIVE_STRENGTHENING;
        }

        if ($checks['normative_fidelity']['ambiguous_claim_ids'] !== []) {
            $notes[] = 'normative_alignment_uncertain';
        }

        $coverageStatus = $checks['source_coverage']['status'];

        if ($coverageStatus === EnterpriseWikiSourceCoverageValidator::STATUS_INCOMPLETE) {
            $findings[] = self::FINDING_SOURCE_SECTION_UNCOVERED;
        } elseif ($coverageStatus === EnterpriseWikiSourceCoverageValidator::STATUS_PARTIAL) {
            $notes[] = 'source_coverage_partial';
        } elseif ($coverageStatus === self::CONTROL_NOT_COMPLETED) {
            $findings[] = self::FINDING_CONTROL_NOT_COMPLETED_SOURCE_COVERAGE;
        }

        return [$findings, $notes];
    }

    /**
     * Reads EnterpriseWikiPageLinkQaAttempt — the per-page record of the semantic link review —
     * and tells four situations apart: the review ran (applied, or no change / a declined change),
     * it was not run because it does not apply (AI disabled, empty page, no record for a page the
     * step never covers), or it was attempted and did not finish (failed after its retry, or a
     * reservation left behind by a crashed worker). Only the last is "not completed".
     *
     * @return array{status: string, failed_page_ids: list<int>, pages: array<int, string>}
     */
    private function semanticLinkQaCheck(EnterpriseWikiIngestRun $run, Collection $pageIds): array
    {
        $attempts = EnterpriseWikiPageLinkQaAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->get(['enterprise_wiki_page_id', 'status', 'reason']);

        if ($attempts->isEmpty()) {
            return ['status' => self::CONTROL_NOT_RUN, 'failed_page_ids' => [], 'pages' => []];
        }

        $pages = [];
        $failed = [];

        foreach ($attempts as $attempt) {
            $outcome = match (true) {
                $attempt->status === EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED
                    && $attempt->reason !== EnterpriseWikiPageLinkQaAttempt::REASON_INVALID_REVISION => self::CONTROL_NOT_COMPLETED,
                $attempt->status === EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED && $attempt->reason === 'reserved' => self::CONTROL_NOT_COMPLETED,
                $attempt->status === EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED
                    && in_array($attempt->reason, [EnterpriseWikiPageLinkQaAttempt::REASON_AI_UNAVAILABLE, 'empty_content'], true) => self::CONTROL_NOT_RUN,
                default => self::CONTROL_COMPLETED,
            };

            $pages[(int) $attempt->enterprise_wiki_page_id] = $outcome;

            if ($outcome === self::CONTROL_NOT_COMPLETED) {
                $failed[] = (int) $attempt->enterprise_wiki_page_id;
            }
        }

        return [
            'status' => match (true) {
                $failed !== [] => self::CONTROL_NOT_COMPLETED,
                in_array(self::CONTROL_COMPLETED, $pages, true) => self::CONTROL_COMPLETED,
                default => self::CONTROL_NOT_RUN,
            },
            'failed_page_ids' => $failed,
            'pages' => $pages,
        ];
    }

    /**
     * Open (undecided) claims on the run's current pages whose source_based text was proven, sentence
     * against sentence, to be stricter than its source (plan §18, P0-2). Decided claims — kept,
     * edited, removed, or explicitly marked not blocking — no longer count, through the same
     * blockingState() the publication gate uses, so QA and publication never disagree.
     *
     * @return array{open_strengthened_claim_ids: list<int>, ambiguous_claim_ids: list<int>}
     */
    private function normativeFidelityCheck(Collection $pageIds): array
    {
        $currentVersionIds = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id');

        $strengthened = [];
        $ambiguous = [];

        $claims = $currentVersionIds->isEmpty() ? collect() : EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->get();

        foreach ($claims as $claim) {
            $meta = (array) ($claim->review_metadata ?? []);

            if ($this->claimFindingExplainer->holdsNormativeFollowUp($claim)) {
                $strengthened[] = (int) $claim->id;
            } elseif ((($meta['normative_check'] ?? [])['status'] ?? null) === EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_AMBIGUOUS) {
                $ambiguous[] = (int) $claim->id;
            }
        }

        return ['open_strengthened_claim_ids' => $strengthened, 'ambiguous_claim_ids' => $ambiguous];
    }

    /**
     * Source-to-plan coverage of the decision this run applied (plan §18, P0-1), re-evaluated from
     * the stored decision and the document's own elements. Not applicable to runs without a
     * document source or a decision article. A failure to read the document is a control that
     * did not complete, never "covered".
     *
     * @return array<string, mixed>
     */
    private function sourceCoverageCheck(EnterpriseWikiIngestRun $run): array
    {
        $decision = (array) ($run->maintainer_decision_json ?? []);

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT
            || ! is_array($decision['source_article'] ?? null)
        ) {
            return ['status' => EnterpriseWikiSourceCoverageValidator::STATUS_NOT_APPLICABLE];
        }

        $document = EnterpriseWikiDocument::query()->find($run->source_id);

        if ($document === null) {
            return ['status' => EnterpriseWikiSourceCoverageValidator::STATUS_NOT_APPLICABLE];
        }

        try {
            $elements = $this->sourceElementService->inspect($document)['elements'];
        } catch (\Throwable $e) {
            Log::warning('[WIKI_POST_INGEST_QA] Source coverage could not be evaluated.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::CONTROL_NOT_COMPLETED];
        }

        $report = $this->sourceCoverageValidator->evaluate($decision, $elements);

        return [
            'status' => $report['status'],
            'uncovered_section_keys' => $report['uncovered_section_keys'],
            'partial_section_keys' => $report['partial_section_keys'],
            'excluded_section_keys' => $report['excluded_section_keys'],
            'material_element_count' => $report['material_element_count'],
            'covered_element_count' => $report['covered_element_count'],
            'invalid_exclusions' => $report['invalid_exclusions'],
            'sections' => array_map(static fn (array $section): array => [
                'section_key' => $section['section_key'],
                'label' => $section['label'],
                'status' => $section['status'],
                'uncovered_element_keys' => $section['uncovered_element_keys'],
            ], $report['sections']),
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
     * Find non-terminal applied runs eligible for explicit retry (null, pending,
     * repair_required, failed, escalated).
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
            $this->baseEligibleQuery($qaStatuses)
                ->nonTerminal()
                ->whereNotIn('status', self::ACTIVE_DOCUMENT_FLOW_STATUSES)
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

    private function executeQa(EnterpriseWikiIngestRun $run): ?array
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
            'claim_qa_signals' => $evaluation['claim_qa_signals'],
            'quality_findings' => $evaluation['quality_findings'] ?? [],
            'quality_notes' => $evaluation['quality_notes'] ?? [],
        ];

        return DB::transaction(function () use ($run, $evaluation, $result): ?array {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return null;
            }

            $lockedRun->update([
                'qa_status' => $evaluation['verdict'],
                'qa_completed_at' => now(),
                'qa_last_error' => $evaluation['reason'],
                'qa_result' => $result,
            ]);

            $this->captureSnapshot($lockedRun, $result);

            return $result;
        });
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
     * Relation work that is unfinished or inconsistent is never a content defect, so it escalates
     * — it is never reported as failed, and never as passed (§3.2):
     *
     * - link_assessment_incomplete: a generated page has no recorded link assessment, or its links
     *   were never materialized into rows for its current content;
     * - link_integration_mismatch: markdown and stored relations disagree.
     *
     * A page whose proposed links were all rejected (rejected_only) does not escalate the run — it
     * is reported in checks.link_integration as needs_followup, never as "checked".
     *
     * @param  array<string, mixed>  $linkIntegration
     * @return list<string>
     */
    private function findIncompleteLinkSteps(array $linkIntegration): array
    {
        $reasons = [];

        if (($linkIntegration['assessment_missing'] ?? []) !== []) {
            $reasons[] = 'link_assessment_incomplete';
        }

        if (($linkIntegration['mismatches'] ?? []) !== []) {
            $reasons[] = 'link_integration_mismatch';
        }

        return $reasons;
    }

    /**
     * A partially completed run (docs/enterprise-wiki-knowledge-integration-plan.md §4.2) names the
     * pages that did not generate, so the escalation explains itself without a log lookup. The
     * per-page reason stays on the pivot's generation_error.
     */
    private function failedPageGenerationReason(Collection $pivotRows): string
    {
        $failed = $pivotRows->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED);

        if ($failed->isEmpty()) {
            return '';
        }

        $titles = EnterpriseWikiPage::query()
            ->whereIn('id', $failed->pluck('enterprise_wiki_page_id'))
            ->orderBy('id')
            ->pluck('title')
            ->implode(', ');

        return ' Page(s) that failed to generate: '.$titles.'.';
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
            // A finding bound to an earlier immutable version is historical once a newer
            // version is current. Lint normally reconciles before QA, but QA must itself remain
            // fail-closed for current truth rather than fail on stale diagnostic rows.
            ->where(function ($query): void {
                $query->whereNull('enterprise_wiki_page_version_id')
                    ->orWhereExists(function ($versionQuery): void {
                        $versionQuery->selectRaw('1')
                            ->from('enterprise_wiki_page_versions')
                            ->whereColumn('enterprise_wiki_page_versions.id', 'enterprise_wiki_lint_findings.enterprise_wiki_page_version_id')
                            ->where('enterprise_wiki_page_versions.is_current', true);
                    });
            })
            ->exists();

        if ($hasCriticalLint) {
            $defects[] = 'critical_lint_findings_or_broken_links';
        }

        return $defects;
    }

    /**
     * Open claim-level QA signals — content that a QA specialist may want to review, reported
     * purely as informational data for the voluntary QA workflow (docs/enterprise-llm-wiki-plan.md,
     * "Arkitekturnotat — v0.10"). Scoped to claims tied to the run's pages' CURRENT page versions
     * only: a claim left over on a superseded version (e.g. after a controlled block revision) is
     * historical record, not an open signal.
     *
     * **Never a completion gate.** Before v0.10 this method (then named
     * findClaimIntegrityDefects()) fed directly into the QA verdict (repair_required) and into
     * EnterpriseWikiDocumentOwnerApprovalService's approval-requirement suppression. Both of those
     * consumers have been corrected: a technically sound run always reaches qa_status=passed, and
     * Document Owner approval requirements are always built from claims' actual source references,
     * regardless of what this method reports. This method's only remaining purpose is to surface,
     * for the voluntary QA screen, which claims a QA specialist may want to look at:
     * - a content_origin=internal_error claim an authorized user has explicitly flagged via
     *   blocking_override — internal_error alone is pure technical noise (a linking/anchoring
     *   limitation, not content a human generated or should review) and stays hidden even here
     *   unless a human decided otherwise, exactly like it stays hidden from the Kjøringer "Funn"
     *   panel (EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()); or
     * - a content_origin=unsupported_generated_content claim that reached a genuine "not confirmed
     *   by source" verdict (EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()), or
     * - a content_origin=source_based claim missing its EnterpriseWikiSourceReference row.
     *
     * Legitimate best_practice suggestions are deliberately excluded — those are a distinct,
     * always-non-blocking review flow (WikiClaimController).
     *
     * @return list<string>
     */
    private function findOpenClaimQaSignals(Collection $pageIds): array
    {
        $currentVersionIds = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id');

        if ($currentVersionIds->isEmpty()) {
            return [];
        }

        $signals = [];

        $claimQaClaims = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->whereIn('content_origin', [
                EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            ])
            ->withCount('sourceReferences')
            ->with('canonicalFact')
            ->get(['id', 'content_origin', 'blocking_override', 'generation_issue', 'review_metadata', 'content_block_key', 'canonical_fact_id']);

        $hasOpenInternalError = $claimQaClaims
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR)
            ->contains(fn (EnterpriseWikiClaim $claim): bool => $claim->blocking_override === true);

        if ($hasOpenInternalError) {
            $signals[] = 'open_internal_error_claims';
        }

        $hasOpenUnsupported = $claimQaClaims
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT)
            ->contains(fn (EnterpriseWikiClaim $claim): bool => $this->claimFindingExplainer->isUserFacingAddition($claim));

        if ($hasOpenUnsupported) {
            $signals[] = 'open_unsupported_generated_content_claims';
        }

        $hasUnprovenSourceBased = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED)
            ->whereDoesntHave('sourceReferences')
            ->exists();

        if ($hasUnprovenSourceBased) {
            $signals[] = 'source_based_claims_missing_provenance';
        }

        return $signals;
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
