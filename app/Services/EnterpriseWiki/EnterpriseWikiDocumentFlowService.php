<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiClaimVerification;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiMaintainerDecisionBatches;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiMaintainerDecisionBatch;
use App\Jobs\EnterpriseWiki\VerifyEnterpriseWikiClaim;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Support\EnterpriseWiki\EnterpriseWikiQueueTrace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestrates the new Enterprise Wiki document flow.
 *
 * The coordinator is intentionally thin:
 * - it reuses the existing document validation and run creation service
 * - it runs the existing Enterprise Wiki services in the new order
 * - it owns status transitions for the new flow
 * - it marks failures terminally so the run never gets stuck mid-stage
 *
 * Applied page generation (article/summary/concept/entity) is NOT performed synchronously here.
 * run() dispatches one GenerateEnterpriseWikiAppliedPage job per page and returns; the flow
 * resumes via continueAfterPagesGenerated() once FinalizeEnterpriseWikiPageGeneration confirms
 * every page job for the run has finished. This keeps a single slow OpenAI call for one page
 * from blocking the maintainer-decision/apply stages or any other page.
 */
class EnterpriseWikiDocumentFlowService
{
    /**
     * How long to wait before re-dispatching the continuation job when a stage is busy —
     * post-ingest QA claimed elsewhere but not yet terminal, or a claim extraction lease is
     * actively held by another live worker — short enough that a run doesn't sit idle
     * for long, long enough not to hammer the same busy row/claim in a tight loop.
     */
    private const STEP_BUSY_RETRY_DELAY_SECONDS = 30;

    /**
     * Recovery-only fallback for a worker/process crash between dispatch and completion of claim
     * verification jobs. The normal path is driven by each completed claim job.
     */
    private const CLAIM_VERIFICATION_CRASH_RECOVERY_DELAY_SECONDS = 60;

    /**
     * Recovery-only fallback for a worker/process crash between dispatch and completion of
     * applied-page generation jobs — see FinalizeEnterpriseWikiPageGeneration's crash-recovery
     * sentinel docblock. The normal path is driven by each completing/failing page job.
     */
    private const PAGE_GENERATION_CRASH_RECOVERY_DELAY_SECONDS = 60;

