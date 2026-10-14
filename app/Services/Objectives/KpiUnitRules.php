<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use Brick\Math\BigDecimal;

/**
 * What a number may be in each KPI unit — the målverdi, the startverdi, the tolerance and every
 * measured value alike.
 *
 *  - percent (prosentandel):  0–100, up to four decimals (99,995 % oppetid)
 *  - percent_change:          any sign, above 100 too, up to four decimals
 *  - count (antall):          whole numbers, never negative
 *  - number (tall):           any sign, up to four decimals
 *  - currency (valuta):       any sign, up to two decimals (øre)
 *  - hours, days:             never negative, up to two decimals
 *
 * Four decimals is what the columns hold (numeric(20,4)); the other limits are what the unit means.
 * These are business rules, enforced where a person enters a number — KpiController and
 * KpiMeasurementController, and the same rules in the form through forClient(). The database does
 * not enforce them, on purpose: a KPI saved before the rules existed keeps its numbers, and
 * definitionProblems() lets the page point at what no longer fits instead of the system guessing.
 */
final class KpiUnitRules
{
    public const ERROR_WHOLE_NUMBER = 'whole_number';

    public const ERROR_DECIMALS = 'too_many_decimals';

    public const ERROR_NEGATIVE = 'negative';

    public const ERROR_PERCENT_SHARE = 'percent_share';

    /** @var array<string, array{decimals: int, min: ?string, max: ?string}> */
    private const RULES = [
        Kpi::UNIT_PERCENT => ['decimals' => 4, 'min' => '0', 'max' => '100'],
        Kpi::UNIT_PERCENT_CHANGE => ['decimals' => 4, 'min' => null, 'max' => null],
        Kpi::UNIT_COUNT => ['decimals' => 0, 'min' => '0', 'max' => null],
        Kpi::UNIT_NUMBER => ['decimals' => 4, 'min' => null, 'max' => null],
        Kpi::UNIT_CURRENCY => ['decimals' => 2, 'min' => null, 'max' => null],
        Kpi::UNIT_HOURS => ['decimals' => 2, 'min' => '0', 'max' => null],
        Kpi::UNIT_DAYS => ['decimals' => 2, 'min' => '0', 'max' => null],
    ];

    /** How many decimals a number in this unit may have. */
    public static function decimals(string $unit): int
    {
        return self::RULES[$unit]['decimals'] ?? 4;
    }

    /**
     * What is wrong with this number in this unit, as an error code, or null when it fits. A
     * tolerance is an amount, not a value, so only its precision is checked here — that it is not
     * negative is KpiTarget's rule.
     */
    public static function problem(string $unit, BigDecimal $value, bool $amountOnly = false): ?string
    {
        $rule = self::RULES[$unit] ?? self::RULES[Kpi::UNIT_NUMBER];

        if ($value->strippedOfTrailingZeros()->getScale() > $rule['decimals']) {
            return $rule['decimals'] === 0 ? self::ERROR_WHOLE_NUMBER : self::ERROR_DECIMALS;
        }

        if ($amountOnly) {
            return null;
        }

        if ($unit === Kpi::UNIT_PERCENT && ($value->isNegative() || $value->isGreaterThan(100))) {
            return self::ERROR_PERCENT_SHARE;
        }

        if ($rule['min'] !== null && $value->isLessThan($rule['min'])) {
            return self::ERROR_NEGATIVE;
        }

        return null;
    }

    /** The problem as the person reads it, or null. */
    public static function message(string $unit, BigDecimal $value, bool $amountOnly = false): ?string
    {
        $code = self::problem($unit, $value, $amountOnly);

        return $code !== null
            ? (string) __("procynia.objectives.kpi.validation.unit_rules.{$code}", ['count' => self::decimals($unit)])
            : null;
    }

    /**
     * The stored numbers of a KPI that do not fit its unit — from before these rules, or from a
     * unit chosen for the wrong thing («Prosentandel» for 1 300 000). Field names, in form order.
     *
     * @return list<string>
     */
    public static function definitionProblems(Kpi $kpi): array
    {
        $fields = [];

        foreach (['target_min', 'target_max', 'baseline_value', 'tolerance'] as $field) {
            $value = KpiTarget::decimal($kpi->getAttribute($field));

            if ($value !== null && self::problem($kpi->unit, $value, $field === 'tolerance') !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The same rules for the form, so it can say what is wrong before anything is sent.
     *
     * @return array<string, array{decimals: int, min: ?string, max: ?string}>
     */
    public static function forClient(): array
    {
        return self::RULES;
    }
}
