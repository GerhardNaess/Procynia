<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseActivity;
use App\Models\ImprovementCaseProcess;
use App\Models\ImprovementCaseStatusChange;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Customer separation across everything Avvik og forbedringer stores, in one place.
 *
 * Customer A holds a case with every kind of record behind it: status history, a Kvalitet link,
 * tiltak with their own history, and an effektverifisering. Customer B's user holds every
 * improvement permission with «Alle», plus Kvalitet read. Nothing of A's may be read, counted,
 * changed, linked to or referenced from B — through any route, and where the schema says so, not
 * through the database either.
 */
class ImprovementTenantIsolationTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const ALL = [
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_EDIT,
        CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        CustomerPermissionCatalog::IMPROVEMENT_DELETE,
        CustomerPermissionCatalog::QUALITY_VIEW,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Bus::fake();
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

    public function test_nothing_of_another_customers_cases_can_be_reached_from_any_route(): void
    {
        $a = $this->customerWithFullCase();
        ['customer' => $customerB] = $this->context();
        $areaB = $this->area($customerB, 'HR');
        $userB = $this->member($customerB);
        $this->grantAll($customerB, $userB, self::ALL);
        $ownCase = $this->improvementCase($customerB, $areaB, 'Egen sak', $userB);

        $before = $this->snapshot($a);
        $case = $a['case'];
        $action = $a['action'];
        $base = "/app/improvements/{$case->id}";

        // Not listed, not searchable, not counted, not in Trenger oppmerksomhet.
        $props = $this->actingAs($userB)->get('/app/improvements?search=hemmelig')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['cases']);
        $this->assertSame(1, $props['visible_count']);
        $this->assertSame(0, $props['attention']['case_total']);
        $this->assertSame(0, $props['attention']['action_total']);
        $this->assertStringNotContainsString('Hemmelig', json_encode($props));

        // Every route under A's case: the same 404, nothing says it exists.
        $this->actingAs($userB)->get($base)->assertNotFound();
        $this->actingAs($userB)->patch($base, $this->payload($areaB, $userB))->assertNotFound();
        $this->actingAs($userB)->delete($base)->assertNotFound();
        $this->actingAs($userB)->post("{$base}/start")->assertNotFound();
        $this->actingAs($userB)->post("{$base}/close", ['closing_note' => 'Kapret'])->assertNotFound();
        $this->actingAs($userB)->post("{$base}/cancel", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($userB)->post("{$base}/reopen", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($userB)->put("{$base}/cause", ['cause_analysis' => 'Kapret'])->assertNotFound();
        $this->actingAs($userB)->put("{$base}/processes/{$a['process']->id}", ['whole_process' => false, 'activity_keys' => []])->assertNotFound();
        $this->actingAs($userB)->post("{$base}/actions", $this->actionPayload($userB))->assertNotFound();
        $this->assertActionRoutesNotFound($userB, $base, $action->id);

        // A's tiltak id under B's own case: still a 404.
        $this->assertActionRoutesNotFound($userB, "/app/improvements/{$ownCase->id}", $action->id);

        // Nor can B point at A's records: area, owner, process.
        $this->actingAs($userB)->post('/app/improvements', $this->payload($a['area'], $userB))->assertSessionHasErrors('business_area_id');
        $this->actingAs($userB)->post('/app/improvements', $this->payload($areaB, $a['user']))->assertSessionHasErrors('owner_user_id');
        $this->actingAs($userB)->post("/app/improvements/{$ownCase->id}/actions", $this->actionPayload($a['user']))->assertSessionHasErrors('owner_user_id');
        // A's process is refused exactly like one that does not exist: nothing says it is there.
        foreach ([$a['process']->id, $a['process']->id + 100000] as $processId) {
            $this->actingAs($userB)
                ->put("/app/improvements/{$ownCase->id}/processes/{$processId}", ['whole_process' => true, 'activity_keys' => []])
                ->assertSessionHasErrors(['quality_process_id' => 'Velg en prosess fra listen.']);
        }

        $this->assertSame(0, ImprovementCaseProcess::query()->where('improvement_case_id', $ownCase->id)->count());
        $this->assertSame(0, ImprovementAction::query()->where('improvement_case_id', $ownCase->id)->count());
        $this->assertSame($before, $this->snapshot($a));
    }

    public function test_the_database_refuses_records_that_mix_two_customers(): void
    {
        $a = $this->customerWithFullCase();
        ['customer' => $customerB] = $this->context();
        $caseB = $this->improvementCase($customerB, $this->area($customerB, 'HR'), 'Egen sak');
        $actionB = $this->improvementAction($caseB, 'Eget tiltak');

        // A tiltak under A's case, filed as B's.
        $this->assertRefused(fn () => DB::table('improvement_actions')->insert([
            'customer_id' => $customerB->id, 'improvement_case_id' => $a['case']->id, 'title' => 'Kapret',
            'status' => 'planned', 'due_date' => '2030-01-31', 'created_at' => now(), 'updated_at' => now(),
        ]));

        // A tiltak status change for A's tiltak, filed as B's.
        $this->assertRefused(fn () => DB::table('improvement_action_status_changes')->insert([
            'customer_id' => $customerB->id, 'improvement_action_id' => $a['action']->id, 'from_status' => 'planned',
            'to_status' => 'in_progress', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        // A judgement of A's completion, attached to B's tiltak or filed as B's.
        foreach ([[$customerB->id, $actionB->id], [$customerB->id, $a['action']->id], [$a['customer']->id, $actionB->id]] as [$customerId, $actionId]) {
            $this->assertRefused(fn () => DB::table('improvement_action_verifications')->insert([
                'customer_id' => $customerId, 'improvement_action_id' => $actionId,
                'completion_status_change_id' => $a['completion']->id, 'result' => 'effective', 'note' => 'Kapret',
                'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // B's case linked to A's process, under either customer.
        foreach ([$customerB->id, $a['customer']->id] as $customerId) {
            $this->assertRefused(fn () => DB::table('improvement_case_processes')->insert([
                'customer_id' => $customerId, 'improvement_case_id' => $caseB->id, 'quality_process_id' => $a['process']->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    private function assertActionRoutesNotFound(User $user, string $caseUrl, int $actionId): void
    {
        $url = "{$caseUrl}/actions/{$actionId}";

        $this->actingAs($user)->patch($url, $this->actionPayload($user, 'Kapret'))->assertNotFound();
        $this->actingAs($user)->delete($url)->assertNotFound();
        $this->actingAs($user)->post("{$url}/start")->assertNotFound();
        $this->actingAs($user)->post("{$url}/complete", ['completion_note' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->post("{$url}/cancel", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->post("{$url}/reopen", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->post("{$url}/verify", ['result' => 'effective', 'note' => 'Kapret'])->assertNotFound();
    }

    /**
     * Customer A, built through its own routes: a case under arbeid with Årsak og bakgrunn, a
     * Kvalitet link, a tiltak completed and judged Ikke effektivt, and an overdue tiltak.
     *
     * @return array<string, mixed>
     */
    private function customerWithFullCase(): array
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, self::ALL, [$area]);
        $case = $this->improvementCase($customer, $area, 'Hemmelig avvik', $user);
        $process = $this->process($customer, $user);
        $base = "/app/improvements/{$case->id}";

        $this->actingAs($user)->post("{$base}/start")->assertSessionHasNoErrors();
        $this->actingAs($user)->put("{$base}/cause", ['cause_analysis' => 'Hemmelig årsak'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put("{$base}/processes/{$process->id}", ['whole_process' => false, 'activity_keys' => ['kontroll']])->assertSessionHasNoErrors();

        $action = $this->improvementAction($case, 'Hemmelig tiltak', $user);
        $this->actingAs($user)->post("{$base}/actions/{$action->id}/complete", ['completion_note' => 'Gjort'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("{$base}/actions/{$action->id}/verify", ['result' => 'not_effective', 'note' => 'Virket ikke'])->assertSessionHasNoErrors();
        $this->improvementAction($case, 'Hemmelig forsinket tiltak', null, '2020-01-01');

        $this->assertSame(1, ImprovementCaseActivity::query()->where('improvement_case_id', $case->id)->count());

        return [
            'customer' => $customer,
            'area' => $area,
            'user' => $user,
            'case' => $case,
            'process' => $process,
            'action' => $action,
            'completion' => ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->where('to_status', 'completed')->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(array $a): array
    {
        $caseId = $a['case']->id;
        $actionIds = ImprovementAction::query()->where('improvement_case_id', $caseId)->pluck('id');

        return [
            'case' => ImprovementCase::query()->whereKey($caseId)->first(['title', 'status', 'cause_analysis', 'owner_user_id', 'business_area_id'])->toArray(),
            'case_history' => ImprovementCaseStatusChange::query()->where('improvement_case_id', $caseId)->count(),
            'processes' => ImprovementCaseProcess::query()->where('improvement_case_id', $caseId)->count(),
            'activities' => ImprovementCaseActivity::query()->where('improvement_case_id', $caseId)->count(),
            'actions' => ImprovementAction::query()->where('improvement_case_id', $caseId)->orderBy('id')->get(['title', 'status', 'owner_user_id'])->toArray(),
            'action_history' => ImprovementActionStatusChange::query()->whereIn('improvement_action_id', $actionIds)->count(),
            'verifications' => ImprovementActionVerification::query()->whereIn('improvement_action_id', $actionIds)->count(),
        ];
    }

    private function process(Customer $customer, User $actor): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Hemmelig prosess',
            'status' => QualityItem::STATUS_DRAFT,
        ]);

        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, [
            'lanes' => [['key' => 'drift', 'label' => 'Drift']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'drift', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'kontroll', 'lane' => 'drift', 'type' => 'step', 'label' => 'Kontroll'],
                ['key' => 'slutt', 'lane' => 'drift', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [['from' => 'start', 'to' => 'kontroll'], ['from' => 'kontroll', 'to' => 'slutt']],
        ], QualityProcessBlueprint::SOURCE_MANUAL, $actor);

        return $process;
    }

    private function assertRefused(callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a record that mixes two customers.');
    }
}
