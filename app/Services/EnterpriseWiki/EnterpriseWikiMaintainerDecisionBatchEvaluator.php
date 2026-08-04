<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\EnterpriseWikiIndexContextService;
use RuntimeException;

/**
 * Narrow execution seam for persisted candidate batches. The future dispatcher supplies the
 * same complete candidate-batch input used by the existing split coordinator; this preparation
 * step deliberately does not wire that dispatcher into the document flow.
 */
class EnterpriseWikiMaintainerDecisionBatchEvaluator
{
    public function __construct(private readonly EnterpriseWikiMaintainerDecisionSplitCoordinator $coordinator, private readonly EnterpriseWikiIndexContextService $indexContextService, private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService) {}

    /** @return array<string,mixed> */
    public function evaluate(int $runId, EnterpriseWikiMaintainerDecisionBatch $batch): array
    {
        $run = $batch->run()->first();
        if ($run === null || $run->id !== $runId) {
            throw new RuntimeException("Maintainer candidate batch [{$batch->batch_number}] has no matching run [{$runId}].");
        }
        $input = $batch->input_payload;
        foreach (['global_plan', 'mentions', 'batch_number', 'total_batches'] as $key) {
            if (! array_key_exists($key, $input)) {
                throw new RuntimeException("Maintainer candidate batch [{$batch->batch_number}] input is missing [{$key}].");
            }
        }
        if ((int) $input['batch_number'] !== $batch->batch_number || (int) $input['total_batches'] !== $batch->total_batches || ! is_array($input['global_plan']) || ! is_array($input['mentions'])) {
            throw new RuntimeException("Maintainer candidate batch [{$batch->batch_number}] input is invalid.");
        }
        $document = EnterpriseWikiDocument::query()->where('customer_id', $run->customer_id)->find($run->source_id);
        if (! $document) {
            throw new RuntimeException("Document [{$run->source_id}] not found for run [{$runId}].");
        }
        $language = Customer::query()->with('language')->find($run->customer_id)?->language?->code ?? 'no';
        $figures = array_values(array_filter($this->sourceElementService->inspect($document)['elements'], fn (array $item): bool => ($item['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE));
        $raw = $this->coordinator->decidePersistedCandidateBatch(['title' => pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: 'Unknown', 'filename' => (string) $document->original_filename], (string) $document->extracted_text, $this->indexContextService->buildForCustomer($run->customer_id), $language, $input['global_plan'], $input['mentions'], $batch->batch_number, $figures);

        return EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch($raw);
    }
}
