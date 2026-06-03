<?php

namespace App\Services\Ai;

use App\Models\AiTokenEvent;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose: Record per-call AI token usage for internal cost tracking without blocking the AI operation.
 * Inputs: A data array with token counts, model, operation key, and resource references.
 * Returns: None.
 * Side effects: Writes one ai_token_events row on success; logs a warning and swallows the error on failure.
 */
class AiTokenLogger
{
    /**
     * Purpose: Persist one AI token usage event without ever throwing to the caller.
     * Inputs: An array with token usage and context fields.
     * Returns: None.
     * Side effects: Inserts one ai_token_events row when the database is available; logs a warning otherwise.
     */
    public function record(array $data): void
    {
        try {
            $provider        = $this->resolveProvider($data);
            $deploymentName  = isset($data['deployment_name']) && $data['deployment_name'] !== ''
                ? (string) $data['deployment_name']
                : null;
            $providerRegion  = isset($data['provider_region']) && $data['provider_region'] !== ''
                ? (string) $data['provider_region']
                : null;

            AiTokenEvent::query()->create([
                'customer_id'   => (int) ($data['customer_id'] ?? 0),
                'user_id'       => isset($data['user_id']) ? (int) $data['user_id'] : null,
                'operation_key' => (string) ($data['operation_key'] ?? 'unknown'),
                'model'         => (string) ($data['model'] ?? 'unknown'),
                'provider'          => $provider,
                'deployment_name'   => $deploymentName,
                'provider_region'   => $providerRegion,
                'input_tokens'  => max(0, (int) ($data['input_tokens'] ?? 0)),
                'output_tokens' => max(0, (int) ($data['output_tokens'] ?? 0)),
                'total_tokens'  => max(0, (int) ($data['total_tokens'] ?? 0)),
                'saved_notice_id'               => isset($data['saved_notice_id']) ? (int) $data['saved_notice_id'] : null,
                'saved_notice_ai_document_id'   => isset($data['saved_notice_ai_document_id']) ? (int) $data['saved_notice_ai_document_id'] : null,
                'requirement_extraction_run_id' => isset($data['requirement_extraction_run_id']) ? (int) $data['requirement_extraction_run_id'] : null,
                'knowledge_item_id'             => isset($data['knowledge_item_id']) ? (int) $data['knowledge_item_id'] : null,
                'elapsed_ms'    => isset($data['elapsed_ms']) ? (int) $data['elapsed_ms'] : null,
                'request_id'    => isset($data['request_id']) && $data['request_id'] !== '' ? (string) $data['request_id'] : null,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_TOKEN_LOGGER] Failed to record AI token usage event.', [
                'operation_key' => $data['operation_key'] ?? 'unknown',
                'customer_id'   => $data['customer_id'] ?? null,
                'model'         => $data['model'] ?? null,
                'error'         => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Purpose: Resolve the provider key for a token event — from caller data or config fallback.
     * Inputs: The data array passed to record().
     * Returns: A stable provider string; never guessed from model name.
     * Side effects: May read from the IoC container.
     */
    private function resolveProvider(array $data): ?string
    {
        if (isset($data['provider']) && $data['provider'] !== '') {
            return (string) $data['provider'];
        }

        try {
            return app(OpenAiClient::class)->providerKey();
        } catch (Throwable) {
            return null;
        }
    }
}
