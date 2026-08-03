<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiTimeoutPlan;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use InvalidArgumentException;

/**
 * Computes the HTTP request timeout for one Enterprise Wiki maintainer-decision AI call,
 * replacing the hardcoded `timeoutSeconds: 60` that caused the Wiki run-592 incident: a
 * global-plan call for a 26-page document never got a response within 60 seconds, even though a
 * comparable call in run 591 legitimately took ~55 seconds to succeed — 60 seconds left almost no
 * margin, and the value was never operation- or workload-aware.
 *
 * Deliberately mirrors EnterpriseWikiAiCapacityPlanner's shape and config structure
 * (config('ai_request_timeout.operations.{operationType}'), with 'global_plan'/'batch'
 * sub-profiles) — same base + input-size term + output-budget term formula style, clamped to a
 * configured [min,max] range and, when the caller knows how much time is left in the owning queue
 * job, further clamped so this one attempt can never risk exceeding that budget.
 *
 * Deliberately NOT adaptive/history-based: a pure, deterministic function of the request. No
 * database read, no response-time model — an explicit non-goal for this task per the work order
 * ("Ikke innfør adaptiv historikk eller ny databasebasert responstidsmodell").
 */
class EnterpriseWikiAiRequestTimeoutPolicy
{
    public function resolve(AiTimeoutRequest $request): AiTimeoutPlan
    {
        return $this->computePlan($this->operationConfig($request->operationType), $request);
    }

    /**
     * Sizes EnterpriseWikiMaintainerDecisionSplitCoordinator's Phase A (global plan) call — see
     * EnterpriseWikiAiCapacityPlanner::planGlobalPlanCall()'s identical reasoning for why this
     * reads a separate 'global_plan' sub-profile rather than the whole-decision one.
     */
    public function resolveForGlobalPlan(AiTimeoutRequest $request): AiTimeoutPlan
    {
        $config = array_merge(
            $this->operationConfig($request->operationType),
            $this->globalPlanConfig($request->operationType),
        );

        return $this->computePlan($config, $request);
    }

    /**
     * Sizes one split-flow batch call — adds a per-candidate term on top of the base/input/output
     * terms, since a batch deciding more concept candidates takes longer even at the same output
     * token ceiling (more distinct items to reason through, not just more output text).
     */
    public function resolveForBatch(AiTimeoutRequest $request, int $candidatesInBatch): AiTimeoutPlan
    {
        $config = array_merge(
            $this->operationConfig($request->operationType),
            $this->batchConfig($request->operationType),
        );

        return $this->computePlan($config, $request, max(0, $candidatesInBatch));
    }

    /** @return array<string, mixed> */
    private function operationConfig(string $operationType): array
    {
        $operationConfig = config("ai_request_timeout.operations.{$operationType}");

        if (! is_array($operationConfig)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiAiRequestTimeoutPolicy: no timeout profile configured for operation [{$operationType}]."
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
                "EnterpriseWikiAiRequestTimeoutPolicy: no 'global_plan' timeout profile configured for operation [{$operationType}]."
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
                "EnterpriseWikiAiRequestTimeoutPolicy: no 'batch' timeout profile configured for operation [{$operationType}]."
            );
        }

        return $batchConfig;
    }

    private function computePlan(array $config, AiTimeoutRequest $request, int $candidateCount = 0): AiTimeoutPlan
    {
        $base = (int) ($config['base_seconds'] ?? 30);
        $secondsPerInputUnit = (float) ($config['seconds_per_input_chars_unit'] ?? 0.0);
        $inputCharsPerUnit = max(1, (int) ($config['input_chars_per_unit'] ?? 1));
        $secondsPerOutputToken = (float) ($config['seconds_per_output_token'] ?? 0.0);
        $secondsPerCandidate = (float) ($config['seconds_per_candidate'] ?? 0.0);

        $inputUnits = (int) ceil(max(0, $request->inputSizeChars) / $inputCharsPerUnit);
        $inputContribution = $secondsPerInputUnit * $inputUnits;
        $outputContribution = $secondsPerOutputToken * max(0, $request->chosenMaxOutputTokens);
        $candidateContribution = $secondsPerCandidate * $candidateCount;

        $computed = $base + $inputContribution + $outputContribution + $candidateContribution;

        $min = (int) config('ai_request_timeout.min_seconds', 30);
        $max = (int) config('ai_request_timeout.max_seconds', 180);

        $rangeClamped = (int) max($min, min($max, (int) round($computed)));
        $wasClampedToRange = $rangeClamped !== (int) round($computed);

        $timeoutSeconds = $rangeClamped;
        $wasClampedToJobBudget = false;

        if ($request->remainingJobBudgetSeconds !== null) {
            $marginRatio = (float) config('ai_request_timeout.job_budget_margin_ratio', 0.2);
            // Never forced back UP to $min here: if the job's own remaining budget is
            // genuinely tight, the resolved timeout must reflect that truthfully rather than
            // silently exceeding it — EnterpriseWikiAiCapacityRetryExecutor is the one that
            // decides whether the remaining budget is even worth attempting a call with.
            $safeCeiling = max(1, (int) floor($request->remainingJobBudgetSeconds * (1 - $marginRatio)));

            if ($safeCeiling < $timeoutSeconds) {
                $timeoutSeconds = $safeCeiling;
                $wasClampedToJobBudget = true;
            }
        }

        $basis = sprintf(
            'base=%ds + input(%d units*%.2fs)=%.1fs + output(%d tokens*%.4fs)=%.1fs%s => computed=%.1fs; '.
            'range=[%d,%d]s -> %ds%s',
            $base,
            $inputUnits,
            $secondsPerInputUnit,
            $inputContribution,
            $request->chosenMaxOutputTokens,
            $secondsPerOutputToken,
            $outputContribution,
            $candidateCount > 0 ? sprintf(' + candidates(%d*%.2fs)=%.1fs', $candidateCount, $secondsPerCandidate, $candidateContribution) : '',
            $computed,
            $min,
            $max,
            $rangeClamped,
            $wasClampedToJobBudget ? "; job budget margin clamped to {$timeoutSeconds}s" : '',
        );

        return new AiTimeoutPlan(
            operationType: $request->operationType,
            timeoutSeconds: $timeoutSeconds,
            minSeconds: $min,
            maxSeconds: $max,
            wasClampedToRange: $wasClampedToRange,
            wasClampedToJobBudget: $wasClampedToJobBudget,
            basis: $basis,
        );
    }
}
