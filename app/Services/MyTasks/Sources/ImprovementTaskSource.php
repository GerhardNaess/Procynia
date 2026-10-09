<?php

namespace App\Services\MyTasks\Sources;

use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementAttentionService as Attention;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Avvik og forbedringer: the cases and tiltak assigned to the person that still ask something of
 * them.
 *
 *  - An Åpen or Under arbeid case the person is ansvarlig for is work until it is closed — one task,
 *    due on the case's frist. Its reasons are ImprovementAttentionService's: the frist has passed,
 *    and, for the case owner who steers the case, how many of its completed tiltak still await
 *    effektverifisering or were judged not effective. No new responsibility is invented: the case
 *    owner is the one the module already shows those findings to.
 *  - A planned or Under arbeid tiltak the person is ansvarlig for — one task, due on its frist.
 *
 * Closed and cancelled cases, and completed or cancelled tiltak, are no one's task.
 *
 * ACCESS. ImprovementCaseAccessService: the module, improvement.view, and the case's fagområde
 * (visibleCases()). A tiltak is shown only on a case the person can see.
 *
 * READ, NOT ACT. improvement.edit in the case's fagområde to work on the case or a tiltak;
 * improvement.close to verify (ImprovementActionController's own gates).
 */
class ImprovementTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly ImprovementCaseAccessService $access,
        private readonly Attention $attention,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function module(): string
    {
        return 'improvements';
    }

    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null && $this->entitlements->hasModule($customer, 'improvements') && $this->access->canOpenModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $can = new AreaPermissions($this->access, $user);

        return $this->caseTasks($user, $today, $can)->merge($this->actionTasks($user, $today, $can))->values();
    }

    /** @return Collection<int, MyTask> */
    private function caseTasks(User $user, CarbonImmutable $today, AreaPermissions $can): Collection
    {
        /** @var EloquentCollection<int, ImprovementCase> $cases */
        $cases = $this->access->visibleCases($user)
            ->where('improvement_cases.owner_user_id', $user->id)
            ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
            ->orderBy('improvement_cases.id')
            ->get(['improvement_cases.*']);

        if ($cases->isEmpty()) {
            return collect();
        }

        $findings = $this->attention->findingsForCases($cases, $today);
        $caseReasons = collect($findings['cases'])->mapWithKeys(fn (array $finding): array => [(int) $finding['case']->id => $finding['reasons']]);
        $verification = [];

        foreach ($findings['actions'] as $finding) {
            foreach (array_keys($finding['reasons']) as $key) {
                if (in_array($key, [Attention::AWAITING_VERIFICATION, Attention::NOT_EFFECTIVE], true)) {
                    $verification[(int) $finding['action']->improvement_case_id][$key] = ($verification[(int) $finding['action']->improvement_case_id][$key] ?? 0) + 1;
                }
            }
        }

        return $cases->toBase()->map(function (ImprovementCase $case) use ($user, $today, $caseReasons, $verification, $can): MyTask {
            $id = (int) $case->id;
            $dueOn = $case->due_date?->toDateString();
            $overdue = isset(($caseReasons[$id] ?? [])[Attention::CASE_OVERDUE]);
            $reasons = [[
                'key' => $overdue ? Attention::CASE_OVERDUE : 'case_open',
                'due_on' => $dueOn,
                'overdue' => $overdue,
                'can_act' => $can->allows(CustomerPermissionCatalog::IMPROVEMENT_EDIT, (int) $case->customer_id, (int) $case->business_area_id),
            ]];

            foreach ($verification[$id] ?? [] as $key => $count) {
                $reasons[] = ['key' => $key, 'due_on' => null, 'overdue' => false, 'can_act' => $can->allows(CustomerPermissionCatalog::IMPROVEMENT_CLOSE, (int) $case->customer_id, (int) $case->business_area_id), 'count' => $count];
            }

            return $this->task('improvement-case-'.$id, 'improvement_case', $case, $case->title, route('app.improvements.show', ['caseId' => $id], false), $dueOn, $reasons, $user, $today);
        })->values();
    }

    /** @return Collection<int, MyTask> */
    private function actionTasks(User $user, CarbonImmutable $today, AreaPermissions $can): Collection
    {
        $actions = ImprovementAction::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('owner_user_id', $user->id)
            ->whereIn('status', ImprovementAction::ACTIVE_STATUSES)
            ->whereIn('improvement_case_id', $this->access->visibleCases($user)
                ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
                ->select('improvement_cases.id'))
            ->with('improvementCase')
            ->orderBy('id')
            ->get();

        return $actions->toBase()->map(function (ImprovementAction $action) use ($user, $today, $can): MyTask {
            $case = $action->improvementCase;
            $dueOn = $action->due_date?->toDateString();
            $overdue = $dueOn !== null && CarbonImmutable::parse($dueOn)->lt($today);

            return $this->task(
                'improvement-action-'.$action->id,
                'improvement_action',
                $case,
                $action->title,
                route('app.improvements.show', ['caseId' => $case->id], false).'#improvement-action-'.$action->id,
                $dueOn,
                [[
                    'key' => $overdue ? Attention::ACTION_OVERDUE : 'action_open',
                    'due_on' => $dueOn,
                    'overdue' => $overdue,
                    'can_act' => $can->allows(CustomerPermissionCatalog::IMPROVEMENT_EDIT, (int) $case->customer_id, (int) $case->business_area_id),
                ]],
                $user,
                $today,
                ['action' => ['id' => (int) $action->id]],
            );
        })->values();
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     * @param  array<string, mixed>  $extra
     */
    private function task(string $id, string $type, ImprovementCase $case, string $title, string $url, ?string $dueOn, array $reasons, User $user, CarbonImmutable $today, array $extra = []): MyTask
    {
        return new MyTask(
            id: $id,
            module: $this->module(),
            type: $type,
            title: $title,
            subjectTitle: $case->title,
            assigneeUserId: (int) $user->id,
            actionUrl: $url,
            dueOn: $dueOn !== null ? CarbonImmutable::parse($dueOn) : null,
            overdue: in_array(true, array_column($reasons, 'overdue'), true),
            reasons: $reasons,
            canAct: ! in_array(false, array_column($reasons, 'can_act'), true),
            details: ['case' => ['id' => (int) $case->id, 'title' => $case->title]] + $extra,
            subject: ['prefix' => 'improvement', 'metadata' => ['improvement_case_id' => (int) $case->id]],
        );
    }
}
