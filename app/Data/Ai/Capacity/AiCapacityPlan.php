<?php

namespace App\Data\Ai\Capacity;

use JsonSerializable;

/**
 * EnterpriseWikiAiCapacityPlanner's decision for one AI call attempt.
 *
 * `strategy` is one of three deterministic outcomes (see EnterpriseWikiAiCapacityPlanner::plan()):
 *  - STRATEGY_SINGLE_CALL: the estimate fits comfortably, with room to spare even after a
 *    hypothetical capacity retry.
 *  - STRATEGY_CAPACITY_RETRY: the estimate fits now, but a capacity retry (if the response
 *    unexpectedly comes back incomplete) would itself get clamped to the same ceiling instead of
 *    the full theoretical retry boost — still informational only; the caller still attempts a
 *    single call first, exactly as for STRATEGY_SINGLE_CALL.
 *  - STRATEGY_SPLIT_REQUIRED: the estimate already exceeds the resolved ceiling before any retry
 *    is even considered — mathematically, a retry would clamp to the exact same ceiling as the
 *    first attempt (see the planner), so it can never help. Only this value should cause a caller
 *    to route through EnterpriseWikiMaintainerDecisionSplitCoordinator instead of a single call.
 */
final readonly class AiCapacityPlan implements JsonSerializable
{
    public const STRATEGY_SINGLE_CALL = 'single_call';

    public const STRATEGY_CAPACITY_RETRY = 'capacity_retry';

    public const STRATEGY_SPLIT_REQUIRED = 'split_required';

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
