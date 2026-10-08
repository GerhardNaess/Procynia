<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierProfile;
use App\Models\SupplierProfileChange;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 1: the leverandørprofil (docs/supplier-assurance-v2-plan.md §4, §13.2,
 * §17, §20). One test per rule:
 *
 *  - no profile until someone fills it in; the first save needs no begrunnelse, writes the current
 *    state and one history row from nothing, and keeps «Ikke avklart» apart from «Nei»; a question
 *    not asked for the supplier is stored as not answered;
 *  - every later save needs a begrunnelse, writes one row with the whole profile before and after,
 *    and a save that changes nothing is refused;
 *  - supplier.edit changes the profile; supplier.assure, supplier.assess and supplier.delete do not;
 *    supplier.view reads it; System Owner without a role is refused; an ended supplier is
 *    read-only; another customer's supplier is 404 and cannot be named by a profile or history row;
 *  - the history is immutable in the model and the database;
 *  - a supplier with a profile can no longer be deleted.
 */
class SupplierProfileTest extends TestCase
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

    public function test_the_first_save_writes_the_profile_and_one_history_row_and_keeps_unknown_apart_from_no(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        // Personal data, no system access: the personal-data questions are asked, privileged access is not.
        $supplier = $this->supplier($customer, $editor, 'Lønn AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 24, ['processes_personal_data' => true]));
        $show = "/app/supplier-management/{$supplier->id}";

        // No backfill: nothing until someone fills it in.
        $profile = $this->actingAs($editor)->get($show)->assertOk()->viewData('page')['props']['profile'];
        $this->assertNull($profile['answers']);
        $this->assertFalse($profile['complete']);
        $this->assertSame([], $profile['history']);
        $this->assertNotContains('privileged_access', $profile['visible']);
        $this->assertContains('data_role', $profile['visible']);

        // Nothing answered is not a profile.
        $this->actingAs($editor)->post("{$show}/profile", [])->assertSessionHasErrors('profile');
        $this->assertFalse($supplier->profile()->exists());

        $this->actingAs($editor)->post("{$show}/profile", [
            'data_role' => 'processor',
            'special_category_data' => 'unknown',
            'stores_our_data' => 'no',
            'uses_subcontractors' => 'no',
            // Not asked for this supplier: stored as not answered.
            'privileged_access' => 'yes',
            'data_location' => 'outside_eea',
            'sectors' => ['staffing', 'ict', 'ict'],
        ])->assertSessionHasNoErrors();

        $stored = $supplier->profile()->sole();
        $this->assertSame(
            ['processor', 'unknown', 'no', 'no', null, null, ['ict', 'staffing'], null, null],
            [$stored->data_role, $stored->special_category_data, $stored->stores_our_data, $stored->uses_subcontractors, $stored->privileged_access, $stored->data_location, $stored->sectors, $stored->high_risk_categories, $stored->on_site_work],
        );
        $this->assertNull($stored->completed_at);

        $change = $supplier->profileChanges()->sole();
        $this->assertNull($change->from_profile);
        $this->assertNull($change->reason);
        // jsonb keeps the keys in its own order; the values are what matter.
        $this->assertEquals($stored->answers(), $change->to_profile);
        $this->assertSame($editor->id, $change->changed_by_user_id);

        $profile = $this->actingAs($editor)->get($show)->viewData('page')['props']['profile'];
        $this->assertSame('unknown', $profile['answers']['special_category_data']);
        $this->assertSame('no', $profile['answers']['uses_subcontractors']);
        $this->assertNotContains('data_location', $profile['visible']);
        $this->assertSame($editor->name, $profile['history'][0]['changed_by_name']);
        $this->assertSame(['processes_personal_data' => true, 'has_system_access' => false, 'supports_critical_delivery' => false, 'hard_to_replace' => false], $profile['basis']);

        // Unknown codes and shapes are refused before anything is written, and by the database below.
        foreach ([['on_site_work', ['on_site_work' => 'maybe']], ['sectors.0', ['sectors' => ['mining']]], ['data_role', ['data_role' => 'owner']], ['sectors', ['sectors' => 'ict']]] as [$field, $invalid]) {
            $this->actingAs($editor)->post("{$show}/profile", $invalid + ['reason' => 'x'])->assertSessionHasErrors($field);
        }
        $this->assertSame(1, $supplier->profileChanges()->count());
        $row = DB::table('supplier_profiles')->where('supplier_id', $supplier->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['on_site_work' => 'maybe']), 'an unknown answer');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['data_role' => 'owner']), 'an unknown role');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['sectors' => '"ict"']), 'a list that is not an array');
    }

    public function test_every_change_needs_a_reason_and_is_one_row_with_the_whole_profile_before_and_after(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS', classification: $this->classification());
        $url = "/app/supplier-management/{$supplier->id}/profile";

        $first = ['uses_subcontractors' => 'unknown', 'on_site_work' => 'no'];
        $this->actingAs($editor)->post($url, $first)->assertSessionHasNoErrors();
        $firstRow = $supplier->profileChanges()->sole();

        $second = ['uses_subcontractors' => 'yes', 'on_site_work' => 'no', 'high_risk_categories' => []];
        $this->actingAs($editor)->post($url, $second)->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post($url, $second + ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->actingAs($editor)->post($url, $second + ['reason' => 'Bruker underleverandør for drift.'])->assertSessionHasNoErrors();
        // The same answers again change nothing and write nothing.
        $this->actingAs($editor)->post($url, $second + ['reason' => 'Igjen.'])->assertSessionHasErrors('profile');

        $this->assertSame(2, $supplier->profileChanges()->count());
        $latest = $supplier->profileChanges()->first();
        $this->assertSame(['unknown', 'no', null], [$latest->from_profile['uses_subcontractors'], $latest->from_profile['on_site_work'], $latest->from_profile['high_risk_categories']]);
        $this->assertSame(['yes', 'no', []], [$latest->to_profile['uses_subcontractors'], $latest->to_profile['on_site_work'], $latest->to_profile['high_risk_categories']]);
        $this->assertSame('Bruker underleverandør for drift.', $latest->reason);
        $this->assertEquals($firstRow->getAttributes(), SupplierProfileChange::query()->find($firstRow->id)->getAttributes());
        $this->assertSame('yes', $supplier->profile()->sole()->uses_subcontractors);

        // Every question asked answered — «Ikke avklart» and «Ingen av disse» included — is complete.
        $complete = array_fill_keys(SupplierProfile::ANSWER_FIELDS, 'unknown') + ['high_risk_categories' => [], 'sectors' => ['goods'], 'data_location' => 'unknown'];
        $this->actingAs($editor)->post($url, $complete + ['reason' => 'Fylt ut resten.'])->assertSessionHasNoErrors();
        $this->assertNotNull($supplier->profile()->sole()->completed_at);
        $profile = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['profile'];
        $this->assertTrue($profile['complete']);
        $this->assertSame([3, 2, 1], array_map(fn (array $entry): int => $entry['id'] - $firstRow->id + 1, $profile['history']));
    }

    public function test_edit_changes_the_profile_assure_alone_does_not_view_reads_it_and_system_owner_is_fail_closed(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Kontroll AS', classification: $this->classification());
        $show = "/app/supplier-management/{$supplier->id}";
        $answers = ['on_site_work' => 'yes'];

        // Leverandørkontroll does not change the facts its requirements are applied by (plan §4.4).
        foreach ([CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $other = $this->supplierUser($customer, [$key]);
            $this->actingAs($other)->post("{$show}/profile", $answers)->assertForbidden();
            $page = $this->actingAs($other)->get($show)->assertOk()->viewData('page')['props'];
            $this->assertFalse($page['permissions']['can_edit_profile'], $key);

            try {
                app(SupplierProfileService::class)->save($supplier, $other, SupplierProfileService::answers($answers), null);
                $this->fail("{$key} must not change the profile below the controller either.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse($supplier->profile()->exists());

        // System Owner without a supplier role of their own: neither reads nor writes.
        $this->actingAs($systemOwner)->get($show)->assertForbidden();
        $this->actingAs($systemOwner)->post("{$show}/profile", $answers)->assertForbidden();

        $this->actingAs($editor)->post("{$show}/profile", $answers)->assertSessionHasNoErrors();
        $reader = $this->supplierUser($customer, []);
        $profile = $this->actingAs($reader)->get($show)->assertOk()->viewData('page')['props']['profile'];
        $this->assertSame('yes', $profile['answers']['on_site_work']);
        $this->assertCount(1, $profile['history']);
        $this->actingAs($reader)->post("{$show}/profile", ['on_site_work' => 'no', 'reason' => 'x'])->assertForbidden();
    }

    public function test_an_ended_supplier_is_read_only_and_another_customers_supplier_is_404(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Avsluttet AS', classification: $this->classification());
        $url = "/app/supplier-management/{$supplier->id}/profile";

        $this->actingAs($editor)->post($url, ['on_site_work' => 'no'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();

        $this->actingAs($editor)->post($url, ['on_site_work' => 'yes', 'reason' => 'x'])->assertSessionHas('error');
        // Also below the controller, where the supplier is locked.
        try {
            app(SupplierProfileService::class)->save($supplier->fresh(), $editor, SupplierProfileService::answers(['on_site_work' => 'yes']), 'x');
            $this->fail('An ended supplier must be refused.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $page = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertFalse($page['permissions']['can_edit_profile']);
        $this->assertSame('no', $page['profile']['answers']['on_site_work']);
        $this->assertSame(1, $supplier->profileChanges()->count());

        // Another customer's supplier: 404, and no profile or history row can name it.
        $foreign = $this->supplier($other, $this->supplierUser($other, []), 'Fremmed AS', classification: $this->classification());
        $this->actingAs($editor)->post("/app/supplier-management/{$foreign->id}/profile", ['on_site_work' => 'no'])->assertNotFound();
        $this->actingAs($editor)->get("/app/supplier-management/{$foreign->id}")->assertNotFound();
        $this->assertFalse($foreign->profile()->exists());
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_profiles')->insert([
            'supplier_id' => $foreign->id,
            'customer_id' => $customer->id,
            'on_site_work' => 'no',
        ]), 'a profile across customers');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_profile_changes')->insert([
            'customer_id' => $customer->id,
            'supplier_id' => $foreign->id,
            'to_profile' => '{}',
            'changed_at' => now(),
        ]), 'a history row across customers');
    }

    public function test_the_profile_history_is_immutable_in_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Historikk AS', classification: $this->classification());
        $url = "/app/supplier-management/{$supplier->id}/profile";
        $this->actingAs($editor)->post($url, ['on_site_work' => 'no'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, ['on_site_work' => 'yes', 'reason' => 'Arbeider hos oss.'])->assertSessionHasNoErrors();
        $change = $supplier->profileChanges()->first();

        foreach (['update' => fn () => $change->update(['reason' => 'Endret']), 'delete' => fn () => $change->delete()] as $what => $write) {
            try {
                $write();
                $this->fail("The model must refuse to {$what} history.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $row = DB::table('supplier_profile_changes')->where('id', $change->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['reason' => 'Endret']), 'changing the reason');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['to_profile' => '{"on_site_work": "no"}']), 'changing the snapshot');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['from_profile' => null]), 'erasing the before');
        $this->assertDatabaseRefuses(fn () => (clone $row)->delete(), 'deleting history');
        // A change without a begrunnelse is refused by the database too; only the first save may omit it.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_profile_changes')->insert([
            'customer_id' => $customer->id,
            'supplier_id' => $supplier->id,
            'from_profile' => '{}',
            'to_profile' => '{}',
            'changed_at' => now(),
        ]), 'a change without a begrunnelse');

        // The one update the database allows: the author's account going. The row stays.
        $editor->delete();
        $this->assertNull(DB::table('supplier_profile_changes')->where('id', $change->id)->value('changed_by_user_id'));
        $this->assertSame(2, DB::table('supplier_profile_changes')->where('supplier_id', $supplier->id)->count());
    }

    public function test_a_supplier_with_a_profile_can_no_longer_be_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $supplier = $this->supplier($customer, $editor, 'Feilregistrert AS', classification: $this->classification());
        $this->assertTrue($supplier->isDeletable());

        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/profile", ['on_site_work' => 'no'])->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->assertFalse($this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($editor)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with a profile');
        $this->assertTrue(Supplier::query()->whereKey($supplier->id)->exists());
    }
}
