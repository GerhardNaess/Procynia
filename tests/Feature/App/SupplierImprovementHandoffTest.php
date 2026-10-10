<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierImprovementCase;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 6: Avvik og forbedringer hos leverandøren
 * (docs/supplier-management-v1-plan.md §7.4, §6.3, §6.4, §9.2, §12). Only the integration — the
 * case's own rules are Avvik og forbedringer's tests. One test per rule:
 *
 *  - a hand-off creates the case through ImprovementCaseCreator with what the person chose, writes
 *    the row in the same transaction, a double submit creates one case, a supplier may have many,
 *    and an assessment is followed up only when its result calls for it;
 *  - it takes supplier.edit and improvement.edit in the chosen area; an ended supplier is
 *    read-only; another customer's supplier is 404; linking takes a case the person can read;
 *  - neither side shows the other's data to someone who cannot read it;
 *  - a supplier with cases is ended, never deleted; a case Avvik og forbedringer deletes takes the
 *    row with it;
 *  - Leverandørkontroll (supplier-assurance-v2-plan §7.1, §13.2, §20): a control short of
 *    Dokumentert is followed up with supplier.assure, not supplier.edit; the row keeps which control,
 *    at most one provenance, and the control is never changed.
 */
class SupplierImprovementHandoffTest extends TestCase
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

    public function test_a_handoff_creates_the_case_through_the_creator_once_per_submit_and_a_supplier_may_have_many(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $closed = $this->area($customer, 'Økonomi');
        $manager = $this->supplierManager($customer, $area);
        $owner = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $url = "/app/supplier-management/{$supplier->id}/improvement-cases";

        // The creator's own rules, refused before anything is written: the type is chosen, the area
        // must be one the person edits in, the owner one who can read cases there.
        foreach ([
            'type' => ['type' => ''],
            'business_area_id' => ['business_area_id' => $closed->id],
            'owner_user_id' => ['owner_user_id' => $this->member($customer)->id],
        ] as $field => $override) {
            $this->actingAs($manager)->post($url, $this->handoffPayload($area, $owner, $override))->assertSessionHasErrors($field);
        }
        $this->assertSame([0, 0], [ImprovementCase::query()->where('customer_id', $customer->id)->count(), SupplierImprovementCase::query()->count()]);

        // The person's choices, edited title and all; the case is the creator's — open, reported by them.
        $payload = $this->handoffPayload($area, $owner, ['type' => ImprovementCase::TYPE_IMPROVEMENT, 'title' => 'Leverandør: Acme AS – svar på henvendelser', 'due_date' => '2030-06-30']);
        $this->actingAs($manager)->post($url, $payload)->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($url, $payload)->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(
            ['improvement', 'Leverandør: Acme AS – svar på henvendelser', $area->id, $owner->id, 'open', $manager->id, '2030-06-30'],
            [$case->type, $case->title, (int) $case->business_area_id, (int) $case->owner_user_id, $case->status, (int) $case->reported_by_user_id, $case->due_date->format('Y-m-d')],
        );
        $link = $supplier->improvementCaseLinks()->sole();
        $this->assertSame(['handoff', null, $customer->id], [$link->origin, $link->supplier_assessment_id, (int) $link->customer_id]);

        // From an assessment: only one of this supplier's whose result calls for follow-up.
        $satisfactory = $this->assessment($supplier, SupplierAssessment::RESULT_SATISFACTORY);
        $weak = $this->assessment($supplier, SupplierAssessment::RESULT_UNSATISFACTORY);
        $foreign = $this->assessment($this->supplier($customer, $manager, 'Annen AS'), SupplierAssessment::RESULT_UNSATISFACTORY);
        foreach ([$satisfactory, $foreign] as $refused) {
            $this->actingAs($manager)->post($url, $this->handoffPayload($area, $owner, ['supplier_assessment_id' => $refused->id]))->assertSessionHasErrors('supplier_assessment_id');
        }
        $this->actingAs($manager)->post($url, $this->handoffPayload($area, $owner, ['supplier_assessment_id' => $weak->id, 'title' => 'Svak vurdering']))->assertSessionHasNoErrors();

        // Many cases per supplier; the page reads type, status and frist from Avvik og forbedringer now.
        $case->forceFill(['status' => ImprovementCase::STATUS_IN_PROGRESS])->save();
        $cases = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props']['improvement_cases'];
        $this->assertSame(
            [['Svak vurdering', 'deviation', 'open', $weak->assessed_on->toDateString()], ['Leverandør: Acme AS – svar på henvendelser', 'improvement', 'in_progress', null]],
            array_map(fn (array $row): array => [$row['title'], $row['type'], $row['status'], $row['assessed_on']], $cases),
        );

        // The case page names the supplier for someone who reads both.
        $origin = $this->actingAs($manager)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props']['supplier_origin'];
        $this->assertSame([['Acme AS', true]], array_map(fn (array $row): array => [$row['name'], $row['from_supplier']], $origin));
    }

    public function test_it_takes_supplier_edit_and_improvement_edit_ended_is_read_only_other_customers_are_404_and_links_take_a_readable_case(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $hiddenArea = $this->area($customer, 'Ledelse');
        $manager = $this->supplierManager($customer, $area);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $url = "/app/supplier-management/{$supplier->id}/improvement-cases";

        // supplier.assess is not enough; supplier.edit without Avvik og forbedringer is offered no
        // area, hears nothing about cases, and a direct request is refused by the creator.
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $this->actingAs($assessor)->post($url, $this->handoffPayload($area, $manager))->assertForbidden();
        $editorOnly = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $page = $this->actingAs($editorOnly)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, []], [$page['improvement_cases'], $page['improvement_handoff']['area_options']]);
        $this->actingAs($editorOnly)->post($url, $this->handoffPayload($area, $manager))->assertSessionHasErrors('business_area_id');
        $this->assertFalse(ImprovementCase::query()->where('customer_id', $customer->id)->exists());

        // Koble til: a case the person can read, once; an afterwards-link can be removed, a hand-off cannot.
        $visible = $this->improvementCase($customer, $area, 'Registrert i modulen');
        $hidden = $this->improvementCase($customer, $hiddenArea, 'Utenfor rekkevidde');
        $this->actingAs($manager)->post("{$url}/link", ['improvement_case_id' => $hidden->id])->assertSessionHasErrors('improvement_case_id');
        $this->actingAs($manager)->post("{$url}/link", ['improvement_case_id' => $visible->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("{$url}/link", ['improvement_case_id' => $visible->id])->assertSessionHasErrors('improvement_case_id');
        $linked = $supplier->improvementCaseLinks()->sole();
        $this->actingAs($manager)->post($url, $this->handoffPayload($area, $manager))->assertSessionHasNoErrors();
        $handedOff = $supplier->improvementCaseLinks()->where('origin', 'handoff')->sole();
        $this->actingAs($manager)->delete("{$url}/{$handedOff->id}")->assertSessionHasErrors('improvement_case_id');
        $this->actingAs($manager)->delete("{$url}/{$linked->id}")->assertSessionHas('success');
        $this->assertSame(['handoff'], $supplier->improvementCaseLinks()->pluck('origin')->all());

        // Ended: nothing new until reopened; the case already there is still shown.
        $this->actingAs($manager)->post("{$url}/link", ['improvement_case_id' => $visible->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($url, $this->handoffPayload($area, $manager))->assertSessionHasErrors('title');
        $late = $this->improvementCase($customer, $area, 'Registrert etter avslutning');
        $this->actingAs($manager)->post("{$url}/link", ['improvement_case_id' => $late->id])->assertSessionHasErrors('improvement_case_id');
        $this->actingAs($manager)->delete("{$url}/".$supplier->improvementCaseLinks()->where('origin', 'linked')->value('id'))->assertSessionHasErrors('improvement_case_id');
        $page = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, 2], [$page['improvement_handoff'], count($page['improvement_cases'])]);
        // No case was created by the refused hand-off: the three registered directly and the one handed off.
        $this->assertSame(4, ImprovementCase::query()->where('customer_id', $customer->id)->count());

        // Another customer's supplier: 404; no row can cross customers.
        $foreign = $this->supplier($other, $this->supplierUser($other, []), 'Fremmed AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$foreign->id}/improvement-cases", $this->handoffPayload($area, $manager))->assertNotFound();
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $foreign->id, 'improvement_case_id' => $visible->id, 'origin' => 'linked', 'created_at' => now(),
        ]), 'a link across customers');
    }

    public function test_neither_side_shows_the_other_to_someone_who_cannot_read_it(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        // A name no translation contains, so finding it on the page can only mean the area leaked.
        $hiddenArea = $this->area($customer, 'Styrerom Nord');
        $manager = $this->supplierManager($customer, $area);
        $this->grant($customer, $manager, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$hiddenArea]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $owner = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area, $hiddenArea]);
        foreach ([[$area, 'Synlig sak'], [$hiddenArea, 'Skjult sak']] as [$caseArea, $title]) {
            $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/improvement-cases", $this->handoffPayload($caseArea, $owner, ['title' => $title, 'description' => 'Svar på henvendelser tar for lang tid.']))->assertSessionHasNoErrors();
        }

        // A supplier reader who sees only one area: the other case is not listed, counted or named.
        $reader = $this->supplierUser($customer, []);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $response = $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->assertOk();
        $this->assertSame(['Synlig sak'], array_column($response->viewData('page')['props']['improvement_cases'], 'title'));
        $this->assertStringNotContainsString('Skjult sak', $response->getContent());
        $this->assertStringNotContainsString('Styrerom Nord', $response->getContent());

        // A case reader without supplier.view, and a System Owner without a supplier role, see no supplier.
        $visibleCase = ImprovementCase::query()->where('title', 'Synlig sak')->sole();
        $caseReader = $this->member($customer);
        $this->grant($customer, $caseReader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->grant($customer, $systemOwner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$area]);
        foreach ([$caseReader, $systemOwner->fresh()] as $person) {
            $response = $this->actingAs($person)->get("/app/improvements/{$visibleCase->id}")->assertOk();
            $this->assertNull($response->viewData('page')['props']['supplier_origin']);
            $this->assertStringNotContainsString('Acme AS', $response->getContent());
        }
        $this->assertSame('Acme AS', $this->actingAs($reader)->get("/app/improvements/{$visibleCase->id}")->viewData('page')['props']['supplier_origin'][0]['name']);
    }

    public function test_a_supplier_with_cases_is_ended_never_deleted_and_a_deleted_case_takes_its_row_along(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $manager = $this->supplierManager($customer, $area, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/improvement-cases", $this->handoffPayload($area, $manager))->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with cases');

        // ImprovementCase::isDeletable() is unchanged: a case its module deletes takes the row with it.
        DB::table('improvement_cases')->where('customer_id', $customer->id)->delete();
        $this->assertFalse($supplier->improvementCaseLinks()->exists());
        $this->assertTrue($supplier->fresh()->isDeletable());
    }

    public function test_a_control_short_of_documented_is_followed_up_with_supplier_assure_and_keeps_its_provenance(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->grant($customer, $assurer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $editor = $this->supplierManager($customer, $area);
        $supplier = $this->supplier($customer, $editor, 'Acme AS');
        $other = $this->supplier($customer, $editor, 'Annen AS');
        $requirement = SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
        // An earlier Dokumentert, then Mangler — the control in force.
        $documented = $this->control($supplier, $requirement, 'documented');
        $missing = $this->control($supplier, $requirement, 'missing');
        $foreign = $this->control($other, $requirement, 'missing');
        $url = "/app/supplier-management/{$supplier->id}/improvement-cases";
        $payload = fn (int $evaluationId, array $overrides = []): array => $this->handoffPayload($area, $assurer, ['supplier_requirement_evaluation_id' => $evaluationId, 'title' => 'DBA mangler'] + $overrides);

        // supplier.edit does not hand off a control; supplier.assure does not hand off the supplier.
        $this->actingAs($editor)->post($url, $payload($missing))->assertForbidden();
        $this->actingAs($assurer)->post($url, $this->handoffPayload($area, $assurer))->assertForbidden();
        // Only this supplier's control, short of Dokumentert, and never together with an assessment.
        foreach ([$documented, $foreign] as $refused) {
            $this->actingAs($assurer)->post($url, $payload($refused))->assertSessionHasErrors('supplier_requirement_evaluation_id');
        }
        $this->actingAs($assurer)->post($url, $payload($missing, ['supplier_assessment_id' => $this->assessment($supplier, SupplierAssessment::RESULT_UNSATISFACTORY)->id]))
            ->assertSessionHasErrors('supplier_requirement_evaluation_id');
        $this->assertSame(0, SupplierImprovementCase::query()->count());

        $this->actingAs($assurer)->post($url, $payload($missing))->assertSessionHasNoErrors();
        $link = $supplier->improvementCaseLinks()->sole();
        $this->assertSame(['handoff', $missing, null], [$link->origin, (int) $link->supplier_requirement_evaluation_id, $link->supplier_assessment_id]);
        // The control is untouched: still Mangler, still the one in force.
        $this->assertSame('missing', DB::table('supplier_requirement_evaluations')->where('id', $missing)->value('status'));

        // The supplier page and the case page say which control; the row offers it on the control in force.
        $page = $this->actingAs($assurer)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['requirement_title' => 'Databehandleravtale', 'evaluated_on' => now()->toDateString()], $page['improvement_cases'][0]['evaluation']);
        $this->assertFalse($page['improvement_handoff']['can_from_supplier']);
        $this->assertSame([], $page['improvement_handoff']['link_options']);
        $this->assertTrue(collect($page['control_requirements']['applicable'])->sole()['can_follow_up']);
        $this->assertFalse(collect($this->actingAs($editor)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['control_requirements']['applicable'])->sole()['can_follow_up']);
        $origin = $this->actingAs($assurer)->get("/app/improvements/{$link->improvement_case_id}")->assertOk()->viewData('page')['props']['supplier_origin'];
        $this->assertSame('Databehandleravtale', $origin[0]['evaluation']['requirement_title']);

        // The database: at most one provenance; a linked case carries none; never another supplier's control.
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->where('id', $link->id)
            ->update(['supplier_assessment_id' => $this->assessment($supplier, SupplierAssessment::RESULT_PARTIALLY_SATISFACTORY)->id]), 'two provenances');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->where('id', $link->id)
            ->update(['origin' => 'linked', 'handoff_key' => null]), 'a linked case from a control');
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_improvement_cases')->where('id', $link->id)
            ->update(['supplier_requirement_evaluation_id' => $foreign]), "another supplier's control");
    }

    private function control(Supplier $supplier, SupplierControlRequirement $requirement, string $status): int
    {
        return (int) DB::table('supplier_requirement_evaluations')->insertGetId([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id,
            'status' => $status, 'rationale' => 'Ikke mottatt.', 'evaluated_on' => now()->toDateString(), 'recorded_at' => now(),
            'requirement_title' => $requirement->title, 'requirement_level' => $requirement->level, 'requirement_theme' => $requirement->theme,
            'applicability_reason' => 'Gjelder alle leverandører', 'supplier_name' => $supplier->name,
        ]);
    }

    /**
     * supplier.view + supplier.edit (+ extra supplier keys), and improvement.view + .edit in the area.
     *
     * @param  list<string>  $extra
     */
    private function supplierManager(Customer $customer, BusinessArea $area, array $extra = []): User
    {
        $user = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, ...$extra]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);

        return $user->fresh();
    }

    private function assessment(Supplier $supplier, string $result): SupplierAssessment
    {
        return SupplierAssessment::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'assessed_on' => now()->toDateString(),
            'quality_rating' => 'poor', 'delivery_rating' => 'poor', 'security_rating' => 'good', 'compliance_rating' => 'good',
            'overall_result' => $result, 'rationale' => 'Svar på henvendelser tar for lang tid.', 'supplier_name' => $supplier->name, 'recorded_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function handoffPayload(BusinessArea $area, User $owner, array $overrides = []): array
    {
        return array_merge([
            'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => 'Leverandør: Acme AS',
            'description' => 'Sak opprettet fra Leverandøroppfølging for Acme AS.',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'due_date' => '',
            'supplier_assessment_id' => null,
            'handoff_key' => (string) Str::uuid(),
        ], $overrides);
    }
}
