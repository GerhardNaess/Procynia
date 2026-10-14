<?php

namespace Tests\Unit\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiUnitRules;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a number may be in each KPI unit. A share is 0–100 %, a change in per cent is not limited,
 * a count is a non-negative whole number, currency has two decimals and may be negative, durations
 * are never negative, a plain number may be anything with up to four decimals. A tolerance is only
 * checked for its precision. No database — the KPI is built in memory.
 */
class KpiUnitRulesTest extends TestCase
{
    /** @return array<string, array{string, string, string|null}> */
    public static function values(): array
    {
        return [
            'share 0' => ['percent', '0', null],
            'share 100' => ['percent', '100', null],
            'share with four decimals' => ['percent', '99.9950', null],
            'share above 100' => ['percent', '100.0001', KpiUnitRules::ERROR_PERCENT_SHARE],
            'share below 0' => ['percent', '-1', KpiUnitRules::ERROR_PERCENT_SHARE],
            'change above 100' => ['percent_change', '250', null],
            'change negative' => ['percent_change', '-40.5', null],
            'count whole' => ['count', '12', null],
            'count with zero decimals' => ['count', '12.0000', null],
            'count with a fraction' => ['count', '12.5', KpiUnitRules::ERROR_WHOLE_NUMBER],
            'count negative' => ['count', '-1', KpiUnitRules::ERROR_NEGATIVE],
            'number negative with decimals' => ['number', '-12.3456', null],
            'currency negative' => ['currency', '-1250.75', null],
            'currency with three decimals' => ['currency', '10.125', KpiUnitRules::ERROR_DECIMALS],
            'hours with two decimals' => ['hours', '1.25', null],
            'hours negative' => ['hours', '-0.5', KpiUnitRules::ERROR_NEGATIVE],
            'days with three decimals' => ['days', '1.125', KpiUnitRules::ERROR_DECIMALS],
            'days zero' => ['days', '0', null],
        ];
    }

    #[DataProvider('values')]
    public function test_a_value_fits_its_unit_or_says_why_not(string $unit, string $value, ?string $expected): void
    {
        $this->assertSame($expected, KpiUnitRules::problem($unit, BigDecimal::of($value)));
    }

    public function test_a_tolerance_is_only_checked_for_its_precision(): void
    {
        $this->assertNull(KpiUnitRules::problem('percent', BigDecimal::of('150'), true));
        $this->assertNull(KpiUnitRules::problem('hours', BigDecimal::of('2'), true));
        $this->assertSame(KpiUnitRules::ERROR_WHOLE_NUMBER, KpiUnitRules::problem('count', BigDecimal::of('0.5'), true));
    }

    public function test_messages_are_in_the_readers_language(): void
    {
        app()->setLocale('no');
        $this->assertSame('Skriv høyst 2 desimaler for denne enheten.', KpiUnitRules::message('currency', BigDecimal::of('1.234')));
        $this->assertStringContainsString('Prosentvis endring', KpiUnitRules::message('percent', BigDecimal::of('130')));

        app()->setLocale('en');
        $this->assertSame('Enter a whole number.', KpiUnitRules::message('count', BigDecimal::of('2.5')));
        $this->assertNull(KpiUnitRules::message('count', BigDecimal::of('2')));
    }

    public function test_stored_numbers_that_no_longer_fit_are_pointed_out_never_changed(): void
    {
        // The real case: «30 % økning av FCV» saved as a share with a revenue interval.
        $kpi = new Kpi(['unit' => 'percent', 'target_min' => '1000000.0000', 'target_max' => '1300000.0000', 'tolerance' => '0.0000']);

        $this->assertSame(['target_min', 'target_max'], KpiUnitRules::definitionProblems($kpi));
        $this->assertSame('1000000.0000', $kpi->target_min);

        $this->assertSame([], KpiUnitRules::definitionProblems(new Kpi(['unit' => 'percent', 'target_min' => '99.5', 'tolerance' => '1'])));
        $this->assertSame(['target_max', 'tolerance'], KpiUnitRules::definitionProblems(new Kpi(['unit' => 'count', 'target_max' => '2.5', 'tolerance' => '0.5'])));
    }

    public function test_the_form_gets_the_same_rules(): void
    {
        $rules = KpiUnitRules::forClient();

        $this->assertSame(Kpi::UNITS, array_keys($rules));
        $this->assertSame(['decimals' => 0, 'min' => '0', 'max' => null], $rules['count']);
        $this->assertSame('100', $rules['percent']['max']);
    }
}
