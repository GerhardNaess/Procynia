<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierAssuranceDecision;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
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
 * The general kontrollbeslutning was retired on 2026-10-12 (Registrer beslutning). End to end:
 *
 *  - no new decision can be registered: the endpoint is gone, and the model refuses a create;
 *  - the decisions registered before are kept — immutable in the model and the database, still a
 *    reason the supplier cannot be deleted — and shown read-only under Historikk;
 *  - Kravstatus is the state of the requirements alone, on the page and in the register, and no
 *    decision moves it; Ta i bruk warns on an open mandatory requirement, and still on a historical
 *    «Ikke godkjent for nye kjøp».
 */
class SupplierAssuranceDecisionTest extends TestCase
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

    public function test_no_new_decision_can_be_registered(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $this->requirement($customer, 'Databehandleravtale', 'mandatory');

        // The endpoint is gone, whatever the decision and whoever asks.
        foreach (['approved', 'approved_with_follow_up', 'not_approved'] as $decision) {
            $status = $this->actingAs($assurer)->post("/app/supplier-management/{$supplier->id}/assurance-decisions", [
                'decision' => $decision, 'rationale' => 'Vurdert.', 'follow_up_note' => 'Følges opp.', 'decided_on' => now()->toDateString(),
            ])->getStatusCode();
            $this->assertContains($status, [404, 405]);
        }

        // Nor through the model.
        try {
            SupplierAssuranceDecision::query()->create($this->decisionRow($customer, $supplier));
            $this->fail('The model must refuse a new decision.');
        } catch (LogicException) {
        }

        $this->assertSame(0, SupplierAssuranceDecision::query()->count());

        // The page offers nothing to decide: no Kontrollstatus with a form, only Kravstatus.
        $props = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('assurance', $props);
        $this->assertSame([], $props['decision_history']);
        $this->assertSame('mandatory_open', $props['requirement_status']['state']);
        $this->assertArrayNotHasKey('decision_required', $props['requirement_status']);
        $this->assertArrayNotHasKey('allowed_decisions', $props['requirement_status']);
        // Without a decision, the supplier is still deletable by this rule.
        $this->assertTrue($supplier->fresh()->isDeletable());
    }

    public function test_historical_decisions_are_kept_and_shown_read_only_under_historikk(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Databehandleravtale', 'mandatory');
        $show = "/app/supplier-management/{$supplier->id}";

        // Two decisions registered before the retirement — written as the old service did.
        $snapshot = [
            'state' => 'follow_up_required',
            'applicable_count' => 1,
            'counts' => ['documented' => 0, 'partially_documented' => 0, 'missing' => 0, 'not_evaluated' => 0, 'temporarily_accepted' => 1, 'renewal_due' => 0, 'acceptance_expired' => 0],
            'unmet' => [['id' => $requirement->id, 'title' => 'Databehandleravtale', 'level' => 'mandatory', 'display_status' => 'temporarily_accepted']],
        ];
        $older = DB::table('supplier_assurance_decisions')->insertGetId($this->decisionRow($customer, $supplier, [
            'decision' => 'approved_with_follow_up', 'follow_up_note' => 'Signert avtale innen fristen.', 'decided_on' => '2026-09-01',
            'decided_by_user_id' => $assurer->id, 'state_snapshot' => json_encode($snapshot),
        ]));
        $newer = DB::table('supplier_assurance_decisions')->insertGetId($this->decisionRow($customer, $supplier, [
            'decision' => 'not_approved', 'rationale' => 'Avtalen ble ikke signert.', 'decided_on' => '2026-10-01',
        ]));

        // Read-only history, newest first, for anyone who can see the supplier — with the state as it was.
        foreach ([$assurer, $reader] as $user) {
            $props = $this->actingAs($user)->get($show)->assertOk()->viewData('page')['props'];
            $this->assertSame([$newer, $older], array_column($props['decision_history'], 'id'));
            $this->assertSame(['not_approved', 'approved_with_follow_up'], array_column($props['decision_history'], 'decision'));
            $this->assertSame('Signert avtale innen fristen.', $props['decision_history'][1]['follow_up_note']);
            $this->assertSame($assurer->name, $props['decision_history'][1]['decided_by_name']);
            $this->assertSame('temporarily_accepted', $props['decision_history'][1]['snapshot']['unmet'][0]['display_status']);
            $this->assertArrayNotHasKey('form', $props['decision_history'][0]);
        }

        // History is not the state now: Kravstatus is the requirements' alone.
        $this->assertSame('mandatory_open', $props['requirement_status']['state']);

        // Immutable in the model and in the database, and still history the supplier cannot be deleted from.
        $decision = SupplierAssuranceDecision::query()->findOrFail($older);
        try {
            $decision->forceFill(['rationale' => 'Endret'])->save();
            $this->fail('The model must refuse a change.');
        } catch (LogicException) {
        }
        try {
            $decision->delete();
            $this->fail('The model must refuse a delete.');
        } catch (LogicException) {
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->where('id', $older)->update(['decision' => 'approved']), 'changing a decision');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->where('id', $older)->delete(), 'deleting a decision');
        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->assertSame(2, SupplierAssuranceDecision::query()->where('supplier_id', $supplier->id)->count());

        // Another customer sees none of it.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->actingAs($foreignAssurer)->get($show)->assertNotFound();
        $foreignSupplier = $this->supplier($foreignCustomer, $foreignAssurer, 'Fremmed AS');
        $this->assertSame([], $this->actingAs($foreignAssurer)->get("/app/supplier-management/{$foreignSupplier->id}")->viewData('page')['props']['decision_history']);

        // An ended supplier keeps its history.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($editor)->post("{$show}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->actingAs($reader)->get($show)->viewData('page')['props']['decision_history']);
    }

    public function test_kravstatus_follows_the_requirements_alone_and_ta_i_bruk_still_warns(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $assurer, 'Lønn AS', Supplier::STATUS_ONBOARDING);
        $dpa = $this->requirement($customer, 'Databehandleravtale', 'mandatory');
        $show = "/app/supplier-management/{$supplier->id}";
        $props = fn (): array => $this->actingAs($assurer)->get($show)->viewData('page')['props'];
        $register = fn (array $query = []): array => array_column($this->actingAs($assurer)->get('/app/supplier-management?'.http_build_query($query))->viewData('page')['props']['suppliers'], 'requirement_status', 'name');

        // A mandatory requirement not evaluated: Obligatorisk krav åpent, and Ta i bruk warns.
        $this->assertSame('mandatory_open', $props()['requirement_status']['state']);
        $this->assertSame(['mandatory_open' => true, 'not_approved_on' => null], $props()['activate_warning']);
        $this->assertSame(['Lønn AS' => 'mandatory_open'], $register());
        $this->assertTrue($this->actingAs($assurer)->get('/app/supplier-management')->viewData('page')['props']['requirement_status_enabled']);

        // Controlled as missing: still open, named with why.
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'missing'))->assertSessionHasNoErrors();
        $this->assertSame(['missing'], array_column($props()['requirement_status']['open_mandatory'], 'display_status'));

        // Temporarily accepted: Krever oppfølging, no warning.
        $until = now()->addDays(10)->toDateString();
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'temporarily_accepted', ['accepted_until' => $until, 'rationale' => 'Avtalen er under signering.']))->assertSessionHasNoErrors();
        $this->assertSame('follow_up_required', $props()['requirement_status']['state']);
        $this->assertSame(['mandatory_open' => false, 'not_approved_on' => null], $props()['activate_warning']);

        // The acceptance runs out: open again.
        $this->travelTo(now()->addDays(11));
        $this->assertSame(['acceptance_expired'], array_column($props()['requirement_status']['open_mandatory'], 'display_status'));
        $this->assertTrue($props()['activate_warning']['mandatory_open']);

        // Documented: I orden — and the system decides nothing.
        $document = SupplierDocument::query()->create(['customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'document_type' => 'data_processing_agreement', 'title' => 'DBA 2026']);
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'documented', ['document_ids' => [$document->id], 'rationale' => 'Signert avtale mottatt.']))->assertSessionHasNoErrors();
        $this->assertSame('in_order', $props()['requirement_status']['state']);
        $this->assertSame(['mandatory_open' => false, 'not_approved_on' => null], $props()['activate_warning']);
        $this->assertSame(0, SupplierAssuranceDecision::query()->count());

        // A historical «Ikke godkjent for nye kjøp» keeps the warning it gave, dated, and moves no state.
        DB::table('supplier_assurance_decisions')->insert($this->decisionRow($customer, $supplier, ['decided_on' => '2026-09-15']));
        $this->assertSame(['mandatory_open' => false, 'not_approved_on' => '2026-09-15'], $props()['activate_warning']);
        $this->assertSame('in_order', $props()['requirement_status']['state']);
        $this->assertSame(['Lønn AS' => 'in_order'], $register());

        // The retired filters do nothing.
        $this->assertSame(['Lønn AS' => 'in_order'], $register(['decision' => 'none', 'decision_required' => 1]));

        // Ended: no Kravstatus in the register — it is no longer followed up.
        $this->actingAs($assurer)->post("{$show}/end", ['reason' => 'Ble ikke valgt.'])->assertSessionHasNoErrors();
        $this->assertSame(['Lønn AS' => null], $register(['status' => 'all']));
    }

    public function test_a_customer_without_control_requirements_has_no_kravstatus_column_even_with_historical_decisions(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Gammel AS');
        DB::table('supplier_assurance_decisions')->insert($this->decisionRow($customer, $supplier, ['decision' => 'approved']));

        $register = $this->actingAs($assurer)->get('/app/supplier-management')->viewData('page')['props'];
        $this->assertFalse($register['requirement_status_enabled']);
        $this->assertArrayNotHasKey('assurance_decisions', $register);
        $this->assertNull($register['suppliers'][0]['requirement_status']);
        $this->assertCount(1, $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['decision_history']);
    }

    /** @return array<string, mixed> */
    private function control(SupplierControlRequirement $requirement, string $status, array $overrides = []): array
    {
        return array_merge([
            'requirement_id' => $requirement->id,
            'status' => $status,
            'rationale' => 'Ikke mottatt.',
            'evaluated_on' => now()->toDateString(),
            'accepted_until' => '',
            'document_ids' => [],
        ], $overrides);
    }

    private function requirement(Customer $customer, string $title, string $level = 'standard'): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'theme' => 'privacy',
            'level' => $level,
            'control_point' => 'before_contract',
            'applies_when' => [],
        ]);
    }

    /**
     * A decision row as the retired service wrote it — inserted directly, since nothing in the
     * application writes one any more.
     *
     * @return array<string, mixed>
     */
    private function decisionRow(Customer $customer, Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'decision' => 'not_approved', 'rationale' => 'Vurdert ut fra kontrollene.',
            'decided_on' => now()->toDateString(), 'recorded_at' => now(), 'state_snapshot' => '{}', 'supplier_name' => $supplier->name,
        ], $overrides);
    }
}
