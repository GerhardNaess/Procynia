<?php

namespace App\Services\Objectives;

use Brick\Math\BigDecimal;

/**
 * Where a value stands against a KPI's målverdi: on target, attention or off target.
 *
 * The tolerance is room outside the target, not inside it. With «≥ 99,5» and tolerance 1,0 the
 * value 99,5 is on target, 98,5 (exactly at the edge) is attention and 98,4 is off target. For an
 * upper bound the room lies above it, for an interval on both sides, by the same amount. Without a
 * tolerance (null or 0) there is no middle: on target or off target.
 *
 * Both edges are inclusive: a value exactly on the bound is on target, a value exactly on the
 * tolerance edge is attention.
 *
 * This decides; it does not present. Whether a value exists to evaluate (Måling mangler) is a
 * question about measurements and periods, not about the target, and is not answered here.
 */
final class KpiTargetPolicy
{
    public const ON_TARGET = 'on_target';

    public const ATTENTION = 'attention';

    public const OFF_TARGET = 'off_target';

    public const RESULTS = [self::ON_TARGET, self::ATTENTION, self::OFF_TARGET];

    public function evaluate(KpiTarget $target, BigDecimal|string|int $value): string
    {
        $value = $value instanceof BigDecimal ? $value : BigDecimal::of($value);

        if ($this->within($target, $value, BigDecimal::zero())) {
            return self::ON_TARGET;
        }

        if ($target->hasTolerance() && $this->within($target, $value, $target->tolerance)) {
            return self::ATTENTION;
        }

        return self::OFF_TARGET;
    }

    /** Whether the value lies inside the target widened by the given margin on each bounded side. */
    private function within(KpiTarget $target, BigDecimal $value, BigDecimal $margin): bool
    {
        if ($target->min !== null && $value->isLessThan($target->min->minus($margin))) {
            return false;
        }

        if ($target->max !== null && $value->isGreaterThan($target->max->plus($margin))) {
            return false;
        }

        return true;
    }
}
