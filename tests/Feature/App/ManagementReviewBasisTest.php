<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Models\ManagementReview;
use App\Models\ManagementReviewSection;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ManagementReview\BasisFormatter;
use App\Services\ManagementReview\ManagementReviewBasisService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Beslutningsgrunnlaget (plan §5, §8, §10): what each section shows, to whom, and how a finalized
 * review keeps what the management had.
 *
 * What these tests defend:
 *
 *  - Period results count only what is dated inside the period (inclusive days), read from the
 *    modules' immutable history; «Status nå» is the state when the basis is read.
 *  - A section says plainly why it has nothing: no registered matters, module not enabled, no
 *    access, or (in a snapshot) not captured — never a misleading zero.
 *  - Reading goes through the module's own access: fagområder, explicit-grant domains, System Owner
 *    fail-closed. The management's judgement on a module section follows the same gate.
 *  - Finalizing freezes the basis: changes in the modules afterwards do not change it; a reader still
 *    sees only their own fagområder of it; it survives the module being cancelled.
 */
class ManagementReviewBasisTest extends TestCase
{
    use CreatesManagementReviewScenarios;
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

    public function test_period_results_count_only_inside_the_period_and_status_reads_now(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW], [$area]);

        $this->improvementCase($customer, $area, 'Før perioden', '2025-12-31 23:59:00');
        $first = $this->improvementCase($customer, $area, 'Første dag', '2026-01-01 00:00:00');
        $this->improvementCase($customer, $area, 'Siste dag', '2026-06-30 23:59:00');
        $this->improvementCase($customer, $area, 'Etter perioden', '2026-07-01 00:00:00');
        $this->improvementCase($customer, $area, 'Forbedring', '2026-03-01 12:00:00', ImprovementCase::TYPE_IMPROVEMENT);
        $this->close($first, '2026-02-01 10:00:00');

        $review = $this->mrReview($manager);
        $section = $this->section($manager, $review, 'improvements');

        $this->assertSame('available', $section['state']);
        $this->assertSame(2, $this->metric($section, 'period', 'deviations_registered'));
        $this->assertSame(1, $this->metric($section, 'period', 'improvements_registered'));
        $this->assertSame(1, $this->metric($section, 'period', 'cases_closed'));
        // The period of the same length before: 2025-07-04 .. 2025-12-31.
        $this->assertSame(1, $this->metric($section, 'previous', 'deviations_registered'));
        // Status now: every deviation still open, whenever it was registered.
        $this->assertSame(3, $this->metric($section, 'status', 'open_deviations'));
    }

    public function test_a_section_says_why_it_is_empty(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW], [$area]);
        $review = $this->mrReview($manager);

        $risks = $this->section($manager, $review, 'risks');
        $this->assertSame('module_unavailable', $risks['state']);
        $this->assertNull($risks['basis']);

        $improvements = $this->section($manager, $review, 'improvements');
        $this->assertSame('available', $improvements['state']);
        $this->assertTrue($improvements['basis']['empty']);

        // Readiness asks only for sections the person can see; Risiko is not one of them.
        $readiness = $this->page($manager, $review)['readiness'];
        $judgements = collect($readiness['items'])->firstWhere('key', 'judgements');
        $this->assertNotContains('risks', $judgements['sections']);
        $this->assertContains('improvements', $judgements['sections']);
    }

    public function test_access_follows_each_module_and_system_owner_gets_no_protected_data(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->mrContext(['basis', 'grc']);
        $area = $this->mrArea($customer, 'Drift');
        $this->risk($customer, $area, 'Datatap');
        $this->supplierRow($customer, 'Skyleverandør AS');
        $review = $this->mrReview($owner);

        $sections = collect($this->page($owner, $review)['sections'])->keyBy('key');
        // System Owner: Kvalitet is customer-wide and theirs; Risiko needs a fagområde through a role,
        // Leverandører and Etterlevelse an explicit grant.
        $this->assertSame('available', $sections['quality']['state']);
        $this->assertSame('no_access', $sections['risks']['state']);
        $this->assertSame('no_access', $sections['suppliers']['state']);
        $this->assertSame('no_access', $sections['compliance']['state']);
        $this->assertNull($sections['risks']['basis']);

        $riskReader = $this->mrReader($customer, [P::RISK_VIEW], [$area]);
        $supplierReader = $this->mrReader($customer, [P::SUPPLIER_VIEW]);
        $this->assertSame(1, $this->metric($this->section($riskReader, $review, 'risks'), 'status', 'risks_open'));
        $this->assertSame('no_access', $this->section($riskReader, $review, 'suppliers')['state']);
        $this->assertSame(1, $this->metric($this->section($supplierReader, $review, 'suppliers'), 'status', 'suppliers_active'));
    }

    public function test_the_judgement_on_a_module_section_is_hidden_from_someone_who_cannot_read_it(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $manager = $this->mrManager($customer, [P::SUPPLIER_VIEW]);
        $review = $this->mrReview($manager);

        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/sections/suppliers", [
            'judgement' => 'not_satisfactory',
            'comment' => 'Tre kritiske leverandører uten gyldig avtale.',
        ])->assertSessionHasNoErrors();

        $colleague = $this->mrManager($customer);
        $hidden = $this->section($colleague, $review, 'suppliers');
        $this->assertSame('no_access', $hidden['state']);
        $this->assertFalse($hidden['judgement_visible']);
        $this->assertNull($hidden['judgement']);
        $this->assertNull($hidden['comment']);
        $this->assertStringNotContainsString('kritiske leverandører', json_encode($this->page($colleague, $review)));

        // Nobody judges a section they cannot read.
        $this->actingAs($colleague)->put("/app/management-reviews/{$review->id}/sections/suppliers", ['judgement' => 'satisfactory'])->assertForbidden();
        $this->assertSame('not_satisfactory', ManagementReviewSection::query()->where('management_review_id', $review->id)->where('section_key', 'suppliers')->value('judgement'));
    }

    public function test_fagomrader_narrow_the_basis_to_the_reader_and_to_the_review_scope(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $north = $this->mrArea($customer, 'Nord');
        $south = $this->mrArea($customer, 'Sør');
        $this->risk($customer, $north, 'Nord-risiko');
        $this->risk($customer, $south, 'Sør-risiko');
        $both = $this->mrManager($customer, [P::RISK_VIEW], [$north, $south]);
        $northOnly = $this->mrReader($customer, [P::RISK_VIEW], [$north]);

        $review = $this->mrReview($both);
        $this->assertSame(2, $this->metric($this->section($both, $review, 'risks'), 'status', 'risks_open'));
        $mine = $this->section($northOnly, $review, 'risks');
        $this->assertSame(1, $this->metric($mine, 'status', 'risks_open'));
        $this->assertSame('partial', $mine['coverage']);
        $this->assertStringNotContainsString('Sør-risiko', json_encode($mine));

        $southReview = $this->mrReview($both, ['all_business_areas' => false, 'business_area_ids' => [$south->id]]);
        $this->assertSame(1, $this->metric($this->section($both, $southReview, 'risks'), 'status', 'risks_open'));
        // A reader with no fagområde inside the review's scope has no access to the section.
        $this->assertSame('no_access', $this->section($northOnly, $southReview, 'risks')['state']);
    }

    public function test_a_finalized_basis_does_not_follow_later_changes(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW], [$area]);
        $this->improvementCase($customer, $area, 'Avvik 1', '2026-02-01 10:00:00');
        $review = $this->finalize($manager, $this->mrReview($manager));

        $this->improvementCase($customer, $area, 'Avvik 2', '2026-03-01 10:00:00');
        $this->improvementCase($customer, $area, 'Avvik 3', '2026-03-02 10:00:00');

        $section = $this->section($manager, $review, 'improvements');
        $this->assertSame(1, $this->metric($section, 'period', 'deviations_registered'));
        $this->assertSame(1, $this->metric($section, 'status', 'open_deviations'));
        $this->assertSame('Status ved ferdigstilling', collect($section['basis']['groups'])->firstWhere('key', 'status')['label']);
    }

    public function test_a_snapshot_is_narrowed_to_the_readers_fagomrader_today(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $north = $this->mrArea($customer, 'Nord');
        $south = $this->mrArea($customer, 'Sør');
        $this->risk($customer, $north, 'Nord-risiko');
        $this->risk($customer, $south, 'Sør-risiko');
        $manager = $this->mrManager($customer, [P::RISK_VIEW], [$north, $south]);
        $review = $this->finalize($manager, $this->mrReview($manager));

        $northOnly = $this->mrReader($customer, [P::RISK_VIEW], [$north]);
        $section = $this->section($northOnly, $review, 'risks');
        $this->assertSame(1, $this->metric($section, 'status', 'risks_open'));
        $this->assertStringNotContainsString('Sør-risiko', json_encode($section));

        $noRisk = $this->mrReader($customer);
        $this->assertSame('no_access', $this->section($noRisk, $review, 'risks')['state']);
    }

    public function test_history_survives_cancelling_the_module_it_came_from(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $area = $this->mrArea($customer, 'Drift');
        $this->risk($customer, $area, 'Datatap');
        $manager = $this->mrManager($customer, [P::RISK_VIEW], [$area]);
        $review = $this->finalize($manager, $this->mrReview($manager));

        app(ModuleEntitlementService::class)->cancelOption($customer, 'risk');
        $this->assertFalse(app(ModuleEntitlementService::class)->hasModule($customer->fresh(), 'risk'));

        $section = $this->section($manager, $review, 'risks');
        $this->assertSame('available', $section['state']);
        $this->assertSame(1, $this->metric($section, 'status', 'risks_open'));

        // A new draft says the module is not enabled.
        $this->assertSame('module_unavailable', $this->section($manager, $this->mrReview($manager), 'risks')['state']);
    }

    public function test_a_section_the_finalizer_could_not_read_is_marked_not_captured(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $this->supplierRow($customer, 'Skyleverandør AS');
        $manager = $this->mrManager($customer);
        $review = $this->finalize($manager, $this->mrReview($manager));

        $supplierReader = $this->mrReader($customer, [P::SUPPLIER_VIEW]);
        $this->assertSame('not_captured', $this->section($supplierReader, $review, 'suppliers')['state']);
        // Someone who could not read it anyway is told only that they have no access.
        $this->assertSame('no_access', $this->section($this->mrReader($customer), $review, 'suppliers')['state']);
    }

    public function test_risk_changes_compare_each_assessment_with_the_one_before(): void
    {
        ['customer' => $customer] = $this->mrContext(['basis', 'grc']);
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::RISK_VIEW], [$area]);
        $risk = $this->risk($customer, $area, 'Datatap');
        $this->assess($risk, $manager, '2025-11-01 10:00:00', 2, 2);
        $this->assess($risk, $manager, '2026-03-01 10:00:00', 4, 5);

        $section = $this->section($manager, $this->mrReview($manager), 'risks');
        $this->assertSame(1, $this->metric($section, 'period', 'assessments'));
        $this->assertSame(1, $this->metric($section, 'period', 'risks_increased'));
        // Status is the residual picture; this risk has only an inherent assessment.
        $this->assertSame(1, $this->metric($section, 'status', 'residual_not_assessed'));
        $changed = collect($section['basis']['lists'])->firstWhere('key', 'changed_risks');
        $this->assertSame('Datatap', $changed['rows'][0]['title']);
        $this->assertSame('4', $changed['rows'][0]['cells']['score_from']);
        $this->assertSame('20', $changed['rows'][0]['cells']['score_to']);
    }

    public function test_a_snapshot_keeps_data_not_words_and_reads_in_any_language(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW], [$area]);
        $review = $this->finalize($manager, $this->mrReview($manager));

        $stored = json_encode(DB::table('management_review_snapshot_sections')->where('management_review_id', $review->id)->pluck('payload')->all());
        $this->assertStringNotContainsString('Avvik registrert', $stored);
        $this->assertStringNotContainsString('Perioderesultater', $stored);

        $basis = app(ManagementReviewBasisService::class)->snapshot($manager, $review)['improvements']['basis'];
        $formatter = app(BasisFormatter::class);

        app()->setLocale('en');
        $english = $formatter->format('improvements', $basis, true);
        $this->assertSame('Status at finalisation', collect($english['groups'])->firstWhere('key', 'status')['label']);
        $this->assertSame('Deviations recorded', $english['groups'][0]['metrics'][0]['label']);

        app()->setLocale('no');
        $norwegian = $formatter->format('improvements', $basis, true);
        $this->assertSame('Avvik registrert', $norwegian['groups'][0]['metrics'][0]['label']);
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function page(User $user, ManagementReview $review): array
    {
        return $this->actingAs($user)->get("/app/management-reviews/{$review->id}")->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function section(User $user, ManagementReview $review, string $key): array
    {
        return collect($this->page($user, $review)['sections'])->firstWhere('key', $key);
    }

    /** @param  array<string, mixed>  $section */
    private function metric(array $section, string $group, string $metric): int
    {
        $rows = collect($section['basis']['groups'])->firstWhere('key', $group)['metrics'];

        return (int) collect($rows)->firstWhere('key', $metric)['value'];
    }

    private function finalize(User $manager, ManagementReview $review): ManagementReview
    {
        $review->participants()->create(['customer_id' => $review->customer_id, 'name' => $manager->name, 'user_id' => $manager->id]);
        $review->forceFill(['conclusion' => 'Egnet og virker.', 'meeting_date' => '2026-07-01'])->save();

        foreach (array_keys(ManagementReviewSectionCatalog::DEFINITIONS) as $key) {
            ManagementReviewSection::query()->create([
                'customer_id' => $review->customer_id, 'management_review_id' => $review->id, 'section_key' => $key, 'judgement' => 'satisfactory',
            ]);
        }

        $this->actingAs($manager);

        return app(ManagementReviewFinalizationService::class)->finalize($manager, $review->fresh());
    }

    private function improvementCase(Customer $customer, $area, string $title, string $createdAt, string $type = ImprovementCase::TYPE_DEVIATION): ImprovementCase
    {
        $case = ImprovementCase::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => $type,
            'title' => $title,
            'description' => 'Beskrivelse',
        ]);
        $case->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $case;
    }

    private function close(ImprovementCase $case, string $at): void
    {
        $case->forceFill(['status' => ImprovementCase::STATUS_CLOSED, 'closing_note' => 'Lukket', 'closed_at' => $at])->saveQuietly();
        ImprovementCaseStatusChange::query()->create([
            'customer_id' => $case->customer_id,
            'improvement_case_id' => $case->id,
            'from_status' => ImprovementCase::STATUS_OPEN,
            'to_status' => ImprovementCase::STATUS_CLOSED,
            'note' => 'Lukket',
            'changed_at' => $at,
        ]);
    }

    private function risk(Customer $customer, $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    private function assess(Risk $risk, User $by, string $at, int $likelihood, int $consequence): void
    {
        RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_by' => $by->id,
            'assessed_at' => CarbonImmutable::parse($at),
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => $likelihood,
            'inherent_consequence' => $consequence,
        ]);
    }

    private function supplierRow(Customer $customer, string $name): Supplier
    {
        $supplier = new Supplier([
            'customer_id' => $customer->id,
            'name' => $name,
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift',
        ]);
        $supplier->status = Supplier::STATUS_ACTIVE;
        $supplier->save();

        return $supplier;
    }
}
