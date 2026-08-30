<?php

namespace App\Data\Ai;

/** Explicit commercial entitlement representation; zero and null never leak into enforcement. */
final readonly class AiQuotaPolicy
{
    public const NONE = 'none';
    public const FINITE = 'finite';
    public const UNLIMITED = 'unlimited';

    public function __construct(public string $type, public int $includedCredits = 0)
    {
        if (! in_array($type, [self::NONE, self::FINITE, self::UNLIMITED], true)) {
            throw new \InvalidArgumentException('Invalid AI quota policy type.');
        }
    }
}
