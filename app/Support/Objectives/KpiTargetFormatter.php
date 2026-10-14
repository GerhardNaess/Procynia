<?php

namespace App\Support\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiGrowth;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiTargetPolicy;
use Brick\Math\BigDecimal;

/**
 * How a KPI's målverdi and unit read on the page: «≥ 99,5 %», «≤ 3 hendelser», «2–5 timer»,
 * «≥ NOK 1 000 000».
 *
 * The one place this is written. Pages receive the finished strings, so the objective's KPI list and
 * the KPI page cannot drift apart. It only presents — whether a value is on target is
 * KpiTargetPolicy's decision, and nothing here is read back as data.
 *
 * Numbers come in as exact decimals and are written without ever passing through a float: trailing
 * zeros dropped (99.5000 → 99,5), thousands grouped with a non-breaking space in Norwegian and a
 * comma in English, so a target never wraps in the middle of a number.
 */
final class KpiTargetFormatter
{
    private const NBSP = "\u{00A0}";

    public function __construct(private readonly ?string $locale = null) {}

    /**
     * The målverdi with its unit — the KPI's own, or another one in the same unit, such as the
     * snapshot a measurement was registered against.
     */
    public function target(Kpi $kpi, ?KpiTarget $target = null): string
    {
        $target ??= $kpi->target();

        if ($target->isInterval() && $target->min->isEqualTo($target->max)) {
            return '='.self::NBSP.$this->withUnit($kpi, $this->number($target->min), $target->min);
        }

        if ($target->isInterval()) {
            return $this->withUnit($kpi, $this->number($target->min).'–'.$this->number($target->max), null);
        }

        $bound = $target->hasMin() ? $target->min : $target->max;
        $sign = $target->hasMin() ? '≥' : '≤';

        return $sign.self::NBSP.$this->withUnit($kpi, $this->number($bound), $bound);
    }

    /** One amount in the KPI's unit, such as its tolerance: «1 %», «2 timer», «NOK 500». */
    public function amount(Kpi $kpi, BigDecimal|string $value): string
    {
        $value = KpiTarget::decimal($value);

        return $this->withUnit($kpi, $this->number($value), $value);
    }

    /**
     * The tolerance as an amount in the KPI's unit — for the percent units in percentage points, so
     * «2» is never read as two per cent of the målverdi: «2 prosentpoeng», «2 timer», «NOK 500».
     */
    public function tolerance(Kpi $kpi, BigDecimal|string $value): string
    {
        if (in_array($kpi->unit, Kpi::PERCENT_UNITS, true)) {
            $value = KpiTarget::decimal($value);

            return $this->number($value).self::NBSP.__(
                'procynia.objectives.kpi.percentage_points.'.($value->isEqualTo(1) ? 'one' : 'other'),
                [],
                $this->locale(),
            );
        }

        return $this->amount($kpi, $value);
    }

    /**
     * What counts as reaching the målverdi, as one sentence: «Målet er nådd når verdien er minst
     * NOK 1 300 000.» Followed, for a målverdi set from a startverdi, by where it comes from.
     */
    public function rule(Kpi $kpi, ?KpiTarget $target = null): string
    {
        $target ??= $kpi->target();
        $key = 'procynia.objectives.kpi.rule.'.$target->type();
        $sentence = (string) match ($target->type()) {
            KpiTarget::TYPE_AT_LEAST => __($key, ['value' => $this->amount($kpi, $target->min)], $this->locale()),
            KpiTarget::TYPE_AT_MOST => __($key, ['value' => $this->amount($kpi, $target->max)], $this->locale()),
            KpiTarget::TYPE_EXACT => __($key, ['value' => $this->amount($kpi, $target->min)], $this->locale()),
            default => __($key, ['min' => $this->amount($kpi, $target->min), 'max' => $this->amount($kpi, $target->max)], $this->locale()),
        };

        $growth = $this->growth($kpi);

        return $growth !== null ? $sentence.' '.$growth : $sentence;
    }

    /**
     * Where a målverdi set from a startverdi comes from: «Det er en økning på 30 % fra startverdien
     * NOK 1 000 000 (1. oktober 2026).» Null for any other KPI. The change is derived, never stored.
     */
    public function growth(Kpi $kpi): ?string
    {
        if (! $kpi->hasBaseline()) {
            return null;
        }

        $baseline = KpiTarget::decimal($kpi->baseline_value);
        $change = KpiGrowth::changePercent($baseline, KpiTarget::decimal($kpi->target_min ?? $kpi->target_max));

        return (string) __('procynia.objectives.kpi.growth_sentence.'.($change->isNegative() ? 'decrease' : 'increase'), [
            'change' => $this->change($change),
            'baseline' => $this->amount($kpi, $baseline),
            'date' => (new KpiPeriodFormatter(locale: $this->locale()))->date($kpi->baseline_date),
        ], $this->locale());
    }

