<?php

namespace App\Services\MyTasks\Sources;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use App\Services\Objectives\KpiMeasurementSchedule;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Objectives\ObjectiveAttentionService as Attention;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Mål og KPI: the objectives and KPIs the person is ansvarlig for that ask something of them.
 *
 *  - An active objective the person owns whose måldato has passed (ObjectiveAttentionService).
 *  - An active KPI under an active objective whose ansvarlig is the person — its own owner, or the
 *    objective's when it has none, the module's own fallback — that is off target, is missing a
 *    measurement, or has a measurement due: a period has ended and its reporting deadline
 *    (KpiPeriods::reportingDeadline, the period end plus the KPI's grace days) is still ahead. That
 *    last one is KpiMeasurementSchedule's «pending», the same state Registrer måling proposes; the
 *    KPI's grace days are its window for «nærmer seg frist».
 *
 * A closed objective or retired KPI is never a task.
 *
 * ACCESS. ObjectiveAccessService: the module, objective.view, and the objective's fagområde.
 * READ, NOT ACT. objective.edit for the objective, objective.measure for a measurement.
 */
class ObjectiveTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly ObjectiveAccessService $access,
        private readonly Attention $attention,
        private readonly KpiMeasurementSchedule $schedule,
        private readonly KpiPeriods $periods,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function module(): string
    {
        return 'objectives';
    }

    public function isAvailableFor(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null && $this->entitlements->hasModule($customer, 'objectives') && $this->access->canOpenModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $userId = (int) $user->id;

        /** @var EloquentCollection<int, Objective> $objectives */
        $objectives = $this->access->visibleObjectives($user)
            ->where('objectives.status', Objective::STATUS_ACTIVE)
            ->where(fn (Builder $query) => $query
                ->where('objectives.owner_user_id', $userId)
                ->orWhereExists(fn ($kpis) => $kpis->selectRaw('1')
                    ->from('kpis')
                    ->whereColumn('kpis.objective_id', 'objectives.id')
                    ->where('kpis.status', Kpi::STATUS_ACTIVE)
                    ->where('kpis.owner_user_id', $userId)))
            ->with(['businessArea:id,name', 'statusChanges'])
            ->orderBy('objectives.id')
            ->get(['objectives.*']);

        if ($objectives->isEmpty()) {
            return collect();
        }

        $findings = $this->attention->findingsForObjectives($objectives, $today);
        $can = new AreaPermissions($this->access, $user);
        $tasks = collect();

        foreach ($findings['objectives'] as $finding) {
            $objective = $finding['objective'];

            if ((int) $objective->owner_user_id !== $userId || ! isset($finding['reasons'][Attention::TARGET_DATE_PASSED])) {
                continue;
            }

            $tasks->push($this->task(
                'objective-'.$objective->id,
                'objective',
                $objective,
                $objective->title,
                route('app.objectives.show', ['objectiveId' => $objective->id], false),
                [[
                    'key' => Attention::TARGET_DATE_PASSED,
                    'due_on' => $objective->target_date?->toDateString(),
                    'overdue' => true,
                    'can_act' => $can->allows(CustomerPermissionCatalog::OBJECTIVE_EDIT, (int) $objective->customer_id, (int) $objective->business_area_id),
                ]],
                $user,
            ));
        }

        $kpiReasons = collect($findings['kpis'])->mapWithKeys(fn (array $finding): array => [(int) $finding['kpi']->id => array_keys($finding['reasons'])]);

        foreach ($this->ownedKpis($objectives, $userId) as $kpi) {
            $kpiTask = $this->kpiTask($kpi, $kpiReasons->get((int) $kpi->id, []), $user, $today, $can);

            if ($kpiTask !== null) {
                $tasks->push($kpiTask);
            }
        }

        return $tasks->values();
    }

    /**
     * The active KPIs under these objectives whose ansvarlig is the person, with what the schedule
     * reads, loaded once.
     *
     * @param  EloquentCollection<int, Objective>  $objectives
     * @return EloquentCollection<int, Kpi>
     */
    private function ownedKpis(EloquentCollection $objectives, int $userId): EloquentCollection
    {
        $byId = $objectives->keyBy('id');

        $kpis = Kpi::query()
            ->where('customer_id', (int) $objectives->first()->customer_id)
            ->whereIn('objective_id', $objectives->modelKeys())
            ->where('status', Kpi::STATUS_ACTIVE)
            ->with('statusChanges')
            ->orderBy('id')
            ->get()
            ->filter(fn (Kpi $kpi): bool => (int) ($kpi->owner_user_id ?? $byId->get($kpi->objective_id)?->owner_user_id) === $userId)
            ->each(fn (Kpi $kpi) => $kpi->setRelation('objective', $byId->get($kpi->objective_id)))
            ->values();

        $measurements = $kpis->isEmpty() ? collect() : KpiMeasurement::query()
            ->whereIn('kpi_id', $kpis->modelKeys())
            ->whereNull('withdrawn_at')
            ->get()
            ->groupBy('kpi_id');

        return $kpis->each(fn (Kpi $kpi) => $kpi->setRelation('measurements', $measurements->get($kpi->id, collect())));
    }

    /** @param  list<string>  $attentionKeys */
    private function kpiTask(Kpi $kpi, array $attentionKeys, User $user, CarbonImmutable $today, AreaPermissions $can): ?MyTask
    {
        $schedule = $this->schedule->for($kpi, $kpi->measurements, $today);
        $grace = (int) $kpi->reporting_grace_days;
        $canMeasure = $can->allows(CustomerPermissionCatalog::OBJECTIVE_MEASURE, (int) $kpi->customer_id, (int) $kpi->objective->business_area_id);
        $reasons = [];

        if ($schedule->measurementMissing()) {
            $period = $schedule->oldestMissing();
            $reasons[] = [
                'key' => Attention::MEASUREMENT_MISSING,
                'due_on' => $this->periods->reportingDeadline($period, $grace)->toDateString(),
                'overdue' => true,
                'can_act' => $canMeasure,
                'count' => count($schedule->missing),
            ];
        } elseif ($schedule->measuredNow && $schedule->pending !== []) {
            $reasons[] = [
                'key' => 'measurement_due',
                'due_on' => $this->periods->reportingDeadline($schedule->pending[0], $grace)->toDateString(),
                'overdue' => false,
                'can_act' => $canMeasure,
            ];
        }

        if (in_array(Attention::KPI_OFF_TARGET, $attentionKeys, true)) {
            $reasons[] = ['key' => Attention::KPI_OFF_TARGET, 'due_on' => null, 'overdue' => false, 'can_act' => $canMeasure];
        }

        if ($reasons === []) {
            return null;
        }

        return $this->task(
            'kpi-'.$kpi->id,
            'kpi',
            $kpi->objective,
            $kpi->title,
            route('app.objectives.kpis.show', ['objectiveId' => $kpi->objective_id, 'kpiId' => $kpi->id], false),
            $reasons,
            $user,
            ['kpi' => ['id' => (int) $kpi->id, 'title' => $kpi->title]],
            // Due once the period ends, by its deadline: the KPI's own grace days are the window.
            max(1, $grace),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     * @param  array<string, mixed>  $extra
     */
    private function task(string $id, string $type, Objective $objective, string $title, string $url, array $reasons, User $user, array $extra = [], ?int $dueSoonDays = null): MyTask
    {
        $dates = array_values(array_filter(array_column($reasons, 'due_on')));
        sort($dates);

        return new MyTask(
            id: $id,
            module: $this->module(),
            type: $type,
            title: $title,
            subjectTitle: $objective->title,
            assigneeUserId: (int) $user->id,
            actionUrl: $url,
            dueOn: $dates !== [] ? CarbonImmutable::parse($dates[0]) : null,
            overdue: in_array(true, array_column($reasons, 'overdue'), true),
            reasons: $reasons,
            canAct: ! in_array(false, array_column($reasons, 'can_act'), true),
            details: ['objective' => ['id' => (int) $objective->id, 'title' => $objective->title]] + $extra,
            dueSoonDays: $dueSoonDays,
            subject: ['prefix' => 'objective', 'metadata' => ['objective_id' => (int) $objective->id]],
        );
    }
}
