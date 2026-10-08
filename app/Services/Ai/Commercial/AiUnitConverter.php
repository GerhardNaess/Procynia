<?php

namespace App\Services\Ai\Commercial;

use InvalidArgumentException;

/**
 * The one conversion from internal AI cost to customer-facing AI units.
 *
 * `units = cost_nok / nok_per_unit` (config/ai_customer_capacity.php). Nothing else in the code base
 * may know how many kroner a unit is: modules, the capacity gate and the UI all speak units, so the
 * rate can change without touching any of them.
 *
 * Exact units are kept as floats for every comparison. Rounding happens once, on the aggregate of
 * a whole period and only for display — rounding each call would turn a thousand 0.2-unit calls
 * into a thousand units.
 */
class AiUnitConverter
{
    public function nokPerUnit(): float
    {
        $rate = (float) config('ai_customer_capacity.nok_per_unit');

        if (! is_finite($rate) || $rate <= 0) {
            throw new InvalidArgumentException('ai_customer_capacity.nok_per_unit must be a positive number.');
        }

        return $rate;
    }

    public function unitsForCost(?float $costNok): float
    {
        return $costNok === null ? 0.0 : max(0.0, $costNok) / $this->nokPerUnit();
    }

    /**
     * Whole units as the customer sees them: rounded up, so the page never shows less than was
     * actually consumed. Float noise below a millionth of a unit is dropped first, so exactly
     * 32.0 units does not become 33.
     */
    public function displayUnits(float $units): int
    {
        return (int) ceil(round(max(0.0, $units), 6));
    }
}
