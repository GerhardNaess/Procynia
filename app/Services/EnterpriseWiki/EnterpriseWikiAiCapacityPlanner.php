<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use InvalidArgumentException;

/**
 * Computes an output-token budget for one AI call, replacing the ~30 independently guessed
 * MAX_OUTPUT_TOKENS constants scattered across Wiki AI client classes. Built after the Wiki
 * run-583 incident: EnterpriseWikiMaintainerDecisionAiClient's fixed 3000-token budget did not
 * grow when commit 353aa98 added the concept_candidates field, truncating a content-rich
 * document's response before it finished.
 *
 * Deliberately generic — this class knows nothing about concept pages, Incident Management, or
 * any specific Wiki decision; it only ever sees the shape described by AiCapacityRequest/
 * AiCapacityPlan, keyed by operationType against config('ai_capacity.operations'). Only
 * EnterpriseWikiMaintainerDecisionAiClient uses it today; another Wiki AI client can adopt it
 * later by adding its own 'operations' profile, with no change to this class.
 *
 * The budget is a deterministic function of the request: base overhead + a per-result-object
 * term + an input-size-driven term (standing in for the number of items — e.g. concept
 * candidates — the model will enumerate, which is unknowable before it responds) + a
 * reasoning-token buffer, then a safety margin, then clamped to the lesser of the operation's and
 * the model's absolute maximum. A retryAttempt above 0 (used for at most one bounded capacity
 * retry — see EnterpriseWikiMaintainerDecisionAiClient) scales the same estimate by the
 * operation's retry_multiplier, still clamped to that same absolute maximum, so a retry can never
 * exceed what the model is configured to allow.
 *
 * plan()'s strategy field (see AiCapacityPlan) also answers a second question — is a single call
 * even safe to attempt? A retry always clamps to the SAME resolved ceiling as the first attempt,
 * so whenever the first attempt's own (unclamped) preferred estimate already exceeds that ceiling,
 * a retry is mathematically guaranteed to buy zero extra tokens: STRATEGY_SPLIT_REQUIRED. When the
 * first attempt fits but a hypothetical retry's preferred estimate would not, retrying (if ever
 * needed) still lands at the ceiling rather than the full theoretical boost — informational only
 * (STRATEGY_CAPACITY_RETRY). Otherwise there is comfortable room even after a hypothetical retry
 * (STRATEGY_SINGLE_CALL).
 *
 * planBatchCall()/planBatchCount() size the split flow's own per-batch calls, using a distinct
 * 'batch' sub-profile (different token-per-item cost than the whole-decision estimate above,
 * since a concept_candidates entry's shape differs from a full page entry) — but the same
 * underlying formula and the same clamp-to-ceiling guarantee.
 */
class EnterpriseWikiAiCapacityPlanner
{
    public function plan(AiCapacityRequest $request): AiCapacityPlan
    {
        $operationConfig = $this->operationConfig($request->operationType);

        return $this->computePlan(
            operationConfig: $operationConfig,
            operationType: $request->operationType,
            model: $request->model,
            inputSizeChars: $request->inputSizeChars,
            resultObjects: $request->expectedResultObjects,
            retryAttempt: $request->retryAttempt,
        );
    }

    /**
     * Sizes EnterpriseWikiMaintainerDecisionSplitCoordinator's Phase A (global plan) call — same
     * formula shape as plan(), but reading the 'global_plan' sub-profile, whose
     * tokens_per_input_chars_unit reflects Phase A's much smaller output (compact
     * concept_candidate_mentions, not full candidate dispositions). Using the whole-decision
     * profile here would size Phase A as if it still had to return full candidate detail,
     * defeating the point of splitting.
     */
    public function planGlobalPlanCall(
        string $operationType,
        string $model,
        int $expectedResultObjects,
        int $inputSizeChars,
        int $retryAttempt = 0,
    ): AiCapacityPlan {
        $globalPlanConfig = $this->globalPlanConfig($operationType);
        $operationConfig = $this->operationConfig($operationType);

        $mergedConfig = array_merge($operationConfig, $globalPlanConfig);

        return $this->computePlan(
            operationConfig: $mergedConfig,
            operationType: $operationType,
            model: $model,
            inputSizeChars: $inputSizeChars,
            resultObjects: $expectedResultObjects,
            retryAttempt: $retryAttempt,
        );
    }

    /**
     * Splits $totalCandidates into batches sized by the operation's 'batch' profile — capped by
     * both a capacity-driven maximum (how many candidates fit the resolved ceiling with margin to
     * spare) and the profile's own configured max_candidates_per_batch, whichever is smaller.
     * Candidates are distributed evenly across the resulting batch count rather than filled to
     * the cap with a small remainder, for a more balanced, deterministic split.
     *
     * @return list<int> Candidate count per batch, in order. Empty when $totalCandidates <= 0.
     */
    public function planBatchCount(string $operationType, string $model, int $totalCandidates): array
    {
        if ($totalCandidates <= 0) {
            return [];
        }

        $batchCount = (int) ceil($totalCandidates / $this->maxItemsPerBatch($operationType, $model));

        return $this->distributeEvenly($totalCandidates, $batchCount);
    }

