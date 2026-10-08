<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Models\SupplierRequirementEvaluationDocument;
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
 * Supplier Assurance v2 phase 3: controls of requirements and their documentation
 * (docs/supplier-assurance-v2-plan.md §8, §10, §13.2, §20). The display statuses are
 * SupplierRequirementStatusTest's; here, one test per rule end to end:
 *
 *  - a control is written with supplier.assure only, on an open supplier, never across customers;
 *  - only a requirement that applies now is controlled — the applicability decision, overrides
 *    included, is the only judge;
 *  - the result, begrunnelse, dates and documentation follow the plan, and a double submit writes one;
 *  - controls and their documentation are history, and the documentation keeps what the person saw
 *    while the row itself stays editable — but never deletable once used.
 */
class SupplierRequirementEvaluationTest extends TestCase
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

    public function test_controls_are_registered_with_supplier_assure_only_on_open_suppliers_and_never_cross_customers(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $foreignCustomer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $requirement = $this->requirement($customer, 'Etiske retningslinjer');
        $document = $this->document($supplier, 'Signerte retningslinjer');
        $url = "/app/supplier-management/{$supplier->id}/requirement-evaluations";

        // edit, assess and delete grant nothing here; System Owner without a role is refused.
        foreach ([CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $this->actingAs($this->supplierUser($customer, [$key]))->post($url, $this->control($requirement, [$document]))->assertForbidden();
        }
        $this->actingAs($reader)->post($url, $this->control($requirement, [$document]))->assertForbidden();
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->actingAs($systemOwner)->post($url, $this->control($requirement, [$document]))->assertForbidden();
        $this->actingAs($systemOwner)->get("/app/supplier-management/{$supplier->id}")->assertForbidden();
        $this->assertSame(0, SupplierRequirementEvaluation::query()->count());

        $this->actingAs($assurer)->post($url, $this->control($requirement, [$document]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $control = SupplierRequirementEvaluation::query()->sole();
        $this->assertSame(
            ['documented', $assurer->id, 'Etiske retningslinjer', 'standard', 'privacy', 'Gjelder alle leverandører', 'Drift AS'],
            [$control->status, $control->evaluated_by_user_id, $control->requirement_title, $control->requirement_level, $control->requirement_theme, $control->applicability_reason, $control->supplier_name],
        );
        $this->assertNotNull($control->recorded_at);
        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->assertFalse($requirement->fresh()->isDeletable());

        // supplier.view reads it all, and is offered nothing.
        $page = $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props']['control_requirements'];
        $row = $page['applicable'][0];
        $this->assertSame(['documented', 'documented', false, null], [$row['display_status'], $row['current']['status'], $row['can_evaluate'], $page['evaluation_form']]);
        $this->assertSame(['Signerte retningslinjer'], array_column($row['evaluations'][0]['documents'], 'title'));

        // Another supplier's or customer's documentation is refused; another customer's supplier is a 404.
        $other = $this->supplier($customer, $assurer, 'Annen AS');
        $foreignAssurer = $this->supplierUser($foreignCustomer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $foreignSupplier = $this->supplier($foreignCustomer, $foreignAssurer, 'Fremmed AS');
        $foreignDocument = $this->document($foreignSupplier, 'Fremmed avtale');
        $this->actingAs($assurer)->post($url, $this->control($requirement, [$this->document($other, 'Annens avtale')]))->assertSessionHasErrors('document_ids');
        $this->actingAs($assurer)->post($url, $this->control($requirement, [$foreignDocument]))->assertSessionHasErrors('document_ids');
        $this->actingAs($assurer)->post("/app/supplier-management/{$foreignSupplier->id}/requirement-evaluations", $this->control($requirement, []))->assertNotFound();
        $this->actingAs($foreignAssurer)->post("/app/supplier-management/{$foreignSupplier->id}/requirement-evaluations", $this->control($requirement, [$foreignDocument]))->assertSessionHasErrors('requirement_id');
        $this->assertSame(1, SupplierRequirementEvaluation::query()->count());

        // The database refuses a row across customers, for both tables.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluations')->insert($this->controlRow($foreignCustomer, $foreignSupplier, $requirement)), 'a control of another customer\'s requirement');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluation_documents')->insert([
            'customer_id' => $customer->id, 'evaluation_id' => $control->id, 'supplier_document_id' => $foreignDocument->id,
            'document_type' => 'agreement', 'document_title' => 'x',
        ]), 'documentation from another customer');

        // Ended: read-only, refused even when asked directly.
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->actingAs($editor)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, $this->control($requirement, [$document], ['rationale' => 'Ny kontroll.']))->assertSessionHasErrors('requirement_id');
        $page = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements'];
        $this->assertSame([1, false], [count($page['applicable'][0]['evaluations']), $page['applicable'][0]['can_evaluate']]);
    }

    public function test_only_a_requirement_that_applies_now_can_be_controlled(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Lønn AS', classification: $this->classification(answers: ['processes_personal_data' => true]));
        $other = $this->supplier($customer, $assurer, 'Annen AS');
        $url = "/app/supplier-management/{$supplier->id}/requirement-evaluations";
        $overrides = "/app/supplier-management/{$supplier->id}/requirement-overrides";
        $notMet = $this->requirement($customer, 'Underleverandører', [['subcontractors']]);
        $personal = $this->requirement($customer, 'Behandlingsoversikt', [['personal_data']], 'important');
        $foreignOwn = $this->requirement($customer, 'Krav for en annen', [], supplier: $other);
        $missing = fn (SupplierControlRequirement $requirement) => $this->control($requirement, [], ['status' => 'missing', 'rationale' => 'Ikke mottatt.']);

        $this->actingAs($assurer)->post($url, $missing($notMet))->assertSessionHasErrors('requirement_id');
        $this->actingAs($assurer)->post($url, $missing($foreignOwn))->assertSessionHasErrors('requirement_id');

        // Included by hand: it applies, so it can be controlled.
        $this->actingAs($assurer)->post($overrides, ['requirement_id' => $notMet->id, 'action' => 'include', 'reason' => 'Vi vil ha oversikten.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, $missing($notMet))->assertSessionHasNoErrors();

        // Excluded: it does not, until cleared back to the rule.
        $this->actingAs($assurer)->post($url, $missing($personal))->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($overrides, ['requirement_id' => $personal->id, 'action' => 'exclude', 'reason' => 'Dekkes av konsernavtalen.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, [...$missing($personal), 'evaluated_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('requirement_id');
        $page = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements'];
        // Its control stays readable, outside the requirements that apply.
        $this->assertSame(['Behandlingsoversikt'], array_column($page['earlier_evaluations'], 'title'));
        $this->actingAs($assurer)->post($overrides, ['requirement_id' => $personal->id, 'action' => 'clear', 'reason' => 'Gjelder likevel.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, [...$missing($personal), 'evaluated_on' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();

        // Retired: no new control, the old ones stay.
        $this->actingAs($assurer)->post("/app/supplier-management/control-requirements/{$personal->id}/retire")->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post($url, [...$missing($personal), 'rationale' => 'Etter utgått.'])->assertSessionHasErrors('requirement_id');
        $this->actingAs($assurer)->delete("/app/supplier-management/control-requirements/{$personal->id}")->assertSessionHasErrors('requirement');
        $this->assertSame(2, SupplierRequirementEvaluation::query()->where('requirement_id', $personal->id)->count());
    }

    public function test_the_result_reason_dates_and_documentation_follow_the_plan(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Drift AS');
        $dpa = $this->requirement($customer, 'Databehandleravtale', level: 'mandatory');
        $code = $this->requirement($customer, 'Etiske retningslinjer');
        $agreement = $this->document($supplier, 'DBA 2026');
        $list = $this->document($supplier, 'Underdatabehandlere');
        $url = "/app/supplier-management/{$supplier->id}/requirement-evaluations";
        $post = fn (array $payload) => $this->actingAs($assurer)->post($url, $payload);

        $post($this->control($dpa, [$agreement], ['status' => 'not_relevant']))->assertSessionHasErrors('status');
        $post($this->control($dpa, [], ['status' => 'documented']))->assertSessionHasErrors('document_ids');
        $post($this->control($dpa, [], ['status' => 'missing', 'rationale' => '  ']))->assertSessionHasErrors('rationale');
        $post($this->control($dpa, [], ['status' => 'partially_documented', 'rationale' => '']))->assertSessionHasErrors('rationale');
        $post($this->control($dpa, [], ['status' => 'temporarily_accepted']))->assertSessionHasErrors('accepted_until');
        $post($this->control($dpa, [], ['status' => 'temporarily_accepted', 'accepted_until' => now()->addMonthsNoOverflow(12)->addDay()->toDateString()]))->assertSessionHasErrors('accepted_until');
        $post($this->control($dpa, [], ['status' => 'temporarily_accepted', 'accepted_until' => now()->subDay()->toDateString()]))->assertSessionHasErrors('accepted_until');
        $post($this->control($dpa, [$agreement], ['evaluated_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('evaluated_on');
        $this->assertSame(0, SupplierRequirementEvaluation::query()->count());

        // Backdated, several documents; a date is kept only with Midlertidig akseptert.
        $post($this->control($dpa, [$agreement, $list], ['evaluated_on' => '2026-01-15', 'accepted_until' => '2026-12-01']))->assertSessionHasNoErrors();
        $first = SupplierRequirementEvaluation::query()->sole();
        $this->assertSame(['2026-01-15', null, 2], [$first->evaluated_on->toDateString(), $first->accepted_until, $first->documents()->count()]);

        // A double submit writes one; the same document may support another requirement.
        $post($this->control($dpa, [$list, $agreement], ['evaluated_on' => '2026-01-15']))->assertSessionHasErrors('status');
        $post($this->control($code, [$agreement]))->assertSessionHasNoErrors();
        $post($this->control($dpa, [], ['status' => 'temporarily_accepted', 'accepted_until' => now()->addMonths(2)->toDateString(), 'rationale' => 'Ny avtale er under signering.']))->assertSessionHasNoErrors();
        $this->assertSame(3, SupplierRequirementEvaluation::query()->count());
        $this->assertSame(2, SupplierRequirementEvaluationDocument::query()->where('supplier_document_id', $agreement->id)->count());

        $row = collect($this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'])->firstWhere('title', 'Databehandleravtale');
        $this->assertSame(['temporarily_accepted', 'temporarily_accepted', 2], [$row['display_status'], $row['current']['status'], count($row['evaluations'])]);
    }

    public function test_controls_are_history_and_keep_the_documentation_as_it_was_while_a_used_document_cannot_be_deleted(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $assurer, 'Sky AS');
        $requirement = $this->requirement($customer, 'Sikkerhetssertifisering', theme: 'information_security');
        $certificate = $this->document($supplier, 'ISO 27001 2026', ['standard' => 'ISO 27001', 'location' => 'P360 2026/12', 'valid_until' => now()->addYear()->toDateString()]);
        $unused = $this->document($supplier, 'Registrert ved en feil');
        $show = "/app/supplier-management/{$supplier->id}";
        $documents = "{$show}/documents";

        $this->actingAs($assurer)->post("{$show}/requirement-evaluations", $this->control($requirement, [$certificate]))->assertSessionHasNoErrors();
        $control = SupplierRequirementEvaluation::query()->sole();
        $used = SupplierRequirementEvaluationDocument::query()->sole();

        // Immutable in the model and in the database, for both tables.
        foreach ([$control, $used] as $row) {
            try {
                $row->forceFill(['created_at' => now()])->save();
                $this->fail('The model must refuse a change.');
            } catch (LogicException) {
            }
            try {
                $row->delete();
                $this->fail('The model must refuse a delete.');
            } catch (LogicException) {
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluations')->where('id', $control->id)->update(['status' => 'missing']), 'changing a control');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluations')->where('id', $control->id)->delete(), 'deleting a control');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluation_documents')->where('id', $used->id)->update(['document_title' => 'Endret']), 'changing the documentation of a control');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_requirement_evaluation_documents')->where('id', $used->id)->delete(), 'deleting the documentation of a control');

        // The row stays editable (edit or assure); the control keeps what the person saw.
        $this->actingAs($assurer)->patch("{$documents}/{$certificate->id}", [
            'document_type' => 'certificate', 'title' => 'ISO 27001-sertifikat', 'standard' => 'ISO 27001:2022',
            'location' => 'P360 2026/99', 'valid_until' => now()->subDay()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(
            ['ISO 27001 2026', 'ISO 27001', 'P360 2026/12', now()->addYear()->toDateString()],
            [$used->fresh()->document_title, $used->fresh()->document_standard, $used->fresh()->document_location, $used->fresh()->document_valid_until->toDateString()],
        );
        $row = $this->actingAs($assurer)->get($show)->viewData('page')['props']['control_requirements']['applicable'][0];
        $snapshot = $row['evaluations'][0]['documents'][0];
        $this->assertSame(['ISO 27001 2026', 'ISO 27001', true, 'ISO 27001-sertifikat', 'expired'], [$snapshot['title'], $snapshot['standard'], $snapshot['changed_since'], $snapshot['now']['title'], $snapshot['now']['status']]);
        // Today's status reads the row as it is now: expired → Må fornyes. The stored result is unchanged.
        $this->assertSame(['renewal_due', 'documented'], [$row['display_status'], $row['current']['status']]);

        // Deleting: the unused row goes; the used one, and a renewal of it, stay — in the service and the database.
        $this->actingAs($assurer)->delete("{$documents}/{$unused->id}")->assertSessionHasNoErrors();
        $this->actingAs($assurer)->delete("{$documents}/{$certificate->id}")->assertSessionHasErrors('title');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_documents')->where('id', $certificate->id)->delete(), 'deleting documentation used in a control');
        $this->actingAs($assurer)->post("{$documents}/{$certificate->id}/renew", ['title' => 'ISO 27001 2027', 'valid_until' => now()->addYears(2)->toDateString()])->assertSessionHasNoErrors();
        $renewal = SupplierDocument::query()->where('title', 'ISO 27001 2027')->sole();
        $this->actingAs($assurer)->delete("{$documents}/{$renewal->id}")->assertSessionHasErrors('title');
        $page = $this->actingAs($assurer)->get($show)->viewData('page')['props'];
        $this->assertSame([false, false], array_column($page['documents'], 'deletable'));

        // Bekreft kravene på nytt: offered on the renewal, a new control with the new edition.
        $this->assertSame([$renewal->id => [['id' => $requirement->id, 'title' => 'Sikkerhetssertifisering']]], $page['control_requirements']['reconfirmable']);
        $this->actingAs($assurer)->post("{$documents}/{$renewal->id}/reconfirm", ['requirement_ids' => [$requirement->id], 'rationale' => 'Nytt sertifikat mottatt.'])->assertSessionHasNoErrors();
        $this->actingAs($assurer)->post("{$documents}/{$renewal->id}/reconfirm", ['requirement_ids' => [$requirement->id], 'rationale' => 'Igjen.'])->assertSessionHasErrors('requirement_ids');
        $latest = SupplierRequirementEvaluation::query()->orderByDesc('id')->first();
        $this->assertSame(['documented', [$renewal->id]], [$latest->status, $latest->documents()->pluck('supplier_document_id')->all()]);
        $this->assertSame('documented', $this->actingAs($assurer)->get($show)->viewData('page')['props']['control_requirements']['applicable'][0]['display_status']);
        $this->assertSame(1, $control->documents()->count());
    }

    /**
     * A catalogue requirement written directly, or one for $supplier.
     *
     * @param  list<list<string>>  $rule
     */
    private function requirement(Customer $customer, string $title, array $rule = [], string $level = 'standard', ?Supplier $supplier = null, string $theme = 'privacy'): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'supplier_id' => $supplier?->id,
            'title' => $title,
            'theme' => $theme,
            'level' => $level,
            'control_point' => 'before_contract',
            'applies_when' => $rule,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function document(Supplier $supplier, string $title, array $attributes = []): SupplierDocument
    {
        return SupplierDocument::query()->create($attributes + [
            'customer_id' => $supplier->customer_id,
            'supplier_id' => $supplier->id,
            'document_type' => 'certificate',
            'title' => $title,
        ]);
    }

    /**
     * Kontroller krav as the form sends it: Dokumentert today with the given documents.
     *
     * @param  list<SupplierDocument>  $documents
     * @return array<string, mixed>
     */
    private function control(SupplierControlRequirement $requirement, array $documents, array $overrides = []): array
    {
        return array_merge([
            'requirement_id' => $requirement->id,
            'status' => 'documented',
            'rationale' => 'Dokumentasjonen dekker kravet.',
            'evaluated_on' => now()->toDateString(),
            'accepted_until' => '',
            'document_ids' => array_map(fn (SupplierDocument $document): int => $document->id, $documents),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function controlRow(Customer $customer, Supplier $supplier, SupplierControlRequirement $requirement): array
    {
        return [
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id,
            'status' => 'missing', 'rationale' => 'x', 'evaluated_on' => now()->toDateString(), 'recorded_at' => now(),
            'requirement_title' => 'x', 'requirement_level' => 'standard', 'requirement_theme' => 'privacy',
            'applicability_reason' => 'x', 'supplier_name' => 'x',
        ];
    }
}
