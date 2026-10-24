<?php

namespace App\Services\Api\Presenters;

use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Services\Quality\QualityAttentionService;
use App\Services\Quality\QualityProcessContextReader;
use App\Services\Quality\QualityToolService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Kvalitet as the external API presents it: structured fields only (D8), and of a process flow only
 * the approved version (D15).
 *
 * In: the register's items (type, title, code, status, ansvarlig, review dates) and their attention
 * codes; for controls also the frequency, how much evidence is registered and the tools from the
 * library; for one item its relations, its publication (approved or not, revision number, when) and,
 * for a process, its approved flow and the controls placed on it. Out: purpose, steps, inputs and
 * outputs, checklists, the control's criterion, method and responsibility, the flow's node
 * descriptions, evidence and tool descriptions, files and downloads, every person who added,
 * approved or withdrew something, the Wiki links and web routes.
 *
 * The approved flow is the latest QualityProcessRevision (QualityProcessBlueprintService::
 * latestRevision()), and only while the process is not retired. Nothing is read from the working
 * version (QualityProcessBlueprint) for what is shown: not its flow, not its activity names, not
 * whether it differs from the approved one (has_unpublished_changes). An activity or placement that
 * exists only in the working version is left out, as if it were not there. The attention code
 * controls_without_activity is never given (D17): Kvalitet judges it against the working version.
 *
 * The attention codes are QualityAttentionService's, the tools QualityToolService's, the activities
 * of a flow QualityProcessContextReader::steps().
 */
class QualityPresenter
{
    /** The finding left out (D17): it is judged against the working version of the process flows. */
    public const FLOW_DEPENDENT_FINDINGS = [QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY];

    public const PUBLICATION_APPROVED = 'approved';

    public const PUBLICATION_NOT_APPROVED = 'not_approved';

    public const PUBLICATION_RETIRED = 'retired';

    public function __construct(
        private readonly QualityAttentionService $attention,
        private readonly QualityToolService $tools,
        private readonly QualityProcessContextReader $reader,
    ) {}

    /**
     * @param  Collection<int, QualityItem>  $items  of one customer, with owner loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $items, int $customerId): array
    {
        $codes = $this->attentionByItem($customerId);

        return $items->map(fn (QualityItem $item): array => $this->row($item, $codes[(int) $item->id] ?? []))->values()->all();
    }

    /**
     * Controls, with what the control register shows about them that is not free text.
     *
     * @param  Collection<int, QualityItem>  $controls  of one customer, with owner and controlDetail loaded
     * @return list<array<string, mixed>>
     */
    public function controlRows(Collection $controls, int $customerId): array
    {
        $codes = $this->attentionByItem($customerId);
        // Active evidence only, as the control register counts it: withdrawn evidence is history.
        $evidence = QualityItemDocument::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $controls->modelKeys() ?: [0])
            ->where('relation_type', QualityItemDocument::RELATION_TYPE_EVIDENCE)
            ->whereNull('withdrawn_at')
            ->selectRaw('quality_item_id, count(*) as evidence_count, max(created_at) as latest_at')
            ->groupBy('quality_item_id')
            ->get()
            ->keyBy('quality_item_id');

