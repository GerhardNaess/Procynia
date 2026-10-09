<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Notifications\UserNotificationWriter;
use App\Support\CustomerContext;

/**
 * Leverandøroppfølging's notifications in the bell.
 *
 * One event, by design (docs/notifications-and-tasks-plan.md §6): somebody became intern ansvarlig
 * for a supplier. That is the only assignment the supplier data model has — owner_user_id — and
 * being handed a supplier is news; a field edited on the register is not. Whether the supplier then
 * needs follow-up is «Mine oppgaver»'s, read live from the attention rules, and reading or deleting
 * this notification changes none of it.
 *
 * Deadline reminders are not here. They are a separate, later job (plan §8).
 */
class SupplierNotificationService
{
    public const EVENT_OWNER_ASSIGNED = 'supplier.owner_assigned';

    public function __construct(
        private readonly UserNotificationWriter $writer,
        private readonly SupplierAccessService $access,
        private readonly CustomerContext $customerContext,
    ) {}

    /**
     * The supplier's intern ansvarlig is now someone it was not before — a new supplier, or a
     * reassignment. Called by the controller after the write, with the owner as it was before it, so
     * a save that leaves the owner unchanged notifies nobody.
     *
     * The supplier's updated_at is in the dedupe key, which is what separates one handover from the
     * next: Gerhard → Alisan → Gerhard is two real handovers to Gerhard and both are heard, while
     * the same write replayed is one. The actor is never told about their own choice, and the
     * recipient must still be able to read suppliers — someone named before losing supplier.view is
     * not told about a supplier they cannot open.
     */
    public function ownerAssigned(Supplier $supplier, ?int $previousOwnerId, ?User $actor): void
    {
        $ownerId = $supplier->owner_user_id !== null ? (int) $supplier->owner_user_id : null;

        if ($ownerId === null || $ownerId === $previousOwnerId) {
            return;
        }

        $recipient = User::query()->find($ownerId);

        if (! $this->access->isValidOwner($recipient, (int) $supplier->customer_id)) {
            return;
        }

        $locale = $this->customerContext->resolveLanguageCode($recipient);

        $this->writer->notify(
            (int) $supplier->customer_id,
            $recipient,
            self::EVENT_OWNER_ASSIGNED,
            sprintf(
                '%s:%d:%d:%d',
                self::EVENT_OWNER_ASSIGNED,
                $supplier->id,
                $ownerId,
                optional($supplier->updated_at)?->getTimestamp() ?? 0,
            ),
            __('procynia.supplier_management.notifications.owner_assigned_title', [], $locale),
            __('procynia.supplier_management.notifications.owner_assigned_message', ['name' => $supplier->name], $locale),
            route('app.supplier-management.show', ['supplierId' => $supplier->id], false),
            [
                'supplier_id' => (int) $supplier->id,
                'previous_owner_user_id' => $previousOwnerId,
                'assigned_by_user_id' => $actor?->id !== null ? (int) $actor->id : null,
            ],
            actor: $actor,
        );
    }
}
