<?php

namespace Tests\Unit\Objectives;

use App\Services\Objectives\KpiGrowth;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A målverdi set as change from a startverdi. The computed målverdi is exact, rounded to the unit
 * and towards the harder target; the change in per cent is derived back from it, never stored.
 */
class KpiGrowthTest extends TestCase
{
    /** @return array<string, array{string, string, string, string, string}> */
    public static function targets(): array
    {
        return [
            'FCV 30 % up' => ['currency', '1000000', '30', '1300000', 'target_min'],
            'cost 20 % down' => ['currency', '1000000', '-20', '800000', 'target_max'],
            'currency rounds up to øre' => ['currency', '100.5', '12.5', '113.07', 'target_min'],
            'count rounds an increase up' => ['count', '7', '30', '10', 'target_min'],
            'count rounds a decrease down' => ['count', '7', '-30', '4', 'target_max'],
            'a 100 % decrease is zero' => ['count', '20', '-100', '0', 'target_max'],
            'hours with decimals' => ['hours', '8', '-12.5', '7', 'target_max'],
            'number with four decimals' => ['number', '3', '33.33', '3.9999', 'target_min'],
        ];
    }

    #[DataProvider('targets')]
    public function test_the_target_is_computed_in_the_unit_towards_the_harder_side(string $unit, string $baseline, string $change, string $target, string $bound): void
    {
        $change = BigDecimal::of($change);

        $this->assertTrue(KpiGrowth::target($unit, BigDecimal::of($baseline), $change)->isEqualTo($target));
        $this->assertSame($bound, KpiGrowth::bound($change));
    }

    public function test_the_change_is_derived_back_from_the_startverdi_and_the_target(): void
    {
        $this->assertSame('30', (string) KpiGrowth::changePercent(BigDecimal::of('1000000.0000'), BigDecimal::of('1300000.0000')));
        $this->assertSame('-20', (string) KpiGrowth::changePercent(BigDecimal::of('1000000'), BigDecimal::of('800000')));
        $this->assertSame('33.33', (string) KpiGrowth::changePercent(BigDecimal::of('3'), BigDecimal::of('3.9999')));
        // Rounded towards the harder target, 7 saker + 30 % is 10 — a change of 42,86 %, which is what the page shows.
        $this->assertSame('42.86', (string) KpiGrowth::changePercent(BigDecimal::of('7'), BigDecimal::of('10')));
    }

    public function test_what_cannot_make_a_target_is_reported_by_field(): void
    {
        $this->assertSame(['target_type' => KpiGrowth::ERROR_UNIT], KpiGrowth::errors('percent', BigDecimal::of('1'), '2026-01-01', BigDecimal::of('10')));
        $this->assertSame(['target_type' => KpiGrowth::ERROR_UNIT], KpiGrowth::errors('percent_change', BigDecimal::of('1'), '2026-01-01', BigDecimal::of('10')));
        $this->assertSame([
            'baseline_value' => KpiGrowth::ERROR_BASELINE_REQUIRED,
            'baseline_date' => KpiGrowth::ERROR_BASELINE_DATE,
            'change_percent' => KpiGrowth::ERROR_CHANGE_REQUIRED,
        ], KpiGrowth::errors('currency', null, null, null));
        $this->assertSame(['baseline_value' => KpiGrowth::ERROR_BASELINE_POSITIVE], KpiGrowth::errors('currency', BigDecimal::of('0'), '2026-01-01', BigDecimal::of('10')));
        $this->assertSame(['change_percent' => KpiGrowth::ERROR_CHANGE_ZERO], KpiGrowth::errors('currency', BigDecimal::of('5'), '2026-01-01', BigDecimal::of('0')));
        $this->assertSame(['change_percent' => KpiGrowth::ERROR_CHANGE_BELOW_MINUS_HUNDRED], KpiGrowth::errors('currency', BigDecimal::of('5'), '2026-01-01', BigDecimal::of('-100.5')));
        $this->assertSame(['change_percent' => KpiGrowth::ERROR_CHANGE_DECIMALS], KpiGrowth::errors('currency', BigDecimal::of('5'), '2026-01-01', BigDecimal::of('10.125')));
        $this->assertSame([], KpiGrowth::errors('count', BigDecimal::of('5'), '2026-01-01', BigDecimal::of('-100')));
    }
}
