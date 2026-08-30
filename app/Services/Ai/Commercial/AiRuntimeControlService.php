<?php

namespace App\Services\Ai\Commercial;

use App\Models\AiRuntimeControl;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Runtime switches are database-backed so queue workers and web requests see the same state. */
class AiRuntimeControlService
{
    public function __construct(private readonly AiQuotaNotificationService $quotaNotifications) {}

    public function globalStopEnabled(): bool
    {
        $control = AiRuntimeControl::query()->orderBy('id')->first();

        // A missing control row is an invalid operational state: do not permit paid calls.
        return ! $control instanceof AiRuntimeControl || $control->global_ai_stop;
    }

    public function setGlobalStop(bool $enabled, ?User $actor = null, ?string $reason = null): AiRuntimeControl
    {
        return DB::transaction(function () use ($enabled, $actor, $reason): AiRuntimeControl {
            $control = AiRuntimeControl::query()->lockForUpdate()->orderBy('id')->firstOrFail();
            $before = ['global_ai_stop' => (bool) $control->global_ai_stop];
            $control->forceFill(['global_ai_stop' => $enabled, 'changed_by_user_id' => $actor?->id, 'reason' => $reason])->save();

            BillingEvent::query()->create([
                'customer_id' => null,
                'user_id' => $actor?->id,
                'event_type' => $enabled ? 'ai_global_stop_enabled' : 'ai_global_stop_disabled',
                'source' => 'ai_cost_control',
                'description' => $reason,
                'before' => $before,
                'after' => ['global_ai_stop' => $enabled],
            ]);

            return $control->refresh();
        });
    }

    public function setCustomerAccess(Customer $customer, string $status, ?User $actor = null, ?string $reason = null): Customer
    {
        if (! in_array($status, [Customer::AI_ACCESS_ENABLED, Customer::AI_ACCESS_SUSPENDED], true)) {
            throw new \InvalidArgumentException('Invalid customer AI access status.');
        }

        $before = null;
        $customer = DB::transaction(function () use ($customer, $status, $actor, $reason, &$before): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $locked->ai_access_status ?? Customer::AI_ACCESS_ENABLED;
            $locked->forceFill(['ai_access_status' => $status])->save();

            BillingEvent::query()->create([
                'customer_id' => $locked->id,
                'user_id' => $actor?->id,
                'event_type' => $status === Customer::AI_ACCESS_SUSPENDED ? 'ai_customer_suspended' : 'ai_customer_resumed',
                'source' => 'ai_cost_control',
                'description' => $reason,
                'before' => ['ai_access_status' => $before],
                'after' => ['ai_access_status' => $status],
            ]);

            return $locked->refresh();
        });

        // A suspension is something the customer must be able to see and act on, not only an
        // internal switch. The global stop stays silent to customers on purpose: it is a platform
        // incident, not a fact about their account.
        if ($before !== $status) {
            $this->quotaNotifications->notifyAccessChanged($customer, $status === Customer::AI_ACCESS_SUSPENDED);
        }

        return $customer;
    }
}
