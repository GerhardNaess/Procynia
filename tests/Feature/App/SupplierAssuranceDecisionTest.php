<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierAssuranceDecision;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\User;
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
 * Supplier Assurance v2 phase 4: Kontrollstatus — the control state computed now, and the decision a
 * person registers (docs/supplier-assurance-v2-plan.md §7.1, §9, §13.2, §20). The state rules are
 * SupplierAssuranceResolverTest's; here, end to end:
 *
 *  - a decision is registered with supplier.assure only, on an open supplier, never across
 *    customers, and is history in the model and the database;
 *  - the system never decides: no row appears because a requirement is open or everything is
 *    documented;
 *  - what may be decided follows the state now, checked by the server; a temporary acceptance lets
 *    «Godkjent med oppfølging» through, and when it expires the state asks for a decision again
 *    while the decision stays as it was, snapshot included;
 *  - the register shows the decision and «Krever beslutning» apart, with two filters.
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

    public function test_decisions_need_supplier_assure_an_open_supplier_and_the_same_customer_and_are_history(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $url = "/app/supplier-management/{$supplier->id}/assurance-decisions";
        $show = "/app/supplier-management/{$supplier->id}";

        // Nothing applies yet: no Kontrollstatus, and nothing to decide.
        $this->assertNull($this->actingAs($assurer)->get($show)->viewData('page')['props']['assurance']);
        $this->actingAs($assurer)->post($url, $this->decision('not_approved'))->assertSessionHasErrors('decision');

        $this->requirement($customer, 'Etiske retningslinjer');

        // edit, assess and delete grant nothing here; view only reads; System Owner without a role is refused.
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $this->actingAs($this->supplierUser($customer, [$key]))->post($url, $this->decision('not_approved'))->assertForbidden();
        }
        $this->actingAs($reader)->post($url, $this->decision('not_approved'))->assertForbidden();
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->actingAs($systemOwner)->post($url, $this->decision('not_approved'))->assertForbidden();
        $this->actingAs($systemOwner)->get($show)->assertForbidden();
        $this->assertSame(0, SupplierAssuranceDecision::query()->count());

        // A begrunnelse always; never a future date.
        $this->actingAs($assurer)->post($url, $this->decision('not_approved', ['rationale' => '   ']))->assertSessionHasErrors('rationale');
        $this->actingAs($assurer)->post($url, $this->decision('not_approved', ['decided_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('decided_on');
        $this->actingAs($assurer)->post($url, $this->decision('not_approved', ['decided_on' => now()->subMonth()->toDateString()]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $decision = SupplierAssuranceDecision::query()->sole();
        $this->assertSame(
            ['not_approved', $assurer->id, 'Drift AS', now()->subMonth()->toDateString(), null],
            [$decision->decision, $decision->decided_by_user_id, $decision->supplier_name, $decision->decided_on->toDateString(), $decision->follow_up_note],
        );
        $this->assertNotNull($decision->recorded_at);
        $this->assertFalse($supplier->fresh()->isDeletable());

        // supplier.view reads the decision and the state, and is offered nothing.
        $assurance = $this->actingAs($reader)->get($show)->assertOk()->viewData('page')['props']['assurance'];
        $this->assertSame(['not_approved', false, null], [$assurance['decision']['decision'], $assurance['permissions']['can_decide'], $assurance['form']]);
        $this->assertSame([$decision->id], array_column($assurance['history'], 'id'));

        // Immutable in the model and in the database.
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
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->where('id', $decision->id)->update(['decision' => 'approved']), 'changing a decision');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->where('id', $decision->id)->delete(), 'deleting a decision');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->insert($this->decisionRow($customer, $supplier, ['decision' => 'decision_required'])), 'a computed signal stored as a decision');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->insert($this->decisionRow($customer, $supplier, ['decision' => 'approved_with_follow_up'])), 'Godkjent med oppfølging without what is followed up');

        // Another customer: a 404 for the supplier, and the database refuses the row.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $foreignSupplier = $this->supplier($foreignCustomer, $foreignAssurer, 'Fremmed AS');
        $this->actingAs($assurer)->post("/app/supplier-management/{$foreignSupplier->id}/assurance-decisions", $this->decision('not_approved'))->assertNotFound();
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_assurance_decisions')->insert($this->decisionRow($foreignCustomer, $supplier)), 'a decision across customers');
        // The other customer's catalogue gives its suppliers nothing of ours.
        $this->assertNull($this->actingAs($foreignAssurer)->get("/app/supplier-management/{$foreignSupplier->id}")->viewData('page')['props']['assurance']);

        // Ended: read-only, refused even when asked directly.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($editor)->post("{$show}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, $this->decision('not_approved', ['rationale' => 'Ny beslutning.']))->assertSessionHasErrors('decision');
        $assurance = $this->actingAs($assurer)->get($show)->viewData('page')['props']['assurance'];
        $this->assertSame([false, true, 1], [$assurance['permissions']['can_decide'], $assurance['permissions']['ended'], count($assurance['history'])]);
        $this->assertSame(1, SupplierAssuranceDecision::query()->count());
    }

    public function test_the_state_decides_what_may_be_decided_and_a_decision_stays_as_it_was_when_the_state_changes(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Lønn AS', Supplier::STATUS_ONBOARDING);
        $dpa = $this->requirement($customer, 'Databehandleravtale', 'mandatory');
        $show = "/app/supplier-management/{$supplier->id}";
        $url = "{$show}/assurance-decisions";
        $state = fn (): array => $this->actingAs($assurer)->get($show)->viewData('page')['props']['assurance']['state'];

        // A mandatory requirement not evaluated: Krever beslutning — and the system decides nothing.
        $this->assertSame(['mandatory_open', true, ['not_approved']], [$state()['state'], $state()['decision_required'], $state()['allowed_decisions']]);
        $this->assertTrue($this->actingAs($assurer)->get($show)->viewData('page')['props']['activate_warning']);
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'missing'))->assertSessionHasNoErrors();
        $this->assertSame(['missing'], array_column($state()['open_mandatory'], 'display_status'));
        $this->assertSame(0, SupplierAssuranceDecision::query()->count());
        $this->actingAs($assurer)->post($url, $this->decision('approved'))->assertSessionHasErrors('decision');
        $this->actingAs($assurer)->post($url, $this->decision('approved_with_follow_up', ['follow_up_note' => 'Avtalen signeres.']))->assertSessionHasErrors('decision');

        // Accepted until a date: Godkjent med oppfølging, with what is followed up; Godkjent still not.
        $until = now()->addDays(10)->toDateString();
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'temporarily_accepted', ['accepted_until' => $until, 'rationale' => 'Avtalen er under signering.']))->assertSessionHasNoErrors();
        $this->assertSame(['follow_up_required', false, ['approved_with_follow_up', 'not_approved']], [$state()['state'], $state()['decision_required'], $state()['allowed_decisions']]);
        $this->actingAs($assurer)->post($url, $this->decision('approved'))->assertSessionHasErrors('decision');
        $this->actingAs($assurer)->post($url, $this->decision('approved_with_follow_up', ['follow_up_note' => '  ']))->assertSessionHasErrors('follow_up_note');
        $this->actingAs($assurer)->post($url, $this->decision('approved_with_follow_up', ['follow_up_note' => 'Signert avtale innen fristen.']))->assertSessionHasNoErrors();
        $first = SupplierAssuranceDecision::query()->sole();
        // jsonb keeps the keys, not their order.
        $this->assertEquals([
            'state' => 'follow_up_required',
            'applicable_count' => 1,
            'counts' => ['documented' => 0, 'partially_documented' => 0, 'missing' => 0, 'not_evaluated' => 0, 'temporarily_accepted' => 1, 'renewal_due' => 0, 'acceptance_expired' => 0],
            'unmet' => [['id' => $dpa->id, 'title' => 'Databehandleravtale', 'level' => 'mandatory', 'display_status' => 'temporarily_accepted']],
        ], $first->state_snapshot);
        $this->assertFalse($this->actingAs($assurer)->get($show)->viewData('page')['props']['activate_warning']);

        // The acceptance runs out: the state asks for a decision again; the decision stands as it was.
        $this->travelTo(now()->addDays(11));
        $page = $this->actingAs($assurer)->get($show)->viewData('page')['props'];
        $this->assertSame(['mandatory_open', true], [$page['assurance']['state']['state'], $page['assurance']['state']['decision_required']]);
        $this->assertSame(['acceptance_expired'], array_column($page['assurance']['state']['open_mandatory'], 'display_status'));
        $this->assertSame([$first->id, 'approved_with_follow_up'], [$page['assurance']['decision']['id'], $page['assurance']['decision']['decision']]);
        $this->assertSame('temporarily_accepted', $page['assurance']['history'][0]['snapshot']['unmet'][0]['display_status']);
        $this->assertTrue($page['activate_warning']);
        $this->actingAs($assurer)->post($url, $this->decision('approved_with_follow_up', ['follow_up_note' => 'Ny frist.']))->assertSessionHasErrors('decision');

        // The register: the decision and Krever beslutning apart, with a filter each.
        $register = fn (array $query = []): array => array_column($this->actingAs($assurer)->get('/app/supplier-management?'.http_build_query($query))->viewData('page')['props']['suppliers'], 'control_status', 'name');
        $this->assertSame(['Lønn AS' => ['decision' => 'approved_with_follow_up', 'decision_required' => true, 'has_state' => true]], $register());
        $this->assertSame(['Lønn AS'], array_keys($register(['decision_required' => 1])));
        $this->assertSame(['Lønn AS'], array_keys($register(['decision' => 'approved_with_follow_up'])));
        $this->assertSame([], $register(['decision' => 'none']));

        // Documented: in order. Still no decision but the person's; Godkjent may now be registered.
        $document = SupplierDocument::query()->create(['customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'document_type' => 'data_processing_agreement', 'title' => 'DBA 2026']);
        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($dpa, 'documented', ['document_ids' => [$document->id], 'rationale' => 'Signert avtale mottatt.']))->assertSessionHasNoErrors();
        $this->assertSame(['in_order', false, ['approved', 'approved_with_follow_up', 'not_approved']], [$state()['state'], $state()['decision_required'], $state()['allowed_decisions']]);
        $this->assertSame(1, SupplierAssuranceDecision::query()->count());
        $this->actingAs($assurer)->post($url, $this->decision('approved', ['follow_up_note' => 'Ignoreres.']))->assertSessionHasNoErrors();

        // A new row; the decision in force is the new one; the first is untouched.
        $this->assertSame(2, SupplierAssuranceDecision::query()->count());
        $assurance = $this->actingAs($assurer)->get($show)->viewData('page')['props']['assurance'];
        $this->assertSame(['approved', null], [$assurance['decision']['decision'], $assurance['decision']['follow_up_note']]);
        $this->assertSame(['approved', 'approved_with_follow_up'], array_column($assurance['history'], 'decision'));
        $this->assertSame([], $assurance['history'][0]['snapshot']['unmet']);
        $this->assertSame($first->state_snapshot, $first->fresh()->state_snapshot);
        $this->assertSame(['approved_with_follow_up', 'Signert avtale innen fristen.'], [$first->fresh()->decision, $first->fresh()->follow_up_note]);
    }

    /** @return array<string, mixed> */
    private function decision(string $decision, array $overrides = []): array
    {
        return array_merge([
            'decision' => $decision,
            'rationale' => 'Vurdert ut fra kontrollene.',
            'follow_up_note' => '',
            'decided_on' => now()->toDateString(),
        ], $overrides);
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

    /** @return array<string, mixed> */
    private function decisionRow(Customer $customer, Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'decision' => 'not_approved', 'rationale' => 'x',
            'decided_on' => now()->toDateString(), 'recorded_at' => now(), 'state_snapshot' => '{}', 'supplier_name' => 'x',
        ], $overrides);
    }
}
