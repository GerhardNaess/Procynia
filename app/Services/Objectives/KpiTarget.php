<?php

namespace App\Services\Objectives;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * A KPI's målverdi: a lower bound, an upper bound or both, and one optional tolerance.
 *
 *  - only min:  value >= min is on target («Oppetid ≥ 99,5 %»)
 *  - only max:  value <= max is on target («Alvorlige avvik ≤ 3»)
 *  - both:      min <= value <= max is on target («Svartid 2–5 timer»)
 *
 * Exact decimals throughout (BigDecimal, the same library behind Laravel's decimal cast), never
 * floats: 99.5 - 1.0 must be exactly 98.5, or a value on the tolerance edge lands on the wrong side.
 *
 * The rules a target must satisfy live here, once: the model refuses to save a KPI that breaks
 * them, and the form reports the same rules field by field through errors().
 */
final class KpiTarget
{
    public const ERROR_NO_BOUND = 'no_bound';

    public const ERROR_MIN_ABOVE_MAX = 'min_above_max';

    public const ERROR_NEGATIVE_TOLERANCE = 'negative_tolerance';

    private function __construct(
        public readonly ?BigDecimal $min,
        public readonly ?BigDecimal $max,
        public readonly ?BigDecimal $tolerance,
    ) {}

    /**
     * @throws InvalidArgumentException when the target breaks one of its rules or a value is not a number
     */
    public static function of(BigDecimal|string|int|null $min, BigDecimal|string|int|null $max, BigDecimal|string|int|null $tolerance = null): self
    {
        $target = new self(self::decimal($min), self::decimal($max), self::decimal($tolerance));
        $errors = $target->errors();

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid KPI target: '.implode(', ', array_unique(array_values($errors))).'.');
        }

        return $target;
    }

    /**
     * What is wrong with this combination, keyed by the field the error belongs to. Empty when the
     * target is valid. Values that are not numbers are the caller's to reject first.
     *
     * @return array<string, string>
     */
    public static function errorsFor(BigDecimal|string|int|null $min, BigDecimal|string|int|null $max, BigDecimal|string|int|null $tolerance): array
    {
        return (new self(self::decimal($min), self::decimal($max), self::decimal($tolerance)))->errors();
    }

    public function hasMin(): bool
    {
        return $this->min !== null;
    }

    public function hasMax(): bool
    {
        return $this->max !== null;
    }

    public function isInterval(): bool
    {
        return $this->min !== null && $this->max !== null;
    }

    /** Zero and no tolerance mean the same: a value is either on target or off it. */
    public function hasTolerance(): bool
    {
        return $this->tolerance !== null && $this->tolerance->isPositive();
    }

    /** @return array<string, string> */
    private function errors(): array
    {
        $errors = [];

        if ($this->min === null && $this->max === null) {
            $errors['target_min'] = self::ERROR_NO_BOUND;
        }

        if ($this->min !== null && $this->max !== null && $this->min->isGreaterThan($this->max)) {
            $errors['target_max'] = self::ERROR_MIN_ABOVE_MAX;
        }

        if ($this->tolerance !== null && $this->tolerance->isNegative()) {
            $errors['tolerance'] = self::ERROR_NEGATIVE_TOLERANCE;
        }

        return $errors;
    }

    /**
     * A number as an exact decimal, or null for an empty value. Strings are what the database and
     * the decimal cast hand over; a float never reaches this.
     */
    public static function decimal(BigDecimal|string|int|null $value): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new InvalidArgumentException("Not a number [{$value}].", 0, $exception);
        }
    }
}