    /**
     * How many items one batch-shaped call of this operation may take on — the capacity-driven
     * maximum (how many fit the resolved ceiling with margin to spare) capped by the profile's own
     * configured maximum, and floored at its configured minimum.
     *
     * Exposed because the bounded delta-repair flow packs repair groups into calls with the same
     * budget arithmetic the split flow uses for candidate batches: one definition of "how much fits
     * in one call", not two that can drift apart.
     */
    public function maxItemsPerBatch(string $operationType, string $model): int
    {
        $batchConfig = $this->batchConfig($operationType);
        $operationConfig = $this->operationConfig($operationType);

        $modelMax = $this->modelMaxOutputTokens($model);
        $operationMax = (int) ($operationConfig['max_output_tokens'] ?? $modelMax);
        $maxAllowedTokens = max(1, min($modelMax, $operationMax));

        $batchOverhead = (int) ($batchConfig['batch_overhead_tokens'] ?? 0);
        $reasoningBuffer = (int) ($operationConfig['reasoning_token_buffer'] ?? 0);
        $tokensPerCandidate = max(1, (int) ($batchConfig['tokens_per_candidate'] ?? 1));
        $safetyMarginRatio = (float) ($batchConfig['safety_margin_ratio'] ?? $operationConfig['safety_margin_ratio'] ?? 0.0);

        $availableForCandidates = ($maxAllowedTokens / (1 + $safetyMarginRatio)) - $batchOverhead - $reasoningBuffer;
        $capacityDrivenMax = (int) floor(max(0, $availableForCandidates) / $tokensPerCandidate);

        $configuredMax = (int) ($batchConfig['max_candidates_per_batch'] ?? $capacityDrivenMax);
        $configuredMin = max(1, (int) ($batchConfig['min_candidates_per_batch'] ?? 1));

        return max($configuredMin, min($capacityDrivenMax, $configuredMax));
    }

    /**
     * Sizes one split-flow batch call — same formula shape as plan(), but reading the 'batch'
     * sub-profile (a concept_candidates-only response has a different per-item token cost than a
     * full page entry) instead of the whole-decision profile.
     */
    public function planBatchCall(
        string $operationType,
        string $model,
        int $candidatesInBatch,
        int $inputSizeChars,
        int $retryAttempt = 0,
    ): AiCapacityPlan {
        $batchConfig = $this->batchConfig($operationType);
        $operationConfig = $this->operationConfig($operationType);

        $mergedConfig = array_merge($operationConfig, [
            'base_overhead_tokens' => $batchConfig['batch_overhead_tokens'] ?? 0,
            'tokens_per_result_object' => $batchConfig['tokens_per_candidate'] ?? 0,
            'safety_margin_ratio' => $batchConfig['safety_margin_ratio'] ?? $operationConfig['safety_margin_ratio'] ?? 0.0,
            'minimum_output_tokens' => $batchConfig['minimum_output_tokens'] ?? 0,
        ]);

        return $this->computePlan(
            operationConfig: $mergedConfig,
            operationType: $operationType,
            model: $model,
            inputSizeChars: $inputSizeChars,
            resultObjects: $candidatesInBatch,
            retryAttempt: $retryAttempt,
        );
    }

    /** @return array<string, mixed> */
    private function operationConfig(string $operationType): array
    {
        $operationConfig = config("ai_capacity.operations.{$operationType}");

        if (! is_array($operationConfig)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: no capacity profile configured for operation [{$operationType}]."
            );
        }

