<?php

namespace App\Services\Quality\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The two ways interpreting a description can fail, told apart because the user does something
 * different about each.
 *
 * `unavailable` is ours: the provider was down, refused, or answered with something unreadable.
 * Trying again later is the whole of the advice.
 *
 * `unusable` is about the description: the model read it and what came back does not hold together
 * as a process — a branch with one way out, a step nothing reaches, no ending. The problems are
 * carried so the user is told which, because the fix is a sentence in their own text and they
 * cannot guess which sentence without being told.
 */
class ProcessFlowInterpretationException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(string $message, public readonly array $problems = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        return new self(__('procynia.quality.errors.flow_ai_unavailable'), [], $previous);
    }

    /** @param list<string> $problems */
    public static function unusable(array $problems): self
    {
        return new self(__('procynia.quality.errors.flow_not_coherent'), $problems);
    }

    /**
     * The same two failures for a requested change to an existing flow. Told in terms of the
     * change rather than the description, because the description is not what the user just wrote.
     */
    public static function changeUnavailable(?Throwable $previous = null): self
    {
        return new self(__('procynia.quality.errors.flow_change_unavailable'), [], $previous);
    }

    /** @param list<string> $problems */
    public static function changeUnusable(array $problems): self
    {
        return new self(__('procynia.quality.errors.flow_change_not_coherent'), $problems);
    }

    /**
     * The third way, and the only one that is about an answer rather than a description.
     *
     * The rewrite came back, and it was not something to put in front of the user — it still
     * carried the question, or it changed nothing at all. Nothing has been altered, so the advice
     * is the one thing that can help: say it differently. See QualityProcessDescriptionClarifier.
     */
    public static function clarificationNotIntegrated(): self
    {
        return new self(__('procynia.quality.errors.flow_clarification_failed'));
    }
}
