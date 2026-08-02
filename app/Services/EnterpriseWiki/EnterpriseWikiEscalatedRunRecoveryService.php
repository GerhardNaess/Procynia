<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single, central decision-maker for whether — and how — an escalated Enterprise Wiki ingest
 * run can be safely resumed, built for the Wiki run-585 incident: a run stuck at
 * status=escalated/qa_status=escalated with `incomplete_steps=[verification_incomplete]` because
 * an OpenAI 429 (quota exhaustion) interrupted claim verification mid-flight, with no active
 * lease and no active job left to ever finish that work.
 *
 * Both wiki:recover-document-flow (manual, single-run) and EnterpriseWikiMaintenanceCycleService
 * (scheduled, sweeping many runs) call this service rather than re-deriving eligibility rules —
 * neither has its own parallel recovery logic.
 *
 * Two entry points:
 *  - evaluate(): read-only preview, no lock, no mutation, no dispatch. Safe to call from
 *    anywhere that just wants to know "would this be recoverable right now" (--dry-run,
 *    EnterpriseWikiRunFindingsService's explanation text).
 *  - attempt(): the real, locked, mutating call. Re-checks everything fresh under
 *    lockForUpdate() so two concurrent callers (a scheduler tick and a manual command, or two
 *    overlapping scheduler ticks) can never both "win" — whichever transaction commits first
 *    moves the run's status away from `escalated`, so the other sees that fresh status and
 *    returns already_running. No database lock is ever held across an AI call: this service
 *    makes none itself — it only decides whether to dispatch ContinueEnterpriseWikiDocumentFlowAfterPages,
 *    which does its own AI work entirely outside this transaction.
 *
 * Deliberately narrow in what it resumes: only `verification_incomplete` and
 * `extraction_incomplete` (both handled by the same continuation job/stage —
 * ContinueEnterpriseWikiDocumentFlowAfterPages -> continueAfterPagesGenerated()). An earlier-stage
 * gap (`page_generation_incomplete`) is a different pipeline stage this service does not attempt
 * to resume. An active lease (`extraction_lease_active`/`verification_lease_active`) always means
 * "already running" — never resumed. A qa_status=failed run is only resumed when its recorded
 * error classifies as transient (EnterpriseWikiTransientFailureClassifier) — a genuine schema/
 * logic defect must never be silently retried forever.
 */
class EnterpriseWikiEscalatedRunRecoveryService
{
    /**
     * Incomplete steps (from EnterpriseWikiPostIngestQaService::findIncompleteSteps()) this
     * service resumes — both are checked and, if needed, re-attempted by the same continuation
     * stage, so resuming to STATUS_VERIFICATION_LINKING correctly addresses either.
     */
    private const RESUMABLE_INCOMPLETE_STEPS = [
        'extraction_incomplete',
        'verification_incomplete',
    ];

    /** Presence of any of these means work is already actively owned by another worker right now. */
    private const ACTIVE_LEASE_STEPS = [
        'extraction_lease_active',
        'verification_lease_active',
    ];

    public function __construct(
        private readonly EnterpriseWikiPostIngestQaService $qaService,
    ) {}

    /**
     * Read-only preview of what attempt() would do, without locking, mutating, or dispatching
     * anything. Safe to call as often as needed (e.g. once per page load to build explanatory
     * text) — evaluate() underneath is itself pure and read-only.
     */
    public function evaluate(int $runId): EnterpriseWikiRunRecoveryResult
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
        }

        return $this->decide($run);
    }

    /**
     * Attempts to resume the run if — and only if — it is genuinely recoverable right now.
     * Idempotent: calling this twice for the same run (concurrently or sequentially) never
     * dispatches two continuation jobs — the second call observes the run's now-changed status
     * (no longer `escalated`) and returns already_running.
     */
    public function attempt(int $runId, string $caller): EnterpriseWikiRunRecoveryResult
    {
        return DB::transaction(function () use ($runId, $caller) {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if ($run === null) {
                return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
            }

            $result = $this->decide($run);

            if ($result->isResumed()) {
                $this->resume($run);

                Log::info('[WIKI_RUN_RECOVERY] Escalated run resumed.', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'incomplete_steps' => $result->incompleteSteps,
                ]);
            } else {
                Log::info('[WIKI_RUN_RECOVERY] Escalated run recovery evaluated.', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'outcome' => $result->outcome,
                    'reason' => $result->reason,
                ]);
            }

            return $result;
        });
    }

    private function decide(EnterpriseWikiIngestRun $run): EnterpriseWikiRunRecoveryResult
    {
        if (in_array($run->status, EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES, true)) {
            return EnterpriseWikiRunRecoveryResult::alreadyRunning(
                "Run [{$run->id}] has status [{$run->status}] — the ordinary pipeline already owns it."
            );
        }

        if (in_array($run->status, [
            EnterpriseWikiIngestRun::STATUS_COMPLETED,
            EnterpriseWikiIngestRun::STATUS_CANCELLED,
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
        ], true)) {
            return EnterpriseWikiRunRecoveryResult::alreadyComplete(
                "Run [{$run->id}] has status [{$run->status}] — nothing to recover."
            );
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_ESCALATED) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has status [{$run->status}] — only status=escalated is handled by this service ".
                '(status=failed remains wiki:recover-document-flow\'s existing responsibility).'
            );
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — cannot resume continuation."
            );
        }

        $document = EnterpriseWikiDocument::find($run->source_id);

        if ($document === null) {
            return EnterpriseWikiRunRecoveryResult::missingDependencies(
                "Run [{$run->id}]'s source document [{$run->source_id}] no longer exists."
            );
        }

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        if ($pageIds->isEmpty()) {
            return EnterpriseWikiRunRecoveryResult::missingDependencies(
                "Run [{$run->id}] has no applied pages — nothing to resume."
            );
        }

        if (EnterpriseWikiPage::query()->whereIn('id', $pageIds)->count() !== $pageIds->count()) {
            return EnterpriseWikiRunRecoveryResult::missingDependencies(
                "Run [{$run->id}] has at least one applied page that no longer exists."
            );
        }

        // Fresh, read-only re-evaluation of the ACTUAL current state — never trust the run's own
        // stored qa_result, since real time has passed since it last escalated and a prior
        // maintenance attempt may have already changed things (e.g. a deep-repair attempt that
        // itself failed, per the run-585 pattern).
        $evaluation = $this->qaService->evaluate($run);
        $incompleteSteps = $evaluation['incomplete_steps'];
        $criticalDefects = $evaluation['critical_defects'];

        foreach (self::ACTIVE_LEASE_STEPS as $leaseStep) {
            if (in_array($leaseStep, $incompleteSteps, true)) {
                return EnterpriseWikiRunRecoveryResult::alreadyRunning(
                    "Run [{$run->id}] has an active lease ({$leaseStep}) — another worker already owns this work.",
                    $incompleteSteps,
                );
            }
        }

        if ($incompleteSteps === [] && $criticalDefects === []) {
            return EnterpriseWikiRunRecoveryResult::staleState(
                "Run [{$run->id}] has no incomplete steps and no critical defects anymore — status is stale, ".
                'not genuinely blocked. Use `wiki:run-post-ingest-qa --run-id='.$run->id.' --retry` to re-finalize.'
            );
        }

        if ($criticalDefects !== []) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has genuine critical defect(s), not just incomplete verification: ".
                implode(', ', $criticalDefects).'.',
                $incompleteSteps,
            );
        }

        $unrecoverableSteps = array_diff($incompleteSteps, self::RESUMABLE_INCOMPLETE_STEPS);

        if ($unrecoverableSteps !== []) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has incomplete step(s) outside this service's recoverable scope: ".
                implode(', ', $unrecoverableSteps).'.',
                $incompleteSteps,
            );
        }

        if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_FAILED
            && ! EnterpriseWikiTransientFailureClassifier::isTransient($run->qa_last_error)) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has qa_status=failed with a non-transient error — refusing automatic recovery: ".
                $run->qa_last_error,
                $incompleteSteps,
            );
        }

        return EnterpriseWikiRunRecoveryResult::resumed(
            "Run [{$run->id}] has resumable incomplete step(s) (".implode(', ', $incompleteSteps).
            ') with no active lease and no permanent defect — resuming continuation from verification_linking.',
            $incompleteSteps,
        );
    }

    /**
     * Resets only the checkpoint/status fields that must reopen — never touches qa_last_error,
     * qa_result, qa_completed_at, or qa_attempt_count, so the history of why the run previously
     * escalated remains visible after it resumes. Mirrors
     * wiki:recover-document-flow::executeResumeContinuation()'s existing status=failed path
     * exactly, so both recovery routes leave a run in the identical shape once resumed.
     */
    private function resume(EnterpriseWikiIngestRun $run): void
    {
        $updates = [
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'finished_at' => null,
            'error_message' => null,
        ];

        if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_RUNNING) {
            $updates['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_PENDING;
        }

        $run->update($updates);

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id);
    }
}
