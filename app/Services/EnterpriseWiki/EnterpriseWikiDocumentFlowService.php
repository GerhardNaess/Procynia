<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
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
     * post-ingest QA claimed elsewhere but not yet terminal, or a claim extraction/verification
     * lease is actively held by another live worker — short enough that a run doesn't sit idle
     * for long, long enough not to hammer the same busy row/claim in a tight loop.
     */
    private const STEP_BUSY_RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private readonly EnterpriseWikiIngestService $ingestService,
        private readonly EnterpriseWikiMaintainerDecisionService $maintainerDecisionService,
        private readonly EnterpriseWikiMaintainerDecisionApplyService $maintainerDecisionApplyService,
        private readonly EnterpriseWikiExtractPageClaimsService $extractPageClaimsService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyPageClaimsService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiIncrementalRelinkService $incrementalRelinkService,
    private readonly EnterpriseWikiAppliedRunLintService $appliedRunLintService,
    private readonly EnterpriseWikiLinkSemanticRepairService $linkSemanticRepairService,
    private readonly EnterpriseWikiPostIngestQaService $postIngestQaService,
    private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
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
            $this->performMaintainerDecision($run);
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

        $currentStage = EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING;

        try {
            $this->performMaterializeWikilinks($run);
            $this->performIncrementalRelinking($run);

            if (! $this->performExtractPageClaims($run)) {
                // Another live worker holds an active reservation on at least one page's
                // claim extraction — a deferred retry of this same continuation job has
                // already been dispatched. Do not proceed to verification/lint/QA, and do
                // not mark the run failed: this is an expected concurrent state.
                return;
            }

            if (! $this->performVerifyPageClaims($run)) {
                // Same reasoning as above, for an actively-held claim verification lease.
                return;
            }

            $this->performAppliedRunLint($run);
            $this->performLinkSemanticRepair($run);

            $currentStage = EnterpriseWikiIngestRun::STATUS_QA;

            if (! $this->performPostIngestQa($run)) {
                // Busy/concurrent state, not a failure — a deferred retry of this same
                // continuation job has already been dispatched. Leave the run exactly where
                // it is; do not finalize, do not mark it failed.
                return;
            }

            $currentStage = 'finalizing';

            $fresh = $run->fresh() ?? $run;

            // Defense in depth: a concurrent invocation may already have finalized this run
            // between this run's own performPostIngestQa() call above and this point.
            if ($fresh->isTerminal()) {
                return;
            }

            $this->finalizeFromExistingQaResult($fresh);
        } catch (Throwable $e) {
            $this->markRunFailed($run->fresh() ?? $run, $e, $currentStage === EnterpriseWikiIngestRun::STATUS_QA, $currentStage);

            throw $e;
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

            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
                'started_at' => now(),
                'finished_at' => null,
                'error_message' => null,
            ]);

            return $run->fresh();
        });
    }

    private function performMaintainerDecision(EnterpriseWikiIngestRun $run): void
    {
        $decision = $this->maintainerDecisionService->runForDocument(
            $run->customer_id,
            $run->source_id,
            $this->resolveLanguageCode($run->customer_id),
        );

        $run->update([
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);

        Log::info('[WIKI_DOCUMENT_FLOW] Maintainer decision generated.', [
            'run_id' => $run->id,
            'source_id' => $run->source_id,
            'source_article_action' => data_get($decision, 'source_article.action'),
            'concept_pages' => count((array) data_get($decision, 'concept_pages', [])),
            'entity_pages' => count((array) data_get($decision, 'entity_pages', [])),
        ]);
    }

    private function performApplyMaintainerDecision(EnterpriseWikiIngestRun $run): void
    {
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_APPLYING]);

        $result = $this->maintainerDecisionApplyService->apply($run->fresh() ?? $run);

        $run->refresh();

        Log::info('[WIKI_DOCUMENT_FLOW] Maintainer decision applied.', [
            'run_id' => $run->id,
            'created_pages' => $result['created'] ?? null,
            'updated_pages' => $result['updated'] ?? null,
        ]);
    }

    /**
     * Dispatch phase 1 of applied page generation: article and summary pages only.
     * Concept and entity pages are deliberately NOT dispatched here — they read the
     * finished article/summary content as context, so FinalizeEnterpriseWikiPageGeneration
     * dispatches them only once every article/summary job has completed successfully.
     */
    private function beginGeneratingPages(EnterpriseWikiIngestRun $run): void
    {
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES]);

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereNull('generated_page_version_id')
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', [
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            ]))
            ->pluck('enterprise_wiki_page_id');

        foreach ($pageIds as $pageId) {
            GenerateEnterpriseWikiAppliedPage::dispatch($run->id, $pageId);
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Article/summary page generation jobs dispatched.', [
            'run_id' => $run->id,
            'pages_dispatched' => $pageIds->count(),
        ]);

        // Safety net for the case where every article/summary page already has a version
        // (e.g. a resumed run): no page job will fire to trigger the phase check, so trigger
        // it once here. This is a cheap no-op if pages are still pending.
        FinalizeEnterpriseWikiPageGeneration::dispatch($run->id);
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

    /**
     * @return bool true when verification is done and the flow may proceed; false when at
     *              least one claim's verification lease is held by another live worker — a
     *              deferred retry has been dispatched and the caller must stop.
     */
    private function performVerifyPageClaims(EnterpriseWikiIngestRun $run): bool
    {
        $result = $this->verifyPageClaimsService->verify($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Page claims verified.', [
            'run_id' => $run->id,
            'pages' => $result['pages'] ?? null,
            'claims' => $result['claims'] ?? null,
            'references' => $result['references'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'no_support' => $result['no_support'] ?? null,
            'busy' => $result['busy'] ?? 0,
        ]);

        if (($result['busy'] ?? 0) > 0) {
            Log::info('[WIKI_DOCUMENT_FLOW] Claim verification busy — continuation deferred.', [
                'run_id' => $run->id,
                'busy' => $result['busy'],
            ]);

            ContinueEnterpriseWikiDocumentFlowAfterPages::dispatch($run->id)
                ->delay(now()->addSeconds(self::STEP_BUSY_RETRY_DELAY_SECONDS));

            return false;
        }

        return true;
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
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_QA]);

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
     */
    public function finalizeFromExistingQaResult(EnterpriseWikiIngestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        match ($fresh->qa_status) {
            EnterpriseWikiIngestRun::QA_STATUS_PASSED => $this->completeRunIfOwnerApproved($fresh),
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

    private function completeRunIfOwnerApproved(EnterpriseWikiIngestRun $run): void
    {
        $gate = $this->documentOwnerApprovalService->evaluateRunCompletionGate($run);

        if (! $gate['ready']) {
            $run->update([
                'status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
                'error_message' => mb_substr((string) ($gate['message'] ?? 'Avventer godkjenning fra Dokumenteier.'), 0, 1000),
            ]);

            Log::info('[WIKI_DOCUMENT_FLOW] Run awaiting document owner approval.', [
                'run_id' => $run->id,
                'pending' => count($gate['pending'] ?? []),
                'rejected' => count($gate['rejected'] ?? []),
                'missing_owner' => count($gate['missing_owner'] ?? []),
            ]);

            return;
        }

        $this->completeRun($run);
    }

    private function markVerificationStage(EnterpriseWikiIngestRun $run): void
    {
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);
    }

    private function completeRun(EnterpriseWikiIngestRun $run): void
    {
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'error_message' => null,
        ]);

        Log::info('[WIKI_DOCUMENT_FLOW] Run completed.', [
            'run_id' => $run->id,
        ]);
    }

    private function escalateRun(EnterpriseWikiIngestRun $run): void
    {
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'finished_at' => now(),
            'error_message' => null,
        ]);

        Log::info('[WIKI_DOCUMENT_FLOW] Run escalated.', [
            'run_id' => $run->id,
        ]);
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
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ];

        if ($qaContext && ! $qaAlreadyTerminal) {
            $update['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_FAILED;
            $update['qa_completed_at'] = now();
            $update['qa_last_error'] = mb_substr($exception->getMessage(), 0, 1000);
        }

        $run->update($update);

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
