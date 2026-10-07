<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierCriticalityChange;
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
 * Leverandøroppfølging phase 3: criticality (docs/supplier-management-v1-plan.md §4.2, §6.3, §9.2).
 * One test per rule:
 *
 *  - the level is the user's choice among Standard/Viktig/Kritisk, Viktig and Kritisk require an
 *    interval from the fixed list, the four answers are required and stored as they were given, and
 *    the database holds the same line;
 *  - the classification at registration is the supplier's own; every later change — the first one
 *    for an unclassified supplier included — is one immutable history row with before and after and
 *    a begrunnelse, and a change that changes nothing is refused;
 *  - supplier.edit changes criticality, supplier.assess alone does not; an ended supplier is
 *    refused; another customer's supplier is 404 and cannot be named by a history row;
 *  - the history is immutable in the model and the database;
 *  - a supplier with criticality history can no longer be deleted.
 */
class SupplierCriticalityTest extends TestCase
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

    public function test_the_user_chooses_one_of_three_levels_and_viktig_and_kritisk_require_an_interval(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        // Registration and Endre kritikalitet share the rules; each is refused before anything is written.
        $refused = [
            ['criticality', $this->classification('high', 12)],
            ['review_interval_months', $this->classification(Supplier::CRITICALITY_IMPORTANT)],
            ['review_interval_months', $this->classification(Supplier::CRITICALITY_CRITICAL)],
            ['review_interval_months', $this->classification(Supplier::CRITICALITY_CRITICAL, 18)],
            ['hard_to_replace', $this->classification(Supplier::CRITICALITY_STANDARD, null, ['hard_to_replace' => null])],
        ];

        foreach ($refused as [$field, $classification]) {
            $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, $classification))
                ->assertSessionHasErrors($field);
        }
        $this->assertFalse(Supplier::query()->where('customer_id', $customer->id)->exists());

        // Standard may be without an interval; the answers are stored as given, never turned into a level.
        $answers = ['processes_personal_data' => true, 'has_system_access' => true, 'supports_critical_delivery' => true, 'hard_to_replace' => true];
        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, $this->classification(Supplier::CRITICALITY_STANDARD, null, $answers)))
            ->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(['criticality' => 'standard', 'review_interval_months' => null, ...$answers], $supplier->classification());

        $url = "/app/supplier-management/{$supplier->id}/criticality";
        $this->actingAs($editor)->post($url, $this->classification(Supplier::CRITICALITY_CRITICAL) + ['reason' => 'Ny avtale.'])
            ->assertSessionHasErrors('review_interval_months');
        $this->actingAs($editor)->post($url, $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => ' '])
            ->assertSessionHasErrors('reason');
        $this->assertSame('standard', $supplier->fresh()->criticality);

        // Below the controller.
        $row = DB::table('suppliers')->where('id', $supplier->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['criticality' => 'important']), 'Viktig without an interval');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['review_interval_months' => 18]), 'an interval outside the list');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['criticality' => 'high', 'review_interval_months' => 12]), 'an unknown level');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['hard_to_replace' => null]), 'a level without its basis');
    }

    public function test_every_change_is_one_history_row_with_before_after_and_reason_and_old_rows_stay_as_they_were(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        // Registered before criticality existed: nothing yet.
        $supplier = $this->supplier($customer, $editor, 'Integrasjon AS');
        $show = "/app/supplier-management/{$supplier->id}";
        $url = "{$show}/criticality";

        $page = $this->actingAs($editor)->get($show)->assertOk()->viewData('page');
        $this->assertNull($page['props']['criticality']['current']);
        $this->assertTrue($page['props']['permissions']['can_change_criticality']);
        $this->assertTrue($supplier->isDeletable());

        // Vurder kritikalitet: the first classification, from nothing.
        $this->actingAs($editor)->post($url, $this->classification(Supplier::CRITICALITY_STANDARD) + ['reason' => 'Lett å erstatte.'])
            ->assertSessionHasNoErrors();
        $first = $supplier->criticalityChanges()->sole();
        $this->assertSame([null, 'standard', null, false], [$first->from_criticality, $first->to_criticality, $first->from_hard_to_replace, $first->to_hard_to_replace]);

        // Standard → Viktig → Kritisk, then the interval alone; each is a row of its own.
        $this->actingAs($editor)->post($url, $this->classification(Supplier::CRITICALITY_IMPORTANT, 24, ['has_system_access' => true]) + ['reason' => 'Får tilgang til ordresystemet.'])
            ->assertSessionHasNoErrors();
        $critical = $this->classification(Supplier::CRITICALITY_CRITICAL, 12, ['has_system_access' => true, 'supports_critical_delivery' => true]);
        $this->actingAs($editor)->post($url, $critical + ['reason' => 'Leverandøren drifter en forretningskritisk integrasjon.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, ['review_interval_months' => 6] + $critical + ['reason' => 'Tettere oppfølging.'])
            ->assertSessionHasNoErrors();
        // The same classification again is no change and writes nothing.
        $this->actingAs($editor)->post($url, ['review_interval_months' => 6] + $critical + ['reason' => 'Igjen.'])
            ->assertSessionHasErrors('criticality');

        $this->assertSame(4, $supplier->criticalityChanges()->count());
        $this->assertEquals($first->getAttributes(), SupplierCriticalityChange::query()->find($first->id)->getAttributes());
        $this->assertSame(['criticality' => 'critical', 'review_interval_months' => 6] + array_slice($critical, 2), $supplier->fresh()->classification());

        $criticality = $this->actingAs($editor)->get($show)->viewData('page')['props']['criticality'];
        $this->assertSame(
            [['critical', 'critical', 12, 6], ['important', 'critical', 24, 12], ['standard', 'important', null, 24], [null, 'standard', null, null]],
            array_map(fn (array $entry): array => [$entry['from']['criticality'] ?? null, $entry['to']['criticality'], $entry['from']['review_interval_months'] ?? null, $entry['to']['review_interval_months']], $criticality['history']),
        );
        $this->assertTrue($criticality['history'][1]['to']['supports_critical_delivery']);
        $this->assertFalse($criticality['history'][1]['from']['supports_critical_delivery']);
        $this->assertSame(['Tettere oppfølging.', $editor->name], [$criticality['reason'], $criticality['decided_by_name']]);
        // Not classified at registration, so the list does not end with a registration line.
        $this->assertNull($criticality['registered']);

        // A real decision was made about it: ended, never deleted.
        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($editor)->delete($show)->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with criticality history');
    }

    public function test_the_classification_at_registration_is_the_suppliers_own_and_closes_the_history(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $this->actingAs($editor)->post('/app/supplier-management', $this->supplierPayload($editor, $this->classification(Supplier::CRITICALITY_IMPORTANT, 24)))
            ->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('customer_id', $customer->id)->sole();
        $this->assertFalse($supplier->criticalityChanges()->exists());
        $this->assertTrue($supplier->isDeletable());

        // Rediger never touches it.
        $this->actingAs($editor)->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($editor, $this->classification(Supplier::CRITICALITY_CRITICAL, 12)))
            ->assertSessionHasNoErrors();
        $this->assertSame('important', $supplier->fresh()->criticality);

        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/criticality", $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => 'Kjernesystem.'])
            ->assertSessionHasNoErrors();
        $criticality = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['criticality'];
        $this->assertSame(['important', 24], [$criticality['registered']['classification']['criticality'], $criticality['registered']['classification']['review_interval_months']]);
        $this->assertSame('important', $criticality['history'][0]['from']['criticality']);

        // The register shows and filters the current level.
        $register = $this->actingAs($editor)->get('/app/supplier-management?criticality=critical')->viewData('page')['props'];
        $this->assertSame([['id' => $supplier->id, 'criticality' => 'critical']], array_map(fn (array $row): array => array_intersect_key($row, ['id' => 0, 'criticality' => 0]), $register['suppliers']));
        $this->assertSame([], $this->actingAs($editor)->get('/app/supplier-management?criticality=standard')->viewData('page')['props']['suppliers']);
    }

    public function test_edit_changes_criticality_assess_alone_does_not_an_ended_supplier_is_refused_and_other_customers_are_404(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $owner = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $owner, 'Ubrukt AS');
        $url = "/app/supplier-management/{$supplier->id}/criticality";
        $change = $this->classification(Supplier::CRITICALITY_IMPORTANT, 24) + ['reason' => 'Viktig for drift.'];

        // supplier.assess is the assessment of how a supplier performs — not how important it is (plan §9.2).
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->actingAs($assessor)->post($url, $change)->assertForbidden();
        $this->assertFalse($this->actingAs($assessor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['permissions']['can_change_criticality']);
        $withoutView = $this->member($customer);
        $this->grantAll($customer, $withoutView, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($withoutView)->post($url, $change)->assertForbidden();
        $this->assertFalse($supplier->criticalityChanges()->exists());

        // Ended: read-only until reopened; the history stays readable.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($editor)->post($url, $change)->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post($url, $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => 'x'])->assertSessionHas('error');
        $page = $this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page');
        $this->assertFalse($page['props']['permissions']['can_change_criticality']);
        $this->assertCount(1, $page['props']['criticality']['history']);
        $this->assertSame('important', $supplier->fresh()->criticality);

        // Another customer's supplier: 404, and no history row can name it.
        $foreign = $this->supplier($other, $this->supplierUser($other, []), 'Fremmed AS');
        $this->actingAs($editor)->post("/app/supplier-management/{$foreign->id}/criticality", $change)->assertNotFound();
        $this->assertNull($foreign->fresh()->criticality);
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_criticality_changes')->insert([
            'customer_id' => $customer->id,
            'supplier_id' => $foreign->id,
            'to_criticality' => 'standard',
            'to_processes_personal_data' => false,
            'to_has_system_access' => false,
            'to_supports_critical_delivery' => false,
            'to_hard_to_replace' => false,
            'reason' => 'x',
            'changed_at' => now(),
        ]), 'a history row across customers');
    }

    public function test_the_criticality_history_is_immutable_in_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Historikk AS');
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/criticality", $this->classification(Supplier::CRITICALITY_CRITICAL, 12) + ['reason' => 'Kjernesystem.'])
            ->assertSessionHasNoErrors();
        $change = $supplier->criticalityChanges()->sole();

        foreach (['update' => fn () => $change->update(['reason' => 'Endret']), 'delete' => fn () => $change->delete()] as $what => $write) {
            try {
                $write();
                $this->fail("The model must refuse to {$what} history.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $row = DB::table('supplier_criticality_changes')->where('id', $change->id);
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['reason' => 'Endret']), 'changing the reason');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['to_criticality' => 'standard']), 'changing the level');
        $this->assertDatabaseRefuses(fn () => (clone $row)->update(['to_hard_to_replace' => true]), 'changing an answer');
        $this->assertDatabaseRefuses(fn () => (clone $row)->delete(), 'deleting history');

        // The one update the database allows: the author's account going. The row stays.
        $editor->delete();
        $this->assertNull(DB::table('supplier_criticality_changes')->where('id', $change->id)->value('changed_by_user_id'));
    }
}
