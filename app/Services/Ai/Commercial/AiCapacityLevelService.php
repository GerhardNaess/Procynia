<?php

namespace App\Services\Ai\Commercial;

use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own choice of AI capacity level (Abonnement → AI-kapasitet → Endre nivå).
 *
 * Only the tier key changes. Usage is not touched and the billing period does not restart: the new
 * level applies at once, to the current period as a whole (v1 — there is no price to prorate yet).
 * The level is the customer's commercial choice; nothing else in the system ever changes it.
 */
class AiCapacityLevelService
{
    public const REFUSED_UNKNOWN = 'unknown';

    public const REFUSED_OVERRIDE = 'override';

    public const REFUSED_UNCHANGED = 'unchanged';

    public function __construct(
        private readonly AiCapacityTierCatalog $tiers,
        private readonly CustomerAiCapacityService $capacity,
    ) {}

    /**
     * Sets the customer's level. Returns null on success, else one of the REFUSED_* reasons; a
     * refusal writes nothing.
     */
    public function change(Customer $customer, string $tierKey, User $actor): ?string
    {
        return DB::transaction(function () use ($customer, $tierKey, $actor): ?string {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $current = $this->tiers->effective($customer->ai_capacity_tier)['key'] ?? null;

            if (! array_key_exists($tierKey, $this->tiers->choosable($current))) {
                return self::REFUSED_UNKNOWN;
            }

            // An override sizes the capacity on its own; a level chosen under it would do nothing.
            if ($customer->included_ai_units !== null) {
                return self::REFUSED_OVERRIDE;
            }

            if ($tierKey === $current) {
                return self::REFUSED_UNCHANGED;
            }

            $before = $this->capacity->forCustomer($customer)->includedUnits;
            $customer->forceFill(['ai_capacity_tier' => $tierKey])->save();
            $after = $this->capacity->forCustomer($customer)->includedUnits;

            // The same trail the admin's tier changes write, so AI-kontroll shows both.
            BillingEvent::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'event_type' => 'ai_capacity_tier_changed',
                'source' => 'ai_cost_control',
                'description' => __('procynia.ai_admin.capacity.self_service_reason'),
                'before' => ['ai_capacity_tier' => $current, 'included_units' => $before],
                'after' => ['ai_capacity_tier' => $tierKey, 'included_units' => $after],
            ]);

            return null;
        });
    }
}
