<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a dry-run maintainer decision for a single wiki document.
 *
 * Fetches the document (customer-scoped), builds the page index, calls the AI
 * client, and returns the validated decision array. Nothing is written to the DB.
 *
 * After the AI decision passes schema validation, EnterpriseWikiMaintainerDecisionConsistencyValidator
 * checks it for logical self-contradictions (e.g. the run-581 "ITIL Incident Management" incident:
 * the article/summary pointed the reader onward to a concept that concept_pages never created).
 * When found, one bounded AI repair pass is attempted; if the repaired decision is still
 * inconsistent, this throws rather than silently applying an unresolved contradiction.
 */
class EnterpriseWikiMaintainerDecisionService
{
    public function __construct(
        private readonly EnterpriseWikiIndexContextService $indexContextService,
        private readonly EnterpriseWikiMaintainerDecisionAiClient $aiClient,
        private readonly EnterpriseWikiMaintainerDecisionConsistencyValidator $consistencyValidator,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
    ) {}

    /**
     * Run a maintainer decision for the given document, scoped to the customer.
     * No pages, versions, claims, or pivot rows are created.
     *
     * @return array<string, mixed> Validated, internally-consistent maintainer decision.
     *
     * @throws \InvalidArgumentException If the document is not found for this customer.
     * @throws \RuntimeException If the AI call fails.
     * @throws EnterpriseWikiMaintainerDecisionInconsistentException If the decision is still
     *                                                               logically inconsistent after one bounded repair pass.
     */
    public function runForDocument(
        int $customerId,
        int $documentId,
        string $languageCode = 'no',
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('id', $documentId)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] not found for customer [{$customerId}]."
            );
        }

        $sourceMeta = [
            'title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
            'filename' => (string) $document->original_filename,
        ];

        $sourceText = (string) ($document->extracted_text ?? '');
        $indexContext = $this->indexContextService->buildForCustomer($customerId);
        $figureCandidates = $this->figureCandidatesForDocument($document);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');

        $decision = $this->aiClient->decide($sourceMeta, $sourceText, $indexContext, $languageCode, $figureCandidates, $context);

        return $this->validateAndRepairForDocument($customerId, $document, $languageCode, $decision, $context);
    }

    /**
     * Validate and, when needed, repair an already-composed maintainer decision. This performs
     * no decision-generation call, persistence, or apply operation.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    public function validateAndRepairForDocument(
        int $customerId,
        EnterpriseWikiDocument $document,
        string $languageCode,
        array $decision,
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();
        $sourceMeta = [
            'title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown',
            'filename' => (string) $document->original_filename,
        ];
        $sourceText = (string) ($document->extracted_text ?? '');
        $indexContext = $this->indexContextService->buildForCustomer($customerId);
        $figureCandidates = $this->figureCandidatesForDocument($document);
        $validFigureKeys = array_column($figureCandidates, 'source_element_key');
        $issues = $this->consistencyValidator->findIssues($decision, $indexContext, $validFigureKeys);

        if ($issues === []) {
            return $decision;
        }

        Log::warning('[WIKI_MAINTAINER_DECISION] Inconsistent decision detected — attempting one bounded repair pass.', [
            'customer_id' => $customerId,
            'document_id' => $document->id,
            'issues' => $issues,
        ]);

        $repaired = $this->aiClient->repair($sourceMeta, $sourceText, $indexContext, $languageCode, $decision, $issues, $figureCandidates, $context);
        $remainingIssues = $this->consistencyValidator->findIssues($repaired, $indexContext, $validFigureKeys);

        if ($remainingIssues !== []) {
            Log::error('[WIKI_MAINTAINER_DECISION] Decision still inconsistent after repair pass.', [
                'customer_id' => $customerId,
                'document_id' => $document->id,
                'issues' => $remainingIssues,
            ]);

            throw new EnterpriseWikiMaintainerDecisionInconsistentException($remainingIssues);
        }

        Log::info('[WIKI_MAINTAINER_DECISION] Repair pass resolved all detected inconsistencies.', [
            'customer_id' => $customerId,
            'document_id' => $document->id,
        ]);

        return $repaired;
    }

    /** @return array{global_plan: array<string,mixed>, batches: list<array<string,mixed>>}|null */
    public function preparePersistedCandidateBatchesForDocument(int $customerId, int $documentId, string $languageCode = 'no', ?AiCallContext $context = null): ?array
    {
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customerId)->where('id', $documentId)->first();
        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found for customer [{$customerId}].");
        }

        $sourceText = (string) ($document->extracted_text ?? '');
        if (! $this->aiClient->requiresSplit($sourceText)) {
            return null;
        }

        return $this->aiClient->preparePersistedCandidateBatches(
            ['title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown', 'filename' => (string) $document->original_filename],
            $sourceText,
            $this->indexContextService->buildForCustomer($customerId),
            $languageCode,
            $this->figureCandidatesForDocument($document),
            $context,
        );
    }

    /** @param list<array<string,mixed>> $batchResults @return array<string,mixed> */
    public function mergePersistedCandidateBatchResults(array $globalPlan, array $batchResults): array
    {
        return $this->aiClient->mergePersistedBatchResults($globalPlan, $batchResults);
    }

    /**
     * Every showable (non-decorative/logo) figure already extracted and classified from this
     * document — EnterpriseWikiDocumentSourceElementService::inspect() has already excluded
     * decorative/logo images (isShowable()) before this ever sees them, so every candidate here is
     * a genuine planning candidate. Shape matches what
     * EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock() renders into the prompt.
     *
     * @return list<array<string, mixed>>
     */
    private function figureCandidatesForDocument(EnterpriseWikiDocument $document): array
    {
        $elements = $this->sourceElementService->inspect($document)['elements'];

        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
        ));
    }
}
