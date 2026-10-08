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
 * Today the only period AI is measured in is the calendar month in the application timezone —
 * the same month the commercial AI-case quota (AiQuotaStatusService) and the monthly NOK budget
 * use. Procynia does not store a subscription's billing anchor locally, so a billing-period
 * constructor cannot be written yet; when it can, it belongs here and nowhere else.
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
