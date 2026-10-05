<?php

namespace App\Support\Objectives;

use App\Models\Kpi;
use App\Services\Objectives\KpiTarget;
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

    /** The målverdi with its unit. */
    public function target(Kpi $kpi): string
    {
        $target = $kpi->target();

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
            Kpi::UNIT_PERCENT => $number.($this->isEnglish() ? '%' : self::NBSP.'%'),
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
