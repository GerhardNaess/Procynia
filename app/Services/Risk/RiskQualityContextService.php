<?php

namespace App\Services\Risk;

use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\Risk;
use App\Models\RiskActivity;
use App\Models\RiskProcess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 * Kvalitet never calls into this service to read. The one thing it triggers is prune(), when a
 * flow is saved or removed, and that only deletes links; it returns nothing to Kvalitet.
 */
class RiskQualityContextService
{
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
            ->get(['id', 'quality_item_id', 'activity_key']);

        $processIds = array_values(array_unique([...$processLinks, ...$activityLinks->pluck('quality_item_id')->map(fn ($id): int => (int) $id)->all()]));

        if ($processIds === []) {
            return [];
        }

        $processes = $this->processesQuery($customerId)->whereIn('quality_items.id', $processIds)->get();
        $blueprints = $this->blueprints($customerId, $processes->pluck('id')->all());

        $rows = [];

        foreach ($processes as $process) {
            $steps = $this->steps($blueprints->get($process->id));
            $activities = [];

            foreach ($activityLinks->where('quality_item_id', $process->id) as $link) {
                $step = $steps[(string) $link->activity_key] ?? null;

                // A key no longer in the flow is pruned on save; this only guards a read in between.
                if ($step === null) {
                    continue;
                }

                $activities[] = [
                    'id' => (int) $link->id,
                    'key' => $step['key'],
                    'label' => $step['label'],
                    'role' => $step['role'],
                    'url' => $this->activityUrl((int) $process->id, $step['key']),
                ];
            }

            $wholeProcess = in_array((int) $process->id, $processLinks, true);

            if (! $wholeProcess && $activities === []) {
                continue;
            }

            $rows[] = [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'url' => route('app.quality.items.show', ['item' => $process->id]),
                'whole_process' => $wholeProcess,
                'activities' => $activities,
            ];
        }

        return $rows;
    }

    /**
     * The customer's processes and the activities of each, to choose from.
     *
     * @return list<array{id: int, title: string, code: ?string, linked: bool, activities: list<array{key: string, label: string, role: ?string, linked: bool}>}>
     */
    public function contextOptions(Risk $risk): array
    {
        $customerId = (int) $risk->customer_id;
        $processes = $this->processesQuery($customerId)->get();
        $blueprints = $this->blueprints($customerId, $processes->pluck('id')->all());

        $linkedProcesses = RiskProcess::query()->where('risk_id', $risk->id)->pluck('quality_item_id')->map(fn ($id): int => (int) $id)->all();
        $linkedActivities = RiskActivity::query()->where('risk_id', $risk->id)->get(['quality_item_id', 'activity_key'])
            ->map(fn (RiskActivity $link): string => $link->quality_item_id.'|'.$link->activity_key)
            ->all();

        return $processes->map(fn (QualityItem $process): array => [
            'id' => (int) $process->id,
            'title' => (string) $process->title,
            'code' => $process->code,
            'linked' => in_array((int) $process->id, $linkedProcesses, true),
            'activities' => array_values(array_map(fn (array $step): array => [
                'key' => $step['key'],
                'label' => $step['label'],
                'role' => $step['role'],
                'linked' => in_array($process->id.'|'.$step['key'], $linkedActivities, true),
            ], $this->steps($blueprints->get($process->id)))),
        ])->all();
    }

    /**
     * Link a process, or one activity in it. Anything that is not a process of the risk's own
     * customer, or an activity in that process's flow, is refused with the same answer, so the form
     * cannot be used to probe other tenants' ids.
     */
    public function link(User $user, Risk $risk, int $processId, ?string $activityKey): void
    {
        $customerId = (int) $risk->customer_id;
        $process = $this->processesQuery($customerId)->whereKey($processId)->first();

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

        $steps = $this->steps($this->blueprints($customerId, [(int) $process->id])->get($process->id));

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
     * Drop activity links whose step is no longer in the process's working flow; with no flow at
     * all, every activity link of the process goes. Only links are removed — no risk is touched, and
     * nothing is reported back, so Kvalitet learns nothing from saving a flow.
     */
    public function prune(int $processId, ?QualityProcessBlueprint $blueprint): void
    {
        RiskActivity::query()
            ->where('quality_item_id', $processId)
            ->whereNotIn('activity_key', array_keys($this->steps($blueprint)))
            ->delete();
    }

    /** @return Builder<QualityItem> */
    private function processesQuery(int $customerId): Builder
    {
        return QualityItem::query()
            ->where('quality_items.customer_id', $customerId)
            ->where('quality_items.quality_type', QualityItem::TYPE_PROCESS)
            ->orderBy('quality_items.title')
            ->orderBy('quality_items.id');
    }

    /**
     * @param  list<int>  $processIds
     * @return Collection<int, QualityProcessBlueprint>
     */
    private function blueprints(int $customerId, array $processIds): Collection
    {
        return QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $processIds)
            ->get()
            ->keyBy('quality_item_id');
    }

    /**
     * The activities of a flow — its steps, in flow order — keyed by node key. Start, end and
     * decision nodes are markers in the flow, not work a risk can sit in.
     *
     * @return array<string, array{key: string, label: string, role: ?string}>
     */
    private function steps(?QualityProcessBlueprint $blueprint): array
    {
        if ($blueprint === null) {
            return [];
        }

        $lanes = [];

        foreach ($blueprint->lanes() as $lane) {
            $lanes[(string) ($lane['key'] ?? '')] = trim((string) ($lane['label'] ?? ''));
        }

        $steps = [];

        foreach ($blueprint->nodes() as $node) {
            $key = (string) ($node['key'] ?? '');

            if ($key === '' || ($node['type'] ?? QualityProcessBlueprint::NODE_STEP) !== QualityProcessBlueprint::NODE_STEP) {
                continue;
            }

            $role = $lanes[(string) ($node['lane'] ?? '')] ?? '';

            $steps[$key] = [
                'key' => $key,
                'label' => trim((string) ($node['label'] ?? '')),
                'role' => $role !== '' ? $role : null,
            ];
        }

        return $steps;
    }

    private function activityUrl(int $processId, string $activityKey): string
    {
        // Straight to the activity in the flow, panel open — see QualityController::show().
        return route('app.quality.items.show', ['item' => $processId]).'?'.http_build_query([
            'tab' => 'flow',
            'activity' => $activityKey,
        ]);
    }
}
