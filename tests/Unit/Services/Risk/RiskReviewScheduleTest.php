<?php

namespace Tests\Unit\Services\Risk;

use App\Models\Risk;
use App\Services\Risk\RiskReviewSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The date rules of periodisk vurdering, without a database: calendar months that clamp at the
 * end of a month, and «Forfalt» only from the day after the next review date.
 */
class RiskReviewScheduleTest extends TestCase
{
    /** @return array<string, array{int, string, string}> */
    public static function intervals(): array
    {
        return [
            'månedlig' => [1, '2026-10-04', '2026-11-04'],
            'kvartalsvis' => [3, '2026-10-04', '2027-01-04'],
            'halvårlig' => [6, '2026-10-04', '2027-04-04'],
            'årlig' => [12, '2026-10-04', '2027-10-04'],
            'månedlig fra 31. januar klemmes til slutten av februar' => [1, '2027-01-31', '2027-02-28'],
            'månedlig fra 31. januar i skuddår' => [1, '2028-01-31', '2028-02-29'],
            'kvartalsvis fra 30. november' => [3, '2026-11-30', '2027-02-28'],
            'kvartalsvis fra 31. mai' => [3, '2026-05-31', '2026-08-31'],
            'halvårlig fra 31. august' => [6, '2026-08-31', '2027-02-28'],
            'årlig fra 29. februar' => [12, '2028-02-29', '2029-02-28'],
        ];
    }

    #[DataProvider('intervals')]
    public function test_next_review_is_the_last_assessment_plus_whole_calendar_months(int $months, string $assessed, string $expected): void
    {
        $next = (new RiskReviewSchedule)->nextReviewOn($months, CarbonImmutable::parse($assessed.' 15:42:00'));

        $this->assertSame($expected, $next?->toDateString());
    }

    public function test_no_interval_or_no_assessment_gives_no_next_review(): void
    {
        $schedule = new RiskReviewSchedule;

        $this->assertNull($schedule->nextReviewOn(null, CarbonImmutable::parse('2026-10-04')));
        $this->assertNull($schedule->nextReviewOn(3, null));
    }

    public function test_due_today_is_not_overdue_but_the_day_after_is(): void
    {
        $schedule = new RiskReviewSchedule;
        $next = CarbonImmutable::parse('2027-01-04');

        $this->assertFalse($schedule->isOverdue(Risk::STATUS_MONITORED, $next, CarbonImmutable::parse('2027-01-03 12:00')));
        $this->assertFalse($schedule->isOverdue(Risk::STATUS_MONITORED, $next, CarbonImmutable::parse('2027-01-04 23:59:59')));
        $this->assertTrue($schedule->isOverdue(Risk::STATUS_MONITORED, $next, CarbonImmutable::parse('2027-01-05 00:00:00')));
    }

    public function test_a_closed_risk_or_one_without_a_next_review_is_never_overdue(): void
    {
        $schedule = new RiskReviewSchedule;
        $today = CarbonImmutable::parse('2030-01-01');

        $this->assertFalse($schedule->isOverdue(Risk::STATUS_CLOSED, CarbonImmutable::parse('2027-01-04'), $today));
        $this->assertFalse($schedule->isOverdue(Risk::STATUS_IDENTIFIED, null, $today));
    }
}
