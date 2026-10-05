<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiActivity;
use App\Models\KpiProcess;
use App\Models\User;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KPI → måler → Kvalitet-prosess eller -aktivitet, from the KPI's side only.
 *
 * A KPI may measure whole processes and/or concrete activities in them, any number of each — the
 * same semantics as Risiko → Kontekst. An activity link names its process, so a process with linked
 * activities needs no process link of its own; the process link means «the process as a whole».
 * Only ids are stored. Titles, step labels and roles are read live through
 * QualityProcessContextReader, the reader Risiko uses too.
 *
 * Access: the caller has reached the KPI through ObjectiveAccessService. Changing links is changing
 * the KPI (objective.edit in its objective's area); anything shown about a process takes Kvalitet
 * read access (QualityProcessContextReader::canRead), which objective.* never implies. Without it
 * the page says nothing about context at all — not even that there is some.
 *
 * Kvalitet never calls into this service. Links to a step that leaves the flow are removed by
 * QualityActivityLinkCleanup; a removed process takes its links along in the database.
 */
class KpiQualityContextService
{
    public function __construct(
        private readonly QualityProcessContextReader $quality,
    ) {}

    public function canReadQuality(User $user): bool
    {
        return $this->quality->canRead($user);
    }

    /**
     * The processes and activities the KPI measures, grouped by process.
     *
     * @return list<array{id: int, title: string, code: ?string, url: string, whole_process: bool, activities: list<array{id: int, key: string, label: string, role: ?string, url: string}>}>
     */
    public function linkedContext(Kpi $kpi): array
    {
        [$processIds, $activityLinks] = $this->links($kpi);

        return $this->quality->groupedContext((int) $kpi->customer_id, $processIds, $activityLinks);
    }

    /**
     * Every process of the customer with its activities, marked with what the KPI already measures.
     *
     * @return list<array{id: int, title: string, code: ?string, linked: bool, activities: list<array{key: string, label: string, role: ?string, linked: bool}>}>
     */
    public function contextOptions(Kpi $kpi): array
    {
        [$processIds, $activityLinks] = $this->links($kpi);

        return $this->quality->options(
            (int) $kpi->customer_id,
            $processIds,
            $activityLinks->map(fn (array $link): string => $link['process_id'].'|'.$link['key'])->all(),
        );
    }

    /**
     * Endre kobling for one process: what the KPI measures in it afterwards — the process as a
     * whole, and/or the given activities. Anything not chosen is unlinked; choosing nothing removes
     * the process from the KPI. A process outside the KPI's customer, or a key that is not a step in
     * that process's flow, is refused with the same answer as a missing one.
     *
     * @param  list<string>  $activityKeys
     */
    public function syncProcess(User $user, Kpi $kpi, int $processId, bool $wholeProcess, array $activityKeys): void
    {
        $customerId = (int) $kpi->customer_id;
        $process = $this->quality->findProcess($customerId, $processId);

        if ($process === null) {
            throw ValidationException::withMessages([
                'quality_process_id' => __('procynia.objectives.kpi.context.validation.process_not_allowed'),
            ]);
        }

        $activityKeys = array_values(array_unique($activityKeys));
        $steps = $this->quality->steps($this->quality->blueprints($customerId, [(int) $process->id])->get($process->id));

        if (array_diff($activityKeys, array_keys($steps)) !== []) {
            throw ValidationException::withMessages([
                'activity_keys' => __('procynia.objectives.kpi.context.validation.activity_not_allowed'),
            ]);
        }

        DB::transaction(function () use ($user, $kpi, $customerId, $process, $wholeProcess, $activityKeys): void {
            $processLink = KpiProcess::query()->where('kpi_id', $kpi->id)->where('quality_process_id', $process->id);

            if ($wholeProcess) {
                KpiProcess::query()->firstOrCreate(
                    ['kpi_id' => $kpi->id, 'quality_process_id' => $process->id],
                    ['customer_id' => $customerId, 'created_by' => $user->id],
                );
            } else {
                $processLink->delete();
            }

            KpiActivity::query()
                ->where('kpi_id', $kpi->id)
                ->where('quality_process_id', $process->id)
                ->whereNotIn('activity_key', $activityKeys)
                ->delete();

            foreach ($activityKeys as $key) {
                KpiActivity::query()->firstOrCreate(
                    ['kpi_id' => $kpi->id, 'quality_process_id' => $process->id, 'activity_key' => $key],
                    ['customer_id' => $customerId, 'created_by' => $user->id],
                );
            }
        });
    }

    /**
     * Berørte prosesser for an objective: the distinct processes its given KPIs measure, as a whole
     * or through an activity, read live. Never stored. The caller passes only KPIs the user can see.
     *
     * @param  Collection<int, Kpi>  $kpis
     * @return list<array{id: int, title: string, code: ?string, url: string}>
     */
    public function processesForKpis(int $customerId, Collection $kpis): array
    {
        $kpiIds = $kpis->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($kpiIds === []) {
            return [];
        }

        $processIds = KpiProcess::query()
            ->where('customer_id', $customerId)
            ->whereIn('kpi_id', $kpiIds)
            ->pluck('quality_process_id')
            ->merge(KpiActivity::query()
                ->where('customer_id', $customerId)
                ->whereIn('kpi_id', $kpiIds)
                ->pluck('quality_process_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['title'],
            'code' => $row['code'],
            'url' => $row['url'],
        ], $this->quality->groupedContext($customerId, $processIds, []));
    }

    /** @return array{0: list<int>, 1: Collection<int, array{id: int, process_id: int, key: string}>} */
    private function links(Kpi $kpi): array
    {
        $processIds = KpiProcess::query()
            ->where('kpi_id', $kpi->id)
            ->where('customer_id', $kpi->customer_id)
            ->pluck('quality_process_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $activityLinks = KpiActivity::query()
            ->where('kpi_id', $kpi->id)
            ->where('customer_id', $kpi->customer_id)
            ->orderBy('id')
            ->get(['id', 'quality_process_id', 'activity_key'])
            ->map(fn (KpiActivity $link): array => [
                'id' => (int) $link->id,
                'process_id' => (int) $link->quality_process_id,
                'key' => (string) $link->activity_key,
            ]);

        return [$processIds, $activityLinks];
    }
}
