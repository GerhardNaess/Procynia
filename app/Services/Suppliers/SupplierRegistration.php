<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Registering a supplier: the master data rules and the write, shared by «Registrer leverandør» and
 * the Excel import (SupplierImportService), so both register a supplier by exactly the same rules.
 *
 * The master data is checked with masterDataRules(); the intern ansvarlig must be a valid owner
 * (SupplierAccessService::isValidOwner()); the organisation number is unique within the customer —
 * «987 654 321» and «987654321» are the same number. The status and classification a supplier is
 * registered with are its own, never a change (plan §5 A, §4.2); after that, status moves only
 * through SupplierLifecycleService and criticality only through SupplierCriticalityService.
 *
 * Authorization and notifications are the caller's: «Registrer leverandør» tells the new intern
 * ansvarlig, the import does not (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»).
 */
class SupplierRegistration
{
    /**
     * The master data of a supplier, for registering and editing. The owner arrives as an id here;
     * the import resolves it from the file first.
     *
     * @return array<string, list<mixed>>
     */
    public static function masterDataRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'organization_number' => ['nullable', 'string', 'max:50'],
            'category' => ['required', 'string', Rule::in(Supplier::CATEGORIES)],
            'deliverable_description' => ['required', 'string', 'max:5000'],
            'owner_user_id' => ['required', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** «987 654 321» and «987654321» are the same number; nothing at all is no number. */
    public static function normalizeOrganizationNumber(?string $value): ?string
    {
        $value = preg_replace('/\s+/u', '', (string) $value);

        return $value !== '' ? $value : null;
    }

    /** Within the customer — the same rule as the database's partial unique index. */
    public function organizationNumberTaken(int $customerId, string $organizationNumber, ?int $exceptId = null): bool
    {
        return Supplier::query()
            ->where('customer_id', $customerId)
            ->where('organization_number', $organizationNumber)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Writes the new supplier. The caller has validated the fields and the owner and runs this in
     * its transaction; a concurrent registration of the same organisation number surfaces as the
     * database's UniqueConstraintViolationException.
     *
     * @param  array<string, mixed>  $fields  the master data, as masterDataRules() describes it
     * @param  array<string, mixed>|null  $classification  as SupplierCriticalityService::classification() returns it, or null for «Ikke vurdert»
     */
    public function register(User $actor, array $fields, string $initialStatus, ?array $classification): Supplier
    {
        if (! in_array($initialStatus, Supplier::INITIAL_STATUSES, true)) {
            throw new DomainException("A supplier is not registered as [{$initialStatus}].");
        }

        $supplier = new Supplier($fields + [
            'customer_id' => (int) $actor->customer_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        // The status and classification it is registered with are the supplier's own; neither is a
        // change.
        $supplier->status = $initialStatus;
        $supplier->forceFill($classification ?? []);
        $supplier->save();

        return $supplier;
    }
}