        return $controls->map(function (QualityItem $control) use ($codes, $evidence, $customerId): array {
            $registered = $evidence->get($control->id);

            return $this->row($control, $codes[(int) $control->id] ?? []) + [
                'frequency' => $control->controlDetail?->frequency,
                'evidence' => [
                    'count' => (int) ($registered->evidence_count ?? 0),
                    'latest_added_at' => $registered?->latest_at !== null ? CarbonImmutable::parse($registered->latest_at)->utc()->toIso8601ZuluString() : null,
                ],
                'tools' => array_map(fn (array $tool): array => [
                    'id' => $tool['id'],
                    'title' => $tool['title'],
                    'category' => $tool['category'],
                ], $this->tools->forControl($customerId, $control)),
            ];
        })->values()->all();
    }

    /**
     * Processes linked from another module, with the linked activities named from the approved flow
     * only. Which links exist is the linking module's own answer (groupedContext()); their names and
     * roles in that answer come from the working version and are not used. An activity not in the
     * approved flow — or in a process without one — is left out, and a process left with nothing
     * linked is left out with it.
     *
     * @param  list<array<string, mixed>>  $grouped  from QualityProcessContextReader::groupedContext()
     * @return list<array{id: int, title: string, code: ?string, whole_process: bool, activity_count: int, activities: list<array{key: string, label: string, role: ?string}>}>
     */
    public function linkedProcesses(int $customerId, array $grouped): array
    {
        $approved = $this->approvedSteps($customerId, array_column($grouped, 'id'));
        $rows = [];

        foreach ($grouped as $process) {
            $steps = $approved[(int) $process['id']] ?? [];
            $activities = [];

            foreach ($process['activities'] as $activity) {
                if (isset($steps[(string) $activity['key']])) {
                    $activities[] = $steps[(string) $activity['key']];
                }
            }

            if (! $process['whole_process'] && $activities === []) {
                continue;
            }

            $rows[] = [
                'id' => $process['id'],
                'title' => $process['title'],
                'code' => $process['code'],
                'whole_process' => $process['whole_process'],
                'activity_count' => count($activities),
                'activities' => $activities,
            ];
        }

        return $rows;
    }

    /**
     * One item (KVA-02): its register row, relations and publication, and for a process its approved
     * flow and the controls placed on it; for a control where it is placed in approved flows.
     *
     * @param  QualityItem  $item  of the identity's customer, with owner loaded
     * @return array<string, mixed>
     */
    public function detail(QualityItem $item): array
    {
        $customerId = (int) $item->customer_id;
        $revision = $item->quality_type === QualityItem::TYPE_PROCESS ? $this->approvedRevisions($customerId, [(int) $item->id])->get($item->id) : null;
        $detail = $this->rows(new Collection([$item]), $customerId)[0] + [
            'relations' => $this->relations($customerId, (int) $item->id),
        ];

        if ($item->quality_type === QualityItem::TYPE_PROCESS) {
            $publication = match (true) {
                $item->status === QualityItem::STATUS_RETIRED => self::PUBLICATION_RETIRED,
                $revision === null => self::PUBLICATION_NOT_APPROVED,
                default => self::PUBLICATION_APPROVED,
            };
            $current = $publication === self::PUBLICATION_APPROVED ? $revision : null;

            $detail += [
                'publication' => [
                    'state' => $publication,
                    'revision_number' => $revision?->revision_number,
                    'approved_at' => $revision?->approved_at?->utc()->toIso8601ZuluString(),
                ],
                'approved_flow' => $current !== null ? $this->flow($current) : null,
                'placed_controls' => $current !== null ? $this->placedControls($customerId, $item, $current) : [],
            ];
        }

        if ($item->quality_type === QualityItem::TYPE_CONTROL) {
            $detail += ['placements' => $this->placements($customerId, $item)];
        }

        return $detail;
    }

    /**
     * The attention codes per item, in QualityAttentionService's order, without the flow-dependent one.
     *
     * @return array<int, list<string>>
     */
    public function attentionByItem(int $customerId): array
    {
        $codes = [];

        foreach ($this->attention->findings($customerId) as $finding) {
            if (in_array($finding['key'], self::FLOW_DEPENDENT_FINDINGS, true)) {
                continue;
            }

            foreach ($finding['items'] as $item) {
                $codes[(int) $item['id']][] = $finding['key'];
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function row(QualityItem $item, array $attention): array
    {
        return [
            'id' => (int) $item->id,
            'quality_type' => $item->quality_type,
            'title' => $item->title,
            'code' => $item->code,
            'status' => $item->status,
            'owner' => $item->owner_user_id !== null
                ? ['id' => (int) $item->owner_user_id, 'name' => $item->owner?->name]
                : null,
            'review_interval_months' => $item->review_interval_months,
            'last_reviewed_on' => $item->last_reviewed_at?->toDateString(),
            'next_review_on' => $item->next_review_at?->toDateString(),
            'attention' => $attention,
            'created_at' => $item->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $item->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The approved revision in force of each process: the latest one, for a process that is not
     * retired. The working version is never read.
     *
     * @param  list<int>  $processIds
     * @return SupportCollection<int, QualityProcessRevision>
     */
    private function approvedRevisions(int $customerId, array $processIds): SupportCollection
    {
        if ($processIds === []) {
            return collect();
        }

        $inForce = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->where('status', '!=', QualityItem::STATUS_RETIRED)
            ->whereIn('id', $processIds)
            ->pluck('id');

        return QualityProcessRevision::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $inForce->all() ?: [0])
            ->orderByDesc('revision_number')
            ->get()
            ->unique('quality_item_id')
            ->keyBy('quality_item_id');
    }

    /**
     * The activities of each process's approved flow, keyed by process id and then activity key.
     *
     * @param  list<int>  $processIds
     * @return array<int, array<string, array{key: string, label: string, role: ?string}>>
     */
    private function approvedSteps(int $customerId, array $processIds): array
    {
        return $this->approvedRevisions($customerId, $processIds)
            ->map(fn (QualityProcessRevision $revision): array => $this->stepsOf($revision))
            ->all();
    }

    /**
     * An approved revision's activities, read by the same rule as every other reader of a flow.
     * The revision's payload is wrapped in a blueprint instance that is never saved.
     *
     * @return array<string, array{key: string, label: string, role: ?string}>
     */
    private function stepsOf(QualityProcessRevision $revision): array
    {
        return $this->reader->steps((new QualityProcessBlueprint)->forceFill(['payload' => $revision->payload]));
    }

    /**
     * The approved flow: lanes, nodes and edges. Not the nodes' descriptions.
     *
     * @return array{lanes: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function flow(QualityProcessRevision $revision): array
    {
        $flow = (new QualityProcessBlueprint)->forceFill(['payload' => $revision->payload]);

        return [
            'lanes' => array_map(fn (array $lane): array => [
                'key' => (string) ($lane['key'] ?? ''),
                'label' => (string) ($lane['label'] ?? ''),
            ], $flow->lanes()),
            'nodes' => array_map(fn (array $node): array => [
                'key' => (string) ($node['key'] ?? ''),
                'type' => (string) ($node['type'] ?? QualityProcessBlueprint::NODE_STEP),
                'label' => (string) ($node['label'] ?? ''),
                'lane' => isset($node['lane']) ? (string) $node['lane'] : null,
                'subprocess_item_id' => isset($node['subprocess_quality_item_id']) ? (int) $node['subprocess_quality_item_id'] : null,
            ], $flow->nodes()),
            'edges' => array_map(fn (array $edge): array => [
                'from' => (string) ($edge['from'] ?? ''),
                'to' => (string) ($edge['to'] ?? ''),
                'label' => isset($edge['label']) && $edge['label'] !== '' ? (string) $edge['label'] : null,
            ], $flow->edges()),
        ];
    }

    /**
     * The controls placed on the process's approved activities. A placement on an activity that
     * exists only in the working version is left out.
     *
     * @return list<array{activity_key: string, control_id: int}>
     */
    private function placedControls(int $customerId, QualityItem $process, QualityProcessRevision $revision): array
    {
        $steps = $this->stepsOf($revision);

        return QualityActivityControl::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $process->id)
            ->orderBy('id')
            ->get(['activity_key', 'control_item_id'])
            ->filter(fn (QualityActivityControl $placement): bool => isset($steps[(string) $placement->activity_key]))
            ->map(fn (QualityActivityControl $placement): array => ['activity_key' => (string) $placement->activity_key, 'control_id' => (int) $placement->control_item_id])
            ->values()
            ->all();
    }

    /**
     * Where a control is placed, in approved flows only.
     *
     * @return list<array{process_id: int, activity_key: string, activity_label: string, activity_role: ?string}>
     */
    private function placements(int $customerId, QualityItem $control): array
    {
        $placements = QualityActivityControl::query()
            ->where('customer_id', $customerId)
            ->where('control_item_id', $control->id)
            ->orderBy('id')
            ->get(['quality_item_id', 'activity_key']);
        $steps = $this->approvedSteps($customerId, $placements->pluck('quality_item_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $rows = [];

        foreach ($placements as $placement) {
            $step = $steps[(int) $placement->quality_item_id][(string) $placement->activity_key] ?? null;

            if ($step !== null) {
                $rows[] = ['process_id' => (int) $placement->quality_item_id, 'activity_key' => $step['key'], 'activity_label' => $step['label'], 'activity_role' => $step['role']];
            }
        }

        return $rows;
    }

    /**
     * The item's relations to other items of the customer — Kvalitet's own relation rows, which no
     * flow version touches.
     *
     * @return list<array{relation_type: string, direction: string, item: array{id: int, quality_type: ?string, title: ?string, code: ?string, status: ?string}}>
     */
    private function relations(int $customerId, int $itemId): array
    {
        return QualityItemRelation::query()
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('from_item_id', $itemId)->orWhere('to_item_id', $itemId))
            ->with(['fromItem:id,title,quality_type,code,status', 'toItem:id,title,quality_type,code,status'])
            ->orderBy('id')
            ->get()
            ->map(function (QualityItemRelation $relation) use ($itemId): array {
                $outgoing = (int) $relation->from_item_id === $itemId;
                $other = $outgoing ? $relation->toItem : $relation->fromItem;

                return [
                    'relation_type' => $relation->relation_type,
                    'direction' => $outgoing ? 'outgoing' : 'incoming',
                    'item' => [
                        'id' => (int) ($outgoing ? $relation->to_item_id : $relation->from_item_id),
                        'quality_type' => $other?->quality_type,
                        'title' => $other?->title,
                        'code' => $other?->code,
                        'status' => $other?->status,
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
