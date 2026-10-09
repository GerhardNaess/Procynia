<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\ComplianceRequirement;
use App\Models\CustomerPackageEntitlement;
use App\Models\ImprovementCase;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\User;
use App\Services\MyTasks\MyTasksService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesGovernanceTaskScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Punkt 6D: Risiko, Avvik og forbedringer, Etterlevelse og revisjon, Kvalitet and Mål og KPI in
 * «Mine oppgaver». Each module's own rules decide, each module's own access service narrows first;
 * the person sees exactly the work assigned to them that still asks something of them.
 */
class MyTasksGovernanceTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesGovernanceTaskScenarios;
    use CreatesImprovementCaseScenarios;
    use DatabaseTransactions;
    use ReadsMyTasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        // A Wednesday.
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_module_gives_its_owner_a_task_with_the_modules_own_reasons(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);

        $risk = $this->riskOwnedBy($customer, $area, $owner);
        $this->riskAction($risk, $owner, '2026-10-09');
        $case = $this->caseOwnedBy($customer, $area, $owner, 'Avvik i rutine', '2026-10-01');
        $this->improvementActionFor($case, $owner, '2026-10-20');
        $this->requirementOwnedBy($customer, $owner);
        $this->auditFor($customer, $owner, '2026-10-08');
        $this->qualityItemOwnedBy($customer, $owner);
        $objective = $this->objectiveOwnedBy($customer, $area, $owner, 'Høy leveransepresisjon', '2026-10-01');
        $this->kpiSince($objective, $owner, '2026-08-01 08:00:00');

        $tasks = collect($this->myTasksIn($this->infoCenterFor($owner)))->keyBy('id');

        $this->assertSame(['not_assessed'], array_column($tasks['risk-'.$risk->id]['reasons'], 'key'));
        $this->assertSame('this_week', $tasks->firstWhere('type', 'risk_action')['group']);
        $this->assertSame('2026-10-09', $tasks->firstWhere('type', 'risk_action')['due_on']);

        $caseTask = $tasks['improvement-case-'.$case->id];
        $this->assertSame(['case_overdue'], array_column($caseTask['reasons'], 'key'));
        $this->assertSame('overdue', $caseTask['group']);
        $this->assertSame('later', $tasks->firstWhere('type', 'improvement_action')['group']);

        $this->assertSame(['not_assessed'], array_column($tasks->firstWhere('type', 'compliance_requirement')['reasons'], 'key'));
        $this->assertSame(['audit_open'], array_column($tasks->firstWhere('type', 'compliance_audit')['reasons'], 'key'));
        $this->assertSame('2026-10-08', $tasks->firstWhere('type', 'compliance_audit')['due_on']);

        $this->assertSame(['controls_without_evidence', 'controls_without_activity'], array_column($tasks->firstWhere('type', 'quality_item')['reasons'], 'key'));
        $this->assertSame(['target_date_passed'], array_column($tasks->firstWhere('type', 'objective')['reasons'], 'key'));

        // August's deadline (7 September) passed: the KPI is missing a measurement.
        $kpiTask = $tasks->firstWhere('type', 'kpi');
        $this->assertSame('measurement_missing', $kpiTask['reasons'][0]['key']);
        $this->assertSame('2026-09-07', $kpiTask['due_on']);

        $this->assertEqualsCanonicalizing(
            ['risk', 'improvements', 'compliance', 'quality', 'objectives'],
            array_values(array_unique($tasks->pluck('module')->all())),
        );
        // Risk, its tiltak, the case, its tiltak, the requirement, the audit, the control, the objective, the KPI.
        $this->assertSame(9, $this->myTasksCount($owner));
        $this->assertTrue($tasks->every(fn (array $task): bool => $task['can_act']));
        $this->assertStringStartsWith('/app/', $tasks['risk-'.$risk->id]['action_url']);
    }

    public function test_nobody_else_gets_the_task_and_finished_objects_are_no_task(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        $colleague = $this->governancePerson($customer);

        $this->riskOwnedBy($customer, $area, $owner, 'Lukket', Risk::STATUS_CLOSED);
        $closedCase = $this->caseOwnedBy($customer, $area, $owner, 'Lukket sak');
        $closedCase->forceFill(['status' => ImprovementCase::STATUS_CLOSED, 'closed_at' => now(), 'closing_note' => 'Ferdig.'])->saveQuietly();
        $retired = $this->requirementOwnedBy($customer, $owner, 'Utgått');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->saveQuietly();
        $this->auditFor($customer, $owner, '2026-10-01', ComplianceAudit::STATUS_CANCELLED);
        $this->qualityItemOwnedBy($customer, $owner, 'Utgått kontroll', QualityItem::TYPE_CONTROL, QualityItem::STATUS_RETIRED);
        $closedObjective = $this->objectiveOwnedBy($customer, $area, $owner, 'Nådd', '2026-01-01');
        $closedObjective->forceFill(['status' => Objective::STATUS_ACHIEVED, 'closed_at' => now()])->saveQuietly();

        $this->assertSame([], $this->governanceTasks($owner));

        $this->riskOwnedBy($customer, $area, $owner, 'Åpen');
        $this->assertCount(1, $this->governanceTasks($owner));
        $this->assertSame([], $this->governanceTasks($colleague));
    }

    public function test_a_task_follows_the_owner_field_and_the_status(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $first = $this->governancePerson($customer);
        $second = $this->governancePerson($customer);
        $case = $this->caseOwnedBy($customer, $area, $first);

        $this->assertCount(1, $this->governanceTasks($first));

        $case->forceFill(['owner_user_id' => $second->id])->save();
        $this->assertSame([], $this->governanceTasks($first));
        $this->assertCount(1, $this->governanceTasks($second));

        $case->forceFill(['status' => ImprovementCase::STATUS_CLOSED, 'closed_at' => now(), 'closing_note' => 'Ferdig.'])->saveQuietly();
        $this->assertSame([], $this->governanceTasks($second));
    }

    public function test_fagomrade_module_and_customer_decide_before_anything_is_shown(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $otherArea = $this->area($customer, 'Økonomi');
        $owner = $this->member($customer);
        // Only the Økonomi fagområde: a risk in Drift is not theirs to see, even as its owner.
        $this->grant($customer, $owner, self::GOVERNANCE_PERMISSIONS, [$otherArea]);
        $this->riskOwnedBy($customer, $area, $owner, 'Utenfor fagområdet');
        $this->objectiveOwnedBy($customer, $area, $owner, 'Utenfor fagområdet', '2026-01-01');

        $this->assertSame([], $this->governanceTasks($owner->fresh()));

        $this->riskOwnedBy($customer, $otherArea, $owner, 'Innenfor');
        $this->assertCount(1, $this->governanceTasks($owner->fresh()));

        // Without the module, nothing — even what is otherwise theirs.
        CustomerPackageEntitlement::query()->where('customer_id', $customer->id)->whereIn('package_key', ['grc', 'risk'])
            ->update(['status' => CustomerPackageEntitlement::STATUS_REVOKED]);
        $this->assertSame([], $this->governanceTasks($owner->fresh(), 'risk'));

        // Another customer's person never sees this customer's objects, even named as owner.
        ['customer' => $other] = $this->governanceCustomer();
        $outsider = $this->governancePerson($other);
        $risk = $this->riskOwnedBy($customer, $otherArea, null, 'Feilkoblet');
        DB::table('risks')->where('id', $risk->id)->update(['owner_user_id' => $outsider->id]);
        $this->assertSame([], $this->governanceTasks($outsider));
    }

    public function test_a_reader_still_sees_their_task_and_is_told_what_they_cannot_do(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $reader = $this->governancePerson($customer, self::GOVERNANCE_VIEW);
        $this->riskOwnedBy($customer, $area, $reader);
        $this->requirementOwnedBy($customer, $reader);

        $tasks = $this->governanceTasks($reader);

        $this->assertCount(2, $tasks);
        $this->assertFalse($tasks[0]['can_act']);
        $this->assertSame([false], array_values(array_unique(array_merge(...array_map(fn (array $task): array => array_column($task['reasons'], 'can_act'), $tasks)))));
    }

    public function test_a_kpi_without_its_own_owner_is_the_objective_owners(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $objectiveOwner = $this->governancePerson($customer);
        $kpiOwner = $this->governancePerson($customer);
        $objective = $this->objectiveOwnedBy($customer, $area, $objectiveOwner);
        $this->kpiSince($objective, null, '2026-08-01 08:00:00', 'Uten egen ansvarlig');
        $this->kpiSince($objective, $kpiOwner, '2026-08-01 08:00:00', 'Med egen ansvarlig');

        $this->assertSame(['Uten egen ansvarlig'], array_column($this->governanceTasks($objectiveOwner), 'title'));
        $this->assertSame(['Med egen ansvarlig'], array_column($this->governanceTasks($kpiOwner), 'title'));
    }

    public function test_the_filter_lists_only_modules_the_person_can_see(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $person = $this->member($customer);
        $this->grantAll($customer, $person, [CustomerPermissionCatalog::RISK_VIEW]);
        $this->riskOwnedBy($customer, $area, $person);

        $modules = $this->infoCenterFor($person->fresh())['my_tasks']['modules'];

        // Anbud (TestCase), Risiko by role; no Kvalitet, Avvik, Mål, Etterlevelse or Leverandører role.
        $this->assertSame(['tender', 'risk'], array_column($modules, 'key'));
        $this->assertSame(1, collect($modules)->firstWhere('key', 'risk')['count']);
    }

    public function test_many_tasks_are_read_in_batches(): void
    {
        ['customer' => $customer, 'area' => $area] = $this->governanceCustomer();
        $owner = $this->governancePerson($customer);
        $service = app(MyTasksService::class);

        $queriesFor = function (int $count) use ($customer, $area, $owner, $service): int {
            foreach (range(1, $count) as $i) {
                $risk = $this->riskOwnedBy($customer, $area, $owner, 'Risiko '.uniqid());
                $this->riskAction($risk, $owner, '2026-10-20', 'Tiltak '.$i);
                $case = $this->caseOwnedBy($customer, $area, $owner, 'Sak '.uniqid());
                $this->improvementActionFor($case, $owner, '2026-10-20');
                $this->requirementOwnedBy($customer, $owner, 'Krav '.uniqid());
                $this->qualityItemOwnedBy($customer, $owner, 'Kontroll '.uniqid());
                $this->objectiveOwnedBy($customer, $area, $owner, 'Mål '.uniqid(), '2026-01-01');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->tasksFor($owner->fresh(), (int) $customer->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($queriesFor(2), $queriesFor(8));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function infoCenterFor(User $user): array
    {
        return $this->actingAs($user)->get(route('app.info-center.index'))->assertOk()->viewData('page')['props']['infoCenter'];
    }

    /** @return list<array<string, mixed>> */
    private function governanceTasks(User $user, ?string $module = null): array
    {
        return array_values(array_filter(
            $this->myTasksIn($this->infoCenterFor($user)),
            fn (array $task): bool => in_array($task['module'], ['risk', 'improvements', 'compliance', 'quality', 'objectives'], true)
                && ($module === null || $task['module'] === $module),
        ));
    }

    private function myTasksCount(User $user): int
    {
        return (int) collect($this->infoCenterFor($user)['summary']['items'])->firstWhere('key', 'my_tasks')['count'];
    }
}
