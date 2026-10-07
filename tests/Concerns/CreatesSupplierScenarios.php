<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers, people with supplier rights and registration payloads for the Leverandøroppfølging
 * feature tests. Builds on CreatesImprovementCaseScenarios (context(), member(), grantAll()), which
 * the test class uses alongside this.
 */
trait CreatesSupplierScenarios
{
    /**
     * A person of the customer whose role holds supplier.view plus the given keys.
     *
     * @param  list<string>  $extra
     */
    private function supplierUser(Customer $customer, array $extra): User
    {
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::SUPPLIER_VIEW, ...$extra]);

        return $user->fresh();
    }

    /**
     * A supplier written directly. Without $classification it is unclassified, as one registered
     * before criticality existed.
     *
     * @param  array<string, mixed>|null  $classification
     */
    private function supplier(Customer $customer, User $owner, string $name, string $status = Supplier::STATUS_ACTIVE, ?array $classification = null): Supplier
    {
        $supplier = new Supplier([
            'customer_id' => $customer->id,
            'name' => $name,
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift av lønnssystem',
            'owner_user_id' => $owner->id,
        ]);
        $supplier->status = $status;
        $supplier->forceFill($classification ?? []);
        $supplier->save();

        return $supplier;
    }

    /**
     * The Kritikalitet fields as a form sends them.
     *
     * @return array<string, mixed>
     */
    private function classification(string $criticality = Supplier::CRITICALITY_STANDARD, ?int $interval = null, array $answers = []): array
    {
        return array_merge([
            'criticality' => $criticality,
            'review_interval_months' => $interval,
            'processes_personal_data' => false,
            'has_system_access' => false,
            'supports_critical_delivery' => false,
            'hard_to_replace' => false,
        ], $answers);
    }

    /** @return array<string, mixed> */
    private function supplierPayload(User $owner, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Drift AS',
            'organization_number' => '',
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift av lønnssystem',
            'owner_user_id' => $owner->id,
            'contact_name' => 'Kari Kontakt',
            'contact_email' => 'kari@drift.example',
            'contact_phone' => '+47 900 00 000',
            'note' => '',
            'initial_status' => Supplier::STATUS_ACTIVE,
        ], $this->classification(), $overrides);
    }

    /** Runs the write in a savepoint, so the refusal does not poison the test's transaction. */
    private function assertDatabaseRefuses(callable $write, string $what): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("The database must refuse {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
