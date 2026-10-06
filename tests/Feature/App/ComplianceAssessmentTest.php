<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;
use App\Services\Compliance\ComplianceAssessmentService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\ComplianceReviewSchedule;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → Etterlevelsesvurderinger, from database to page.
 *
 * What these tests defend:
 *
 *  - compliance.assess — not compliance.edit — registers an assessment; access first (404 for a
 *    requirement the user cannot see), permission next (403). System Owner needs an own role.
 *  - Only an active requirement is assessed, on the server; the date is the system's.
 *  - Four results, a rationale always, and no stored «not assessed».
 *  - Every assessment snapshots the requirement and its source, so history stays true, and a
 *    change since the latest assessment is shown without touching the result.
 *  - One resolver decides the current status (latest assessed_at, highest id on a tie); one
 *    schedule decides the next review and whether it is overdue. Neither is stored.
 *  - Assessments are immutable in the model and in the database, survive their author's
 *    deletion, and hold the customer line through a composite key.
 */
class ComplianceAssessmentTest extends TestCase
{
    use CreatesComplianceScenarios;
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

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_an_assessor_registers_the_first_assessment_with_the_system_date_and_a_snapshot(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer, 'Vera Vurderer');
        $source = $this->complianceSource($customer, 'ISO 27001', '2022');
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', null, 'A.5.15', 12);
        $this->travelTo(CarbonImmutable::parse('2026-03-10 09:30:00'));

