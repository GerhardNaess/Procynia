<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
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
 */
class EnterpriseWikiDocumentFlowService
{
    public function __construct(
        private readonly EnterpriseWikiIngestService $ingestService,
        private readonly EnterpriseWikiMaintainerDecisionService $maintainerDecisionService,
        private readonly EnterpriseWikiMaintainerDecisionApplyService $maintainerDecisionApplyService,
        private readonly EnterpriseWikiGenerateAppliedPagesService $generateAppliedPagesService,
        private readonly EnterpriseWikiExtractPageClaimsService $extractPageClaimsService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyPageClaimsService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiAppliedRunLintService $appliedRunLintService,
        private readonly EnterpriseWikiPostIngestQaService $postIngestQaService,
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
     * Run the document flow for a queued ingest run.
     *
     * Duplicate dispatches are ignored by the atomic claim step at the top.
     */
    public function run(int $runId): void
    {
        $run = $this->claimRun($runId);

        if ($run === null) {
            return;
        }

        $currentStage = EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION;

        try {
            $this->performMaintainerDecision($run);

            $currentStage = EnterpriseWikiIngestRun::STATUS_APPLYING;
            $this->performApplyMaintainerDecision($run);

            $currentStage = EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES;
            $this->performGenerateAppliedPages($run);

            $currentStage = EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING;
            $this->performExtractPageClaims($run);
            $this->performVerifyPageClaims($run);
            $this->performBuildPageLinks($run);
            $this->performAppliedRunLint($run);

            $currentStage = EnterpriseWikiIngestRun::STATUS_QA;
            $this->performPostIngestQa($run);

            $currentStage = 'finalizing';
            $this->finalizeFromQaResult($run->fresh() ?? $run);
        } catch (Throwable $e) {
            $this->markRunFailed($run->fresh() ?? $run, $e, $currentStage === EnterpriseWikiIngestRun::STATUS_QA);

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

    private function performGenerateAppliedPages(EnterpriseWikiIngestRun $run): void
    {
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES]);

        $result = $this->generateAppliedPagesService->generate($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Applied pages generated.', [
            'run_id' => $run->id,
            'article' => $result['article'] ?? null,
            'summary' => $result['summary'] ?? null,
            'concept' => $result['concept'] ?? null,
            'entity' => $result['entity'] ?? null,
            'skipped' => $result['skipped'] ?? null,
        ]);
    }

    private function performExtractPageClaims(EnterpriseWikiIngestRun $run): void
    {
        $this->markVerificationStage($run);

        $result = $this->extractPageClaimsService->extract($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Page claims extracted.', [
            'run_id' => $run->id,
            'pages' => $result['pages'] ?? null,
            'claims' => $result['claims'] ?? null,
            'skipped' => $result['skipped'] ?? null,
        ]);
    }

    private function performVerifyPageClaims(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->verifyPageClaimsService->verify($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Page claims verified.', [
            'run_id' => $run->id,
            'pages' => $result['pages'] ?? null,
            'claims' => $result['claims'] ?? null,
            'references' => $result['references'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'no_support' => $result['no_support'] ?? null,
        ]);
    }

    private function performBuildPageLinks(EnterpriseWikiIngestRun $run): void
    {
        $result = $this->buildPageLinksService->build($run->fresh() ?? $run);

        Log::info('[WIKI_DOCUMENT_FLOW] Page links built.', [
            'run_id' => $run->id,
            'pages_checked' => $result['pages_checked'] ?? null,
            'links_created' => $result['links_created'] ?? null,
            'links_skipped' => $result['links_skipped'] ?? null,
            'missing_versions' => $result['missing_versions'] ?? null,
        ]);
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

    private function performPostIngestQa(EnterpriseWikiIngestRun $run): void
    {
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_QA]);

        $result = $this->postIngestQaService->runForRun($run->fresh() ?? $run);

        if ($result === null) {
            throw new InvalidArgumentException(
                "Post-ingest QA did not claim run [{$run->id}]."
            );
        }

        Log::info('[WIKI_DOCUMENT_FLOW] Post-ingest QA completed.', [
            'run_id' => $run->id,
            'qa_status' => $run->fresh()?->qa_status,
        ]);
    }

    private function finalizeFromQaResult(EnterpriseWikiIngestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        match ($fresh->qa_status) {
            EnterpriseWikiIngestRun::QA_STATUS_PASSED => $this->completeRun($fresh),
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

    private function markRunFailed(EnterpriseWikiIngestRun $run, Throwable $exception, bool $qaContext = false): void
    {
        $update = [
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            'finished_at' => now(),
        ];

        if ($qaContext) {
            $update['qa_status'] = EnterpriseWikiIngestRun::QA_STATUS_FAILED;
            $update['qa_completed_at'] = now();
            $update['qa_last_error'] = mb_substr($exception->getMessage(), 0, 1000);
        }

        $run->update($update);

        Log::error('[WIKI_DOCUMENT_FLOW] Run failed.', [
            'run_id' => $run->id,
            'qa_context' => $qaContext,
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
