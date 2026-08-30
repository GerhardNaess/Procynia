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

    // Operational safety budgets. Distinct from the commercial quota on purpose: these say
    // Procynia stopped spending, not that the customer used up what they bought.
    public const CUSTOMER_DAILY_BUDGET_EXHAUSTED = 'AI_CUSTOMER_DAILY_BUDGET_EXHAUSTED';

    public const CUSTOMER_MONTHLY_BUDGET_EXHAUSTED = 'AI_CUSTOMER_MONTHLY_BUDGET_EXHAUSTED';

    public const GLOBAL_DAILY_BUDGET_EXHAUSTED = 'AI_GLOBAL_DAILY_BUDGET_EXHAUSTED';

    public const GLOBAL_MONTHLY_BUDGET_EXHAUSTED = 'AI_GLOBAL_MONTHLY_BUDGET_EXHAUSTED';

    /** The model has no usable price, so the call cannot be costed and is refused. */
    public const MODEL_PRICE_UNKNOWN = 'AI_MODEL_PRICE_UNKNOWN';

    public const PAYMENT_UNPAID = 'AI_PAYMENT_UNPAID';

    public const PAYMENT_INCOMPLETE = 'AI_PAYMENT_INCOMPLETE';

    /** Reasons a platform-wide safety mechanism refused the call. Never operator-overridable. */
    public const PLATFORM_REASONS = [
        self::GLOBAL_STOP,
        self::GLOBAL_DAILY_BUDGET_EXHAUSTED,
        self::GLOBAL_MONTHLY_BUDGET_EXHAUSTED,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
