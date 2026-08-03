<?php

namespace App\Console\Commands;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiEscalatedRunRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunRecoveryResult;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers a run stuck at status=failed OR status=escalated by computing a deterministic resume
 * plan from existing checkpoints and artifacts, rather than always restarting the pipeline from
 * verification_linking.
 *
 * Two entirely separate paths, each owning its own eligibility rules:
 *
 *  - status=failed (unchanged since before the Wiki run-585 recovery work): handled entirely
 *    inline below, exactly as before — computes revalidate_and_finalize vs resume_continuation
 *    from EnterpriseWikiPostIngestQaService::evaluate() and acts directly.
 *  - status=escalated (added for run-585): delegated ENTIRELY to
 *    EnterpriseWikiEscalatedRunRecoveryService — this command holds no parallel eligibility
 *    logic for that path; it only prints the service's own result and maps it to an exit code.
 *
 * Post-ingest QA (EnterpriseWikiPostIngestQaService) is a pure, deterministic end check — it
 * calls no AI, generates nothing, and re-runs no page generation/linking/claim work. That makes
 * it safe to re-evaluate directly from recovery, against whatever artifacts already exist,
 * rather than trusting a possibly-stale or technically-corrupted `qa_status`/snapshot recorded
 * before this command runs (a technical failure during QA — e.g. an OpenAI outage under the old
 * AI-based QA, or any other unexpected exception — must never be mistaken for a real content
 * verdict; see EnterpriseWikiPostIngestQaService's own class docs).
 *
 * For status=failed, the command picks exactly one of two safe outcomes:
 *
 *   - revalidate_and_finalize: every continuation step (page generation, claim extraction,
 *     claim verification) is recorded complete and no lease is active — QA can be safely
 *     re-evaluated right now. Runs EnterpriseWikiPostIngestQaService::runForRun() (deterministic,
 *     no AI, no stage re-execution) and finalizes the run from whatever verdict that produces.
 *   - resume_continuation: some step is not yet complete, or a lease is still active — QA
 *     cannot be safely judged yet. Restore the run to verification_linking and dispatch a
 *     fresh continuation job; every stage is independently safe to re-run against work it
 *     already finished, so resuming never repeats completed work or duplicates artifacts.
 *
 * This is deliberately NOT a general-purpose "set run status" command — every guard below
 * must pass, using existing artifacts as the source of truth, or the command refuses outright.
 */
#[AsCommand(name: 'wiki:recover-document-flow')]
class EnterpriseWikiRecoverDocumentFlow extends Command
{
    protected $signature = 'wiki:recover-document-flow
                            {--run-id= : The ingest run to recover}
                            {--dry-run : Report the observed checkpoints and resume plan without changing anything}';

    protected $description = 'Recover an Enterprise Wiki ingest run stuck at status=failed or status=escalated, deterministically from its already-recorded artifacts.';

    public function handle(
        EnterpriseWikiDocumentFlowService $flowService,
        EnterpriseWikiPostIngestQaService $qaService,
        EnterpriseWikiEscalatedRunRecoveryService $recoveryService,
    ): int {
        $runIdOption = $this->option('run-id');

        if (! $runIdOption) {
            $this->error('[WIKI_RECOVERY] Provide --run-id=<id>.');

            return self::FAILURE;
        }

        $runId = (int) $runIdOption;
        $dryRun = (bool) $this->option('dry-run');

        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run === null) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] not found.");

            return self::FAILURE;
        }

        if ($run->status === EnterpriseWikiIngestRun::STATUS_ESCALATED) {
            return $this->handleEscalatedRun($runId, $dryRun, $recoveryService);
        }

        if ($run->status === EnterpriseWikiIngestRun::STATUS_COMPLETED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has status [{$run->status}] — already terminal, nothing to recover.");

            return self::FAILURE;
        }

        if ($run->status !== EnterpriseWikiIngestRun::STATUS_FAILED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has status [{$run->status}], expected [failed] — refusing recovery.");

            return self::FAILURE;
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has maintainer_decision_status [{$run->maintainer_decision_status}], expected [applied] — refusing recovery.");

            return self::FAILURE;
        }

        $pagesCount = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->count();

        if ($pagesCount === 0) {
            $this->error("[WIKI_RECOVERY] Run [{$runId}] has no applied pages — refusing recovery.");

            return self::FAILURE;
        }

        $evaluation = $qaService->evaluate($run);

        $this->printEvaluation($run, $pagesCount, $evaluation);

        $outcome = $evaluation['incomplete_steps'] === [] ? 'revalidate_and_finalize' : 'resume_continuation';

        $this->line("[WIKI_RECOVERY] Chosen next step: {$outcome}");

        if ($dryRun) {
            $this->info('[WIKI_RECOVERY] --dry-run: no changes made, no job dispatched, no QA re-evaluation executed.');

            return self::SUCCESS;
        }

        if ($outcome === 'revalidate_and_finalize') {
            $this->executeRevalidateAndFinalize($run, $flowService, $qaService);
        } else {
            $this->executeResumeContinuation($run, $evaluation);
        }

        return self::SUCCESS;
    }

    /**
     * Releases a stale qa_status=running claim (the run's own status=failed already proves
     * the worker that held it died without reaching a terminal state), then re-evaluates QA
     * for real via the ordinary atomic-claim path and finalizes from whatever verdict it
     * produces. Never re-runs page generation, linking, claim extraction, or verification —
     * EnterpriseWikiPostIngestQaService reads only what already exists.
     */
    private function executeRevalidateAndFinalize(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiDocumentFlowService $flowService,
        EnterpriseWikiPostIngestQaService $qaService,
    ): void {
        if ($run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_RUNNING) {
            $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PENDING]);
        }

        $qaService->runForRun($run->fresh(), retry: true);

        $flowService->finalizeFromExistingQaResult($run->fresh());

        $fresh = $run->fresh();

        Log::info('[WIKI_RECOVERY] Run re-evaluated deterministically and finalized.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'qa_status' => $fresh->qa_status,
            'status' => $fresh->status,
            'qa_attempt_count' => $fresh->qa_attempt_count,
        ]);

        $this->info("[WIKI_RECOVERY] Run [{$run->id}] re-evaluated — qa_status=[{$fresh->qa_status}], status=[{$fresh->status}]. No stages re-run, no AI calls made.");
    }

    private function executeResumeContinuation(EnterpriseWikiIngestRun $run, array $evaluation): void
    {
        $wasStaleQaClaim = $run->qa_status === EnterpriseWikiIngestRun::QA_STATUS_RUNNING;

        $updates = [
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'finished_at' => null,
            'error_message' => null,
            // Wiki run-588: the run is being actively resumed — a stale failed_phase from the
            // failure being recovered from must not keep marking a later step red for a run
            // that is no longer failed.
            'failed_phase' => null,
            'transient_failure' => null,
        ];

        if ($wasStaleQaClaim) {
            // qa_status=running with the owning run already marked failed means the worker
            // that claimed QA died before it could reach a terminal state — that claim is
            // stale and must be released so a fresh continuation can reclaim it.
            $updates['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_PENDING;
        }

        $run->update($updates);

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id);

        Log::info('[WIKI_RECOVERY] Run restored to verification_linking and a fresh continuation job dispatched.', [
            'run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'stale_qa_claim_released' => $wasStaleQaClaim,
            'incomplete_steps' => $evaluation['incomplete_steps'],
        ]);

        $this->info("[WIKI_RECOVERY] Run [{$run->id}] restored to verification_linking".($wasStaleQaClaim ? ' (stale qa_status=running released to pending)' : '').' — fresh continuation job dispatched.');
    }

    private function printEvaluation(EnterpriseWikiIngestRun $run, int $pagesCount, array $evaluation): void
    {
        $this->line("[WIKI_RECOVERY] Observed state for run [{$run->id}]:");
        $this->line("[WIKI_RECOVERY]   status={$run->status} qa_status=".($run->qa_status ?? 'null')." qa_attempt_count={$run->qa_attempt_count} pages={$pagesCount}");
        $this->line('[WIKI_RECOVERY]   predicted verdict='.$evaluation['verdict']
            .' incomplete_steps=['.implode(', ', $evaluation['incomplete_steps']).']'
            .' critical_defects=['.implode(', ', $evaluation['critical_defects']).']');

        if ($evaluation['reason'] !== null) {
            $this->line("[WIKI_RECOVERY]   reason: {$evaluation['reason']}");
        }
    }

    /**
     * Delegates the entire status=escalated eligibility decision to
     * EnterpriseWikiEscalatedRunRecoveryService — this command holds no parallel business logic
     * for this path, it only reports the service's own result and maps it to an exit code.
     *
     * --dry-run uses the service's read-only evaluate() (no lock, no mutation, no dispatch);
     * without it, attempt() performs the real, locked, idempotent recovery.
     */
    private function handleEscalatedRun(int $runId, bool $dryRun, EnterpriseWikiEscalatedRunRecoveryService $recoveryService): int
    {
        $result = $dryRun
            ? $recoveryService->evaluate($runId)
            : $recoveryService->attempt($runId, caller: 'command');

        $this->line("[WIKI_RECOVERY] Escalated run [{$runId}]: outcome={$result->outcome}");
        $this->line("[WIKI_RECOVERY]   reason: {$result->reason}");

        if ($result->incompleteSteps !== []) {
            $this->line('[WIKI_RECOVERY]   incomplete_steps=['.implode(', ', $result->incompleteSteps).']');
        }

        if ($dryRun) {
            $this->info('[WIKI_RECOVERY] --dry-run: no changes made, no job dispatched.');

            return self::SUCCESS;
        }

        if ($result->outcome === EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED) {
            $this->info("[WIKI_RECOVERY] Run [{$runId}] resumed — continuation job dispatched.");

            return self::SUCCESS;
        }

        $this->warn("[WIKI_RECOVERY] Run [{$runId}] was not resumed ({$result->outcome}).");

        return self::FAILURE;
    }
}
