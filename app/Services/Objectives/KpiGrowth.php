<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A målverdi set as change from a startverdi: «30 % økning fra 1 000 000 kr» is «minst 1 300 000 kr».
 *
 * The computed målverdi is what is stored and judged — an increase as target_min, a decrease as
 * target_max — so a growth KPI is evaluated exactly like any other «Minst» or «Høyst» KPI. The change
 * in per cent is what the person typed and what the page shows, but it is derived again from the
 * startverdi and the målverdi (changePercent()), never stored next to them.
 *
 * Rounding is to the unit's decimals (KpiUnitRules), and always towards the harder target: an
 * increase rounds up, a decrease rounds down. 7 saker + 30 % is «minst 10», not «minst 9».
 *
 * A change in per cent of a per cent is ambiguous (relative or percentage points), so the percent
 * units take no startverdi (UNITS).
 */
final class KpiGrowth
{
    /** The units a målverdi can be set as change from a startverdi in. */
    public const UNITS = [Kpi::UNIT_CURRENCY, Kpi::UNIT_COUNT, Kpi::UNIT_NUMBER, Kpi::UNIT_HOURS, Kpi::UNIT_DAYS];

    /** «30», «12,5» or «-20,25» per cent. */
    public const CHANGE_DECIMALS = 2;

    public const ERROR_UNIT = 'growth_unit';

    public const ERROR_BASELINE_REQUIRED = 'baseline_required';

    public const ERROR_BASELINE_POSITIVE = 'baseline_positive';

    public const ERROR_BASELINE_DATE = 'baseline_date_required';

    public const ERROR_CHANGE_REQUIRED = 'change_required';

    public const ERROR_CHANGE_ZERO = 'change_zero';

    public const ERROR_CHANGE_BELOW_MINUS_HUNDRED = 'change_below_minus_hundred';

    public const ERROR_CHANGE_DECIMALS = 'change_decimals';

    /** The målverdi the change leads to, in the unit's precision, rounded towards the harder target. */
    public static function target(string $unit, BigDecimal $baseline, BigDecimal $changePercent): BigDecimal
    {
        $exact = $baseline->multipliedBy(BigDecimal::of(100)->plus($changePercent))
            ->dividedBy(100, $baseline->getScale() + $changePercent->getScale() + 2, RoundingMode::Unnecessary);

        return $exact->toScale(
            KpiUnitRules::decimals($unit),
            $changePercent->isPositive() ? RoundingMode::Ceiling : RoundingMode::Floor,
        );
    }

    /** Which bound the målverdi is: target_min for an increase, target_max for a decrease. */
    public static function bound(BigDecimal $changePercent): string
    {
        return $changePercent->isPositive() ? 'target_min' : 'target_max';
    }

    /** The change from startverdi to målverdi in per cent, to two decimals: 1 000 000 → 1 300 000 is 30. */
    public static function changePercent(BigDecimal $baseline, BigDecimal $target): BigDecimal
    {
        return $target->minus($baseline)->multipliedBy(100)
            ->dividedBy($baseline, self::CHANGE_DECIMALS, RoundingMode::HalfUp)
            ->strippedOfTrailingZeros();
    }

    /**
     * What is wrong with a growth target as typed, keyed by field. Empty when it can be computed.
     *
     * @return array<string, string>
     */
    public static function errors(string $unit, ?BigDecimal $baseline, ?string $baselineDate, ?BigDecimal $changePercent): array
    {
        if (! in_array($unit, self::UNITS, true)) {
            return ['target_type' => self::ERROR_UNIT];
        }

        $errors = [];

        if ($baseline === null) {
            $errors['baseline_value'] = self::ERROR_BASELINE_REQUIRED;
        } elseif (! $baseline->isPositive()) {
            $errors['baseline_value'] = self::ERROR_BASELINE_POSITIVE;
        }

        if ($baselineDate === null) {
            $errors['baseline_date'] = self::ERROR_BASELINE_DATE;
        }

        if ($changePercent === null) {
            $errors['change_percent'] = self::ERROR_CHANGE_REQUIRED;
        } elseif ($changePercent->isZero()) {
            $errors['change_percent'] = self::ERROR_CHANGE_ZERO;
        } elseif ($changePercent->isLessThan(-100)) {
            $errors['change_percent'] = self::ERROR_CHANGE_BELOW_MINUS_HUNDRED;
        } elseif ($changePercent->strippedOfTrailingZeros()->getScale() > self::CHANGE_DECIMALS) {
            $errors['change_percent'] = self::ERROR_CHANGE_DECIMALS;
        }

        return $errors;
    }
}
