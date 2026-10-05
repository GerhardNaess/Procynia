<?php

namespace App\Services\Risk;

use App\Models\QualityActivityControl;
use App\Models\Risk;
use App\Models\RiskActivity;
use App\Models\RiskProcess;
use App\Models\User;
use App\Services\Quality\QualityProcessContextReader;
use Illuminate\Validation\ValidationException;

/**
 * Risiko → hører hjemme i → Kvalitet-prosess eller -aktivitet, from the risk's side only.
 *
 * A risk may concern whole processes and/or concrete activities in them, any number of each. The
 * process is the existing `process` QualityItem and the activity a step in its working flow; this
 * service stores which ones and nothing more. Titles, step labels and roles are read from Kvalitet
 * whenever the risk page is shown, never copied.
 *
 * Access follows Risiko → Kontroll (RiskControlService): the caller has reached the risk through
 * RiskAccessService::visibleRisks(), changing links takes risk.edit in its area, and anything shown
 * about a process takes Kvalitet read access (RiskControlService::canReadQuality). Without that the
 * page says nothing about context at all — not even that there is some.
 *
 * What a process and an activity are, and how they are read, is QualityProcessContextReader's —
 * shared with Mål og KPI. Kvalitet never calls into this service. When a step leaves a flow the
 * link is removed by QualityActivityLinkCleanup, which returns nothing to Kvalitet.
 */
class RiskQualityContextService
{
    public function __construct(
        private readonly QualityProcessContextReader $quality,
    ) {}

    /**
     * The processes and activities the risk is linked to, read live from Kvalitet and grouped by
     * process. A process appears when it is linked as a whole, or when one of its activities is.
     *
     * @return list<array{id: int, title: string, code: ?string, url: string, whole_process: bool, activities: list<array{id: int, key: string, label: string, role: ?string, url: string}>}>
     */
    public function linkedContext(Risk $risk): array
    {
        $customerId = (int) $risk->customer_id;

        $processLinks = RiskProcess::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $customerId)
            ->pluck('quality_item_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $activityLinks = RiskActivity::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get(['id', 'quality_item_id', 'activity_key'])
            ->map(fn (RiskActivity $link): array => [
                'id' => (int) $link->id,
                'process_id' => (int) $link->quality_item_id,
                'key' => (string) $link->activity_key,
            ]);

        return $this->quality->groupedContext($customerId, $processLinks, $activityLinks);
    }

    /**
     * The customer's processes and the activities of each, to choose from.
     *
     * @return list<array{id: int, title: string, code: ?string, linked: bool, activities: list<array{key: string, label: string, role: ?string, linked: bool}>}>
     */
    public function contextOptions(Risk $risk): array
    {
        $linkedProcesses = RiskProcess::query()->where('risk_id', $risk->id)->pluck('quality_item_id')->map(fn ($id): int => (int) $id)->all();
        $linkedActivities = RiskActivity::query()->where('risk_id', $risk->id)->get(['quality_item_id', 'activity_key'])
            ->map(fn (RiskActivity $link): string => $link->quality_item_id.'|'.$link->activity_key)
            ->all();

        return $this->quality->options((int) $risk->customer_id, $linkedProcesses, $linkedActivities);
    }

    /**
     * Link a process, or one activity in it. Anything that is not a process of the risk's own
     * customer, or an activity in that process's flow, is refused with the same answer, so the form
     * cannot be used to probe other tenants' ids.
     */
    public function link(User $user, Risk $risk, int $processId, ?string $activityKey): void
    {
        $customerId = (int) $risk->customer_id;
        $process = $this->quality->findProcess($customerId, $processId);

        if ($process === null) {
            throw ValidationException::withMessages([
                'quality_item_id' => __('procynia.risk.validation.process_not_allowed'),
            ]);
        }

        if ($activityKey === null) {
            RiskProcess::query()->firstOrCreate(
                ['risk_id' => $risk->id, 'quality_item_id' => $process->id],
                ['customer_id' => $customerId, 'created_by' => $user->id],
            );

            return;
        }

        $steps = $this->quality->steps($this->quality->blueprints($customerId, [(int) $process->id])->get($process->id));

        if (! isset($steps[$activityKey])) {
            throw ValidationException::withMessages([
                'activity_key' => __('procynia.risk.validation.activity_not_allowed'),
            ]);
        }

        RiskActivity::query()->firstOrCreate(
            ['risk_id' => $risk->id, 'quality_item_id' => $process->id, 'activity_key' => $activityKey],
            ['customer_id' => $customerId, 'created_by' => $user->id],
        );
    }

    /** Remove the whole-process link. The process, and links to its activities, are left as they are. */
    public function unlinkProcess(Risk $risk, int $processId): bool
    {
        return RiskProcess::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->where('quality_item_id', $processId)
            ->delete() > 0;
    }

    /** Remove one activity link, by its id on this risk. */
    public function unlinkActivity(Risk $risk, int $linkId): bool
    {
        return RiskActivity::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->whereKey($linkId)
            ->delete() > 0;
    }

    /**
     * Where controls sit in Kvalitet's flows: «Prosess › Aktivitet» for each placement, keyed by
     * control id, read live like everything else here. A placement whose activity is no longer in
     * the flow is left out.
     *
     * @param  list<int>  $controlIds
     * @return array<int, list<string>>
     */
    public function controlPlacements(int $customerId, array $controlIds): array
    {
        if ($controlIds === []) {
            return [];
        }

        $placements = QualityActivityControl::query()
            ->where('customer_id', $customerId)
            ->whereIn('control_item_id', $controlIds)
            ->orderBy('id')
            ->get(['quality_item_id', 'activity_key', 'control_item_id']);

        $processIds = $placements->pluck('quality_item_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $processes = $this->quality->processesQuery($customerId)->whereIn('quality_items.id', $processIds)->get()->keyBy('id');
        $blueprints = $this->quality->blueprints($customerId, $processIds);
        $steps = [];
        $rows = [];

        foreach ($placements as $placement) {
            $process = $processes->get((int) $placement->quality_item_id);

            if ($process === null) {
                continue;
            }

            $steps[$process->id] ??= $this->quality->steps($blueprints->get($process->id));
            $step = $steps[$process->id][(string) $placement->activity_key] ?? null;

            if ($step === null) {
                continue;
            }

            $label = $step['label'] !== '' ? $process->title.' › '.$step['label'] : (string) $process->title;
            $rows[(int) $placement->control_item_id][] = $label;
        }

        return array_map(fn (array $labels): array => array_values(array_unique($labels)), $rows);
    }
}