    private const ARTICLE_SUMMARY_PAGE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
    ];

    private const INITIAL_PAGE_GENERATION_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
    ];

    public function __construct(
        private readonly EnterpriseWikiIngestService $ingestService,
        private readonly EnterpriseWikiMaintainerDecisionService $maintainerDecisionService,
        private readonly EnterpriseWikiMaintainerDecisionBatchStateService $maintainerBatchStateService,
        private readonly EnterpriseWikiMaintainerDecisionApplyService $maintainerDecisionApplyService,
        private readonly EnterpriseWikiExtractPageClaimsService $extractPageClaimsService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyPageClaimsService,
        private readonly EnterpriseWikiGenerateAppliedPagesService $generateAppliedPagesService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiIncrementalRelinkService $incrementalRelinkService,
        private readonly EnterpriseWikiAppliedRunLintService $appliedRunLintService,
        private readonly EnterpriseWikiLinkSemanticRepairService $linkSemanticRepairService,
        private readonly EnterpriseWikiPostIngestQaService $postIngestQaService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly EnterpriseWikiPatchApplicationService $patchApplicationService,
        private readonly EnterpriseWikiCrossPageConsistencyService $crossPageConsistencyService,
        private readonly EnterpriseWikiCrossPageReconciliationService $crossPageReconciliationService,
    ) {}

    /**
     * Prepare a document-specific ingest run.
     *
     * The document row is locked while we inspect existing runs so parallel clicks
     * cannot create duplicate active runs for the same source document.
     *
     * @return array{run: EnterpriseWikiIngestRun, created: bool}
     */
    public function prepareRunForDocument(int $customerId, int $documentId): array
    {
        return DB::transaction(function () use ($customerId, $documentId): array {
            $document = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->where('id', $documentId)
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                throw new InvalidArgumentException(
                    "EnterpriseWikiDocument [{$documentId}] not found for customer [{$customerId}]."
                );
            }

            if ($document->document_status !== EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
                throw new InvalidArgumentException(
                    "EnterpriseWikiDocument [{$documentId}] has document_status '{$document->document_status}', expected 'extracted'."
                );
            }

            if (blank($document->extracted_text)) {
                throw new InvalidArgumentException(
                    "EnterpriseWikiDocument [{$documentId}] has no extracted text."
                );
            }

            $existingRun = EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->whereNotIn('status', EnterpriseWikiIngestRun::TERMINAL_STATUSES)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existingRun !== null) {
                return [
                    'run' => $existingRun,
                    'created' => false,
                ];
            }

            return [
                'run' => $this->ingestService->createQueuedRunForDocument($customerId, $document),
                'created' => true,
            ];
        });
    }

    /**
     * Re-sync document-owner approvals after a source document owner changes.
     *
     * The service only touches current active page versions that actually reference the
     * changed document through existing provenance, then re-evaluates the linked runs.
     *
     * @return Collection<int, EnterpriseWikiPageVersion>
     */
    public function syncDocumentOwnerApprovals(EnterpriseWikiDocument $document)
    {
        $versions = $this->documentOwnerApprovalService->syncForDocument($document);

        $runIds = EnterpriseWikiIngestRunPage::query()
            ->whereIn('generated_page_version_id', $versions->pluck('id'))
            ->pluck('enterprise_wiki_ingest_run_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($runIds as $runId) {
            $run = EnterpriseWikiIngestRun::query()->find($runId);

            if (! $run instanceof EnterpriseWikiIngestRun) {
                continue;
            }

            if (! $run->isTerminal()) {
                $this->reconcileRunDocumentOwnerApprovalState($run);

                continue;
            }

            // A run that already finished is normally left alone — that is what the terminal guard
            // is for. The one exception is this exact situation: the owner change just created a
            // NEW, still-undecided approval requirement on a current page version of a run that had
            // completed, which would otherwise leave the run looking finished while a real human
            // decision is outstanding. Only `completed` is eligible; see the method's own docs.
            $this->reopenCompletedRunForOutstandingOwnerApproval($run);
        }

        return $versions;
    }

    /**
     * Reopen a COMPLETED run whose document owner approval is no longer satisfied.
     *
     * Called only from syncDocumentOwnerApprovals(), i.e. only after a document's owner actually
     * changed. The change itself is never sufficient: approval requirements are keyed on
     * (page version, document owner, source documents), so a new owner produces a new PENDING row,
     * and it is that outstanding requirement — established by the same
     * EnterpriseWikiDocumentOwnerApprovalService::evaluateRunCompletionGate() the ordinary path
     * uses — that reopens the run. A gate that still reports ready leaves the run completed.
     *
     * Deliberately narrow in three ways:
     *
     *  - Only `completed`. `failed`, `cancelled` and `escalated` are terminal for reasons that have
     *    nothing to do with who owns a document, and a pending approval says nothing about the
     *    technical fault that ended them. Reopening those would turn an ownership edit into a way to
     *    resurrect a broken run.
     *  - The write itself re-asserts `status = completed`, so a run that moved on between the read
     *    and the update is left exactly as it is.
     *
     * Deliberately NOT guarded on qa_status, unlike reconcileRunDocumentOwnerApprovalState(). That
     * guard exists there because that method can COMPLETE a run, and a technically failed run must
     * never be completed by an approval. This method only ever moves completed -> awaiting, which is
     * strictly more conservative: it grants nothing, it only reinstates a human gate. Requiring a
     * sound qa_status here would instead make the reopen silently depend on a column a legitimately
     * completed run need not carry (an older run has qa_status null), which is exactly the
     * non-determinism this fix exists to remove. `status = completed` already carries the only
     * precondition that matters: this run was previously judged finished.
     *
     * No new status, no new column and no new state machine: this sets the same fields, to the same
     * values, as the awaiting branch of reconcileRunDocumentOwnerApprovalState(). Once the new owner
     * (or a System Owner override) decides, the ordinary completion path runs unchanged — the run is
     * non-terminal again, so WikiDocumentOwnerApprovalController's existing
     * finalizeFromExistingQaResult() call completes it exactly as it did the first time.
     */
    private function reopenCompletedRunForOutstandingOwnerApproval(EnterpriseWikiIngestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        if ($fresh->status !== EnterpriseWikiIngestRun::STATUS_COMPLETED) {
            return;
        }

        $gate = $this->documentOwnerApprovalService->evaluateRunCompletionGate($fresh);

        if ($gate['ready']) {
            return;
        }

        $updated = EnterpriseWikiIngestRun::query()
            ->whereKey($fresh->id)
            ->where('status', EnterpriseWikiIngestRun::STATUS_COMPLETED)
            ->update([
                'status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
                'finished_at' => null,
                'error_message' => mb_substr((string) ($gate['message'] ?? 'Avventer godkjenning fra Dokumenteier.'), 0, 1000),
                'failed_phase' => null,
                'transient_failure' => null,
            ]);

        if ($updated > 0) {
            Log::info('[WIKI_DOCUMENT_FLOW] Completed run reopened for outstanding document owner approval.', [
                'run_id' => $fresh->id,
                'pending' => count($gate['pending'] ?? []),
                'rejected' => count($gate['rejected'] ?? []),
                'missing_owner' => count($gate['missing_owner'] ?? []),
            ]);
        }
    }

    /**
     * Manually cancel a non-terminal run so its source document becomes eligible for deletion.
     *
     * Any active claim-extraction (page-level), claim-verification, or page-generation
     * dispatch/lease state held for the run is released so a worker that still holds one cannot
     * write back into a run that has already moved to a terminal status — see
     * EnterpriseWikiExtractPageClaimsService::reserve()/release(), EnterpriseWikiVerifyPageClaimsService,
     * and EnterpriseWikiGenerateAppliedPagesService for the matching lease lifecycles. Generated
     * pages, page versions, and claims are left untouched: cancelling a run only stops it from
     * progressing further, it does not undo Wiki content the run already produced.
     */
    public function cancelRun(EnterpriseWikiIngestRun $run, User $actor, ?string $reason = null): EnterpriseWikiIngestRun
    {
        $cancelled = DB::transaction(function () use ($run, $actor, $reason): EnterpriseWikiIngestRun {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->isTerminal()) {
                return $locked;
            }

            DB::table('enterprise_wiki_ingest_run_pages')
                ->where('enterprise_wiki_ingest_run_id', $locked->id)
                ->update([
                    'claims_claimed_at' => null,
                    'claims_claim_token' => null,
                    'generation_dispatched_at' => null,
                    'generation_claimed_at' => null,
                    'generation_claim_token' => null,
                    'updated_at' => now(),
                ]);

            $claimIds = DB::table('enterprise_wiki_claims as c')
                ->join('enterprise_wiki_page_versions as pv', 'pv.id', '=', 'c.enterprise_wiki_page_version_id')
                ->join('enterprise_wiki_ingest_run_pages as rp', 'rp.enterprise_wiki_page_id', '=', 'pv.enterprise_wiki_page_id')
                ->where('rp.enterprise_wiki_ingest_run_id', $locked->id)
                ->where(fn ($q) => $q->whereNotNull('c.verification_claimed_at')->orWhereNotNull('c.verification_dispatched_at'))
                ->pluck('c.id');

            if ($claimIds->isNotEmpty()) {
                DB::table('enterprise_wiki_claims')
                    ->whereIn('id', $claimIds)
                    ->update([
                        'verification_dispatched_at' => null,
                        'verification_claimed_at' => null,
                        'verification_claim_token' => null,
                        'updated_at' => now(),
                    ]);
            }

            $locked->update([
                'status' => EnterpriseWikiIngestRun::STATUS_CANCELLED,
                'finished_at' => now(),
                'error_message' => mb_substr(
                    'Kjøring avbrutt av '.$actor->name.($reason !== null && $reason !== '' ? ': '.$reason : '.'),
                    0,
                    1000
                ),
            ]);

            return $locked->fresh() ?? $locked;
        });

        Log::info('[WIKI_DOCUMENT_FLOW] Run cancelled.', [
            'run_id' => $run->id,
            'actor_user_id' => $actor->id,
        ]);

        return $cancelled;
    }

    /**
     * Run the document flow for a queued ingest run up to and including dispatching applied
     * page generation. Duplicate dispatches are ignored by the atomic claim step at the top.
     *
     * The rest of the flow (claim extraction onward) resumes in continueAfterPagesGenerated()
     * once every dispatched page job has finished — see FinalizeEnterpriseWikiPageGeneration.
     */
    public function run(int $runId): void
    {
        $run = $this->claimRun($runId);

        if ($run === null) {
            return;
        }

        try {
            if (! $this->performMaintainerDecision($run)) {
                return;
            }
            $this->performApplyMaintainerDecision($run);
            $this->beginGeneratingPages($run);
        } catch (Throwable $e) {
            $this->markRunFailed($run->fresh() ?? $run, $e, false);

            throw $e;
        }
    }

    /**
     * Resume the flow after every applied page for the run has been generated: claim
     * extraction, verification, linking, lint, and QA.
     *
     * No separate atomic claim is needed here: FinalizeEnterpriseWikiPageGeneration already
     * transitions the run out of generating_pages under its own row lock before dispatching
     * this continuation exactly once, so by the time this runs the run is already claimed.
     *
     * Every individual stage below is independently safe to re-run against a run that has
     * already progressed past it — see the class-level docs on each stage's service for its
     * own idempotency/checkpoint mechanism. This method itself does not need to compute which
     * stage to resume from: stages that are already complete detect this internally (via an
     * artifact-based check or an explicit checkpoint column) and skip without a new AI call,
     * so simply re-running the full sequence from the top is always correct, whether this is
     * the first attempt, a duplicate dispatch, a queue retry, or a resumption after a worker
     * restart.
     */
    public function continueAfterPagesGenerated(int $runId): void
    {
        // Brief row lock purely to make the terminal check race-free against a concurrent
        // duplicate invocation finishing at almost the same instant — released immediately
        // after, never held across a stage's AI calls below.
        $run = DB::transaction(function () use ($runId): ?EnterpriseWikiIngestRun {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if (! $locked instanceof EnterpriseWikiIngestRun) {
                return null;
            }

            return $locked->isTerminal() ? null : $locked;
        });

        if ($run === null) {
            if (EnterpriseWikiIngestRun::query()->find($runId) === null) {
                Log::warning('[WIKI_DOCUMENT_FLOW] Run not found for page-generation continuation.', [
                    'run_id' => $runId,
                ]);
            }

            return;
        }

        if ($this->isWaitingOnHumanAction($run, 'continueAfterPagesGenerated')) {
            return;
        }

        if ($run->status === EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS) {
            $this->continueAfterClaimVerification($run->id, true);

            return;
        }

        if ($run->status === EnterpriseWikiIngestRun::STATUS_POST_CLAIM_VERIFICATION) {
            // A sentinel already atomically claimed post-verification continuation.
            return;
        }

        $currentStage = EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING;

        try {
            // Fase 8K-3: existing pages the decision patches are handled HERE, after the run's own
            // new pages are generated and before wikilinks are materialized — so a patched page's
            // links and claims are picked up by the same steps that follow for generated pages.
            // Deliberately not a queued job: the patch engine is fully deterministic with no AI call
            // (see EnterpriseWikiPatchApplicationService), so it belongs with the other synchronous
            // steps in this continuation rather than needing its own lease/fan-in machinery.
            $this->performApplyPatchTargets($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            // A maintainer prompt is intentionally bounded. Reconcile any additional, high-confidence
            // current assertions it could not see before materializing links and running QA.
            $this->performCrossPageReconciliation($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            $this->performMaterializeWikilinks($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            $this->performIncrementalRelinking($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            if (! $this->performExtractPageClaims($run)) {
                // Another live worker holds an active reservation on at least one page's
                // claim extraction — a deferred retry of this same continuation job has
                // already been dispatched. Do not proceed to verification/lint/QA, and do
                // not mark the run failed: this is an expected concurrent state.
                return;
            }

            $this->beginClaimVerification($run);
        } catch (Throwable $e) {
            $this->markRunFailed($run->fresh() ?? $run, $e, $currentStage === EnterpriseWikiIngestRun::STATUS_QA, $currentStage);

            throw $e;
        }
    }

    /**
     * Fan-in entry point. Only the sentinel that atomically moves the run from
     * verifying_claims to post_claim_verification may continue into lint/semantic/QA.
     */
    public function continueAfterClaimVerification(int $runId, bool $recoverUndispatchedClaims = false): void
    {
        $result = DB::transaction(function () use ($runId, $recoverUndispatchedClaims): array {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                return ['run' => null, 'continue' => false, 'pending' => false, 'recover' => false, 'reschedule_recovery' => false];
            }

            if ($run->status !== EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS) {
                return ['run' => $run, 'continue' => false, 'pending' => false, 'recover' => false, 'reschedule_recovery' => false];
            }

            $pending = $this->verifyPageClaimsService->unverifiedClaimIdsForRun($run);
            $hasActiveLease = $this->verifyPageClaimsService->hasActiveClaimLeaseForRun($run);

            if ($pending !== [] || $hasActiveLease) {
                // No run-wide time window here: whether anything actually gets redispatched is
                // decided per claim, atomically, by
                // EnterpriseWikiVerifyPageClaimsService::reserveClaimForDispatch() inside
                // dispatchClaimVerificationWork() — a claim genuinely still queued within its own
                // dispatch-wait window, or still actively leased, loses that compare-and-swap and
                // is left untouched regardless of how long this run itself has been idle.
                $recoveryReady = $recoverUndispatchedClaims && ! $hasActiveLease;

                return [
                    'run' => $run->fresh() ?? $run,
                    'continue' => false,
                    'pending' => true,
                    'recover' => $recoveryReady,
                    'reschedule_recovery' => $recoverUndispatchedClaims && ! $recoveryReady,
                ];
            }

            $run->update(['status' => EnterpriseWikiIngestRun::STATUS_POST_CLAIM_VERIFICATION]);

            return ['run' => $run->fresh() ?? $run, 'continue' => true, 'pending' => false, 'recover' => false, 'reschedule_recovery' => false];
        });

        $run = $result['run'];

        if (! $run instanceof EnterpriseWikiIngestRun) {
            return;
        }

        if ($this->isWaitingOnHumanAction($run, 'continueAfterClaimVerification')) {
            return;
        }

        if ($result['pending']) {
            if ($result['recover']) {
                $this->dispatchClaimVerificationWork($run);
            } elseif ($result['reschedule_recovery']) {
                $this->dispatchClaimVerificationRecoveryFallback($run);
            }

            return;
        }

        if (! $result['continue']) {
            return;
        }

        $currentStage = EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING;

        try {
            $this->performAppliedRunLint($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            $this->performLinkSemanticRepair($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            // Fase 8K-4: runs LAST of the content passes, deliberately after semantic repair — that
            // step can write a new current version, and this check must read the content the run
            // actually finished with. Placed before QA so its findings reach the existing
            // aggregation with no QA special-casing.
            $this->performCrossPageConsistencyCheck($run);
            if (($run->fresh() ?? $run)->isTerminal()) {
                return;
            }

            $currentStage = EnterpriseWikiIngestRun::STATUS_QA;

            if (! $this->performPostIngestQa($run)) {
                return;
            }

            $fresh = $run->fresh() ?? $run;

            if (! $fresh->isTerminal()) {
                $this->finalizeFromExistingQaResult($fresh);
            }
        } catch (Throwable $e) {
            $this->markRunFailed($run->fresh() ?? $run, $e, $currentStage === EnterpriseWikiIngestRun::STATUS_QA, $currentStage);

            throw $e;
        }
    }

    public function markClaimVerificationFailed(int $runId, Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);

        if ($run instanceof EnterpriseWikiIngestRun && ! $run->isTerminal()) {
            $this->markRunFailed($run, $exception, false, EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);
        }
    }

    private function claimRun(int $runId): ?EnterpriseWikiIngestRun
    {
        return DB::transaction(function () use ($runId): ?EnterpriseWikiIngestRun {
            $run = EnterpriseWikiIngestRun::query()
                ->lockForUpdate()
                ->find($runId);

            if (! $run instanceof EnterpriseWikiIngestRun) {
                Log::warning('[WIKI_DOCUMENT_FLOW] Run not found while claiming queued flow.', [
                    'run_id' => $runId,
                ]);

                return null;
            }

            if ($run->status !== EnterpriseWikiIngestRun::STATUS_QUEUED) {
                Log::info('[WIKI_DOCUMENT_FLOW] Run already claimed or finished — skipping duplicate dispatch.', [
                    'run_id' => $run->id,
                    'status' => $run->status,
                ]);

                return null;
            }

            EnterpriseWikiQueueTrace::log('claim_before_started_at', [
                'run_id' => $run->id,
                'current_status' => $run->status,
            ]);

            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
                'started_at' => now(),
                'finished_at' => null,
                'error_message' => null,
                'failed_phase' => null,
                'transient_failure' => null,
            ]);

            $freshRun = $run->fresh();

            EnterpriseWikiQueueTrace::log('claim_after_started_at', [
                'run_id' => $run->id,
                'current_status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
                'started_at' => $freshRun?->started_at?->format('Y-m-d\TH:i:s.v\Z'),
            ]);

            return $freshRun;
        });
    }

    private function performMaintainerDecision(EnterpriseWikiIngestRun $run): bool
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return false;
        }

        if ($run->maintainer_decision_generated_at !== null) {
            return true;
        }

        $summary = $this->maintainerBatchStateService->summary($run->id);
        if ($summary['total'] > 0) {
            $this->dispatchMaintainerBatchWork($run);

            return false;
        }

        $run->increment('maintainer_decision_attempt_count');

        if (($run->fresh() ?? $run)->isTerminal()) {
            return false;
        }

        $prepared = $this->maintainerDecisionService->preparePersistedCandidateBatchesForDocument(
            $run->customer_id,
            $run->source_id,
            $this->resolveLanguageCode($run->customer_id),
            $this->buildAiCallContext($run),
        );

        if ($prepared !== null) {
            if (($run->fresh() ?? $run)->isTerminal()) {
                return false;
            }

            if ($prepared['batches'] === []) {
                $document = EnterpriseWikiDocument::query()
                    ->where('customer_id', $run->customer_id)
                    ->findOrFail($run->source_id);
                $decision = $this->maintainerDecisionService->validateAndRepairForDocument(
                    $run->customer_id,
                    $document,
                    $this->resolveLanguageCode($run->customer_id),
                    $this->maintainerDecisionService->mergePersistedCandidateBatchResults($prepared['global_plan'], []),
                    $this->buildAiCallContext($run),
                );
                $this->persistMaintainerDecision($run, $decision);

                return true;
            }

            $this->maintainerBatchStateService->createBatches($run->id, $prepared['batches']);
            $this->dispatchMaintainerBatchWork($run);

            Log::info('[WIKI_DOCUMENT_FLOW] Persisted maintainer candidate batches dispatched.', [
                'run_id' => $run->id,
                'batch_count' => count($prepared['batches']),
            ]);

            return false;
        }

        if (($run->fresh() ?? $run)->isTerminal()) {
            return false;
        }

        $decision = $this->maintainerDecisionService->runForDocument(
            $run->customer_id,
            $run->source_id,
            $this->resolveLanguageCode($run->customer_id),
            $this->buildAiCallContext($run),
        );

        $this->persistMaintainerDecision($run, $decision);

        Log::info('[WIKI_DOCUMENT_FLOW] Maintainer decision generated.', [
            'run_id' => $run->id,
            'source_id' => $run->source_id,
            'source_article_action' => data_get($decision, 'source_article.action'),
            'concept_pages' => count((array) data_get($decision, 'concept_pages', [])),
            'entity_pages' => count((array) data_get($decision, 'entity_pages', [])),
        ]);

        return true;
    }

    private function dispatchMaintainerBatchWork(EnterpriseWikiIngestRun $run): void
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return;
        }

        foreach ($this->maintainerBatchStateService->resumableBatchNumbers($run->id) as $batchNumber) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                return;
            }

            RunEnterpriseWikiMaintainerDecisionBatch::dispatch($run->id, $batchNumber);
        }

        if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
            return;
        }

        FinalizeEnterpriseWikiMaintainerDecisionBatches::dispatch($run->id)
            ->delay(now()->addSeconds(self::STEP_BUSY_RETRY_DELAY_SECONDS));
    }

    public function continueAfterMaintainerDecisionBatches(int $runId): void
    {
        $run = EnterpriseWikiIngestRun::query()->findOrFail($runId);
        if ($this->isWaitingOnHumanAction($run, 'continueAfterMaintainerDecisionBatches')) {
            return;
        }
        if ($run->isTerminal() || $run->maintainer_decision_generated_at === null) {
            return;
        }

        try {
            $this->performApplyMaintainerDecision($run);
            $this->beginGeneratingPages($run);
        } catch (Throwable $exception) {
            $this->markRunFailed($run->fresh() ?? $run, $exception, false);

            throw $exception;
        }
    }

    private function isWaitingOnHumanAction(EnterpriseWikiIngestRun $run, string $entryPoint): bool
    {
        if (! $run->isAwaitingHumanAction()) {
            return false;
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Run awaiting human action — automatic continuation skipped.', [
            'run_id' => $run->id,
            'entry_point' => $entryPoint,
            'status' => $run->status,
            'qa_status' => $run->qa_status,
        ]);

        return true;
    }

    public function markMaintainerDecisionFailed(int $runId, Throwable $exception): void
    {
        $run = EnterpriseWikiIngestRun::query()->find($runId);
        if ($run instanceof EnterpriseWikiIngestRun && ! $run->isTerminal()) {
            $this->markRunFailed($run, $exception, false, EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION);
        }
    }

    /** @param array<string,mixed> $decision */
    public function persistMaintainerDecision(EnterpriseWikiIngestRun $run, array $decision): bool
    {
        return DB::transaction(function () use ($run, $decision): bool {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->isTerminal() || $locked->maintainer_decision_generated_at !== null) {
                return false;
            }
            $locked->update(['maintainer_decision_json' => $decision, 'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING, 'maintainer_decision_generated_at' => now()]);

            return true;
        });
    }

    /**
     * Wiki run-592: how much of RunEnterpriseWikiDocumentFlow's own queue-job timeout is left
     * right now, so EnterpriseWikiAiRequestTimeoutPolicy never resolves an AI-call timeout that
     * could push the whole job past its own configured ceiling. Null when $run->started_at is
     * unknown (should not normally happen for an in-flight run) — the policy then falls back to
     * its configured range without a job-budget clamp.
     */
    private function buildAiCallContext(EnterpriseWikiIngestRun $run): AiCallContext
    {
        $remainingJobBudgetSeconds = $run->started_at !== null
            ? max(0, RunEnterpriseWikiDocumentFlow::TIMEOUT_SECONDS - now()->diffInSeconds($run->started_at))
            : null;

        return new AiCallContext(
            runId: $run->id,
            documentId: $run->source_id,
            remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
        );
    }

    private function performApplyMaintainerDecision(EnterpriseWikiIngestRun $run): void
    {
        $updated = EnterpriseWikiIngestRun::query()
            ->whereKey($run->id)
            ->nonTerminal()
            ->update(['status' => EnterpriseWikiIngestRun::STATUS_APPLYING]);

        if ($updated === 0) {
            return;
        }

        $result = $this->maintainerDecisionApplyService->apply($run->fresh() ?? $run);

        $run->refresh();

        Log::info('[WIKI_DOCUMENT_FLOW] Maintainer decision applied.', [
            'run_id' => $run->id,
            'created_pages' => $result['created'] ?? null,
            'updated_pages' => $result['updated'] ?? null,
            // Fase 8K-2: existing pages this decision patches. They intentionally get no pivot row
            // and no generation — patch application is 8K-3.
            'patch_targets_deferred' => $result['patch_targets_deferred'] ?? 0,
        ]);
    }

    /**
     * Dispatch the initial applied-page generation wave: article, summary, and concept pages
     * whose maintainer-plan entry already contains explicit owned_topics. Concept pages without
     * that scoped responsibility, and entity pages, still wait for the deferred concept/entity
     * phase so they can use finished article/summary context.
     *
     * Article dispatched before summary (best effort, not a hard guarantee under a
     * multi-worker queue): EnterpriseWikiGenerateAppliedPagesService::buildArticleSummaryContextForRun()
     * has the summary page read the article's finished content_markdown as context when it is
     * already available, so the summary condenses the actual article instead of independently
     * re-deriving from the raw source — this ordering maximizes the chance that content exists in
     * time, with a graceful, correctness-preserving fallback to the raw source when it does not.
     */
    private function beginGeneratingPages(EnterpriseWikiIngestRun $run): void
    {
        $updated = EnterpriseWikiIngestRun::query()
            ->whereKey($run->id)
            ->nonTerminal()
            ->update(['status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES]);

        if ($updated === 0) {
            return;
        }

        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $initialRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereNull('generated_page_version_id')
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', self::INITIAL_PAGE_GENERATION_TYPES))
            ->with('page')
            ->get()
            ->filter(fn (EnterpriseWikiIngestRunPage $row): bool => $this->isInitialPageGenerationWavePage($row->page, $decisionJson))
            ->sortBy(fn (EnterpriseWikiIngestRunPage $row): int => $this->initialPageGenerationSortOrder($row->page))
            ->values();

        $pageIds = $initialRows->pluck('enterprise_wiki_page_id');
        $dispatched = 0;

        foreach ($pageIds as $pageId) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                return;
            }

            if ($this->generateAppliedPagesService->reservePageForDispatch($run->id, $pageId)) {
                GenerateEnterpriseWikiAppliedPage::dispatch($run->id, $pageId);
                $dispatched++;
            }
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Initial page generation jobs dispatched.', [
            'run_id' => $run->id,
            'pages_considered' => $pageIds->count(),
            'pages_dispatched' => $dispatched,
            'article_summary_pages' => $initialRows
                ->filter(fn (EnterpriseWikiIngestRunPage $row): bool => $row->page !== null && in_array($row->page->page_type, self::ARTICLE_SUMMARY_PAGE_TYPES, true))
                ->count(),
            'independent_concept_pages' => $initialRows
                ->filter(fn (EnterpriseWikiIngestRunPage $row): bool => $row->page?->page_type === EnterpriseWikiPage::PAGE_TYPE_CONCEPT)
                ->count(),
        ]);

        // Safety net for the case where every initial-wave page already has a version
        // (e.g. a resumed run): no page job will fire to trigger the phase check, so trigger
        // it once here. This is a cheap no-op if pages are still pending.
        FinalizeEnterpriseWikiPageGeneration::dispatch($run->id);

        if ($pageIds->isNotEmpty()) {
            // Crash-recovery sentinel: catches a page whose dispatch-CAS committed but whose
            // Redis enqueue was lost, or a worker that crashed mid-generation — see
            // FinalizeEnterpriseWikiPageGeneration::attemptStalePageRecovery().
            FinalizeEnterpriseWikiPageGeneration::dispatch($run->id, true)
                ->delay(now()->addSeconds(self::PAGE_GENERATION_CRASH_RECOVERY_DELAY_SECONDS));
        }
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function isInitialPageGenerationWavePage(?EnterpriseWikiPage $page, array $decisionJson): bool
    {
        if (! $page instanceof EnterpriseWikiPage) {
            return false;
        }

        return match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => true,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $this->conceptCanGenerateWithoutArticleSummaryContext($page, $decisionJson),
            default => false,
        };
    }

    private function initialPageGenerationSortOrder(?EnterpriseWikiPage $page): int
    {
        return match ($page?->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 0,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => 1,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => 2,
            default => 3,
        };
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     */
    private function conceptCanGenerateWithoutArticleSummaryContext(EnterpriseWikiPage $page, array $decisionJson): bool
    {
        if ($page->page_type !== EnterpriseWikiPage::PAGE_TYPE_CONCEPT) {
            return false;
        }

        $entry = $this->conceptDecisionEntry($page, $decisionJson);

        return $entry !== null && EnterpriseWikiMaintainerDecisionPrompt::ownedTopicNames($entry['owned_topics'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $decisionJson
     * @return array<string, mixed>|null
     */
    private function conceptDecisionEntry(EnterpriseWikiPage $page, array $decisionJson): ?array
    {
        foreach ((array) data_get($decisionJson, 'concept_pages', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['title'] ?? null) === $page->title) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function nonEmptyStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item): string => trim((string) $item), $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Fase 8K-3: apply this run's validated patch_targets to the existing pages they name.
     *
     * A patch failure is reported but does NOT fail the run: each patch target is independent of the
     * run's own generated pages, and of the other targets. Failing the whole run because one section
     * could not be located would throw away correctly generated pages, while silently retrying it as
     * a full regeneration is exactly the destructive behaviour Fase 8K removes. The failure is logged
     * per page with its concrete reason, the page keeps its existing current version untouched, and
     * detecting the resulting still-stale substance is 8K-4's job.
     */
    private function performApplyPatchTargets(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->patchApplicationService->applyForRun($run->fresh() ?? $run);

        if ($result['pages_patched'] === 0 && $result['pages_skipped'] === 0 && $result['failures'] === []) {
            return;
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Patch targets applied.', [
            'run_id' => $run->id,
            'pages_patched' => $result['pages_patched'],
            'pages_skipped' => $result['pages_skipped'],
            'targets_applied' => $result['targets_applied'],
            'failures' => count($result['failures']),
        ]);

        if ($result['failures'] !== []) {
            Log::error('[WIKI_DOCUMENT_FLOW] Some patch targets could not be applied — existing versions left untouched.', [
                'run_id' => $run->id,
                'failures' => $result['failures'],
            ]);
        }
    }

    /**
     * Reconcile high-confidence dependent current assertions before the normal content passes and
     * final detection-only consistency check. The reconciler can only emit targets seeded by this
     * run's already-authorised replacements and still uses the normal resolver/patch engine.
     */
    private function performCrossPageReconciliation(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->crossPageReconciliationService->reconcileForRun($run->fresh() ?? $run);

        if ($result['discovered'] === 0 && $result['unresolved'] === 0) {
            return;
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Cross-page current-state reconciliation completed.', [
            'run_id' => $run->id,
            'discovered' => $result['discovered'],
            'validated' => $result['validated'],
            'rejected' => $result['rejected'],
            'unresolved' => $result['unresolved'],
            'pages_patched' => $result['pages_patched'],
            'targets_applied' => $result['targets_applied'],
            'failures' => count($result['failures']),
        ]);

        if ($result['failures'] !== []) {
            Log::error('[WIKI_DOCUMENT_FLOW] Some derived cross-page targets could not be applied — final QA remains strict.', [
                'run_id' => $run->id,
                'failures' => $result['failures'],
            ]);
        }
    }

    /**
     * Parse every applied page's current content_markdown for inline [[wikilinks]] and
     * materialize the canonical link_type=wikilink EnterpriseWikiPageLink rows. Runs
     * before claims/verification/lint so those stages, and later backlinks/graph reads,
     * see relations derived from the actual final page content of this run.
     */
    private function performMaterializeWikilinks(EnterpriseWikiIngestRun $run): void
    {
        $this->markVerificationStage($run);

        $result = $this->buildPageLinksService->materializeWikilinksForRun($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Wikilinks materialized.', [
            'run_id' => $run->id,
            'pages_processed' => $result['pages_processed'] ?? null,
            'occurrences_found' => $result['occurrences_found'] ?? null,
            'valid_links' => $result['valid_links'] ?? null,
            'broken_slugs' => $result['broken_slugs'] ?? null,
            'self_links' => $result['self_links'] ?? null,
            'created' => $result['created'] ?? null,
            'updated' => $result['updated'] ?? null,
            'stale_links_removed' => $result['stale_links_removed'] ?? null,
        ]);
    }

    /**
     * When this run created or updated a concept/entity page, relink any existing customer
     * pages (outside this run) that plausibly already discuss it. Runs after this run's own
     * wikilinks are materialized so backlinks/traversal/graph immediately reflect any new
     * cross-page link, and before claims/lint/QA so those stages see the final link graph.
     */
    private function performIncrementalRelinking(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->incrementalRelinkService->relinkForRun($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Incremental relinking completed.', [
            'run_id' => $run->id,
            'triggers_processed' => $result['triggers_processed'] ?? null,
            'candidates_considered' => $result['candidates_considered'] ?? null,
            'applied' => $result['applied'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'failed' => $result['failed'] ?? null,
        ]);
    }

    /**
     * @return bool true when extraction is done (nothing left actively leased elsewhere) and
     *              the flow may proceed; false when at least one page's extraction lease is
     *              held by another live worker — a deferred retry has been dispatched and the
     *              caller must stop without proceeding or marking the run failed.
     */
    private function performExtractPageClaims(EnterpriseWikiIngestRun $run): bool
    {
        $this->markVerificationStage($run);

        $result = $this->extractPageClaimsService->extract($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Page claims extracted.', [
            'run_id' => $run->id,
            'pages' => $result['pages'] ?? null,
            'claims' => $result['claims'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'busy' => $result['busy'] ?? 0,
            'capped_pages' => $result['capped_pages'] ?? 0,
        ]);

        if (($result['busy'] ?? 0) > 0) {
            Log::info('[WIKI_DOCUMENT_FLOW] Claim extraction busy — continuation deferred.', [
                'run_id' => $run->id,
                'busy' => $result['busy'],
            ]);

            ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id)
                ->delay(now()->addSeconds(self::STEP_BUSY_RETRY_DELAY_SECONDS));

            return false;
        }

        return true;
    }

    private function beginClaimVerification(EnterpriseWikiIngestRun $run): void
    {
        $run = DB::transaction(function () use ($run): ?EnterpriseWikiIngestRun {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $locked instanceof EnterpriseWikiIngestRun || $locked->isTerminal()) {
                return null;
            }

            $locked->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

            return $locked->fresh() ?? $locked;
        });

        if (! $run instanceof EnterpriseWikiIngestRun) {
            return;
        }

        $this->dispatchClaimVerificationWork($run);
    }

    /**
     * Dispatches a VerifyEnterpriseWikiClaim job for each currently unverified claim — but only
     * the ones that atomically win EnterpriseWikiVerifyPageClaimsService::reserveClaimForDispatch()
     * right now. That single per-claim compare-and-swap is what makes this method safe to call
     * both for the very first dispatch (every claim wins, since none has a
     * verification_dispatched_at yet) and for a recovery pass (only genuinely stale/never-
     * dispatched claims win — one still legitimately queued or actively leased loses the CAS and
     * is silently left alone). Two dispatchers calling this concurrently for the same run can
     * never enqueue the same claim twice, because the CAS is a single atomic UPDATE at the
     * database level.
     *
     * Crash window: if the process dies after reserveClaimForDispatch() commits but before
     * VerifyEnterpriseWikiClaim::dispatch() reaches Redis, the claim is left marked "dispatched"
     * with no job actually queued. This is by design left unrecovered until
     * EnterpriseWikiVerifyPageClaimsService::DISPATCH_STALE_SECONDS elapses — the very next
     * recovery sentinel pass then finds the same claim still unverified, still not leased, and
     * now past the dispatch-stale window, so it wins the CAS again and gets a real job dispatched.
     */
    private function dispatchClaimVerificationWork(EnterpriseWikiIngestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        if ($fresh->isTerminal() || $fresh->status !== EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS) {
            return;
        }

        $claimIds = $this->verifyPageClaimsService->unverifiedClaimIdsForRun($fresh);
        $dispatched = 0;

        foreach ($claimIds as $claimId) {
            if ($this->verifyPageClaimsService->reserveClaimForDispatch($claimId)) {
                VerifyEnterpriseWikiClaim::dispatch($fresh->id, $claimId);
                $dispatched++;
            }
        }

        if ($claimIds === []) {
            $this->continueAfterClaimVerification($fresh->id);

            Log::info('[WIKI_DOCUMENT_FLOW] Claim verification skipped — no unverified claims.', [
                'run_id' => $fresh->id,
            ]);

            return;
        }

        $this->dispatchClaimVerificationRecoveryFallback($fresh);

        Log::info('[WIKI_DOCUMENT_FLOW] Claim verification jobs dispatched.', [
            'run_id' => $fresh->id,
            'claims_considered' => count($claimIds),
            'claims_dispatched' => $dispatched,
            'recovery_fallback_delay_seconds' => self::CLAIM_VERIFICATION_CRASH_RECOVERY_DELAY_SECONDS,
            'queue' => VerifyEnterpriseWikiClaim::QUEUE,
        ]);
    }

    private function dispatchClaimVerificationRecoveryFallback(EnterpriseWikiIngestRun $run): void
    {
        FinalizeEnterpriseWikiClaimVerification::dispatch($run->id, true)
            ->delay(now()->addSeconds(self::CLAIM_VERIFICATION_CRASH_RECOVERY_DELAY_SECONDS));
    }

    private function performAppliedRunLint(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->appliedRunLintService->lint($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Applied run lint completed.', [
            'run_id' => $run->id,
            'errors' => $result['errors'] ?? null,
            'warnings' => $result['warnings'] ?? null,
            'info' => $result['info'] ?? null,
            'findings_created' => $result['findings_created'] ?? null,
        ]);
    }

    /**
     * Fase 8K-4 — post-patch cross-page current-state consistency. Detection only: it writes lint
     * findings and never mutates a page, so a failure here must not fail an otherwise sound run.
     * The findings themselves are what QA acts on.
     */
    private function performCrossPageConsistencyCheck(EnterpriseWikiIngestRun $run): void
    {
        try {
            $result = $this->crossPageConsistencyService->checkForRun($run->fresh() ?? $run);
        } catch (Throwable $e) {
            Log::error('[WIKI_DOCUMENT_FLOW] Cross-page consistency check failed — run continues.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Cross-page consistency check completed.', [
            'run_id' => $run->id,
            'assertions' => $result['assertions'],
            'pages_considered' => $result['pages_considered'],
            'occurrences' => $result['occurrences'],
            'findings_created' => $result['findings_created'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * Semantic QA and repair of this run's pages' inline wikilinks (8I-6): catches what
     * deterministic lint cannot — a central concept mentioned but never linked, a misleading
     * anchor, or a wrongly-targeted link. Runs after the deterministic lint pass so any repair
     * this makes is immediately reflected by the re-lint it triggers internally, and before
     * post-ingest QA so the final content is what gets QA-reviewed.
     */
    private function performLinkSemanticRepair(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->linkSemanticRepairService->repairForRun($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Link semantic QA/repair completed.', [
            'run_id' => $run->id,
            'pages_reviewed' => $result['pages_reviewed'] ?? null,
            'applied' => $result['applied'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'failed' => $result['failed'] ?? null,
        ]);
    }

    /**
     * Runs (or recognizes the outcome of) post-ingest QA for this run's first, ordinary-flow
     * attempt. Returns true when the flow may proceed to finalizeFromExistingQaResult() — either
     * because this call claimed and ran QA itself, or because QA was already terminally
     * completed by someone else (the scheduled QA sweep winning a race against this
     * continuation, in particular). Returns false when QA is busy/in-progress elsewhere and
     * not yet terminal: a deferred retry of this same continuation job has been dispatched,
     * and the caller must stop without finalizing or marking the run failed.
     *
     * Never throws for a "did not claim" outcome — see the run-24 race this replaces: the
     * scheduled `wiki:run-post-ingest-qa --all-pending` sweep claiming a run that was still
     * mid continueAfterPagesGenerated() and racing this exact call.
     */
    private function performPostIngestQa(EnterpriseWikiIngestRun $run): bool
    {
        $run = DB::transaction(function () use ($run): ?EnterpriseWikiIngestRun {
            $locked = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $locked instanceof EnterpriseWikiIngestRun || $locked->isTerminal()) {
                return null;
            }

            $locked->update(['status' => EnterpriseWikiIngestRun::STATUS_QA]);

            return $locked->fresh() ?? $locked;
        });

        if (! $run instanceof EnterpriseWikiIngestRun) {
            return false;
        }

        $result = $this->postIngestQaService->runForRun($run->fresh() ?? $run);

        if ($result !== null) {
            $qaStatus = $run->fresh()?->qa_status;

            Log::info('[WIKI_DOCUMENT_FLOW] Post-ingest QA claimed by continuation.', [
                'run_id' => $run->id,
                'qa_status' => $qaStatus,
                'claim_result' => 'claimed',
                'caller' => 'continuation',
            ]);

            Log::info('[WIKI_DOCUMENT_FLOW] Post-ingest QA completed.', [
                'run_id' => $run->id,
                'qa_status' => $qaStatus,
            ]);

            return true;
        }

        $fresh = $run->fresh();

        if ($fresh === null) {
            throw new InvalidArgumentException("Run [{$run->id}] disappeared during post-ingest QA.");
        }

        $terminalQaStatuses = [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
        ];

        if (in_array($fresh->qa_status, $terminalQaStatuses, true)) {
            // Someone else — most likely the scheduled QA sweep — already ran QA to a
            // terminal outcome for this run. Do not attempt a new QA run, do not create a
            // duplicate snapshot: proceed straight to finalization using that result.
            Log::info('[WIKI_DOCUMENT_FLOW] Post-ingest QA already completed.', [
                'run_id' => $run->id,
                'status' => $fresh->status,
                'qa_status' => $fresh->qa_status,
                'qa_attempt_count' => $fresh->qa_attempt_count,
                'claim_result' => 'already_completed',
                'caller' => 'continuation',
            ]);

            return true;
        }

        // qa_status is null/pending/running/repair_required but the atomic claim still
        // failed: another worker (the scheduler, or a concurrent continuation dispatch) is
        // actively running QA for this run right now. This is a genuine busy/concurrent
        // state, not a failure — defer via the existing queue/retry pattern rather than
        // polling in-process or assuming any outcome.
        Log::info('[WIKI_DOCUMENT_FLOW] Post-ingest QA busy — continuation deferred.', [
            'run_id' => $run->id,
            'status' => $fresh->status,
            'qa_status' => $fresh->qa_status,
            'qa_attempt_count' => $fresh->qa_attempt_count,
            'claim_result' => 'busy',
            'caller' => 'continuation',
        ]);

        ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id)
            ->delay(now()->addSeconds(self::STEP_BUSY_RETRY_DELAY_SECONDS));

        return false;
    }

    /**
     * Finalize a run purely from its already-recorded terminal qa_status (passed/escalated/
     * failed) — no stage re-execution, no AI calls. Used both by the ordinary continuation
     * flow above and by wiki:recover-document-flow's direct-finalize path, which calls this
     * after independently validating the QA snapshot and required artifacts still hold.
     *
     * v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): claims and their QA
     * review are a voluntary, non-blocking quality loop, never a completion gate.
     * EnterpriseWikiPostIngestQaService::evaluate() no longer produces QA_STATUS_REPAIR_REQUIRED
     * for any new evaluation — a technically sound run always reaches qa_status=passed regardless
     * of open claim QA signals. The REPAIR_REQUIRED arm below is kept only so a historical run
     * already recorded with that status (before this change) is treated exactly like a passed
     * run — its claim QA signals remain fully visible and actionable in the voluntary QA screen,
     * they just no longer stop the run from completing/being used.
     */
    public function finalizeFromExistingQaResult(EnterpriseWikiIngestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        match ($fresh->qa_status) {
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED => $this->completeRunIfOwnerApproved($fresh),
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED => $this->escalateRun($fresh),
            EnterpriseWikiIngestRun::QA_STATUS_FAILED => $this->markRunFailed(
                $fresh,
                new InvalidArgumentException($fresh->qa_last_error ?: 'Post-ingest QA failed.'),
                true,
            ),
            default => $this->markRunFailed(
                $fresh,
                new InvalidArgumentException('Post-ingest QA did not reach a terminal state.'),
                true,
            ),
        };
    }

    /**
     * Re-evaluate the owner-approval gate for a run after document ownership changes.
     *
     * Guarded on qa_status being technically sound (passed, or the legacy repair_required value
     * — see finalizeFromExistingQaResult()): Document Owner approval exists to gate legitimate,
     * source-backed content — it must never complete a run whose own technical QA genuinely
     * failed or is still escalated. It is NOT guarded on claim QA signals: since v0.10
     * (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"), claims and their QA review
     * are a voluntary, non-blocking quality loop, and
     * EnterpriseWikiDocumentOwnerApprovalService always builds its approval requirements from
     * claims' actual source references regardless of open claim QA signals — so this gate can no
     * longer look "vacuously ready" the way it could before that change. This is called both from
     * the ordinary QA-passed path (completeRunIfOwnerApproved(), where the guard is always
     * satisfied) and from syncDocumentOwnerApprovals() after a document owner changes, which has
     * no QA context of its own.
     */
    public function reconcileRunDocumentOwnerApprovalState(EnterpriseWikiIngestRun $run): void
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return;
        }

        if (! in_array($run->qa_status, [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ], true)) {
            return;
        }

        $gate = $this->documentOwnerApprovalService->evaluateRunCompletionGate($run);

        if (! $gate['ready']) {
            EnterpriseWikiIngestRun::query()->whereKey($run->id)->nonTerminal()->update([
                'status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
                'finished_at' => null,
                'error_message' => mb_substr((string) ($gate['message'] ?? 'Avventer godkjenning fra Dokumenteier.'), 0, 1000),
                'failed_phase' => null,
                'transient_failure' => null,
            ]);

            Log::info('[WIKI_DOCUMENT_FLOW] Run awaiting document owner approval after owner sync.', [
                'run_id' => $run->id,
                'pending' => count($gate['pending'] ?? []),
                'rejected' => count($gate['rejected'] ?? []),
                'missing_owner' => count($gate['missing_owner'] ?? []),
            ]);

            return;
        }

        $this->completeRun($run);
    }

    private function completeRunIfOwnerApproved(EnterpriseWikiIngestRun $run): void
    {
        $this->reconcileRunDocumentOwnerApprovalState($run);
    }

    private function markVerificationStage(EnterpriseWikiIngestRun $run): void
    {
        EnterpriseWikiIngestRun::query()
            ->whereKey($run->id)
            ->nonTerminal()
            ->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);
    }

    private function completeRun(EnterpriseWikiIngestRun $run): void
    {
        $updated = EnterpriseWikiIngestRun::query()->whereKey($run->id)->nonTerminal()->update([
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'error_message' => null,
            'failed_phase' => null,
            'transient_failure' => null,
        ]);

        if ($updated > 0) {
            Log::info('[WIKI_DOCUMENT_FLOW] Run completed.', [
                'run_id' => $run->id,
            ]);
        }
    }

    private function escalateRun(EnterpriseWikiIngestRun $run): void
    {
        $updated = EnterpriseWikiIngestRun::query()->whereKey($run->id)->nonTerminal()->update([
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'finished_at' => now(),
            'error_message' => null,
            'failed_phase' => null,
            'transient_failure' => null,
        ]);

        if ($updated > 0) {
            Log::info('[WIKI_DOCUMENT_FLOW] Run escalated.', [
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Marks the run's execution status failed. Run execution status (`status`) and semantic
     * QA result (`qa_status`/`qa_result`/`qa_last_error`, and the QA snapshot they're backed
     * by) are two distinct, orthogonal states — an exception in the continuation pipeline
     * means the ORCHESTRATION failed, not that QA itself produced a bad verdict.
     *
     * If `qa_status` already holds a terminal, legitimate result (passed/escalated/failed —
     * set by this run's own real QA execution, e.g. by the scheduler winning a claim race
     * before this exception was thrown) it is left completely untouched: `qa_completed_at`,
     * `qa_last_error`, and the QA snapshot already recorded for it are not overwritten. This
     * is exactly the run-24 defect: a genuine `qa_status=passed` was clobbered to `failed`
     * because the exception happened while `$currentStage === STATUS_QA`, even though QA had
     * already legitimately completed under a different worker.
     */
    private function markRunFailed(EnterpriseWikiIngestRun $run, Throwable $exception, bool $qaContext = false, ?string $phase = null): void
    {
        // Capture before update() mutates $run->status to 'failed' on this same instance.
        $phase ??= $run->status;

        $qaAlreadyTerminal = in_array($run->qa_status, [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
        ], true);

        $update = [
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            // Wiki run-588: the phase the run was actually in when it failed — persisted
            // separately from the generic terminal 'status' above so the run list can show
            // exactly which step failed (and which later steps never ran) instead of the
            // "everything but the last step looks done" fallback that shipped before this.
            // Only ever a known EnterpriseWikiIngestRun::FAILED_PHASES value — never free text.
            'failed_phase' => EnterpriseWikiIngestRun::isValidFailedPhase($phase) ? $phase : null,
            // Wiki run-592: whether this failure is a documented-transient HTTP/network condition
            // (EnterpriseWikiTransientFailureClassifier) — the single field
            // EnterpriseWikiMaintainerDecisionFailureRecoveryService checks before allowing the
            // user-triggered "Prøv beslutningsfasen på nytt" action to resume the SAME run.
            'transient_failure' => EnterpriseWikiTransientFailureClassifier::isTransient($exception->getMessage()),
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ];

        if ($qaContext && ! $qaAlreadyTerminal) {
            $update['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_FAILED;
            $update['qa_completed_at'] = now();
            $update['qa_last_error'] = mb_substr($exception->getMessage(), 0, 1000);
        }

        $updated = EnterpriseWikiIngestRun::query()
            ->whereKey($run->id)
            ->nonTerminal()
            ->update($update);

        if ($updated === 0) {
            return;
        }

        Log::error('[WIKI_DOCUMENT_FLOW] Run failed.', [
            'run_id' => $run->id,
            'qa_context' => $qaContext,
            'qa_status_preserved' => $qaContext && $qaAlreadyTerminal,
            'phase' => $phase,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
