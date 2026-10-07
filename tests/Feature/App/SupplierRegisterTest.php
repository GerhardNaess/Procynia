<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierStatusChange;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 2: the register and the lifecycle (docs/supplier-management-v1-plan.md
 * §4.1, §6, §9). One test per rule:
 *
 *  - the lifecycle Under vurdering → Aktiv → Avsluttet → Aktiv, every change one immutable row, and
 *    every other transition refused; an ended supplier is read-only until it is reopened;
 *  - tenant isolation: another customer's supplier is absent, 404 by every route;
 *  - supplier.view reads, supplier.edit writes, supplier.delete deletes — and supplier.assess
 *    alone does none of it;
 *  - the intern ansvarlig is an active supplier reader of the same customer, and that grants nothing;
 *  - the organisation number is unique within the customer only;
 *  - the history is immutable in the model and the database;
 *  - only an unused supplier can be deleted, in the controller and the database.
 */
class SupplierRegisterTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

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

    public function test_a_supplier_moves_through_its_lifecycle_and_an_ended_one_is_read_only(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, ['initial_status' => Supplier::STATUS_ONBOARDING]))
            ->assertRedirect();
        $supplier = Supplier::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(Supplier::STATUS_ONBOARDING, $supplier->status);
        // The status it is registered with is the supplier's own, not a change.
        $this->assertFalse($supplier->statusChanges()->exists());

        $url = "/app/supplier-management/{$supplier->id}";

        // Under vurdering → Aktiv, without a reason; a second Ta i bruk is refused.
        $this->actingAs($editor)->post("{$url}/activate")->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("{$url}/activate")->assertSessionHasErrors('reason');
        $this->assertSame(1, $supplier->statusChanges()->count());

        // Reopening something that is not ended is refused; ending needs a reason.
        $this->actingAs($editor)->post("{$url}/reopen", ['reason' => 'Feil'])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post("{$url}/end", ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post("{$url}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->assertSame(Supplier::STATUS_ENDED, $supplier->fresh()->status);

        // Ended: read-only. Nothing changes until it is reopened, and it cannot be ended or activated again.
        $this->actingAs($editor)->patch($url, $this->supplierPayload($editor, ['name' => 'Nytt navn']))->assertSessionHas('error');
        $this->assertSame('Drift AS', $supplier->fresh()->name);
        $this->actingAs($editor)->post("{$url}/end", ['reason' => 'Igjen'])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post("{$url}/activate")->assertSessionHasErrors('reason');
        $page = $this->actingAs($editor)->get($url)->assertOk()->viewData('page');
        $this->assertSame(
            ['can_edit' => false, 'can_activate' => false, 'can_end' => false, 'can_reopen' => true],
            array_intersect_key($page['props']['permissions'], array_flip(['can_edit', 'can_activate', 'can_end', 'can_reopen'])),
        );

        // Gjenåpne: active and editable again; the ending stays in the history.
        $this->actingAs($editor)->post("{$url}/reopen", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post("{$url}/reopen", ['reason' => 'Ny avtale.'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->patch($url, $this->supplierPayload($editor, ['name' => 'Nytt navn']))->assertSessionHasNoErrors();
        $this->assertSame(['active', 'Nytt navn'], [$supplier->fresh()->status, $supplier->fresh()->name]);

        $page = $this->actingAs($editor)->get($url)->assertOk()->viewData('page');
        $this->assertSame('App/SupplierManagement/Show', $page['component']);
        $this->assertSame(Supplier::STATUS_ONBOARDING, $page['props']['registered']['status']);
        $this->assertSame(
            [['ended', 'active', 'Ny avtale.'], ['active', 'ended', 'Avtalen er sagt opp.'], ['onboarding', 'active', null]],
            array_map(fn (array $entry): array => [$entry['from_status'], $entry['to_status'], $entry['reason']], $page['props']['status_history']),
        );
        $this->assertSame([$editor->name], array_values(array_unique(array_column($page['props']['status_history'], 'changed_by_name'))));
    }

    public function test_another_customers_supplier_is_absent_from_the_register_and_404_by_every_route(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $foreignOwner = $this->supplierUser($other, []);
        $own = $this->supplier($customer, $editor, 'Egen AS');
        $foreign = $this->supplier($other, $foreignOwner, 'Fremmed AS');

        $page = $this->actingAs($editor)->get('/app/supplier-management?status=all')->assertOk()->viewData('page');
        $this->assertSame([$own->id], array_column($page['props']['suppliers'], 'id'));
        $this->assertSame(1, $page['props']['visible_count']);
        $search = $this->actingAs($editor)->get('/app/supplier-management?status=all&search=Fremmed')->viewData('page');
        $this->assertSame([], $search['props']['suppliers']);

        $url = "/app/supplier-management/{$foreign->id}";
        $this->actingAs($editor)->get($url)->assertNotFound();
        $this->actingAs($editor)->patch($url, $this->supplierPayload($editor))->assertNotFound();
        $this->actingAs($editor)->post("{$url}/activate")->assertNotFound();
        $this->actingAs($editor)->post("{$url}/end", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($editor)->post("{$url}/reopen", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($editor)->delete($url)->assertNotFound();
        $this->assertSame(['Fremmed AS', Supplier::STATUS_ACTIVE], [$foreign->fresh()->name, $foreign->fresh()->status]);

        // Below the controller: a history row cannot name another customer's supplier.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_status_changes')->insert([
            'customer_id' => $customer->id,
            'supplier_id' => $foreign->id,
            'from_status' => 'active',
            'to_status' => 'ended',
            'reason' => 'x',
            'changed_at' => now(),
        ]), 'a history row across customers');
    }

    public function test_view_reads_edit_writes_delete_deletes_and_assess_alone_does_none_of_it(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $owner, 'Ubrukt AS');
        $url = "/app/supplier-management/{$supplier->id}";

        $withoutView = $this->member($customer);
        $this->grantAll($customer, $withoutView, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($withoutView)->get($url)->assertForbidden();
        $this->actingAs($withoutView)->post('/app/supplier-management', $this->supplierPayload($owner))->assertForbidden();

        // view alone, and view + assess: read, never write.
        foreach ([[], [CustomerPermissionCatalog::SUPPLIER_ASSESS]] as $extra) {
            $reader = $this->supplierUser($customer, $extra);
            $page = $this->actingAs($reader)->get('/app/supplier-management')->assertOk()->viewData('page');
            $this->assertFalse($page['props']['permissions']['can_edit']);
            $this->assertSame([], $page['props']['owner_options']);
            $this->actingAs($reader)->get($url)->assertOk();

            $this->actingAs($reader)->post('/app/supplier-management', $this->supplierPayload($owner))->assertForbidden();
            $this->actingAs($reader)->patch($url, $this->supplierPayload($owner))->assertForbidden();
            $this->actingAs($reader)->post("{$url}/end", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($reader)->post("{$url}/reopen", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($reader)->post("{$url}/activate")->assertForbidden();
            $this->actingAs($reader)->delete($url)->assertForbidden();
        }

        // edit does not delete; delete does.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($editor)->delete($url)->assertForbidden();
        $deleter = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($deleter)->patch($url, $this->supplierPayload($owner))->assertForbidden();
        $this->actingAs($deleter)->delete($url)->assertRedirect('/app/supplier-management');
        $this->assertNull(Supplier::query()->find($supplier->id));
    }

    public function test_the_internal_owner_is_an_active_supplier_reader_of_the_same_customer_and_gains_nothing(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $reader = $this->supplierUser($customer, []);
        $foreign = $this->supplierUser($other, []);
        $noAccess = $this->member($customer);
        $inactive = $this->supplierUser($customer, []);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$foreign, $noAccess, $inactive] as $candidate) {
            $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($candidate))
                ->assertSessionHasErrors('owner_user_id');
        }
        $this->assertFalse(Supplier::query()->where('customer_id', $customer->id)->exists());

        $candidates = array_column(app(SupplierAccessService::class)->ownerCandidates($editor), 'id');
        $this->assertEqualsCanonicalizing([$editor->id, $reader->id], $candidates);

        // Being responsible is not a permission.
        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($reader))->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame($reader->id, (int) $supplier->owner_user_id);
        $this->actingAs($reader)->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($reader))->assertForbidden();
    }

    public function test_the_organization_number_is_unique_within_the_customer_only(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $otherEditor = $this->supplierUser($other, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, ['organization_number' => '987 654 321']))->assertSessionHasNoErrors();
        $this->assertSame('987654321', Supplier::query()->where('customer_id', $customer->id)->value('organization_number'));

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, ['name' => 'Kopi AS', 'organization_number' => '987654321']))
            ->assertSessionHasErrors('organization_number');
        $this->actingAs($otherEditor)->post('/app/supplier-management', $this->supplierPayload($otherEditor, ['organization_number' => '987654321']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_status_history_is_immutable_in_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Historikk AS', Supplier::STATUS_ONBOARDING);
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Ikke valgt.'])->assertSessionHasNoErrors();
        $change = $supplier->statusChanges()->sole();

        foreach (['update' => fn () => $change->update(['reason' => 'Endret']), 'delete' => fn () => $change->delete()] as $what => $write) {
            try {
                $write();
                $this->fail("The model must refuse to {$what} history.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $row = DB::table('supplier_status_changes')->where('id', $change->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['reason' => 'Endret']), 'changing the reason');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['to_status' => 'active']), 'changing the status');
        $this->assertDatabaseRefuses(fn () => (clone $row)->delete(), 'deleting history');

        // Only lawful transitions, and only Ta i bruk without a reason.
        $insert = fn (string $from, string $to, ?string $reason) => DB::table('supplier_status_changes')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'from_status' => $from, 'to_status' => $to,
            'reason' => $reason, 'changed_at' => now(),
        ]);
        $this->assertDatabaseRefuses(fn () => $insert('active', 'onboarding', 'x'), 'Aktiv → Under vurdering');
        $this->assertDatabaseRefuses(fn () => $insert('ended', 'onboarding', 'x'), 'Avsluttet → Under vurdering');
        $this->assertDatabaseRefuses(fn () => $insert('active', 'ended', null), 'ending without a reason');
        $this->assertDatabaseRefuses(fn () => $insert('ended', 'active', ' '), 'reopening with a blank reason');

        // The one update the database allows: the author's account going. The row stays.
        $editor->delete();
        $this->assertNull(DB::table('supplier_status_changes')->where('id', $change->id)->value('changed_by_user_id'));
    }

    public function test_only_an_unused_supplier_can_be_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $unused = $this->supplier($customer, $user, 'Feilregistrert AS');
        $used = $this->supplier($customer, $user, 'Brukt AS', Supplier::STATUS_ONBOARDING);
        $this->actingAs($user)->post("/app/supplier-management/{$used->id}/activate")->assertSessionHasNoErrors();

        $page = $this->actingAs($user)->get("/app/supplier-management/{$used->id}")->viewData('page');
        $this->assertSame([false, true], [$page['props']['permissions']['can_delete'], $page['props']['permissions']['has_delete_right']]);

        $this->actingAs($user)->delete("/app/supplier-management/{$used->id}")->assertSessionHas('error');
        $this->assertNotNull($used->fresh());
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $used->id)->delete(), 'deleting a supplier with history');

        $this->actingAs($user)->delete("/app/supplier-management/{$unused->id}")->assertRedirect('/app/supplier-management');
        $this->assertNull($unused->fresh());

        // The customer going still takes the supplier and its history with it.
        $customer->delete();
        $this->assertFalse(SupplierStatusChange::query()->where('supplier_id', $used->id)->exists());
        $this->assertNull(Supplier::query()->find($used->id));
    }
}
