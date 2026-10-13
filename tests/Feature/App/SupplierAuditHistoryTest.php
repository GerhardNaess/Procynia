<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierComplianceRequirement;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierControlRequirementStatusChange;
use App\Models\SupplierDeletion;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierControlRequirementService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Sporbarhet in Leverandøroppfølging: who retired or reused a control requirement, and who deleted a
 * supplier.
 *
 *  - Sett som utgått and Ta i bruk igjen each write one immutable
 *    SupplierControlRequirementStatusChange — catalogue and supplier-specific requirements alike —
 *    in the same transaction; a repeat, a refusal or a failure writes nothing; the controls stay; the
 *    history is read on the requirement, by those who may read it; a requirement with that history is
 *    never deleted.
 *  - Slett leverandør writes one immutable SupplierDeletion in the same transaction: none without the
 *    deletion, no deletion without it, and it outlives the supplier. The log is read-only, the
 *    customer's own, for supplier.delete only.
 */
class SupplierAuditHistoryTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const CATALOGUE = '/app/supplier-management/control-requirements';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_retire_and_reactivate_each_write_one_status_change_and_keep_the_controls(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Transport AS', classification: $this->classification());
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/control-requirements", $this->requirementPayload(['title' => 'Utslippsfrie kjøretøy']))->assertSessionHas('success');
        $own = SupplierControlRequirement::query()->where('supplier_id', $supplier->id)->sole();
        $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/requirement-evaluations", [
            'requirement_id' => $own->id, 'status' => 'missing', 'rationale' => 'Ikke dokumentert.', 'evaluated_on' => now()->toDateString(), 'accepted_until' => '', 'document_ids' => [],
        ])->assertSessionHasNoErrors();
        $evaluationIds = $own->evaluations()->pluck('id')->all();

        // Sett som utgått: one row, with customer, supplier, requirement, statuses, who and when.
        $retiredAt = Carbon::parse('2026-10-13 09:30:00');
        $this->travelTo($retiredAt);
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$own->id}/retire")->assertSessionHas('success');
        $change = SupplierControlRequirementStatusChange::query()->sole();
        $this->assertSame(
            [(int) $customer->id, (int) $own->id, (int) $supplier->id, 'active', 'retired', (int) $assurer->id, $retiredAt->toIso8601String()],
            [(int) $change->customer_id, (int) $change->requirement_id, (int) $change->supplier_id, $change->from_status, $change->to_status, (int) $change->changed_by_user_id, $change->changed_at->toIso8601String()],
        );

        // Again, without a change of status: refused, no second row.
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$own->id}/retire")->assertSessionHasErrors('requirement');
        $this->assertSame(1, SupplierControlRequirementStatusChange::query()->count());

        // Read on the retired requirement, under Utgåtte kontrollkrav.
        $retired = $this->supplierPage($supplier, $assurer)['control_requirements']['retired_own'][0];
        $this->assertSame([['retired', $assurer->name]], array_map(fn (array $entry): array => [$entry['to_status'], $entry['changed_by_name']], $retired['status_history']));

        // Ta i bruk igjen: one more row, the other way; a repeat writes nothing.
        $this->travelTo($retiredAt->copy()->addDay());
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$own->id}/reactivate")->assertSessionHas('success');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$own->id}/reactivate")->assertSessionHasErrors('requirement');
        $this->assertSame([['active', 'retired'], ['retired', 'active']], SupplierControlRequirementStatusChange::query()->orderBy('id')->get()
            ->map(fn (SupplierControlRequirementStatusChange $row): array => [$row->from_status, $row->to_status])->all());

        // The same requirement with the same control; its history apart from the controls, newest first.
        $this->assertSame($evaluationIds, $own->evaluations()->pluck('id')->all());
        $row = collect($this->supplierPage($supplier, $assurer)['control_requirements']['applicable'])->firstWhere('id', $own->id);
        $this->assertSame(['Ikke dokumentert.'], array_column($row['evaluations'], 'rationale'));
        $this->assertSame(['active', 'retired'], array_column($row['status_history'], 'to_status'));

        // A catalogue requirement: the same, with no supplier; read on Kontrollkrav.
        $this->actingAs($assurer)->post(self::CATALOGUE, $this->requirementPayload(['title' => 'Databehandleravtale']))->assertSessionHas('success');
        $catalogue = SupplierControlRequirement::query()->whereNull('supplier_id')->sole();
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$catalogue->id}/retire")->assertSessionHas('success');
        $change = SupplierControlRequirementStatusChange::query()->where('requirement_id', $catalogue->id)->sole();
        $this->assertSame([(int) $customer->id, null, 'retired'], [(int) $change->customer_id, $change->supplier_id, $change->to_status]);
        $listed = collect($this->actingAs($assurer)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['requirements'])->firstWhere('id', $catalogue->id);
        $this->assertSame([['retired', $assurer->name]], array_map(fn (array $entry): array => [$entry['to_status'], $entry['changed_by_name']], $listed['status_history']));

        // A reader sees it; another customer reaches neither the requirement nor its history.
        $reader = $this->supplierUser($customer, []);
        $this->assertCount(1, collect($this->actingAs($reader)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['requirements'])->firstWhere('id', $catalogue->id)['status_history']);
        ['customer' => $foreignCustomer] = $this->context('grc');
        $foreign = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->actingAs($foreign)->get("/app/supplier-management/{$supplier->id}")->assertNotFound();
        $this->assertSame([], $this->actingAs($foreign)->get(self::CATALOGUE)->assertOk()->viewData('page')['props']['requirements']);
    }

    public function test_refused_and_failed_status_changes_write_no_history(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Transport AS', classification: $this->classification());
        $requirement = $this->requirement($customer, 'Databehandleravtale');

        // Without supplier.assure, or from another customer: refused, nothing written.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($editor)->post(self::CATALOGUE."/{$requirement->id}/retire")->assertForbidden();
        $this->actingAs($this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]))->post(self::CATALOGUE."/{$requirement->id}/retire")->assertNotFound();

        // A requirement for an ended supplier is not retired, and leaves no row.
        $own = $this->requirement($customer, 'Særkrav', $supplier);
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHas('success');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$own->id}/retire")->assertSessionHasErrors('requirement');

        // The status write fails after the history row: both are rolled back.
        Event::listen('eloquent.updating: '.SupplierControlRequirement::class, function (): void {
            throw new RuntimeException('Simulated failure.');
        });
        try {
            app(SupplierControlRequirementService::class)->retire($assurer, $requirement);
            $this->fail('The retirement should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure.', $exception->getMessage());
        }

        $this->assertSame([SupplierControlRequirement::STATUS_ACTIVE, SupplierControlRequirement::STATUS_ACTIVE], [$requirement->fresh()->status, $own->fresh()->status]);
        $this->assertSame(0, SupplierControlRequirementStatusChange::query()->count());
    }

    public function test_status_history_is_append_only_and_a_requirement_with_it_is_never_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $used = $this->requirement($customer, 'Databehandleravtale');
        $unused = $this->requirement($customer, 'Aldri brukt');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$used->id}/retire")->assertSessionHas('success');
        $this->actingAs($assurer)->post(self::CATALOGUE."/{$used->id}/reactivate")->assertSessionHas('success');

        // Slett: not offered and refused once there is a status history; still there for an unused one.
        $rows = collect($this->actingAs($assurer)->get(self::CATALOGUE)->viewData('page')['props']['requirements'])->keyBy('id');
        $this->assertSame([false, true], [$rows[$used->id]['deletable'], $rows[$unused->id]['deletable']]);
        $this->actingAs($assurer)->delete(self::CATALOGUE."/{$used->id}")
            ->assertSessionHasErrors(['requirement' => __('procynia.supplier_management.validation.control_requirement_has_history')]);
        $this->assertNotNull($used->fresh());
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirements')->where('id', $used->id)->delete(), 'deleting a requirement with status history');
        $this->actingAs($assurer)->delete(self::CATALOGUE."/{$unused->id}")->assertSessionHas('success');
        $this->assertNull($unused->fresh());

        // The history can be neither changed nor deleted, through the model or below it.
        $change = SupplierControlRequirementStatusChange::query()->orderBy('id')->firstOrFail();
        foreach ([fn () => $change->forceFill(['to_status' => 'active'])->save(), fn () => $change->delete()] as $write) {
            try {
                $write();
                $this->fail('The model must refuse it.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_status_changes')->where('id', $change->id)->update(['changed_at' => now()->subYear()]), 'changing a status change');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_status_changes')->where('id', $change->id)->delete(), 'deleting a status change');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_status_changes')->insert([
            'customer_id' => $customer->id, 'requirement_id' => $used->id, 'from_status' => 'active', 'to_status' => 'active', 'changed_at' => now(),
        ]), 'a change that is no change');

        // Never across customers: the requirement is referenced with its customer.
        ['customer' => $foreignCustomer] = $this->context('grc');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_control_requirement_status_changes')->insert([
            'customer_id' => $foreignCustomer->id, 'requirement_id' => $used->id, 'from_status' => 'active', 'to_status' => 'retired', 'changed_at' => now(),
        ]), 'a status change of another customer\'s requirement');

        // The customer going takes the history with it.
        $customer->delete();
        $this->assertSame(0, SupplierControlRequirementStatusChange::query()->where('requirement_id', $used->id)->count());
    }

    public function test_deleting_a_supplier_writes_one_deletion_row_that_outlives_it(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $deleter = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE, CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $supplier = $this->supplier($customer, $deleter, 'Feilregistrert AS');
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $this->link($supplier, $requirement, $deleter);
        $supplierId = (int) $supplier->id;

        $deletedAt = Carbon::parse('2026-10-13 14:05:00');
        $this->travelTo($deletedAt);
        $this->actingAs($deleter)->delete("/app/supplier-management/{$supplierId}")->assertRedirect(route('app.supplier-management.index'))->assertSessionHas('success');

        // Gone, with its earlier compliance links as before; the requirement itself stays.
        $this->assertNull(Supplier::query()->find($supplierId));
        $this->assertSame(0, SupplierComplianceRequirement::query()->where('supplier_id', $supplierId)->count());
        $this->assertNotNull(ComplianceRequirement::query()->find($requirement->id));

        // One row: only who, when, and the supplier's id and name as they were.
        $deletion = SupplierDeletion::query()->sole();
        $this->assertSame(
            [(int) $customer->id, $supplierId, 'Feilregistrert AS', (int) $deleter->id, $deletedAt->toIso8601String()],
            [(int) $deletion->customer_id, (int) $deletion->supplier_id, $deletion->supplier_name, (int) $deletion->deleted_by_user_id, $deletion->deleted_at->toIso8601String()],
        );

        // Read under Slettede leverandører.
        $this->assertSame(
            [['Feilregistrert AS', $deleter->name, $deletedAt->toIso8601String()]],
            array_map(fn (array $row): array => [$row['supplier_name'], $row['deleted_by_name'], $row['deleted_at']], $this->actingAs($deleter)->get('/app/supplier-management/deleted')->assertOk()->viewData('page')['props']['deletions']),
        );
    }

    public function test_a_refused_or_failed_deletion_writes_no_row_and_a_failed_row_keeps_the_supplier(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $deleter = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE, CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $lifecycle = app(SupplierLifecycleService::class);

        // Without supplier.delete, or from another customer: refused, nothing written.
        $supplier = $this->supplier($customer, $deleter, 'Acme AS');
        $this->actingAs($this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]))->delete("/app/supplier-management/{$supplier->id}")->assertForbidden();
        $this->actingAs($this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]))->delete("/app/supplier-management/{$supplier->id}")->assertNotFound();

        // The delete conditions are unchanged: a supplier with a history is not deleted, and no row.
        $ended = $this->supplier($customer, $deleter, 'Brukt AS');
        $this->actingAs($deleter)->post("/app/supplier-management/{$ended->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHas('success');
        $this->actingAs($deleter)->delete("/app/supplier-management/{$ended->id}")->assertSessionHas('error');
        $this->assertNotNull($ended->fresh());

        // Checked again inside the lock: a history written after the page was read stops it.
        $stale = $this->supplier($customer, $deleter, 'Gammel visning AS');
        $this->assertTrue($stale->isDeletable());
        $lifecycle->end(Supplier::query()->findOrFail($stale->id), $deleter, 'Avsluttet i mellomtiden.');
        $this->assertFalse($lifecycle->delete($stale, $deleter));
        $this->assertSame(0, SupplierDeletion::query()->count());

        // The deletion row cannot be written: the supplier and its links stay.
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $this->link($supplier, $requirement, $deleter);
        Event::listen('eloquent.creating: '.SupplierDeletion::class, function (): void {
            throw new RuntimeException('Simulated log failure.');
        });
        try {
            $lifecycle->delete($supplier, $deleter);
            $this->fail('The delete should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated log failure.', $exception->getMessage());
        }
        $this->assertNotNull(Supplier::query()->find($supplier->id));
        $this->assertSame(1, $supplier->requirementLinks()->count());
        $this->assertSame(0, SupplierDeletion::query()->count());
        Event::forget('eloquent.creating: '.SupplierDeletion::class);

        // The supplier delete fails after the row is written: the row is rolled back with it.
        Supplier::deleting(function (Supplier $deleting) use ($supplier): void {
            if ((int) $deleting->id === (int) $supplier->id) {
                throw new RuntimeException('Simulated failure.');
            }
        });
        try {
            $lifecycle->delete($supplier, $deleter);
            $this->fail('The delete should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure.', $exception->getMessage());
        }
        $this->assertNotNull(Supplier::query()->find($supplier->id));
        $this->assertSame(1, $supplier->requirementLinks()->count());
        $this->assertSame(0, SupplierDeletion::query()->count());
    }

    public function test_the_deletion_log_is_read_only_append_only_and_the_customers_own(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $deleter = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $foreignDeleter = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($deleter)->delete('/app/supplier-management/'.$this->supplier($customer, $deleter, 'Egen AS')->id)->assertSessionHas('success');
        $this->actingAs($foreignDeleter)->delete('/app/supplier-management/'.$this->supplier($foreignCustomer, $foreignDeleter, 'Fremmed AS')->id)->assertSessionHas('success');

        // Each customer sees its own, and the register offers the way there only with supplier.delete.
        $names = fn (User $as): array => array_column($this->actingAs($as)->get('/app/supplier-management/deleted')->assertOk()->viewData('page')['props']['deletions'], 'supplier_name');
        $this->assertSame(['Egen AS'], $names($deleter));
        $this->assertSame(['Fremmed AS'], $names($foreignDeleter));
        $this->assertTrue($this->actingAs($deleter)->get('/app/supplier-management')->viewData('page')['props']['permissions']['can_view_deletions']);
        foreach ([[CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSURE], []] as $keys) {
            $other = $this->supplierUser($customer, $keys);
            $this->assertFalse($this->actingAs($other)->get('/app/supplier-management')->viewData('page')['props']['permissions']['can_view_deletions']);
            $this->actingAs($other)->get('/app/supplier-management/deleted')->assertForbidden();
        }

        // Nothing writes to it but the deletion: no route, no model change, nothing below the model.
        $this->actingAs($deleter)->post('/app/supplier-management/deleted')->assertMethodNotAllowed();
        $this->actingAs($deleter)->delete('/app/supplier-management/deleted')->assertMethodNotAllowed();
        $deletion = SupplierDeletion::query()->where('customer_id', $customer->id)->sole();
        foreach ([fn () => $deletion->forceFill(['supplier_name' => 'Annet AS'])->save(), fn () => $deletion->delete()] as $write) {
            try {
                $write();
                $this->fail('The model must refuse it.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_deletions')->where('id', $deletion->id)->update(['supplier_name' => 'Annet AS']), 'changing a deletion');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_deletions')->where('id', $deletion->id)->delete(), 'deleting a deletion');

        // The customer going takes its own log with it, and nothing of the other's.
        $customer->delete();
        $this->assertSame(['Fremmed AS'], SupplierDeletion::query()->pluck('supplier_name')->all());
    }

    private function requirement(Customer $customer, string $title, ?Supplier $supplier = null): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'theme' => 'privacy',
            'level' => 'standard',
            'control_point' => 'ongoing',
            'supplier_id' => $supplier?->id,
        ]);
    }

    private function link(Supplier $supplier, ComplianceRequirement $requirement, User $by): SupplierComplianceRequirement
    {
        return SupplierComplianceRequirement::query()->create([
            'customer_id' => (int) $supplier->customer_id,
            'supplier_id' => (int) $supplier->id,
            'compliance_requirement_id' => (int) $requirement->id,
            'created_by' => (int) $by->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function supplierPage(Supplier $supplier, User $as): array
    {
        return $this->actingAs($as)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function requirementPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Databehandleravtale',
            'description' => '',
            'guidance' => '',
            'theme' => 'privacy',
            'level' => 'standard',
            'control_point' => 'ongoing',
            'control_interval_months' => 12,
            'accepted_document_types' => [],
            'basis_text' => '',
            'rule_mode' => 'all',
            'conditions' => [],
            'criticality_scope' => '',
        ], $overrides);
    }
}
