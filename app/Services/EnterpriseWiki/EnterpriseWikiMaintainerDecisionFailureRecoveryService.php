<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single, central decision-maker for whether — and how — a run that failed during the
 * maintainer_decision phase can be safely resumed WITHOUT re-uploading the source document. Built
 * for the Wiki run-592 incident: a plain cURL timeout on the global-plan AI call left the run
 * status=failed/failed_phase=maintainer_decision with nothing persisted (no decision, no pages),
 * and the only prior recovery path (wiki:recover-document-flow's status=failed branch) explicitly
 * refuses any run without an APPLIED maintainer decision and at least one page — structurally
 * unable to help here.
 *
 * Deliberately narrow and separate from EnterpriseWikiEscalatedRunRecoveryService (status=escalated,
 * resumes from verification_linking) and wiki:recover-document-flow's own status=failed branch
 * (requires maintainer_decision_status=applied) — this service owns exactly one case: status=failed
 * + failed_phase=maintainer_decision, with no persisted decision or pages yet.
 *
 * Two eligibility paths, controlled by $allowManualOverride:
 *  - AUTOMATIC (default, $allowManualOverride=false): only a TRANSIENT recorded failure
 *    (EnterpriseWikiTransientFailureClassifier, set by
 *    EnterpriseWikiDocumentFlowService::markRunFailed()) is recoverable. A non-transient failure
 *    (schema violation, figure-planning conflict, consistency error, permanent API error, missing
 *    local configuration) is never auto-recoverable — nothing must silently retry a genuine defect
 *    that will just fail again.
 *  - MANUAL (Wiki run-593, $allowManualOverride=true): a human explicitly asked to retry — the
 *    transient-failure requirement is dropped, but every OTHER safety check below (status=failed,
 *    failed_phase=maintainer_decision, no maintainer_decision_status, no applied pages, document +
 *    extracted text still present) still applies unchanged. This only widens WHICH failures are
 *    offered a retry; it never weakens what "safe to resume" means. Only ever pass true from a
 *    caller that is itself an explicit, one-off user action (never from a scheduled/automatic
 *    caller) — see WikiController::retryMaintainerDecision().
 *
 * Two entry points, same shape as EnterpriseWikiEscalatedRunRecoveryService:
 *  - evaluate(): read-only preview, no lock, no mutation, no dispatch — safe to call whenever the
 *    Kjøringer UI needs to know "should the retry button show for this run".
 *  - attempt(): the real, locked, mutating call. Re-checks everything fresh under lockForUpdate()
 *    so two concurrent callers (a double click, or two browser tabs) can never both "win" —
 *    whichever transaction commits first moves the run's status away from `failed`, so the other
 *    sees that fresh status and returns already_running. Resuming re-enters the run at the exact
 *    same entrypoint (RunEnterpriseWikiDocumentFlow -> EnterpriseWikiDocumentFlowService::run())
 *    a fresh queued run would use — nothing was ever persisted for this run's maintainer decision,
 *    so restarting from STATUS_QUEUED is not a special case, it is simply "run it again". Document
 *    extraction is never re-run: it already happened once at upload time, entirely outside this
 *    flow — performMaintainerDecision() only ever reads the document's already-extracted text.
 */
class EnterpriseWikiMaintainerDecisionFailureRecoveryService
{
    /**
     * Read-only preview of what attempt() would do, without locking, mutating, or dispatching
     * anything. $allowManualOverride must match whatever attempt() will actually be called with
     * (e.g. the Kjøringer UI's button-gating check must pass the same value the click handler
     * uses), otherwise the button's visibility and the action's real eligibility can disagree.
     */
    public function evaluate(int $runId, bool $allowManualOverride = false): EnterpriseWikiRunRecoveryResult
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
        }

        return $this->decide($run, $allowManualOverride);
    }

    /**
     * Attempts to resume the run if — and only if — it is genuinely recoverable right now.
     * Idempotent: calling this twice for the same run (concurrently or sequentially) never
     * dispatches two document-flow jobs — the second call observes the run's now-changed status
     * (no longer `failed`) and returns already_running.
     *
     * @param  bool  $allowManualOverride  See the class docblock. Only ever true for an explicit,
     *                                     one-off user action — never a scheduled/automatic caller.
     */
    public function attempt(int $runId, string $caller, bool $allowManualOverride = false): EnterpriseWikiRunRecoveryResult
    {
        return DB::transaction(function () use ($runId, $caller, $allowManualOverride) {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if ($run === null) {
                return EnterpriseWikiRunRecoveryResult::notRecoverable("Run [{$runId}] not found.");
            }

            $result = $this->decide($run, $allowManualOverride);

            if ($result->isResumed()) {
                $this->resume($run);

                Log::info('[WIKI_RUN_RECOVERY] Maintainer-decision run resumed.', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'manual_override' => $allowManualOverride,
                    'maintainer_decision_attempt_count' => $run->maintainer_decision_attempt_count,
                ]);
            } else {
                Log::info('[WIKI_RUN_RECOVERY] Maintainer-decision run recovery evaluated.', [
                    'run_id' => $run->id,
                    'customer_id' => $run->customer_id,
                    'caller' => $caller,
                    'manual_override' => $allowManualOverride,
                    'outcome' => $result->outcome,
                    'reason' => $result->reason,
                ]);
            }

            return $result;
        });
    }

    private function decide(EnterpriseWikiIngestRun $run, bool $allowManualOverride): EnterpriseWikiRunRecoveryResult
    {
        if (in_array($run->status, EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES, true)) {
            return EnterpriseWikiRunRecoveryResult::alreadyRunning(
                "Run [{$run->id}] has status [{$run->status}] — the ordinary pipeline already owns it."
            );
        }

        if (in_array($run->status, [
            EnterpriseWikiIngestRun::STATUS_COMPLETED,
            EnterpriseWikiIngestRun::STATUS_CANCELLED,
            EnterpriseWikiIngestRun::STATUS_ESCALATED,
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
        ], true)) {
            return EnterpriseWikiRunRecoveryResult::alreadyComplete(
                "Run [{$run->id}] has status [{$run->status}] — nothing to recover."
            );
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_FAILED) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has status [{$run->status}] — only status=failed is handled by this service."
            );
        }

        if ($run->failed_phase !== EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has failed_phase [".($run->failed_phase ?? 'null').'] — only failed_phase='.
                'maintainer_decision is handled by this service (a later-phase failure remains '.
                'wiki:recover-document-flow\'s existing responsibility).'
            );
        }

        if (! $allowManualOverride && $run->transient_failure !== true) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] has a non-transient (or unclassified) failure — refusing automatic recovery: ".
                ($run->error_message ?? '(no error message recorded)')
            );
        }

        // Wiki run-592: a run that failed during maintainer_decision never gets as far as
        // persisting maintainer_decision_json or any applied page — a non-null value here (or an
        // existing pivot row) means the run's actual state does not match what this service
        // expects, and resuming from STATUS_QUEUED would silently discard or duplicate that state.
        if ($run->maintainer_decision_status !== null) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] already has maintainer_decision_status [{$run->maintainer_decision_status}] — ".
                'resuming from scratch would be inconsistent with an existing decision.'
            );
        }

        if (EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->exists()) {
            return EnterpriseWikiRunRecoveryResult::notRecoverable(
                "Run [{$run->id}] already has applied page(s) — resuming from scratch would be inconsistent."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            return EnterpriseWikiRunRecoveryResult::missingDependencies(
                "Run [{$run->id}]'s source document [{$run->source_id}] no longer exists."
            );
        }

        if (trim((string) ($document->extracted_text ?? '')) === '') {
            return EnterpriseWikiRunRecoveryResult::missingDependencies(
                "Run [{$run->id}]'s source document [{$run->source_id}] has no extracted text to plan from."
            );
        }

        return EnterpriseWikiRunRecoveryResult::resumed(
            "Run [{$run->id}] failed during maintainer_decision (".($allowManualOverride ? 'manual override' : 'transient').
            ') with no persisted decision or pages yet — safe to resume from a fresh queued dispatch, reusing '.
            'the same run_id and document.'
        );
    }

    /**
     * Resets the run to STATUS_QUEUED and re-dispatches RunEnterpriseWikiDocumentFlow — the exact
     * same entrypoint a brand-new run uses. EnterpriseWikiDocumentFlowService::claimRun() (called
     * first thing inside that job) already resets started_at and re-clears finished_at/
     * error_message/failed_phase/transient_failure under its own row lock, and only proceeds when
     * status is still STATUS_QUEUED — so even if this method's own dispatch somehow raced with
     * another, the job-level guard still allows only one live attempt to actually run.
     *
     * maintainer_decision_attempt_count and the qa/claim-content-repair fields are deliberately
     * left untouched — this run never reached any of those stages, and the attempt count itself is
     * incremented by performMaintainerDecision() on the next attempt, preserving a visible history
     * of how many times maintainer_decision has actually been tried.
     */
    private function resume(EnterpriseWikiIngestRun $run): void
    {
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
            'finished_at' => null,
            'error_message' => null,
            'failed_phase' => null,
            'transient_failure' => null,
        ]);

        RunEnterpriseWikiDocumentFlow::dispatch($run->id);
    }
}
