<?php

namespace App\Exceptions;

use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A decision reserved for a person was attempted by something that is not one, or through a
 * channel where no person decides. An AuthorizationException, so it renders as 403.
 */
class HumanDecisionRefused extends AuthorizationException
{
    public function __construct(public readonly HumanDecision $decision, public readonly string $refusal)
    {
        parent::__construct("Decision [{$decision->value}] is reserved for a person ({$refusal}).");
    }
}
