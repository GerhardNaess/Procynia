<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierComplianceRequirement;
use App\Models\SupplierStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ta i bruk, Avslutt leverandør and Gjenåpne leverandør — the only way a supplier's status changes —
 * and Slett leverandør.
 *
 *   onboarding → active          activate()   no begrunnelse
 *   onboarding, active → ended   end()        begrunnelse required
 *   ended → active               reopen()     begrunnelse required
 *
 * There is no way back from Aktiv to Under vurdering.
 *
 * Each writes the supplier's status and one immutable SupplierStatusChange in one transaction, with
 * the supplier row locked and its status checked again inside the lock, so a double submit or two
 * people at once cannot write the same change twice.
 *
 * Slett leverandør locks the row the same way and asks Supplier::isDeletable() again inside the lock.
 * The supplier's earlier links to Etterlevelse og revisjon (SupplierComplianceRequirement, historical
 * only, with no way left to remove them) go with it in the same transaction — never on their own, and
 * never the compliance requirements themselves.
 *
 * Authorization is the caller's (supplier.edit through SupplierAccessService). This only guards the
 * transition itself.
 */
class SupplierLifecycleService
{
    public function activate(Supplier $supplier, User $actor): Supplier
    {
        return $this->transition($supplier, $actor, [Supplier::STATUS_ONBOARDING], Supplier::STATUS_ACTIVE, null);
    }

    public function end(Supplier $supplier, User $actor, ?string $reason): Supplier
    {
        return $this->transition($supplier, $actor, [Supplier::STATUS_ONBOARDING, Supplier::STATUS_ACTIVE], Supplier::STATUS_ENDED, $this->requiredReason($reason));
    }

    public function reopen(Supplier $supplier, User $actor, ?string $reason): Supplier
    {
        return $this->transition($supplier, $actor, [Supplier::STATUS_ENDED], Supplier::STATUS_ACTIVE, $this->requiredReason($reason));
    }

    /**
     * Slett leverandør: false, and nothing changed, when the supplier may not be deleted (anymore). The
     * links and the supplier go together or not at all.
     */
    public function delete(Supplier $supplier): bool
    {
        return DB::transaction(function () use ($supplier): bool {
            $locked = Supplier::query()
                ->whereKey($supplier->id)
                ->where('customer_id', (int) $supplier->customer_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isDeletable()) {
                return false;
            }

            SupplierComplianceRequirement::query()
                ->where('supplier_id', (int) $locked->id)
                ->where('customer_id', (int) $locked->customer_id)
                ->delete();

            $locked->delete();

            return true;
        });
    }

    private function requiredReason(?string $reason): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('procynia.supplier_management.validation.reason_required'),
            ]);
        }

        return $reason;
    }

    /** @param  list<string>  $from */
    private function transition(Supplier $supplier, User $actor, array $from, string $to, ?string $reason): Supplier
    {
        return DB::transaction(function () use ($supplier, $actor, $from, $to, $reason): Supplier {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    'reason' => __('procynia.supplier_management.validation.transition_not_allowed'),
                ]);
            }

            SupplierStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'from_status' => $locked->status,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ]);

            $locked->forceFill([
                'status' => $to,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }
}
