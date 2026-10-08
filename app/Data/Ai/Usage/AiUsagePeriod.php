<?php

namespace App\Data\Ai\Usage;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A half-open window [start, end) over `ai_usage_attempts.started_at`.
 *
 * One definition of "a period" for every reader of AI usage, so the admin report, a future
 * capacity engine and the subscription page cannot disagree about which day a call belongs to.
 *
 * Two kinds of period exist. The calendar month in the application timezone (UTC) is what the
 * commercial AI-case quota (AiQuotaStatusService) and the monthly NOK budget still use. A
 * customer's actual billing period comes from CustomerBillingPeriodResolver
 * (`BillingPeriod::toUsagePeriod()`, or `AiUsageLedger::forBillingPeriod()`) — never derive one
 * here from a calendar.
 */
final readonly class AiUsagePeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /** The calendar month containing $at, in the application timezone. */
    public static function calendarMonth(?DateTimeInterface $at = null): self
    {
        $at = CarbonImmutable::instance($at ?? now())->setTimezone(self::timezone());

        return new self($at->startOfMonth(), $at->startOfMonth()->addMonth());
    }

    /** Whole days from $from to $to inclusive, in the application timezone. */
    public static function days(DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        $start = CarbonImmutable::parse($from, self::timezone())->startOfDay();
        $end = CarbonImmutable::parse($to, self::timezone())->startOfDay()->addDay();

        return new self($start, $end);
    }

    private static function timezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }
}