        return $operationConfig;
    }

    /** @return array<string, mixed> */
    private function globalPlanConfig(string $operationType): array
    {
        $globalPlanConfig = $this->operationConfig($operationType)['global_plan'] ?? null;

        if (! is_array($globalPlanConfig)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: no 'global_plan' profile configured for operation [{$operationType}]."
            );
        }

        return $globalPlanConfig;
    }

    /** @return array<string, mixed> */
    private function batchConfig(string $operationType): array
    {
        $batchConfig = $this->operationConfig($operationType)['batch'] ?? null;

        if (! is_array($batchConfig)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: no 'batch' profile configured for operation [{$operationType}]."
            );
        }

        return $batchConfig;
    }

    private function computePlan(
        array $operationConfig,
        string $operationType,
        string $model,
        int $inputSizeChars,
        int $resultObjects,
        int $retryAttempt,
    ): AiCapacityPlan {
        $modelMax = $this->modelMaxOutputTokens($model);
        $operationMax = (int) ($operationConfig['max_output_tokens'] ?? $modelMax);
        $maxAllowedTokens = min($modelMax, $operationMax);

        if ($maxAllowedTokens <= 0) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: resolved max_output_tokens for operation [{$operationType}] ".
                "/ model [{$model}] must be a positive integer, got [{$maxAllowedTokens}]."
            );
        }

        $baseOverhead = (int) ($operationConfig['base_overhead_tokens'] ?? 0);
        $perObjectTokens = (int) ($operationConfig['tokens_per_result_object'] ?? 0);
        $inputCharsPerUnit = max(1, (int) ($operationConfig['input_chars_per_unit'] ?? 1));
        $tokensPerInputUnit = (int) ($operationConfig['tokens_per_input_chars_unit'] ?? 0);
        $reasoningBuffer = (int) ($operationConfig['reasoning_token_buffer'] ?? 0);
        $minimumOutputTokens = (int) ($operationConfig['minimum_output_tokens'] ?? 0);
        $safetyMarginRatio = (float) ($operationConfig['safety_margin_ratio'] ?? 0.0);
        $retryMultiplier = (float) ($operationConfig['retry_multiplier'] ?? 1.0);

        if ($minimumOutputTokens > $maxAllowedTokens) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: minimum_output_tokens ({$minimumOutputTokens}) for operation ".
                "[{$operationType}] exceeds its resolved maximum ({$maxAllowedTokens})."
            );
        }

        $resultObjects = max(0, $resultObjects);
        $inputUnits = (int) ceil(max(0, $inputSizeChars) / $inputCharsPerUnit);

        $objectContribution = $perObjectTokens * $resultObjects;
        $inputContribution = $tokensPerInputUnit * $inputUnits;

        $estimatedNeedTokens = $baseOverhead + $objectContribution + $inputContribution + $reasoningBuffer;

        $rawPreferred0 = max(
            (int) ceil($estimatedNeedTokens * (1 + $safetyMarginRatio)),
            $minimumOutputTokens,
        );
        $rawPreferred1 = (int) ceil($rawPreferred0 * $retryMultiplier);

        $strategy = match (true) {
            $rawPreferred0 > $maxAllowedTokens => AiCapacityPlan::STRATEGY_SPLIT_REQUIRED,
            $rawPreferred1 > $maxAllowedTokens => AiCapacityPlan::STRATEGY_CAPACITY_RETRY,
            default => AiCapacityPlan::STRATEGY_SINGLE_CALL,
        };

        $retryLevel = max(0, $retryAttempt);
        $preferredTokens = $retryLevel > 0
            ? (int) ceil($rawPreferred0 * ($retryMultiplier ** $retryLevel))
            : $rawPreferred0;

        $chosenMaxOutputTokens = min($preferredTokens, $maxAllowedTokens);
        $wasClamped = $preferredTokens > $maxAllowedTokens;

        $basis = sprintf(
            'base=%d + objects(%d*%d)=%d + input(%d units*%d)=%d + reasoning=%d => need=%d; '.
            'margin=%d%%%s => preferred=%d; capped at min(model=%d, operation=%d)=%d%s; strategy=%s',
            $baseOverhead,
            $perObjectTokens,
            $resultObjects,
            $objectContribution,
            $inputUnits,
            $tokensPerInputUnit,
            $inputContribution,
            $reasoningBuffer,
            $estimatedNeedTokens,
            (int) round($safetyMarginRatio * 100),
            $retryLevel > 0 ? sprintf(' + retry x%.2f^%d', $retryMultiplier, $retryLevel) : '',
            $preferredTokens,
            $modelMax,
            $operationMax,
            $maxAllowedTokens,
            $wasClamped ? '; clamped' : '',
            $strategy,
        );

        return new AiCapacityPlan(
            operationType: $operationType,
            model: $model,
            chosenMaxOutputTokens: $chosenMaxOutputTokens,
            estimatedMinimumTokens: $minimumOutputTokens,
            estimatedNeedTokens: $estimatedNeedTokens,
            maxAllowedTokens: $maxAllowedTokens,
            wasClamped: $wasClamped,
            basis: $basis,
            retryLevel: $retryLevel,
            strategy: $strategy,
        );
    }

    /**
     * Falls back to the conservative default ceiling for a model with no entry in
     * config('ai_capacity.models') — never an unbounded budget for an unrecognised model.
     */
    private function modelMaxOutputTokens(string $model): int
    {
        $configured = config("ai_capacity.models.{$model}.max_output_tokens");

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        return (int) config('ai_capacity.default_model_max_output_tokens', 4000);
    }

    /** @return list<int> $count positive-sized shares of $total, differing by at most 1. */
    private function distributeEvenly(int $total, int $count): array
    {
        $count = max(1, $count);
        $base = intdiv($total, $count);
        $remainder = $total % $count;

        $batches = array_fill(0, $count, $base);

        for ($i = 0; $i < $remainder; $i++) {
            $batches[$i]++;
        }

        return array_values(array_filter($batches, fn (int $size): bool => $size > 0));
    }
}
