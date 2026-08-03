<?php

namespace App\Data\Ai\Capacity;

use JsonSerializable;

/**
 * Everything EnterpriseWikiAiRequestTimeoutPolicy needs to compute one HTTP request timeout,
 * built for the Wiki run-592 incident: EnterpriseWikiAiCapacityRetryExecutor called
 * OpenAiClient::createResponse() with a hardcoded `timeoutSeconds: 60`, which is far too short for
 * a large global-plan/batch call (run 591's own successful global-plan call took ~55 seconds) and
 * was never operation- or workload-aware.
 *
 * $chosenMaxOutputTokens is the EnterpriseWikiAiCapacityPlanner-resolved output-token ceiling for
 * this exact attempt — used as a deterministic proxy for expected response latency, since actual
 * tokens produced can never be known before the call returns.
 *
 * $remainingJobBudgetSeconds is how much time is left in the owning queue job's own timeout before
 * this call is made (null when unknown/not threaded through, e.g. a code path outside the queued
 * document flow) — the policy never resolves a timeout that could, combined with the one allowed
 * network retry, exceed what the job itself has left.
 */
final readonly class AiTimeoutRequest implements JsonSerializable
{
    public function __construct(
        public string $operationType,
        public int $inputSizeChars,
        public int $chosenMaxOutputTokens,
        public ?int $remainingJobBudgetSeconds = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_type' => $this->operationType,
            'input_size_chars' => $this->inputSizeChars,
            'chosen_max_output_tokens' => $this->chosenMaxOutputTokens,
            'remaining_job_budget_seconds' => $this->remainingJobBudgetSeconds,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
