<?php

namespace Tests\Unit\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiPeriod;
use App\Services\Objectives\KpiPeriods;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calendar periods and reporting deadlines. Periods follow the calendar, never an interval added
 * to a date, and weeks are ISO weeks.
 */
class KpiPeriodsTest extends TestCase
{
    private KpiPeriods $periods;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periods = new KpiPeriods;
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function containingPeriods(): array
    {
        return [
            // Weeks: ISO, Monday to Sunday, numbered by the ISO year.
            'week mid-year' => [Kpi::FREQUENCY_WEEKLY, '2026-10-05', '2026-10-05', '2026-10-11', '2026-W41'],
            'week on its Sunday' => [Kpi::FREQUENCY_WEEKLY, '2026-10-11', '2026-10-05', '2026-10-11', '2026-W41'],
            'week 1 starting in December' => [Kpi::FREQUENCY_WEEKLY, '2025-12-29', '2025-12-29', '2026-01-04', '2026-W01'],
            'new year in week 1 of the new year' => [Kpi::FREQUENCY_WEEKLY, '2026-01-01', '2025-12-29', '2026-01-04', '2026-W01'],
            'new year in week 53 of the old year' => [Kpi::FREQUENCY_WEEKLY, '2027-01-01', '2026-12-28', '2027-01-03', '2026-W53'],
            'new year in week 52 of the old year' => [Kpi::FREQUENCY_WEEKLY, '2022-01-01', '2021-12-27', '2022-01-02', '2021-W52'],
            'leap day in its week' => [Kpi::FREQUENCY_WEEKLY, '2028-02-29', '2028-02-28', '2028-03-05', '2028-W09'],

            // Months: the calendar month, whatever its length.
            'month on the 31st' => [Kpi::FREQUENCY_MONTHLY, '2026-01-31', '2026-01-01', '2026-01-31', '2026-01'],
            'February' => [Kpi::FREQUENCY_MONTHLY, '2026-02-14', '2026-02-01', '2026-02-28', '2026-02'],
            'February in a leap year' => [Kpi::FREQUENCY_MONTHLY, '2028-02-29', '2028-02-01', '2028-02-29', '2028-02'],
            'February in a century year that is no leap year' => [Kpi::FREQUENCY_MONTHLY, '2100-02-10', '2100-02-01', '2100-02-28', '2100-02'],
            'December' => [Kpi::FREQUENCY_MONTHLY, '2026-12-31', '2026-12-01', '2026-12-31', '2026-12'],

            // Quarters: Q1–Q4.
            'Q1 first day' => [Kpi::FREQUENCY_QUARTERLY, '2026-01-01', '2026-01-01', '2026-03-31', '2026-Q1'],
            'Q1 last day' => [Kpi::FREQUENCY_QUARTERLY, '2026-03-31', '2026-01-01', '2026-03-31', '2026-Q1'],
            'Q2 first day' => [Kpi::FREQUENCY_QUARTERLY, '2026-04-01', '2026-04-01', '2026-06-30', '2026-Q2'],
            'Q3' => [Kpi::FREQUENCY_QUARTERLY, '2026-08-15', '2026-07-01', '2026-09-30', '2026-Q3'],
            'Q4 last day' => [Kpi::FREQUENCY_QUARTERLY, '2026-12-31', '2026-10-01', '2026-12-31', '2026-Q4'],

            // Years.
            'year first day' => [Kpi::FREQUENCY_YEARLY, '2026-01-01', '2026-01-01', '2026-12-31', '2026'],
            'year last day' => [Kpi::FREQUENCY_YEARLY, '2026-12-31', '2026-01-01', '2026-12-31', '2026'],
            'leap year' => [Kpi::FREQUENCY_YEARLY, '2028-07-01', '2028-01-01', '2028-12-31', '2028'],
        ];
    }

