<?php

namespace App\Services\Ai\Commercial;

use App\Models\Customer;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Modules\ModuleEntitlementService;

/**
 * A customer's base AI capacity, in AI units per month, from the shape of its subscription:
 *
 *   basis (when Basis is active) + per_user × active users + the weight of each active option
 *
 * The weights are fixed, manually calibrated values in config/ai_customer_capacity.php `base`. They
 * only size the customer's one shared pool; no module gets capacity of its own. Read fresh every
 * time, so a user or option added or removed changes the base at once — the chosen tier never moves.
 */
class AiBaseCapacityCalculator
{
    public function __construct(
        private readonly ModuleEntitlementService $modules,
        private readonly BillingEntitlementService $billing,
    ) {}

    public function unitsPerMonth(Customer $customer): int
    {
        $base = (array) config('ai_customer_capacity.base', []);
        $options = (array) ($base['options'] ?? []);
        $units = 0;

        foreach ($this->modules->activePackageKeys($customer) as $package) {
            if (($this->modules->package($package)['kind'] ?? null) === ModuleEntitlementService::KIND_BASE) {
                $units += $this->weight($base['basis'] ?? 0);
            } elseif ($this->modules->isOption($package)) {
                $units += $this->weight($options[$package] ?? 0);
            }
        }

        return $units + $this->weight($base['per_user'] ?? 0) * $this->billing->currentBillableUsers($customer);
    }

    private function weight(mixed $value): int
    {
        return max(0, (int) $value);
    }
}
