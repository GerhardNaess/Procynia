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
}
