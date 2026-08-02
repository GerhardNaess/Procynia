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
 */
class EnterpriseWikiAiCapacityPlanner
{
    public function plan(AiCapacityRequest $request): AiCapacityPlan
    {
        $operationConfig = config("ai_capacity.operations.{$request->operationType}");

        if (! is_array($operationConfig)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: no capacity profile configured for operation [{$request->operationType}]."
            );
        }

        $modelMax = $this->modelMaxOutputTokens($request->model);
        $operationMax = (int) ($operationConfig['max_output_tokens'] ?? $modelMax);
        $maxAllowedTokens = min($modelMax, $operationMax);

        if ($maxAllowedTokens <= 0) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiCapacityPlanner: resolved max_output_tokens for operation [{$request->operationType}] ".
                "/ model [{$request->model}] must be a positive integer, got [{$maxAllowedTokens}]."
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
                "[{$request->operationType}] exceeds its resolved maximum ({$maxAllowedTokens})."
            );
        }

        $resultObjects = max(0, $request->expectedResultObjects);
        $inputUnits = (int) ceil(max(0, $request->inputSizeChars) / $inputCharsPerUnit);

        $objectContribution = $perObjectTokens * $resultObjects;
        $inputContribution = $tokensPerInputUnit * $inputUnits;

        $estimatedNeedTokens = $baseOverhead + $objectContribution + $inputContribution + $reasoningBuffer;

        $preferredTokens = (int) ceil($estimatedNeedTokens * (1 + $safetyMarginRatio));
        $preferredTokens = max($preferredTokens, $minimumOutputTokens);

        $retryLevel = max(0, $request->retryAttempt);

        if ($retryLevel > 0) {
            $preferredTokens = (int) ceil($preferredTokens * ($retryMultiplier ** $retryLevel));
        }

        $chosenMaxOutputTokens = min($preferredTokens, $maxAllowedTokens);
        $wasClamped = $preferredTokens > $maxAllowedTokens;

        $basis = sprintf(
            'base=%d + objects(%d*%d)=%d + input(%d units*%d)=%d + reasoning=%d => need=%d; '.
            'margin=%d%%%s => preferred=%d; capped at min(model=%d, operation=%d)=%d%s',
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
        );

        return new AiCapacityPlan(
            operationType: $request->operationType,
            model: $request->model,
            chosenMaxOutputTokens: $chosenMaxOutputTokens,
            estimatedMinimumTokens: $minimumOutputTokens,
            estimatedNeedTokens: $estimatedNeedTokens,
            maxAllowedTokens: $maxAllowedTokens,
            wasClamped: $wasClamped,
            basis: $basis,
            retryLevel: $retryLevel,
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
}
