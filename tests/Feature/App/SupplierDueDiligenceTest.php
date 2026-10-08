<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\Supplier;
use App\Models\SupplierAssuranceDecision;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierImprovementCase;
use App\Models\SupplierProfile;
use App\Models\SupplierRisk;
use App\Models\User;
use App\Services\Suppliers\SupplierAttentionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 7: Aktsomhetsvurdering (docs/supplier-assurance-v2-plan.md §11, §13.2,
 * §15.1, §20). The relevance rule is SupplierProfilePredicatesTest's and template 4's structure
 * SupplierRequirementTemplatesTest's; here, end to end:
 *
 *  - an assessment takes supplier.assure, an open supplier and the same customer; six areas, each
 *    chosen; a conclusion and a begrunnelse; it is history in the model and the database, with the
 *    plan's snapshot;
 *  - high levels never decide anything: no assurance decision, no criticality, no lifecycle change;
 *  - signals 10 and 11, only for suppliers with an applying requirement;
 *  - «Tiltak kreves» is followed up in Avvik og forbedringer, and a risk created in Risiko, both
 *    with supplier.assure plus the target module's right, and with provenance.
 */
class SupplierDueDiligenceTest extends TestCase
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

    public function test_an_assessment_takes_supplier_assure_six_chosen_areas_and_a_begrunnelse_and_is_history(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $assurer, 'Tekstil AS', classification: $this->classification('important', 12));
        $this->profile($supplier, ['high_risk_categories' => ['textiles'], 'production_outside_eea' => 'unknown']);
        $this->requirement($customer);
        $url = "/app/supplier-management/{$supplier->id}/due-diligence-assessments";
        $show = "/app/supplier-management/{$supplier->id}";

        // edit, assess and delete grant nothing here; view only reads; System Owner without a role is refused.
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $this->actingAs($this->supplierUser($customer, [$key]))->post($url, $this->assessment())->assertForbidden();
        }
        $this->actingAs($reader)->post($url, $this->assessment())->assertForbidden();
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->actingAs($systemOwner)->post($url, $this->assessment())->assertForbidden();
        $this->actingAs($systemOwner)->get($show)->assertForbidden();
        // Another customer's supplier is a 404.
        $foreign = $this->supplier($foreignCustomer, $this->supplierUser($foreignCustomer, []), 'Fremmed AS');
        $this->actingAs($assurer)->post("/app/supplier-management/{$foreign->id}/due-diligence-assessments", $this->assessment())->assertNotFound();

        // Each of the six areas must be chosen, from the four levels; Ukjent is a level.
        foreach (SupplierDueDiligenceAssessment::AREAS as $area) {
            $this->actingAs($assurer)->post($url, $this->assessment([$area => '']))->assertSessionHasErrors($area);
            $this->actingAs($assurer)->post($url, $this->assessment([$area => 'medium']))->assertSessionHasErrors($area);
        }
        foreach ([
            'conclusion' => ['conclusion' => 'approved'],
            'rationale' => ['rationale' => '   '],
            'review_interval_months' => ['review_interval_months' => 18],
            'assessed_on' => ['assessed_on' => now()->addDay()->toDateString()],
        ] as $field => $override) {
            $this->actingAs($assurer)->post($url, $this->assessment($override))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, SupplierDueDiligenceAssessment::query()->count());

        // Backdated is allowed; recorded_at is when it was registered. Every area high, measures required.
        $high = array_fill_keys(SupplierDueDiligenceAssessment::AREAS, 'high');
        $this->actingAs($assurer)->post($url, $this->assessment(['environment_risk' => 'unknown', 'conclusion' => 'measures_required', 'assessed_on' => now()->subMonth()->toDateString()] + $high))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $first = SupplierDueDiligenceAssessment::query()->sole();
        $this->assertSame(
            ['unknown', 'measures_required', $assurer->id, now()->subMonth()->toDateString(), 'Tekstil AS', 'important', ['textiles'], 'unknown', null],
            [$first->environment_risk, $first->conclusion, $first->assessed_by_user_id, $first->assessed_on->toDateString(), $first->supplier_name, $first->criticality, $first->high_risk_categories, $first->production_outside_eea, $first->investigation_summary],
        );
        $this->assertNotNull($first->recorded_at);
        $this->assertFalse($supplier->fresh()->isDeletable());

        // Nothing decided by it: no assurance decision, criticality and status as they were.
        $this->assertSame(0, SupplierAssuranceDecision::query()->count());
        $this->assertSame(['important', Supplier::STATUS_ACTIVE], [$supplier->fresh()->criticality, $supplier->fresh()->status]);

        // A second, later assessment is a new row and the one in force; the first stays as it was.
        $supplier->forceFill(['name' => 'Tekstil Norge AS'])->save();
        $this->actingAs($assurer)->post($url, $this->assessment(['conclusion' => 'monitor', 'review_interval_months' => 6]))->assertSessionHasNoErrors();
        // A third, backdated before both, is history but not in force.
        $this->actingAs($assurer)->post($url, $this->assessment(['assessed_on' => now()->subYear()->toDateString()]))->assertSessionHasNoErrors();
        $this->assertSame(3, SupplierDueDiligenceAssessment::query()->count());

        // supplier.view reads it all, newest first, with the snapshot as it was; and is offered nothing.
        $data = $this->actingAs($reader)->get($show)->assertOk()->viewData('page')['props']['due_diligence'];
        $this->assertSame(['monitor', 'measures_required', 'no_significant_risk'], array_column($data['history'], 'conclusion'));
        $this->assertSame(['Tekstil Norge AS', 'Tekstil AS'], [$data['history'][0]['snapshot']['supplier_name'], $data['history'][1]['snapshot']['supplier_name']]);
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $data['next_on']);
        $this->assertSame([true, false], [$data['relevant'], $data['permissions']['can_assess']]);
        $this->assertSame(['textiles'], $data['mapping']['high_risk_categories']);
        // Not answered stays not answered, never «no».
        $this->assertNull($data['mapping']['labour_intensive']);
        $this->assertTrue($this->actingAs($assurer)->get($show)->viewData('page')['props']['due_diligence']['permissions']['can_assess']);

        // Immutable in the model and in the database.
        try {
            $first->forceFill(['conclusion' => 'monitor'])->save();
            $this->fail('The model must refuse a change.');
        } catch (LogicException) {
        }
        try {
            $first->delete();
            $this->fail('The model must refuse a delete.');
        } catch (LogicException) {
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_due_diligence_assessments')->where('id', $first->id)->update(['child_labour_risk' => 'low']), 'changing an assessment');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_due_diligence_assessments')->where('id', $first->id)->delete(), 'deleting an assessment');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_due_diligence_assessments')->insert($this->row($customer, $supplier, ['forced_labour_risk' => 'medium'])), 'a level outside the four');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_due_diligence_assessments')->insert($this->row($customer, $supplier, ['review_interval_months' => 36])), 'an interval outside 6, 12, 24');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_due_diligence_assessments')->insert($this->row($foreignCustomer, $supplier)), 'an assessment across customers');
        // The person who assessed may be deleted: the row keeps everything else.
        DB::table('supplier_due_diligence_assessments')->where('id', $first->id)->update(['assessed_by_user_id' => null]);

        // Ended: read-only, and the history still reads.
        $supplier->forceFill(['status' => Supplier::STATUS_ENDED])->save();
        $this->actingAs($assurer)->post($url, $this->assessment())->assertSessionHasErrors('conclusion');
        $ended = $this->actingAs($assurer)->get($show)->viewData('page')['props']['due_diligence'];
        $this->assertSame([false, true, 3, false], [$ended['permissions']['can_assess'], $ended['permissions']['ended'], count($ended['history']), $ended['overdue']]);
    }

    public function test_the_card_is_absent_until_leverandorkontroll_is_started_and_says_when_it_is_not_relevant(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Kontor AS');
        $show = "/app/supplier-management/{$supplier->id}";

        // A customer without control requirements sees the page as before (plan §17).
        $this->assertNull($this->actingAs($assurer)->get($show)->viewData('page')['props']['due_diligence']);

        $this->requirement($customer);
        $data = $this->actingAs($assurer)->get($show)->viewData('page')['props']['due_diligence'];
        $this->assertSame([false, null, true, []], [$data['relevant'], $data['because'], $data['mapping']['profile_empty'], $data['history']]);

        // Not relevant, still allowed.
        $this->actingAs($assurer)->post("{$show}/due-diligence-assessments", $this->assessment())->assertSessionHasNoErrors();
    }

    public function test_signal_10_and_11_follow_the_profile_and_the_next_date_only_for_suppliers_with_requirements(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $relevant = $this->supplier($customer, $assurer, 'Tekstil AS');
        $onboarding = $this->supplier($customer, $assurer, 'Ny AS', Supplier::STATUS_ONBOARDING);
        $plain = $this->supplier($customer, $assurer, 'Kontor AS');
        $ended = $this->supplier($customer, $assurer, 'Gammel AS', Supplier::STATUS_ENDED);
        foreach ([$relevant, $onboarding, $ended] as $supplier) {
            $this->profile($supplier, ['high_risk_categories' => ['textiles']]);
        }
        $this->profile($plain, ['high_risk_categories' => [], 'production_outside_eea' => 'no', 'uses_subcontractors' => 'no', 'labour_intensive' => 'no']);
        // Another customer's relevant supplier never counts here.
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $foreign = $this->supplier($foreignCustomer, $foreignAssurer, 'Fremmed AS');
        $this->profile($foreign, ['high_risk_categories' => ['textiles']]);
        $this->requirement($foreignCustomer);

        $keys = fn (Supplier $supplier): array => array_values(array_intersect(
            array_column(app(SupplierAttentionService::class)->findingsForSupplier($supplier->fresh()), 'key'),
            [SupplierAttentionService::DUE_DILIGENCE_MISSING, SupplierAttentionService::DUE_DILIGENCE_OVERDUE],
        ));

        // No applying requirement: no signal, however relevant (§15.1).
        $this->assertSame([], $keys($relevant));

        $this->requirement($customer);
        $this->assertSame([SupplierAttentionService::DUE_DILIGENCE_MISSING], $keys($relevant));
        $this->assertSame([SupplierAttentionService::DUE_DILIGENCE_MISSING], $keys($onboarding));
        $this->assertSame([], $keys($plain));
        $this->assertSame([], $keys($ended));

        // The register's existing filter and panel carry it, for this customer's suppliers only.
        $overview = $this->actingAs($assurer)->get('/app/supplier-management?attention=1')->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['Tekstil AS', 'Ny AS'], array_column($overview['suppliers'], 'name'));
        $this->assertSame(2, collect($overview['attention']['categories'])->firstWhere('key', SupplierAttentionService::DUE_DILIGENCE_MISSING)['count']);

        // An assessment clears it; when its next date passes, Aktsomhetsvurdering forfalt — active only.
        $this->row($customer, $relevant, ['assessed_on' => now()->subMonthsNoOverflow(12)->toDateString()], insert: true);
        $this->assertSame([], $keys($relevant), 'due today is not overdue');
        $this->row($customer, $relevant, ['assessed_on' => now()->subMonthsNoOverflow(12)->subDay()->toDateString()], insert: true);
        $this->assertSame([], $keys($relevant), 'the later assessment is the one in force');
        $old = $this->supplier($customer, $assurer, 'Forfalt AS');
        $this->profile($old, ['high_risk_categories' => ['textiles']]);
        $this->row($customer, $old, ['assessed_on' => now()->subMonthsNoOverflow(12)->subDay()->toDateString()], insert: true);
        $this->assertSame([SupplierAttentionService::DUE_DILIGENCE_OVERDUE], $keys($old));
        $this->row($customer, $onboarding, ['assessed_on' => now()->subYears(2)->toDateString()], insert: true);
        $this->assertSame([], $keys($onboarding), 'Aktsomhetsvurdering forfalt is for active suppliers');

        // «Neste kontroller» lists the next assessment, overdue.
        $plan = $this->actingAs($assurer)->get("/app/supplier-management/{$old->id}")->viewData('page')['props']['follow_up_plan'];
        $entry = collect($plan['entries'])->firstWhere('kind', 'due_diligence');
        $this->assertSame([now()->subDay()->toDateString(), true], [$entry['date'], $entry['overdue']]);
    }

    public function test_measures_required_is_followed_up_in_avvik_and_a_risk_created_with_supplier_assure_and_provenance(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->grant($customer, $assurer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$area]);
        $supplier = $this->supplier($customer, $assurer, 'Tekstil AS');
        $other = $this->supplier($customer, $assurer, 'Annen AS');
        $measures = $this->row($customer, $supplier, ['conclusion' => 'measures_required'], insert: true);
        $monitor = $this->row($customer, $supplier, ['conclusion' => 'monitor'], insert: true);
        $foreign = $this->row($customer, $other, ['conclusion' => 'measures_required'], insert: true);
        $cases = "/app/supplier-management/{$supplier->id}/improvement-cases";
        $case = fn (int $id, array $overrides = []): array => $overrides + [
            'type' => ImprovementCase::TYPE_DEVIATION, 'title' => 'Tiltak i leverandørkjeden', 'description' => 'Fra aktsomhetsvurderingen.',
            'business_area_id' => $area->id, 'owner_user_id' => $assurer->id, 'due_date' => '',
            'supplier_due_diligence_assessment_id' => $id, 'handoff_key' => (string) Str::uuid(),
        ];

        // supplier.edit does not hand off an aktsomhetsvurdering; only «Tiltak kreves», only this supplier's, only one provenance.
        $this->actingAs($editor)->post($cases, $case($measures))->assertForbidden();
        foreach ([$monitor, $foreign] as $refused) {
            $this->actingAs($assurer)->post($cases, $case($refused))->assertSessionHasErrors('supplier_due_diligence_assessment_id');
        }
        $this->actingAs($assurer)->post($cases, $case($measures, ['supplier_requirement_evaluation_id' => 1]))->assertSessionHasErrors('supplier_due_diligence_assessment_id');
        // Without improvement.edit there is no case.
        $noImprovement = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->actingAs($noImprovement)->post($cases, $case($measures))->assertSessionHasErrors('business_area_id');
        $this->assertSame(0, SupplierImprovementCase::query()->count());

        $this->actingAs($assurer)->post($cases, $case($measures))->assertSessionHasNoErrors();
        $link = $supplier->improvementCaseLinks()->sole();
        $this->assertSame(['handoff', $measures, null, null], [$link->origin, (int) $link->supplier_due_diligence_assessment_id, $link->supplier_assessment_id, $link->supplier_requirement_evaluation_id]);
        $assessedOn = now()->toDateString();
        $this->assertSame($assessedOn, $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['improvement_cases'][0]['due_diligence_assessed_on']);
        $this->assertSame($assessedOn, $this->actingAs($assurer)->get("/app/improvements/{$link->improvement_case_id}")->viewData('page')['props']['supplier_origin'][0]['due_diligence_assessed_on']);
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->where('id', $link->id)->update(['supplier_requirement_evaluation_id' => null, 'supplier_assessment_id' => null, 'supplier_due_diligence_assessment_id' => $foreign]), "another supplier's assessment");
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->where('id', $link->id)->update(['origin' => 'linked', 'handoff_key' => null]), 'a linked case from an assessment');

        // Opprett risiko from the assessment: supplier.assure plus risk.create; supplier.edit only from the supplier.
        $risks = "/app/supplier-management/{$supplier->id}/risks";
        $risk = fn (array $overrides = []): array => $overrides + [
            'title' => 'Tvangsarbeid i leverandørkjeden', 'cause' => 'Produksjon i høyrisikoland', 'event' => 'Brudd avdekkes',
            'consequence' => 'Omdømme og kontraktsbrudd', 'business_area_id' => $area->id, 'owner_user_id' => null,
            'supplier_due_diligence_assessment_id' => $monitor,
        ];
        $this->actingAs($editor)->post($risks, $risk())->assertForbidden();
        $this->actingAs($assurer)->post($risks, $risk())->assertSessionHasErrors('business_area_id');
        $this->actingAs($assurer)->post($risks, $risk(['supplier_due_diligence_assessment_id' => null]))->assertForbidden();
        $this->grant($customer, $assurer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$area]);
        $this->actingAs($assurer->fresh())->post($risks, $risk(['supplier_due_diligence_assessment_id' => $foreign]))->assertSessionHasErrors('supplier_due_diligence_assessment_id');
        $this->actingAs($assurer->fresh())->post($risks, $risk())->assertSessionHasNoErrors();
        $riskLink = SupplierRisk::query()->sole();
        $this->assertSame(['created_from_supplier', $monitor], [$riskLink->origin, (int) $riskLink->supplier_due_diligence_assessment_id]);
        $this->assertSame($assessedOn, $this->actingAs($assurer->fresh())->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['risks'][0]['due_diligence_assessed_on']);
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_risks')->where('id', $riskLink->id)->update(['origin' => 'linked']), 'a linked risk from an assessment');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_risks')->where('id', $riskLink->id)->update(['supplier_due_diligence_assessment_id' => $foreign]), "another supplier's assessment");

        // Neither hand-off decided anything about the supplier.
        $this->assertSame(0, SupplierAssuranceDecision::query()->count());

        // Ended: no hand-off.
        $supplier->forceFill(['status' => Supplier::STATUS_ENDED])->save();
        $this->actingAs($assurer->fresh())->post($cases, $case($measures))->assertSessionHasErrors('title');
        $this->actingAs($assurer->fresh())->post($risks, $risk())->assertSessionHasErrors('title');
    }

    /** @return array<string, mixed> */
    private function assessment(array $overrides = []): array
    {
        return array_merge(array_fill_keys(SupplierDueDiligenceAssessment::AREAS, 'low'), [
            'supply_chain_description' => 'Sying i to fabrikker, kjent underleverandør for stoff.',
            'investigation_summary' => '',
            'conclusion' => 'no_significant_risk',
            'rationale' => 'Egenerklæring og revisjonsrapport gjennomgått.',
            'review_interval_months' => 24,
            'assessed_on' => now()->toDateString(),
        ], $overrides);
    }

    /**
     * A row as the service stores it — or, with $insert, inserted and its id returned.
     *
     * @return array<string, mixed>|int
     */
    private function row(Customer $customer, Supplier $supplier, array $overrides = [], bool $insert = false): array|int
    {
        $row = array_merge(array_fill_keys(SupplierDueDiligenceAssessment::AREAS, 'low'), [
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'conclusion' => 'monitor', 'rationale' => 'Vurdert.',
            'review_interval_months' => 12, 'assessed_on' => now()->toDateString(), 'recorded_at' => now(), 'supplier_name' => $supplier->name,
        ], $overrides);

        return $insert ? (int) DB::table('supplier_due_diligence_assessments')->insertGetId($row) : $row;
    }

    /** A catalogue requirement for every supplier, so Leverandørkontroll is started and something applies. */
    private function requirement(Customer $customer): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Etiske retningslinjer', 'theme' => 'ethics', 'level' => 'standard',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
    }

    /** @param  array<string, mixed>  $answers */
    private function profile(Supplier $supplier, array $answers): void
    {
        (new SupplierProfile)->forceFill($answers + ['supplier_id' => $supplier->id, 'customer_id' => $supplier->customer_id])->save();
    }
}
