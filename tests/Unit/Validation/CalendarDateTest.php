<?php

namespace Tests\Unit\Validation;

use App\Support\CalendarDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «I dag» on a date field is the Norwegian day. The app runs in UTC, so between midnight and 01:00
 * (02:00 in summer) the UTC day is still yesterday in Norway's eyes.
 */
class CalendarDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function moments(): array
    {
        return [
            'summer, before midnight in Norway' => ['2026-07-01 21:59:59', '2026-07-01'],
            'summer, just after midnight in Norway' => ['2026-07-01 22:00:00', '2026-07-02'],
            'winter, before midnight in Norway' => ['2026-12-31 22:59:59', '2026-12-31'],
            'winter, just after midnight in Norway' => ['2026-12-31 23:00:00', '2027-01-01'],
            'last summer-time night, after midnight' => ['2026-10-24 22:30:00', '2026-10-25'],
            'first winter-time night, after midnight' => ['2026-10-25 23:30:00', '2026-10-26'],
            'night summer time starts, after midnight' => ['2026-03-28 23:30:00', '2026-03-29'],
            'midday, same day in both' => ['2026-10-16 10:00:00', '2026-10-16'],
        ];
    }

    #[DataProvider('moments')]
    public function test_today_is_the_norwegian_calendar_day(string $utc, string $norwegianDay): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $this->assertSame($norwegianDay, CalendarDate::todayString());
        $this->assertSame($norwegianDay.' 00:00:00', CalendarDate::today()->toDateTimeString());
        // The app's own clock is untouched: timestamps stay in UTC.
        $this->assertSame('UTC', now()->getTimezone()->getName());
        $this->assertSame($utc, now()->toDateTimeString());
    }

    public function test_a_rule_up_to_today_accepts_the_norwegian_day_and_refuses_the_next(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 22:30:00', 'UTC'));

        $rule = ['occurred_at' => ['date_format:Y-m-d', 'before_or_equal:'.CalendarDate::todayString()]];

        $this->assertTrue(Validator::make(['occurred_at' => '2026-10-16'], $rule)->passes());
        $this->assertTrue(Validator::make(['occurred_at' => '2026-10-15'], $rule)->passes());
        $this->assertFalse(Validator::make(['occurred_at' => '2026-10-17'], $rule)->passes());

        // What `today` meant before: the UTC day, which refused the Norwegian today.
        $this->assertFalse(Validator::make(['occurred_at' => '2026-10-16'], ['occurred_at' => ['before_or_equal:today']])->passes());
    }
}
