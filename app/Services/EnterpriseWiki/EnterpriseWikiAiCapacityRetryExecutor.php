<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseIncompleteException;
use App\Services\OpenAi\OpenAiClient;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Runs one AI call attempt, and — only when it comes back status=incomplete with
 * reason=max_output_tokens — exactly one further attempt at a higher, capacity-planner-chosen
 * budget. Never re-parses or reuses the first attempt's (possibly partial) response text; the
 * retry is a brand-new call with the identical semantic input and schema, just a bigger
 * max_output_tokens. Any other exception (schema violation, refusal, malformed envelope, a
 * non-token incomplete reason, timeout/API error) propagates immediately, untouched — only this
 * one specific signal ever triggers a capacity retry.
 *
 * Extracted from EnterpriseWikiMaintainerDecisionAiClient so the exact same bounded-retry-plus-
 * logging mechanics are reusable by EnterpriseWikiMaintainerDecisionSplitCoordinator's own calls
 * (global plan + each candidate batch) without duplicating this logic — the two callers differ
 * only in how they build a capacity plan for a given retry level ($planFor) and how they build the
 * OpenAI payload for a given budget ($buildPayload).
 */
class EnterpriseWikiAiCapacityRetryExecutor
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    /**
     * @param  Closure(int): AiCapacityPlan  $planFor  fn(int $retryAttempt): AiCapacityPlan
     * @param  Closure(int): array  $buildPayload  fn(int $maxOutputTokens): array
     * @return array<string, mixed> The decoded (but not yet schema-parsed) response body.
     *
     * @throws EnterpriseWikiAiOutputCapacityExceededException when the response is still
     *                                                         incomplete/max_output_tokens after one capacity retry.
     */
    public function execute(string $operationLabel, int $inputSizeChars, Closure $planFor, Closure $buildPayload): array
    {
        $plan = $planFor(0);

        try {
            return $this->attemptCall($buildPayload($plan->chosenMaxOutputTokens), $operationLabel, $plan, $inputSizeChars);
        } catch (EnterpriseWikiResponseIncompleteException $e) {
            if (! $e->reachedMaxOutputTokens()) {
                throw $e;
            }

            $retryPlan = $planFor(1);

            try {
                return $this->attemptCall($buildPayload($retryPlan->chosenMaxOutputTokens), $operationLabel, $retryPlan, $inputSizeChars);
            } catch (EnterpriseWikiResponseIncompleteException $e2) {
                if (! $e2->reachedMaxOutputTokens()) {
                    throw $e2;
                }

                throw new EnterpriseWikiAiOutputCapacityExceededException(
                    $operationLabel,
                    $retryPlan,
                    $e2->diagnostics['output_tokens'] ?? null,
                    $e2->diagnostics['response_id'] ?? null,
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function attemptCall(array $payload, string $operationLabel, AiCapacityPlan $plan, int $inputSizeChars): array
    {
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 60);

        $this->logCapacityDecision($operationLabel, $plan, $inputSizeChars, $response);

        return $this->responsesDecoder->decode($response, $operationLabel);
    }

    /**
     * One structured log line per attempt covering the capacity decision and the actual usage
     * OpenAI reports — reuses EnterpriseWikiResponsesDecoder::diagnostics() (the same data the
     * decoder's own "Response rejected" warning uses on failure) rather than duplicating a raw
     * response dump. Never logs document text, secrets, or full raw AI output.
     */
    private function logCapacityDecision(string $operationLabel, AiCapacityPlan $plan, int $inputSizeChars, array $response): void
    {
        $diagnostics = $this->responsesDecoder->diagnostics($response, $operationLabel);

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] Capacity decision for AI call.', [
            'operation' => $operationLabel,
            'operation_type' => $plan->operationType,
            'model' => $plan->model,
            'input_size_chars' => $inputSizeChars,
            'strategy' => $plan->strategy,
            'retry_level' => $plan->retryLevel,
            'chosen_max_output_tokens' => $plan->chosenMaxOutputTokens,
            'estimated_minimum_tokens' => $plan->estimatedMinimumTokens,
            'estimated_need_tokens' => $plan->estimatedNeedTokens,
            'max_allowed_tokens' => $plan->maxAllowedTokens,
            'was_clamped' => $plan->wasClamped,
            'basis' => $plan->basis,
            'response_id' => $diagnostics['response_id'] ?? null,
            'response_status' => $diagnostics['response_status'] ?? null,
            'incomplete_reason' => $diagnostics['incomplete_reason'] ?? null,
            'output_tokens' => $diagnostics['output_tokens'] ?? null,
            'reasoning_tokens' => $diagnostics['reasoning_tokens'] ?? null,
            'total_tokens' => $diagnostics['total_tokens'] ?? null,
        ]);
    }
}
