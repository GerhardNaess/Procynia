<?php

namespace Tests\Unit\Services\Suppliers;

use App\Services\Suppliers\SupplierReviewSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Neste vurdering = the current assessment's day + the supplier's interval, in calendar months that
 * clamp to the month's last day; no interval or no assessment gives no date.
 */
class SupplierReviewScheduleTest extends TestCase
{
    public function test_the_next_review_is_whole_calendar_months_on_and_none_without_interval_or_assessment(): void
    {
        $schedule = new SupplierReviewSchedule;
        $next = fn (?int $months, ?string $day): ?string => $schedule->nextReviewOn($months, $day !== null ? CarbonImmutable::parse($day) : null)?->toDateString();

        $this->assertSame('2027-10-07', $next(12, '2026-10-07'));
        $this->assertSame('2028-10-07', $next(24, '2026-10-07'));
        // A day the target month does not have clamps to its last day, never into the next month.
        $this->assertSame('2027-02-28', $next(6, '2026-08-31'));
        $this->assertSame('2028-02-29', $next(6, '2027-08-31'));
        $this->assertNull($next(null, '2026-10-07'));
        $this->assertNull($next(12, null));
    }
}
