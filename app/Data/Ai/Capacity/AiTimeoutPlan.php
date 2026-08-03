<?php

namespace App\Data\Ai\Capacity;

use JsonSerializable;

/**
 * EnterpriseWikiAiRequestTimeoutPolicy's resolved timeout for one AI call attempt — see
 * AiTimeoutRequest for the incident this replaces (Wiki run-592's hardcoded 60-second timeout).
 */
final readonly class AiTimeoutPlan implements JsonSerializable
{
    public function __construct(
        public string $operationType,
        public int $timeoutSeconds,
        public int $minSeconds,
        public int $maxSeconds,
        public bool $wasClampedToRange,
        public bool $wasClampedToJobBudget,
        public string $basis,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_type' => $this->operationType,
            'timeout_seconds' => $this->timeoutSeconds,
            'min_seconds' => $this->minSeconds,
            'max_seconds' => $this->maxSeconds,
            'was_clamped_to_range' => $this->wasClampedToRange,
            'was_clamped_to_job_budget' => $this->wasClampedToJobBudget,
            'basis' => $this->basis,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
