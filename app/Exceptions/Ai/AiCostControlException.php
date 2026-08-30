<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/** Safe, stable hard-stop code. Controllers must present the code's translation, never this message. */
class AiCostControlException extends RuntimeException
{
    public const NOT_INCLUDED = 'AI_NOT_INCLUDED';
    public const QUOTA_EXHAUSTED = 'AI_QUOTA_EXHAUSTED';
    public const CUSTOMER_SUSPENDED = 'AI_CUSTOMER_SUSPENDED';
    public const GLOBAL_STOP = 'AI_GLOBAL_STOP';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
