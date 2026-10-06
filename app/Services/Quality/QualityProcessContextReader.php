<?php

namespace App\Services\Quality;

use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How another module reads Kvalitet's processes and their activities, live, for a link it owns.
 *
 * Risiko (a risk belongs in a process or an activity), Mål og KPI (a KPI measures one) and
 * Etterlevelse og revisjon (a requirement is met through a process or a control) all store only ids — a process id and, for an activity, the step's key in that process's working
 * flow. Everything shown about them is read here when the page is drawn: title, code, step label,
 * role. Nothing is ever copied.
 *
 * What counts as a process (a `process` QualityItem of the same customer) and as an activity (a
 * `step` node in its working blueprint; start, end and decision are markers) is decided once, here.
 *
 * The gate is canRead(): Kvalitet read access by the rules Kvalitet itself applies. A page that
 * cannot pass it says nothing about the linked context at all.
 *
 * Read-only, and only ever called from the linking module's side. Kvalitet never calls into this
 * class, and nothing here reads the link tables of the modules that use it.
 */
class QualityProcessContextReader
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /**
     * Whether the user may read Kvalitet — its controls, processes and activities: the `quality`
     * module for the customer and quality.view for them. No other permission implies it.
     */
    public function canRead(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->entitlements->hasModule($customer, 'quality')
            && $this->permissions->has($user, CustomerPermissionCatalog::QUALITY_VIEW);
    }

    /**
     * The customer's processes, by title.
     *
     * @return Builder<QualityItem>
     */
    public function processesQuery(int $customerId): Builder
    {
        return QualityItem::query()
            ->where('quality_items.customer_id', $customerId)
            ->where('quality_items.quality_type', QualityItem::TYPE_PROCESS)
            ->orderBy('quality_items.title')
            ->orderBy('quality_items.id');
    }

    /**
     * The customer's controls, by title.
     *
     * @return Builder<QualityItem>
     */
    public function controlsQuery(int $customerId): Builder
    {
        return QualityItem::query()
            ->where('quality_items.customer_id', $customerId)
            ->where('quality_items.quality_type', QualityItem::TYPE_CONTROL)
            ->orderBy('quality_items.title')
            ->orderBy('quality_items.id');
    }

    /**
     * The evidence recorded on controls in Kvalitet — the `evidence` capacity of
     * quality_item_documents — keyed by control id, oldest first, in the shape the control's own
     * page shows it. Read-only: nothing here adds, changes or judges evidence.
     *
     * @param  list<int>  $controlIds
     * @return array<int, list<array{id: int, title: ?string, description: ?string, filename: ?string, download_url: ?string, document_removed: bool, added_by: ?string, added_at: ?string}>>
     */
    public function controlEvidence(int $customerId, array $controlIds): array
    {
        if ($controlIds === []) {
            return [];
        }

        $rows = [];

        QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $controlIds)
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->with(['document:id,original_filename', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->each(function (QualityItemDocument $evidence) use (&$rows): void {
                $rows[(int) $evidence->quality_item_id][] = [
                    'id' => (int) $evidence->id,
                    // Evidence attached as a plain file before evidence had a name falls back to it.
                    'title' => $evidence->title ?? $evidence->document?->original_filename,
                    'description' => $evidence->note,
                    'filename' => $evidence->document?->original_filename,
                    'download_url' => $evidence->document !== null
                        ? route('app.wiki.sources.download', ['document' => $evidence->document->id])
                        : null,
                    'document_removed' => $evidence->document_removed_at !== null,
                    'added_by' => $evidence->createdBy?->name,
                    'added_at' => $evidence->created_at?->toDateString(),
                ];
            });

        return $rows;
    }

    /**
     * The working blueprints of the given processes, keyed by process id.
     *
     * @param  list<int>  $processIds
     * @return Collection<int, QualityProcessBlueprint>
     */
    public function blueprints(int $customerId, array $processIds): Collection
    {
        return QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $processIds)
            ->get()
            ->keyBy('quality_item_id');
    }

    /**
     * The activities of a flow — its steps, in flow order — keyed by node key. Start, end and
     * decision nodes are markers in the flow, not work that can be linked to.
     *
     * @return array<string, array{key: string, label: string, role: ?string}>
     */
    public function steps(?QualityProcessBlueprint $blueprint): array
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

    /**
     * A process of the customer, or null — the same answer for a foreign id, a non-process item and
     * a missing one, so a form cannot be used to probe other tenants' ids.
     */
    public function findProcess(int $customerId, int $processId): ?QualityItem
    {
        return $this->processesQuery($customerId)->whereKey($processId)->first();
    }

    /**
     * Linked processes and activities, read live and grouped by process. A process appears when it
     * is linked as a whole, or when one of its activities is; an activity whose key is no longer a
     * step in the flow is left out (links are pruned on save — this only guards a read in between).
     *
     * @param  list<int>  $wholeProcessIds
     * @param  iterable<array{id: int, process_id: int, key: string}>  $activityLinks  in display order
     * @return list<array{id: int, title: string, code: ?string, url: string, whole_process: bool, activities: list<array{id: int, key: string, label: string, role: ?string, url: string}>}>
     */
    public function groupedContext(int $customerId, array $wholeProcessIds, iterable $activityLinks): array
    {
        $activityLinks = collect($activityLinks);
        $processIds = array_values(array_unique([...$wholeProcessIds, ...$activityLinks->pluck('process_id')->map(fn ($id): int => (int) $id)->all()]));

        if ($processIds === []) {
            return [];
        }

        $processes = $this->processesQuery($customerId)->whereIn('quality_items.id', $processIds)->get();
        $blueprints = $this->blueprints($customerId, $processes->pluck('id')->all());

        $rows = [];

        foreach ($processes as $process) {
            $steps = $this->steps($blueprints->get($process->id));
            $activities = [];

            foreach ($activityLinks->where('process_id', (int) $process->id) as $link) {
                $step = $steps[(string) $link['key']] ?? null;

                if ($step === null) {
                    continue;
                }

                $activities[] = [
                    'id' => (int) $link['id'],
                    'key' => $step['key'],
                    'label' => $step['label'],
                    'role' => $step['role'],
                    'url' => $this->activityUrl((int) $process->id, $step['key']),
                ];
            }

            $wholeProcess = in_array((int) $process->id, $wholeProcessIds, true);

            if (! $wholeProcess && $activities === []) {
                continue;
            }

            $rows[] = [
                'id' => (int) $process->id,
                'title' => (string) $process->title,
                'code' => $process->code,
                'url' => $this->processUrl((int) $process->id),
                'whole_process' => $wholeProcess,
                'activities' => $activities,
            ];
        }

        return $rows;
    }

    /**
     * The customer's processes and the activities of each, to choose from, marked with what is
     * already linked.
     *
     * @param  list<int>  $linkedProcessIds
     * @param  list<string>  $linkedActivities  «processId|activityKey»
     * @return list<array{id: int, title: string, code: ?string, linked: bool, activities: list<array{key: string, label: string, role: ?string, linked: bool}>}>
     */
    public function options(int $customerId, array $linkedProcessIds, array $linkedActivities): array
    {
        $processes = $this->processesQuery($customerId)->get();
        $blueprints = $this->blueprints($customerId, $processes->pluck('id')->all());

        return $processes->map(fn (QualityItem $process): array => [
            'id' => (int) $process->id,
            'title' => (string) $process->title,
            'code' => $process->code,
            'linked' => in_array((int) $process->id, $linkedProcessIds, true),
            'activities' => array_values(array_map(fn (array $step): array => [
                'key' => $step['key'],
                'label' => $step['label'],
                'role' => $step['role'],
                'linked' => in_array($process->id.'|'.$step['key'], $linkedActivities, true),
            ], $this->steps($blueprints->get($process->id)))),
        ])->all();
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
        $processes = $this->processesQuery($customerId)->whereIn('quality_items.id', $processIds)->get()->keyBy('id');
        $blueprints = $this->blueprints($customerId, $processIds);
        $steps = [];
        $rows = [];

        foreach ($placements as $placement) {
            $process = $processes->get((int) $placement->quality_item_id);

            if ($process === null) {
                continue;
            }

            $steps[$process->id] ??= $this->steps($blueprints->get($process->id));
            $step = $steps[$process->id][(string) $placement->activity_key] ?? null;

            if ($step === null) {
                continue;
            }

            $label = $step['label'] !== '' ? $process->title.' › '.$step['label'] : (string) $process->title;
            $rows[(int) $placement->control_item_id][] = $label;
        }

        return array_map(fn (array $labels): array => array_values(array_unique($labels)), $rows);
    }

    public function processUrl(int $processId): string
    {
        return route('app.quality.items.show', ['item' => $processId]);
    }

    public function activityUrl(int $processId, string $activityKey): string
    {
        // Straight to the activity in the flow, panel open — see QualityController::show().
        return $this->processUrl($processId).'?'.http_build_query([
            'tab' => 'flow',
            'activity' => $activityKey,
        ]);
    }
}
