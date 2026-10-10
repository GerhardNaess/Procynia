<?php

namespace App\Services\MyTasks;

use App\Models\User;
use App\Services\MyTasks\Sources\ComplianceTaskSource;
use App\Services\MyTasks\Sources\ImprovementTaskSource;
use App\Services\MyTasks\Sources\ManagementReviewTaskSource;
use App\Services\MyTasks\Sources\ObjectiveTaskSource;
use App\Services\MyTasks\Sources\QualityTaskSource;
use App\Services\MyTasks\Sources\RiskTaskSource;
use App\Services\MyTasks\Sources\SupplierTaskSource;
use App\Services\MyTasks\Sources\TenderTaskSource;
use App\Services\MyTasks\Sources\WikiTaskSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Mine oppgaver»: every module's open work for one person, in one list.
 *
 * Gathers and orders, nothing more. There is no task table and no task state: each source reads its
 * module's own rows on every call, so this list cannot disagree with the module it points into. The
 * bell is a different thing — it says something happened, and reading or deleting a notification
 * changes nothing here (docs/notifications-and-tasks-plan.md).
 */
class MyTasksService
{
    /** @var list<MyTaskSource> */
    private array $sources;

    public function __construct(
        TenderTaskSource $tender,
        WikiTaskSource $wiki,
        SupplierTaskSource $suppliers,
        RiskTaskSource $risk,
        ImprovementTaskSource $improvements,
        ComplianceTaskSource $compliance,
        QualityTaskSource $quality,
        ObjectiveTaskSource $objectives,
        ManagementReviewTaskSource $managementReview,
    ) {
        // Module order for equal dates: the rail's order (config/procynia_modules.php).
        $this->sources = [$tender, $wiki, $quality, $improvements, $risk, $objectives, $compliance, $suppliers, $managementReview];
    }

    /**
     * The modules that can contribute tasks to this person at all — for the page's module filter.
     *
     * @return list<string>
     */
    public function availableModules(User $user): array
    {
        return array_values(array_map(
            fn (MyTaskSource $source): string => $source->module(),
            array_filter($this->sources, fn (MyTaskSource $source): bool => $source->isAvailableFor($user)),
        ));
    }

    /**
     * Every open task, overdue first, then by due date, undated last.
     *
     * @return Collection<int, MyTask>
     */
    public function tasksFor(User $user, int $customerId, ?CarbonInterface $today = null): Collection
    {
        // The same answer every source would give, given once: an inactive person, or one asking
        // about another customer, has no work here.
        if (! $user->is_active || (int) $user->customer_id !== $customerId) {
            return collect();
        }

        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $moduleOrder = array_flip(array_map(fn (MyTaskSource $source): string => $source->module(), $this->sources));
        $groupOrder = array_flip(MyTask::GROUPS);

        return collect($this->sources)
            ->flatMap(fn (MyTaskSource $source): Collection => $source->openTasksFor($user, $customerId, $today))
            ->sort(fn (MyTask $a, MyTask $b): int => [
                $groupOrder[$a->group($today)],
                $a->dueOn === null ? 1 : 0,
                $a->dueOn?->toDateString() ?? '',
                $moduleOrder[$a->module] ?? PHP_INT_MAX,
                mb_strtolower($a->title),
                $a->id,
            ] <=> [
                $groupOrder[$b->group($today)],
                $b->dueOn === null ? 1 : 0,
                $b->dueOn?->toDateString() ?? '',
                $moduleOrder[$b->module] ?? PHP_INT_MAX,
                mb_strtolower($b->title),
                $b->id,
            ])
            ->values();
    }

    /**
     * The page's payload: the count, and the tasks in the four groups, each always present so the
     * page can name an empty group or skip it as it likes.
     *
     * `modules` is every module that can give this person tasks, with how many it has now — the
     * page's filter, which therefore never offers a module the person cannot see.
     *
     * @param  Collection<int, MyTask>  $tasks  from tasksFor()
     * @param  list<string>  $modules  from availableModules()
     * @return array{count: int, modules: list<array{key: string, count: int}>, groups: list<array{key: string, tasks: list<array<string, mixed>>}>}
     */
    public function payload(Collection $tasks, ?CarbonInterface $today = null, array $modules = []): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $rows = $tasks->map(fn (MyTask $task): array => $task->toArray($today));

        $perModule = $tasks->countBy(fn (MyTask $task): string => $task->module);

        return [
            'count' => $tasks->count(),
            'modules' => array_map(fn (string $module): array => ['key' => $module, 'count' => (int) ($perModule[$module] ?? 0)], $modules),
            'groups' => array_map(fn (string $group): array => [
                'key' => $group,
                'tasks' => $rows->where('group', $group)->values()->all(),
            ], MyTask::GROUPS),
        ];
    }
}
