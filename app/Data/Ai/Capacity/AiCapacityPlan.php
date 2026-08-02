<?php

namespace App\Data\Ai\Capacity;

use JsonSerializable;

/**
 * EnterpriseWikiAiCapacityPlanner's decision for one AI call attempt.
 *
 * `strategy` is deliberately reserved for a future value such as 'split_required', for when
 * adaptive budgeting plus one capacity retry are no longer enough for a given operation — this
 * class only ever produces STRATEGY_SINGLE_CALL today; no splitting logic exists yet.
 */
final readonly class AiCapacityPlan implements JsonSerializable
{
    public const STRATEGY_SINGLE_CALL = 'single_call';

    public function __construct(
        public string $operationType,
        public string $model,
        public int $chosenMaxOutputTokens,
        public int $estimatedMinimumTokens,
        public int $estimatedNeedTokens,
        public int $maxAllowedTokens,
        public bool $wasClamped,
        public string $basis,
        public int $retryLevel,
        public string $strategy = self::STRATEGY_SINGLE_CALL,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_type' => $this->operationType,
            'model' => $this->model,
            'chosen_max_output_tokens' => $this->chosenMaxOutputTokens,
            'estimated_minimum_tokens' => $this->estimatedMinimumTokens,
            'estimated_need_tokens' => $this->estimatedNeedTokens,
            'max_allowed_tokens' => $this->maxAllowedTokens,
            'was_clamped' => $this->wasClamped,
            'basis' => $this->basis,
            'retry_level' => $this->retryLevel,
            'strategy' => $this->strategy,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
