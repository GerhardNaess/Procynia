<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One half of the split phase 1 failed — the call itself, or its schema parse.
 *
 * Fail-closed, and named: phase 1 is now two calls, so "the maintainer decision failed" would no
 * longer say which half, and the two halves fail for entirely different reasons (1A is two pages
 * with an evidence contract; 1B is an open-ended candidate list). The run reports which one, and
 * the step retries both — there is deliberately no partial-phase-1 state to resume from, because a
 * half-plan is worth less than the cost of storing it.
 */
class EnterpriseWikiMaintainerDecisionPhaseFailedException extends RuntimeException
{
    public static function documentPlan(Throwable $previous): self
    {
        return new self(
            'Maintainer decision phase 1A (source_article/source_summary) failed: '.$previous->getMessage(),
            0,
            $previous,
        );
    }

    public static function candidatePlan(Throwable $previous): self
    {
        return new self(
            'Maintainer decision phase 1B (concept candidates/entity pages/patch targets) failed: '.$previous->getMessage(),
            0,
            $previous,
        );
    }
}
