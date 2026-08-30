<?php

namespace App\Support\Ai;

use App\Data\Ai\AiCallContext;
use Closure;

/** Holds the current nested AI-call context without putting telemetry state in prompts or jobs. */
class AiCallContextScope
{
    /** @var list<array{context: AiCallContext, latest_attempt_id: ?int}> */
    private array $stack = [];

    public function current(): AiCallContext
    {
        return $this->stack[array_key_last($this->stack)]['context'] ?? AiCallContext::fromAuthenticatedUser();
    }

    public function within(AiCallContext $context, Closure $callback): mixed
    {
        $this->stack[] = ['context' => $context, 'latest_attempt_id' => null];

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    public function rememberAttempt(?int $attemptId): void
    {
        $index = array_key_last($this->stack);

        if ($index !== null) {
            $this->stack[$index]['latest_attempt_id'] = $attemptId;
        }
    }

    public function latestAttemptId(): ?int
    {
        $index = array_key_last($this->stack);

        return $index !== null ? $this->stack[$index]['latest_attempt_id'] : null;
    }
}