    /** The change from the startverdi as a label: «Økning på 30 %», «Reduksjon på 20 %». */
    public function changeLabel(Kpi $kpi): ?string
    {
        if (! $kpi->hasBaseline()) {
            return null;
        }

        $change = KpiGrowth::changePercent(KpiTarget::decimal($kpi->baseline_value), KpiTarget::decimal($kpi->target_min ?? $kpi->target_max));

        return (string) __('procynia.objectives.kpi.change_label.'.($change->isNegative() ? 'decrease' : 'increase'), [
            'change' => $this->change($change),
        ], $this->locale());
    }

    /** A change in per cent without its sign — the sentence says which way: «30 %», «12,5 %». */
    public function change(BigDecimal $percent): string
    {
        return $this->number($percent->abs()).($this->isEnglish() ? '%' : self::NBSP.'%');
    }

    /**
     * What the tolerance means for this KPI, or null without one: «Inntil 2 dager over målet gir
     * «Trenger oppmerksomhet».» Always outwards — the tolerance never turns the direction around.
     */
    public function toleranceRule(Kpi $kpi, ?KpiTarget $target = null): ?string
    {
        $target ??= $kpi->target();

        if (! $target->hasTolerance()) {
            return null;
        }

        $side = match ($target->type()) {
            KpiTarget::TYPE_AT_LEAST => 'below',
            KpiTarget::TYPE_AT_MOST => 'above',
            default => 'outside',
        };

        return (string) __("procynia.objectives.kpi.tolerance_rule.{$side}", [
            'tolerance' => $this->tolerance($kpi, $target->tolerance),
        ], $this->locale());
    }

    /**
     * Why the KPI has the status it has, in one sentence: the latest value, which side of the
     * målverdi it is on, and whether the tolerance kept it at «Trenger oppmerksomhet». $value is null
     * when there is no current measurement.
     */
    public function explanation(Kpi $kpi, ?string $value, string $result, ?KpiTarget $target = null): string
    {
        $target ??= $kpi->target();
        $t = 'procynia.objectives.kpi.explanation';

        if ($value === null) {
            return (string) __("{$t}.not_measured", [], $this->locale());
        }

        $decimal = KpiTarget::decimal($value);
        $replace = [
            'value' => $this->amount($kpi, $decimal),
            'target' => $this->target($kpi, $target),
            'tolerance' => $target->hasTolerance() ? $this->tolerance($kpi, $target->tolerance) : '',
        ];

        if ($result === KpiTargetPolicy::ON_TARGET) {
            return (string) __("{$t}.on_target", $replace, $this->locale());
        }

        $side = $target->min !== null && $decimal->isLessThan($target->min) ? 'below' : 'above';
        $key = $result === KpiTargetPolicy::ATTENTION
            ? "attention_{$side}"
            : ($target->hasTolerance() ? "off_target_{$side}_tolerance" : "off_target_{$side}");

        return (string) __("{$t}.{$key}", $replace, $this->locale());
    }

    /** The unit as a column reads it: «Prosent», «Antall (hendelser)», «Valuta (NOK)». */
    public function unit(Kpi $kpi): string
    {
        $name = (string) __("procynia.objectives.kpi.units.{$kpi->unit}", [], $this->locale());

        $detail = match (true) {
            $kpi->unit === Kpi::UNIT_CURRENCY => $kpi->currency_code,
            $kpi->unit_label !== null => $kpi->unit_label,
            default => null,
        };

        return $detail !== null ? "{$name} ({$detail})" : $name;
    }

    /** An exact decimal in the reader's notation, without trailing zeros. */
    public function number(BigDecimal $value): string
    {
        $english = $this->isEnglish();
        $plain = (string) $value->strippedOfTrailingZeros();
        $negative = str_starts_with($plain, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($plain, '-'), 2), 2, null);

        // Groups of three from the right; the digits are ASCII, the separator may not be.
        $groups = array_map(strrev(...), array_reverse(str_split(strrev($whole), 3)));
        $grouped = implode($english ? ',' : self::NBSP, $groups);

        return ($negative ? '-' : '').$grouped.($fraction !== null ? ($english ? '.' : ',').$fraction : '');
    }

    /**
     * The number (or range) written with the unit. $single is the value when there is one, for
     * «1 time» against «2 timer»; null for a range, which always reads in the plural.
     */
    private function withUnit(Kpi $kpi, string $number, ?BigDecimal $single): string
    {
        $one = $single !== null && $single->isEqualTo(1);
        $locale = $this->locale();

        return match ($kpi->unit) {
            Kpi::UNIT_PERCENT, Kpi::UNIT_PERCENT_CHANGE => $number.($this->isEnglish() ? '%' : self::NBSP.'%'),
            Kpi::UNIT_CURRENCY => $kpi->currency_code.self::NBSP.$number,
            Kpi::UNIT_HOURS, Kpi::UNIT_DAYS => $number.self::NBSP.__(
                "procynia.objectives.kpi.unit_suffix.{$kpi->unit}.".($one ? 'one' : 'other'),
                [],
                $locale,
            ),
            default => $kpi->unit_label !== null ? $number.self::NBSP.$kpi->unit_label : $number,
        };
    }

    private function locale(): string
    {
        return $this->locale ?? app()->getLocale();
    }

    private function isEnglish(): bool
    {
        return str_starts_with(strtolower($this->locale()), 'en');
    }
}
