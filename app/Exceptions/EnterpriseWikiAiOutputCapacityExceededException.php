<?php

namespace App\Exceptions;

use App\Data\Ai\Capacity\AiCapacityPlan;
use RuntimeException;

/**
 * Thrown when an AI call still returns status=incomplete/reason=max_output_tokens after one
 * bounded capacity retry (see EnterpriseWikiMaintainerDecisionAiClient) — a deliberately distinct
 * failure from:
 *  - EnterpriseWikiMaintainerDecisionInconsistentException: a COMPLETE decision that is logically
 *    inconsistent (handled by the separate consistency-repair pass, not this exception).
 *  - EnterpriseWikiResponseIncompleteException for a non-token incomplete reason (e.g. content
 *    filter): never treated as a capacity problem, never retried by capacity logic.
 *
 * No partial decision is ever attached or persisted from this path.
 */
class EnterpriseWikiAiOutputCapacityExceededException extends RuntimeException
{
    public function __construct(
        string $operationLabel,
        public readonly AiCapacityPlan $lastPlan,
        public readonly ?int $actualOutputTokens,
        public readonly ?string $responseId,
        public readonly string $recommendedNextMechanism =
            'Raise this operation\'s configured max_output_tokens ceiling in config(ai_capacity.operations), '.
            'or — once adaptive budgeting and one capacity retry are no longer enough — a future '.
            "split_required capacity strategy. Never raise it above the model's configured maximum.",
        public readonly bool $retrySkippedAsPointless = false,
    ) {
        $actual = $actualOutputTokens !== null ? (string) $actualOutputTokens : 'unknown';
        $response = $responseId ?? 'unknown';

        // Two different failures, never conflated in the message: a retry that ran and still did
        // not fit, and a retry that was deliberately not run because it would have been given the
        // exact same budget as the attempt that just failed.
        $cause = $retrySkippedAsPointless
            ? 'status=incomplete/reason=max_output_tokens, and no capacity retry was attempted because a retry '
                .'clamps to the same ceiling and could not have produced a larger response'
            : "exhausted capacity retry — still status=incomplete/reason=max_output_tokens after retry level {$lastPlan->retryLevel}";

        parent::__construct(
            "{$operationLabel}: {$cause} (chosen max_output_tokens={$lastPlan->chosenMaxOutputTokens}, ".
            "actual output_tokens={$actual}, response ID: {$response}). {$recommendedNextMechanism}"
        );
    }
}
