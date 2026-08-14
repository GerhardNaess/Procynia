<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use RuntimeException;

/**
 * Narrow execution seam for persisted candidate batches. The future dispatcher supplies the
 * same complete candidate-batch input used by the existing split coordinator; this preparation
 * step deliberately does not wire that dispatcher into the document flow.
 */
class EnterpriseWikiMaintainerDecisionBatchEvaluator
{
    public function __construct(private readonly EnterpriseWikiMaintainerDecisionSplitCoordinator $coordinator) {}

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
        // The queued batch rebuilds the SAME authoritative context the in-process paths use — it is
        // a deterministic function of (customer, document), so a batch job cannot end up planning
        // from a different, thinner view of the document than the run that dispatched it.
        $planning = EnterpriseWikiPlanningContext::forDocument($run->customer_id, $document);
        $raw = $this->coordinator->decidePersistedCandidateBatch($planning, $language, $input['global_plan'], $input['mentions'], $batch->batch_number);

        return EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch($raw);
    }
}