        $this->actingAs($assessor)
            ->post($this->assessUrl($requirement), [
                'result' => 'compliant',
                'rationale' => '  Rutinen er etablert og fulgt.  ',
                // Nothing in the request sets the date.
                'assessed_at' => '2020-01-01 00:00:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $assessment = ComplianceAssessment::query()->where('requirement_id', $requirement->id)->sole();
        $this->assertSame('compliant', $assessment->result);
        $this->assertSame('Rutinen er etablert og fulgt.', $assessment->rationale);
        $this->assertSame((int) $assessor->id, (int) $assessment->assessed_by_user_id);
        $this->assertSame('2026-03-10 09:30:00', $assessment->assessed_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['A.5.15', 'Tilgangsstyring', 'Kravtekst for Tilgangsstyring', 'ISO 27001', '2022'],
            [$assessment->requirement_reference, $assessment->requirement_title, $assessment->requirement_text, $assessment->source_name, $assessment->source_version],
        );

        $props = $this->actingAs($assessor)->get("/app/compliance/requirements/{$requirement->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('compliant', $props['compliance']['status']);
        $this->assertSame('Vera Vurderer', $props['compliance']['assessed_by_name']);
        $this->assertSame('2027-03-10', $props['compliance']['next_review_on']);
        $this->assertFalse($props['compliance']['is_overdue']);
        $this->assertFalse($props['compliance']['changed_since_assessment']);
        $this->assertCount(1, $props['assessments']);
        $this->assertSame('ISO 27001', $props['assessments'][0]['snapshot']['source_name']);
        $this->assertTrue($props['permissions']['can_assess']);
        $this->assertSame(ComplianceAssessment::RESULTS, $props['assessment_results']);
    }

    public function test_every_result_can_be_registered_and_not_assessed_is_never_one(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        foreach (ComplianceAssessment::RESULTS as $result) {
            $this->actingAs($assessor)->post($this->assessUrl($requirement), ['result' => $result, 'rationale' => "Begrunnelse for {$result}"])->assertSessionHasNoErrors();
        }

        $this->assertSame(ComplianceAssessment::RESULTS, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->orderBy('id')->pluck('result')->all());

        $this->actingAs($assessor)->post($this->assessUrl($requirement), ['result' => 'not_assessed', 'rationale' => 'Forsøk'])->assertSessionHasErrors('result');
        $this->assertSame(4, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->count());
    }

    public function test_a_rationale_is_required_for_every_result_including_not_applicable(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        foreach (['', '   '] as $rationale) {
            $this->actingAs($assessor)
                ->post($this->assessUrl($requirement), ['result' => 'not_applicable', 'rationale' => $rationale])
                ->assertSessionHasErrors(['rationale' => 'Begrunnelse må fylles ut.']);
        }

        $this->actingAs($assessor)->post($this->assessUrl($requirement), ['rationale' => 'Uten resultat'])->assertSessionHasErrors(['result' => 'Resultat må fylles ut.']);

        try {
            app(ComplianceAssessmentService::class)->assess($requirement, $assessor, 'not_applicable', ' ');
            $this->fail('The service must refuse an empty rationale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rationale', $exception->errors());
        }

        $this->actingAs($assessor)
            ->post($this->assessUrl($requirement), ['result' => 'not_applicable', 'rationale' => 'Kravet gjelder ikke fordi virksomheten ikke behandler denne typen data.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->count());
    }

    public function test_edit_without_assess_cannot_assess_and_view_only_reads(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $editor = $this->complianceEditor($customer);
        $reader = $this->complianceReader($customer);
        app(ComplianceAssessmentService::class)->assess($requirement, $this->complianceAssessor($customer), 'compliant', 'Oppfylt');

        foreach ([$editor, $reader] as $user) {
            $props = $this->actingAs($user)->get("/app/compliance/requirements/{$requirement->id}")->assertOk()->viewData('page')['props'];
            $this->assertFalse($props['permissions']['can_assess']);
            $this->assertSame('compliant', $props['compliance']['status']);
            $this->assertCount(1, $props['assessments']);

            $this->actingAs($user)->post($this->assessUrl($requirement), ['result' => 'non_compliant', 'rationale' => 'Forsøk'])->assertForbidden();
        }

        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->count());
    }

    public function test_assess_needs_view_and_system_owner_needs_an_explicit_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        // compliance.assess alone, without view, opens nothing.
        $assessOnly = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessOnly, [CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $this->actingAs($assessOnly)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Forsøk'])->assertForbidden();

        // System Owner without an own role: forbidden, like the rest of the module.
        $this->actingAs($owner)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Forsøk'])->assertForbidden();
        $this->actingAs($owner)->get("/app/compliance/requirements/{$requirement->id}")->assertForbidden();
        $this->assertSame(0, ComplianceAssessment::query()->count());

        // With an own role that grants assess, System Owner assesses like anyone with that role.
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $this->actingAs($owner)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Oppfylt'])->assertSessionHasNoErrors();
        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->count());
    }

    public function test_another_customers_requirement_and_assessments_are_undiscoverable(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $foreign = $this->complianceRequirement($this->complianceSource($other), 'Fremmed krav');
        app(ComplianceAssessmentService::class)->assess($foreign, $this->complianceAssessor($other), 'non_compliant', 'Hemmelig begrunnelse');
        $own = $this->complianceRequirement($this->complianceSource($customer), 'Eget krav');

        // Access first: a foreign id is a 404 for read and write, exactly like an id that does not exist.
        $this->actingAs($assessor)->get("/app/compliance/requirements/{$foreign->id}")->assertNotFound();
        $this->actingAs($assessor)->post($this->assessUrl($foreign), ['result' => 'compliant', 'rationale' => 'Forsøk'])->assertNotFound();
        $this->actingAs($assessor)->post('/app/compliance/requirements/999999999/assessments', ['result' => 'compliant', 'rationale' => 'Forsøk'])->assertNotFound();
        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $foreign->id)->count());

        // The register neither lists nor counts it, and its result reaches no row.
        $page = $this->actingAs($assessor)->get('/app/compliance/requirements')->assertOk()->viewData('page');
        $this->assertSame([(int) $own->id], array_column($page['props']['requirements'], 'id'));
        $this->assertSame(1, $page['props']['visible_count']);
        $this->assertSame('not_assessed', $page['props']['requirements'][0]['compliance']['status']);
        $this->assertStringNotContainsString('Hemmelig begrunnelse', json_encode($page['props']));

        // The resolver only ever sees the ids it is handed, and only within the customer it is given.
        $this->assertCount(0, app(ComplianceStatusResolver::class)->latestForRequirements((int) $customer->id, [(int) $foreign->id]));
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_a_retired_requirement_keeps_its_history_but_cannot_be_assessed(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav', null, null, 1);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00'));
        app(ComplianceAssessmentService::class)->assess($requirement, $assessor, 'partially_compliant', 'Delvis');
        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $this->complianceEditor($customer), 'Utgått');
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00'));

        $this->actingAs($assessor)
            ->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Forsøk'])
            ->assertSessionHas('error', 'Kravet er utgått og kan ikke vurderes. Gjenåpne det først.');

        try {
            app(ComplianceAssessmentService::class)->assess($requirement->fresh(), $assessor, 'compliant', 'Forsøk');
            $this->fail('The service must refuse a retired requirement.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, ComplianceAssessment::query()->where('requirement_id', $requirement->id)->count());

        $props = $this->actingAs($assessor)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_assess']);
        $this->assertSame('partially_compliant', $props['compliance']['status']);
        // Long past a monthly interval, and still neither due nor overdue: it is retired.
        $this->assertNull($props['compliance']['next_review_on']);
        $this->assertFalse($props['compliance']['is_overdue']);
        $this->assertCount(1, $props['assessments']);

        $row = $this->actingAs($assessor)->get('/app/compliance/requirements')->viewData('page')['props']['requirements'][0];
        $this->assertSame('retired', $row['status']);
        $this->assertSame(['status' => 'partially_compliant', 'is_overdue' => false], array_intersect_key($row['compliance'], array_flip(['status', 'is_overdue'])));
    }

    public function test_a_requirement_that_has_been_assessed_cannot_be_deleted(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $manager = $this->complianceManager($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        app(ComplianceAssessmentService::class)->assess($requirement, $this->complianceAssessor($customer), 'compliant', 'Oppfylt');

        $this->assertFalse($requirement->isDeletable());
        $this->assertFalse($this->actingAs($manager)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertSessionHas('error');
        $this->assertNotNull($requirement->fresh());

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_requirements')->where('id', $requirement->id)->delete(), 'deleting an assessed requirement');
    }

    // ---------------------------------------------------------------------
    // Resolver, schedule, snapshot
    // ---------------------------------------------------------------------

    public function test_the_current_assessment_is_the_latest_by_date_and_the_highest_id_on_a_tie(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $other = $this->complianceRequirement($this->complianceSource($customer, 'NSM'), 'Annet krav');
        $resolver = app(ComplianceStatusResolver::class);

        $this->assertNull($resolver->latestFor($requirement));
        $this->assertSame('not_assessed', $resolver->statusOf(null));
        $this->assertSame('not_assessed', $resolver->summarize($requirement, null)['status']);

        // Written out of order: the later date wins over the higher id.
        $this->insertAssessment($requirement, 'non_compliant', '2026-05-01 12:00:00');
        $this->insertAssessment($requirement, 'compliant', '2026-04-01 12:00:00');
        $this->assertSame('non_compliant', $resolver->latestFor($requirement)->result);

        // The same instant: the higher id wins.
        $this->insertAssessment($requirement, 'partially_compliant', '2026-05-01 12:00:00');
        $this->assertSame('partially_compliant', $resolver->latestFor($requirement)->result);

        $this->insertAssessment($other, 'not_applicable', '2026-01-01 12:00:00');
        $latest = $resolver->latestForRequirements((int) $customer->id, [(int) $requirement->id, (int) $other->id]);
        $this->assertSame(['partially_compliant', 'not_applicable'], [$latest[(int) $requirement->id]->result, $latest[(int) $other->id]->result]);

        // The page and the register agree with the resolver, and nothing is stored on the requirement.
        $assessor = $this->complianceAssessor($customer);
        $this->assertSame('partially_compliant', $this->actingAs($assessor)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props']['compliance']['status']);
        $this->assertSame('partially_compliant', $this->actingAs($assessor)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props']['assessments'][0]['result']);
        foreach (['compliance_status', 'current_assessment_id', 'next_review_date', 'next_review_on'] as $column) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('compliance_requirements', $column), $column);
        }
    }

    public function test_the_review_schedule_follows_the_latest_assessment_and_the_interval(): void
    {
        $schedule = app(ComplianceReviewSchedule::class);
        $active = ComplianceRequirement::STATUS_ACTIVE;

        // No overflow: 31 January + 1 month is the last day of February.
        $this->assertSame('2026-02-28', $schedule->nextReviewOn($active, 1, CarbonImmutable::parse('2026-01-31 15:00'))->toDateString());
        $this->assertSame('2029-02-28', $schedule->nextReviewOn($active, 12, CarbonImmutable::parse('2028-02-29'))->toDateString());
        $this->assertSame('2028-02-29', $schedule->nextReviewOn($active, 1, CarbonImmutable::parse('2028-01-31'))->toDateString());
        $this->assertSame('2026-09-30', $schedule->nextReviewOn($active, 6, CarbonImmutable::parse('2026-03-31'))->toDateString());

        // No interval, never assessed, retired: no next date.
        $this->assertNull($schedule->nextReviewOn($active, null, CarbonImmutable::parse('2026-01-01')));
        $this->assertNull($schedule->nextReviewOn($active, 3, null));
        $this->assertNull($schedule->nextReviewOn(ComplianceRequirement::STATUS_RETIRED, 3, CarbonImmutable::parse('2020-01-01')));

        // Due today is not overdue; the day after is.
        $due = CarbonImmutable::parse('2026-04-01');
        $this->assertFalse($schedule->isOverdue($due, CarbonImmutable::parse('2026-03-31')));
        $this->assertFalse($schedule->isOverdue($due, CarbonImmutable::parse('2026-04-01 23:59')));
        $this->assertTrue($schedule->isOverdue($due, CarbonImmutable::parse('2026-04-02 00:00')));
        $this->assertFalse($schedule->isOverdue(null, CarbonImmutable::parse('2099-01-01')));
    }

    public function test_overdue_through_the_pages_never_for_unassessed_or_intervalless_requirements(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $source = $this->complianceSource($customer);
        $quarterly = $this->complianceRequirement($source, 'Kvartalsvis', null, 'A.1', 3);
        $noInterval = $this->complianceRequirement($source, 'Uten intervall', null, 'A.2', null);
        $neverAssessed = $this->complianceRequirement($source, 'Aldri vurdert', null, 'A.3', 1);
        $this->insertAssessment($quarterly, 'compliant', '2026-01-15 08:00:00');
        $this->insertAssessment($noInterval, 'non_compliant', '2020-01-15 08:00:00');

        $register = function () use ($assessor): array {
            $rows = $this->actingAs($assessor)->get('/app/compliance/requirements')->viewData('page')['props']['requirements'];

            return array_combine(array_column($rows, 'title'), array_column($rows, 'compliance'));
        };

        $this->travelTo(CarbonImmutable::parse('2026-04-15 22:00:00'));
        $rows = $register();
        $this->assertFalse($rows['Kvartalsvis']['is_overdue'], 'Due today is not overdue.');
        $this->assertSame('2026-04-15', $this->actingAs($assessor)->get("/app/compliance/requirements/{$quarterly->id}")->viewData('page')['props']['compliance']['next_review_on']);

        $this->travelTo(CarbonImmutable::parse('2026-04-16 00:01:00'));
        $rows = $register();
        $this->assertSame(['status' => 'compliant', 'is_overdue' => true], array_intersect_key($rows['Kvartalsvis'], array_flip(['status', 'is_overdue'])));
        $this->assertSame(['status' => 'non_compliant', 'is_overdue' => false], array_intersect_key($rows['Uten intervall'], array_flip(['status', 'is_overdue'])));
        $this->assertSame(['status' => 'not_assessed', 'is_overdue' => false], array_intersect_key($rows['Aldri vurdert'], array_flip(['status', 'is_overdue'])));

        $props = $this->actingAs($assessor)->get("/app/compliance/requirements/{$noInterval->id}")->viewData('page')['props'];
        $this->assertNull($props['compliance']['next_review_on']);
    }

    public function test_the_snapshot_keeps_history_true_and_a_change_since_the_latest_assessment_is_shown(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $editor = $this->complianceEditor($customer);
        $owner = $this->complianceReader($customer);
        $source = $this->complianceSource($customer, 'ISO 27001', '2022');
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', $owner, 'A.5.15', 12);
        $this->actingAs($assessor)->post($this->assessUrl($requirement), ['result' => 'compliant', 'rationale' => 'Oppfylt'])->assertSessionHasNoErrors();

        $show = fn (): array => $this->actingAs($assessor)->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props'];
        $this->assertFalse($show()['compliance']['changed_since_assessment']);

        // Owner and interval are not part of what was assessed: no change.
        $this->actingAs($editor)->patch("/app/compliance/requirements/{$requirement->id}", array_merge($this->requirementPayload($source, $editor, 'Tilgangsstyring', 'A.5.15', 6), ['requirement_text' => 'Kravtekst for Tilgangsstyring']))->assertSessionHasNoErrors();
        $this->assertFalse($show()['compliance']['changed_since_assessment']);

        // The text is: the change is shown, the result stands and the history is untouched.
        $this->actingAs($editor)->patch("/app/compliance/requirements/{$requirement->id}", array_merge($this->requirementPayload($source, $owner, 'Tilgangsstyring', 'A.5.15', 6), ['requirement_text' => 'Ny kravtekst']))->assertSessionHasNoErrors();
        $props = $show();
        $this->assertTrue($props['compliance']['changed_since_assessment']);
        $this->assertSame('compliant', $props['compliance']['status']);
        $this->assertSame('Kravtekst for Tilgangsstyring', $props['assessments'][0]['snapshot']['requirement_text']);

        // A new assessment snapshots the new text, and the message goes away.
        $this->actingAs($assessor)->post($this->assessUrl($requirement), ['result' => 'partially_compliant', 'rationale' => 'Delvis etter endringen'])->assertSessionHasNoErrors();
        $props = $show();
        $this->assertFalse($props['compliance']['changed_since_assessment']);
        $this->assertSame(['partially_compliant', 'compliant'], array_column($props['assessments'], 'result'));
        $this->assertSame(['Ny kravtekst', 'Kravtekst for Tilgangsstyring'], array_column(array_column($props['assessments'], 'snapshot'), 'requirement_text'));

        // A change to the source's version counts too.
        $this->actingAs($editor)->patch("/app/compliance/sources/{$source->id}", ['name' => 'ISO 27001', 'version' => '2027', 'kind' => 'standard'])->assertSessionHasNoErrors();
        $this->assertTrue($show()['compliance']['changed_since_assessment']);
        $this->assertSame('2022', $show()['assessments'][0]['snapshot']['source_version']);
    }

    // ---------------------------------------------------------------------
    // Immutability and the database
    // ---------------------------------------------------------------------

    public function test_an_assessment_cannot_be_changed_or_deleted_by_the_application_or_the_database(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $assessment = app(ComplianceAssessmentService::class)->assess($requirement, $this->complianceAssessor($customer), 'compliant', 'Oppfylt');

        foreach (['update' => fn () => $assessment->update(['result' => 'non_compliant']), 'delete' => fn () => $assessment->delete()] as $label => $attempt) {
            try {
                $attempt();
                $this->fail("The model must refuse {$label}.");
            } catch (LogicException) {
            }
        }

        foreach ([
            'result' => 'non_compliant',
            'rationale' => 'Omskrevet',
            'assessed_at' => '2020-01-01 00:00:00',
            'requirement_text' => 'Omskrevet snapshot',
            'source_version' => '1999',
            'assessed_by_user_id' => $this->complianceReader($customer)->id,
        ] as $column => $value) {
            $this->assertDatabaseRefuses(fn () => DB::table('compliance_assessments')->where('id', $assessment->id)->update([$column => $value]), "changing {$column}");
        }

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_assessments')->where('id', $assessment->id)->delete(), 'deleting an assessment');

        $fresh = $assessment->fresh();
        $this->assertSame(['compliant', 'Oppfylt'], [$fresh->result, $fresh->rationale]);
    }

    public function test_the_database_knows_only_the_four_results_and_needs_a_rationale(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        foreach (['not_assessed', 'COMPLIANT', ''] as $result) {
            $this->assertDatabaseRefuses(fn () => $this->insertAssessment($requirement, $result, '2026-01-01 00:00:00'), "the result «{$result}»");
        }

        $this->assertDatabaseRefuses(fn () => $this->insertAssessment($requirement, 'compliant', '2026-01-01 00:00:00', ' '), 'an empty rationale');

        $this->expectException(DomainException::class);
        ComplianceAssessment::query()->create([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'result' => 'not_assessed', 'rationale' => 'Nei',
            'assessed_at' => now(), 'requirement_title' => 'Krav', 'requirement_text' => 'Tekst', 'source_name' => 'ISO',
        ]);
    }

    public function test_the_database_refuses_an_assessment_across_customers(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $own = $this->complianceRequirement($this->complianceSource($customer), 'Eget krav');
        $foreign = $this->complianceRequirement($this->complianceSource($other), 'Fremmed krav');

        $this->assertDatabaseRefuses(fn () => DB::table('compliance_assessments')->insert(array_merge($this->assessmentRow($own, 'compliant', '2026-01-01'), ['customer_id' => $other->id])), 'an assessment naming another customer');
        $this->assertDatabaseRefuses(fn () => DB::table('compliance_assessments')->insert(array_merge($this->assessmentRow($foreign, 'compliant', '2026-01-01'), ['customer_id' => $customer->id])), 'an assessment of another customer\'s requirement');
        $this->assertSame(0, DB::table('compliance_assessments')->count());
    }

    public function test_a_deleted_assessor_leaves_the_assessment_without_an_author(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $assessor = $this->complianceAssessor($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        app(ComplianceAssessmentService::class)->assess($requirement, $assessor, 'non_compliant', 'Mangler rutine');

        $assessor->delete();

        $assessment = ComplianceAssessment::query()->where('requirement_id', $requirement->id)->sole();
        $this->assertNull($assessment->assessed_by_user_id);
        $this->assertSame(['non_compliant', 'Mangler rutine'], [$assessment->result, $assessment->rationale]);

        $props = $this->actingAs($this->complianceReader($customer))->get("/app/compliance/requirements/{$requirement->id}")->viewData('page')['props'];
        $this->assertNull($props['compliance']['assessed_by_name']);
        $this->assertNull($props['assessments'][0]['assessed_by_name']);
        $this->assertSame('non_compliant', $props['compliance']['status']);
    }

    public function test_a_customer_going_takes_its_assessments_with_it(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $other] = $this->complianceContext();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        app(ComplianceAssessmentService::class)->assess($requirement, $this->complianceAssessor($customer), 'compliant', 'Oppfylt');
        $kept = $this->complianceRequirement($this->complianceSource($other), 'Annen kundes krav');
        app(ComplianceAssessmentService::class)->assess($kept, $this->complianceAssessor($other), 'compliant', 'Oppfylt');

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertSame(0, DB::table('compliance_assessments')->where('customer_id', $customer->id)->count());
        $this->assertSame(1, DB::table('compliance_assessments')->where('customer_id', $other->id)->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function complianceAssessor($customer, string $name = '')
    {
        $user = $this->complianceMember($customer, $name);
        $this->complianceGrant($customer, $user, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);

        return $user;
    }

    private function assessUrl(ComplianceRequirement $requirement): string
    {
        return "/app/compliance/requirements/{$requirement->id}/assessments";
    }

    /** @return array<string, mixed> */
    private function assessmentRow(ComplianceRequirement $requirement, string $result, string $assessedAt, string $rationale = 'Begrunnelse'): array
    {
        return [
            'customer_id' => $requirement->customer_id,
            'requirement_id' => $requirement->id,
            'result' => $result,
            'rationale' => $rationale,
            'assessed_at' => $assessedAt,
            'requirement_reference' => $requirement->reference,
            'requirement_title' => $requirement->title,
            'requirement_text' => $requirement->requirement_text,
            'source_name' => 'ISO 27001',
            'source_version' => '2022',
        ];
    }

    /** A row at a chosen moment, which the service never allows — for the resolver and schedule. */
    private function insertAssessment(ComplianceRequirement $requirement, string $result, string $assessedAt, string $rationale = 'Begrunnelse'): void
    {
        $requirement->loadMissing('source');
        DB::table('compliance_assessments')->insert(array_merge($this->assessmentRow($requirement, $result, $assessedAt, $rationale), [
            'source_name' => $requirement->source->name,
            'source_version' => $requirement->source->version,
        ]));
    }

    /** Runs the write in a savepoint, so the refusal does not poison the test's transaction. */
    private function assertDatabaseRefuses(callable $write, string $what): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("The database must refuse {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
