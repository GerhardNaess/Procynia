<?php

namespace App\Support\Ai;

use App\Data\Ai\AiCallContext;
use Closure;
use LogicException;

/** Holds the current nested AI-call context without putting telemetry state in prompts or jobs. */
class AiCallContextScope
{
    /** @var list<array{context: AiCallContext, latest_attempt_id: ?int}> */
    private array $stack = [];

    public function current(): AiCallContext
    {
        return $this->stack[array_key_last($this->stack)]['context'] ?? AiCallContext::fromAuthenticatedUser();
    }

    /**
     * Run $callback inside $context, narrowed onto whatever context is already current.
     *
     * The inner context wins where it says something and inherits where it is silent — so an AI
     * client that only names its operation still runs for the job's customer. A nested scope that
     * names a DIFFERENT customer than the one it runs inside is a tenancy fault, never a narrowing,
     * and is refused before any provider call.
     */
    public function within(AiCallContext $context, Closure $callback): mixed
    {
        $outer = $this->current();

        if (($context->customerId ?? 0) > 0 && ($outer->customerId ?? 0) > 0 && $context->customerId !== $outer->customerId) {
            throw new LogicException(sprintf(
                'AI call context for customer [%d] cannot run inside the context of customer [%d].',
                $context->customerId,
                $outer->customerId,
            ));
        }

        $this->stack[] = ['context' => $context->inheritFrom($outer), 'latest_attempt_id' => null];

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