    #[DataProvider('containingPeriods')]
    public function test_a_day_falls_in_its_calendar_period(string $frequency, string $day, string $start, string $end, string $key): void
    {
        $period = $this->periods->containing($frequency, CarbonImmutable::parse($day.' 23:59:59'));

        $this->assertSame([$start, $end, $key], $this->describe($period));
        $this->assertTrue($period->contains(CarbonImmutable::parse($day.' 12:00')));
        $this->assertSame('00:00:00', $period->start->format('H:i:s'));
        $this->assertSame('00:00:00', $period->end->format('H:i:s'));
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function successions(): array
    {
        return [
            'month after 31 January is February, not 3 March' => [Kpi::FREQUENCY_MONTHLY, '2026-01-31', ['2026-02', '2026-03', '2026-04']],
            'leap February and on' => [Kpi::FREQUENCY_MONTHLY, '2028-01-31', ['2028-02', '2028-03']],
            'month across the year end' => [Kpi::FREQUENCY_MONTHLY, '2026-11-30', ['2026-12', '2027-01', '2027-02']],
            'quarters across the year end' => [Kpi::FREQUENCY_QUARTERLY, '2026-08-31', ['2026-Q4', '2027-Q1', '2027-Q2']],
            'weeks across a 53-week year' => [Kpi::FREQUENCY_WEEKLY, '2026-12-21', ['2026-W53', '2027-W01', '2027-W02']],
            'weeks across a 52-week year' => [Kpi::FREQUENCY_WEEKLY, '2025-12-22', ['2026-W01', '2026-W02']],
            'years' => [Kpi::FREQUENCY_YEARLY, '2027-12-31', ['2028', '2029']],
        ];
    }

    /** @param list<string> $expectedKeys */
    #[DataProvider('successions')]
    public function test_the_next_period_follows_the_calendar_and_previous_walks_back(string $frequency, string $day, array $expectedKeys): void
    {
        $first = $this->periods->containing($frequency, CarbonImmutable::parse($day));
        $period = $first;
        $keys = [];

        foreach ($expectedKeys as $ignored) {
            $next = $this->periods->next($period);
            // Contiguous: no gap and no overlap.
            $this->assertTrue($next->start->equalTo($period->end->addDay()), "{$next->key} starts the day after {$period->key}");
            $period = $next;
            $keys[] = $period->key;
        }

        $this->assertSame($expectedKeys, $keys);

        foreach ($expectedKeys as $ignored) {
            $period = $this->periods->previous($period);
        }

        $this->assertTrue($period->equals($first));
    }

    public function test_month_after_31_january_runs_through_the_last_of_february(): void
    {
        $february = $this->periods->next($this->periods->containing(Kpi::FREQUENCY_MONTHLY, CarbonImmutable::parse('2026-01-31')));

        $this->assertSame(['2026-02-01', '2026-02-28', '2026-02'], $this->describe($february));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function keys(): array
    {
        return [
            'week 1 begins in the old year' => [Kpi::FREQUENCY_WEEKLY, '2026-W01', '2025-12-29', '2026-01-04'],
            'week 53 where it exists' => [Kpi::FREQUENCY_WEEKLY, '2026-W53', '2026-12-28', '2027-01-03'],
            'month' => [Kpi::FREQUENCY_MONTHLY, '2028-02', '2028-02-01', '2028-02-29'],
            'quarter' => [Kpi::FREQUENCY_QUARTERLY, '2026-Q3', '2026-07-01', '2026-09-30'],
            'year' => [Kpi::FREQUENCY_YEARLY, '2026', '2026-01-01', '2026-12-31'],
        ];
    }

    #[DataProvider('keys')]
    public function test_a_chosen_period_is_normalised_from_its_key(string $frequency, string $key, string $start, string $end): void
    {
        $this->assertSame([$start, $end, $key], $this->describe($this->periods->fromKey($frequency, $key)));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidKeys(): array
    {
        return [
            'week 53 in a 52-week year' => [Kpi::FREQUENCY_WEEKLY, '2025-W53'],
            'week 0' => [Kpi::FREQUENCY_WEEKLY, '2026-W00'],
            'week without padding' => [Kpi::FREQUENCY_WEEKLY, '2026-W1'],
            'month 13' => [Kpi::FREQUENCY_MONTHLY, '2026-13'],
            'month 0' => [Kpi::FREQUENCY_MONTHLY, '2026-00'],
            'a date for a month' => [Kpi::FREQUENCY_MONTHLY, '2026-01-31'],
            'quarter 5' => [Kpi::FREQUENCY_QUARTERLY, '2026-Q5'],
            'a month key for a quarter' => [Kpi::FREQUENCY_QUARTERLY, '2026-03'],
            'a short year' => [Kpi::FREQUENCY_YEARLY, '26'],
            'unknown frequency' => ['daily', '2026-01-01'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_a_key_that_names_no_real_period_is_refused(string $frequency, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->periods->fromKey($frequency, $key);
    }

    public function test_an_unknown_frequency_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->periods->containing('daily', CarbonImmutable::parse('2026-01-01'));
    }

    public function test_with_seven_grace_days_a_month_is_due_a_week_after_it_ends(): void
    {
        $january = $this->periods->fromKey(Kpi::FREQUENCY_MONTHLY, '2026-01');

        $this->assertSame('2026-02-07', $this->periods->reportingDeadline($january, 7)->toDateString());
        $this->assertFalse($this->periods->isOverdue($january, 7, CarbonImmutable::parse('2026-01-31 23:00')));
        $this->assertFalse($this->periods->isOverdue($january, 7, CarbonImmutable::parse('2026-02-07 23:59:59')));
        $this->assertTrue($this->periods->isOverdue($january, 7, CarbonImmutable::parse('2026-02-08 00:00')));
    }

    public function test_with_zero_grace_days_a_period_is_due_on_its_last_day(): void
    {
        $q1 = $this->periods->fromKey(Kpi::FREQUENCY_QUARTERLY, '2026-Q1');

        $this->assertSame('2026-03-31', $this->periods->reportingDeadline($q1, 0)->toDateString());
        $this->assertFalse($this->periods->isOverdue($q1, 0, CarbonImmutable::parse('2026-03-31 18:00')));
        $this->assertTrue($this->periods->isOverdue($q1, 0, CarbonImmutable::parse('2026-04-01')));
    }

    public function test_grace_days_cross_the_year_end_and_leap_february(): void
    {
        $december = $this->periods->fromKey(Kpi::FREQUENCY_MONTHLY, '2026-12');
        $this->assertSame('2027-01-07', $this->periods->reportingDeadline($december, 7)->toDateString());

        $leapFebruary = $this->periods->fromKey(Kpi::FREQUENCY_MONTHLY, '2028-02');
        $this->assertSame('2028-03-07', $this->periods->reportingDeadline($leapFebruary, 7)->toDateString());

        $week53 = $this->periods->fromKey(Kpi::FREQUENCY_WEEKLY, '2026-W53');
        $this->assertSame('2027-01-10', $this->periods->reportingDeadline($week53, 7)->toDateString());
    }

    public function test_negative_grace_days_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->periods->reportingDeadline($this->periods->fromKey(Kpi::FREQUENCY_YEARLY, '2026'), -1);
    }

    /** @return array<string, array{string, int, string, string}> */
    public static function duePeriods(): array
    {
        return [
            'monthly, inside the grace days: the month before last is the latest due' => [Kpi::FREQUENCY_MONTHLY, 7, '2026-02-07', '2025-12'],
            'monthly, the day after the grace days: last month is due' => [Kpi::FREQUENCY_MONTHLY, 7, '2026-02-08', '2026-01'],
            'monthly, zero grace days: last month is due on the 1st' => [Kpi::FREQUENCY_MONTHLY, 0, '2026-03-01', '2026-02'],
            'quarterly, early in Q2' => [Kpi::FREQUENCY_QUARTERLY, 7, '2026-04-03', '2025-Q4'],
            'quarterly, after the grace days' => [Kpi::FREQUENCY_QUARTERLY, 7, '2026-04-08', '2026-Q1'],
            'weekly, across the year end' => [Kpi::FREQUENCY_WEEKLY, 0, '2027-01-04', '2026-W53'],
            'weekly, grace longer than a week' => [Kpi::FREQUENCY_WEEKLY, 10, '2026-10-05', '2026-W38'],
            'yearly, early January' => [Kpi::FREQUENCY_YEARLY, 7, '2027-01-05', '2025'],
            'yearly, after the grace days' => [Kpi::FREQUENCY_YEARLY, 7, '2027-01-08', '2026'],
        ];
    }

    #[DataProvider('duePeriods')]
    public function test_the_latest_due_period_is_never_the_running_one(string $frequency, int $graceDays, string $today, string $expectedKey): void
    {
        $period = $this->periods->latestDuePeriod($frequency, $graceDays, CarbonImmutable::parse($today.' 09:00'));

        $this->assertSame($expectedKey, $period->key);
        $this->assertTrue($this->periods->isOverdue($period, $graceDays, CarbonImmutable::parse($today)));
        $this->assertFalse($this->periods->isOverdue($this->periods->next($period), $graceDays, CarbonImmutable::parse($today)));
    }

    /** @return array{string, string, string} */
    private function describe(KpiPeriod $period): array
    {
        return [$period->start->toDateString(), $period->end->toDateString(), $period->key];
    }
}
