<?php

namespace App\Services\Quality;

use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a process blueprint goes through here.
 *
 * A blueprint is only ever written by a person: adopting a proposal they read and corrected, or
 * saving the editor. Nothing generates one. A deterministic generator once did — seeding a flow
 * from the process's steps, or from a worked ITIL Incident Management example when it had none —
 * and because store() is keyed on the process it replaced whatever was already there, approval
 * and all. store() now refuses every seeded source outright; see
 * QualityProcessBlueprint::RETIRED_SOURCES.
 *
 * The one rule this class exists to enforce: what reaches the database is always drawable. The
 * swimlane is laid out from the payload with no error handling of its own — it is a pure function
 * over lanes, nodes and edges — so a node in a lane that does not exist, or an edge to a node that
 * was deleted, would be a broken diagram rather than a caught exception. Normalisation therefore
 * happens on the way in, once, and the renderer may assume a closed graph.
 *
 * Normalisation is forgiving rather than strict, deliberately. An editor that refuses to save
 * because the user deleted a node an edge still pointed at would trap them: the only way out is the
 * edit they cannot make. Dangling rows are dropped instead, which is the same answer
 * replaceProcessSteps() gives to a blank row. The one genuine error is a flow with nothing in it.
 *
 * Tenancy is a precondition, not a check: callers resolve the customer through CustomerContext and
 * pass it in, and the item is verified to belong to it before anything is written.
 *
 * The one exception to "forgiving" is a subprocess reference that would close a cycle — see
 * QualityProcessSubprocessService for why that one is refused rather than dropped.
 */
class QualityProcessBlueprintService
{
    public function __construct(
        private readonly QualityProcessSubprocessService $subprocesses,
    ) {}

    /**
     * Store a blueprint against a process, replacing whatever it had.
     *
     * Saving always clears an approval. An approval is a statement about one specific flow, so it
     * cannot outlive an edit to that flow — keeping it would let an approved diagram show work
     * nobody approved. Re-approving an edited blueprint is one click, and an honest one.
     *
     * @param  array<string, mixed>  $payload
     * @param  ?string  $description  The plain-language text a flow was interpreted from. Null
     *                                means "leave whatever is stored alone", not "clear it": every
     *                                ordinary edit passes null, and an editor save would otherwise
     *                                throw away the description the flow was proposed from the
     *                                first time somebody corrected a label.
     */
    public function store(
        int $customerId,
        QualityItem $item,
        array $payload,
        string $source,
        ?User $actor = null,
        ?string $description = null,
    ): QualityProcessBlueprint {
        $this->assertProcess($customerId, $item);

        // A seeded flow may not be stored at all, over an existing blueprint or onto an empty
        // process. The generator that produced these is gone; this is the structural guarantee
        // that no future example, demo or step seeder can take its place and overwrite a flow a
        // person described, corrected and adopted.
        if (in_array($source, QualityProcessBlueprint::RETIRED_SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => __('procynia.quality.errors.seeded_blueprint_source'),
            ]);
        }

        if (! in_array($source, QualityProcessBlueprint::SOURCES, true)) {
            throw ValidationException::withMessages([
                'source' => __('procynia.quality.errors.unknown_blueprint_source'),
            ]);
        }

        $attributes = [
            'customer_id' => $customerId,
            'payload' => $this->normalise($payload, $customerId, (int) $item->id),
            'source' => $source,
            'status' => QualityProcessBlueprint::STATUS_DRAFT,
            'generated_at' => now(),
            'generated_by_user_id' => $actor?->id,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ];

        if ($description !== null) {
            $attributes['description'] = $description;
        }

        $blueprint = QualityProcessBlueprint::query()->updateOrCreate(
            ['quality_item_id' => $item->id],
            $attributes,
        );

        // The flow's activities are nodes the graph answers questions about — which step produced
        // which Wiki article — so a saved flow has to reach it. After commit: the job reads SQL, and on
        // the sync driver it would otherwise run against a transaction that has not landed yet. A
        // failed projection is a stale graph, never lost data — `wiki:graph-project` repairs it.
        ProjectQualityItemToGraph::dispatch((int) $item->id)->afterCommit();

        return $blueprint;
    }

    /**
     * Vouch for the flow as it stands.
     *
     * Nothing about the payload changes — approval is a statement about it, not an edit of it.
     */
    public function approve(int $customerId, QualityItem $item, ?User $actor = null): QualityProcessBlueprint
    {
        $this->assertProcess($customerId, $item);

        $blueprint = $this->forItem($customerId, $item);

        if ($blueprint === null) {
            throw ValidationException::withMessages([
                'blueprint' => __('procynia.quality.errors.blueprint_not_found'),
            ]);
        }

        $blueprint->forceFill([
            'status' => QualityProcessBlueprint::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by_user_id' => $actor?->id,
        ])->save();

        return $blueprint;
    }

    public function forItem(int $customerId, QualityItem $item): ?QualityProcessBlueprint
    {
        return QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $item->id)
            ->first();
    }

    /**
     * Make a payload drawable.
     *
     * In order, because each pass depends on the one before it: lanes first so nodes have somewhere
     * to resolve to, nodes next so edges have endpoints to resolve to, edges last.
     *
     * @param  array<string, mixed>  $payload
     * @return array{lanes: list<array<string, string>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function normalise(array $payload, ?int $customerId = null, ?int $itemId = null): array
    {
        $lanes = $this->normaliseLanes($payload['lanes'] ?? []);
        $nodes = $this->normaliseNodes($payload['nodes'] ?? [], $lanes, $customerId, $itemId);

        if ($nodes === []) {
            throw ValidationException::withMessages([
                'nodes' => __('procynia.quality.errors.blueprint_has_no_nodes'),
            ]);
        }

        // A lane nothing happens in is noise on the diagram — it takes a full horizontal band to
        // say nothing. Dropped after the nodes have resolved against the full list, never before,
        // so a lane is only removed once it is certain no node landed in it.
        $usedLanes = array_unique(array_column($nodes, 'lane'));
        $lanes = array_values(array_filter(
            $lanes,
            static fn (array $lane): bool => in_array($lane['key'], $usedLanes, true),
        ));

        return [
            'lanes' => $lanes,
            'nodes' => $nodes,
            'edges' => $this->normaliseEdges($payload['edges'] ?? [], array_column($nodes, 'key')),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function normaliseLanes(mixed $rows): array
    {
        $lanes = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $label = trim((string) (is_array($row) ? ($row['label'] ?? '') : $row));

            if ($label === '') {
                continue;
            }

            $key = $this->key(
                is_array($row) ? (string) ($row['key'] ?? '') : '',
                $label,
                'lane',
                $seen,
            );

            $lanes[] = ['key' => $key, 'label' => $label];
        }

        // A flow with no stated roles is still a flow. One unnamed lane keeps the diagram
        // rectangular rather than making the renderer invent a fallback of its own.
        if ($lanes === []) {
            $lanes[] = ['key' => 'lane-1', 'label' => __('procynia.quality.blueprint.default_lane')];
        }

        return $lanes;
    }

    /**
     * @param  list<array<string, string>>  $lanes
     * @return list<array<string, mixed>>
     */
    private function normaliseNodes(mixed $rows, array $lanes, ?int $customerId, ?int $itemId): array
    {
        $laneKeys = array_column($lanes, 'key');
        $fallbackLane = $laneKeys[0];

        $rows = is_array($rows) ? $rows : [];

        // Resolved for the whole flow in one go, before any node is built: every reference asks the
        // same question of the same graph, and that graph is read once per save. Without a customer
        // there is no tenant to check a target against and no graph to look for a cycle in, so
        // nothing resolves — that is the interpreter's path, where a model reading a description
        // has no business naming a process by id, and the user's path always has both.
        $subprocesses = $customerId === null || $itemId === null
            ? []
            : $this->subprocesses->resolveAll($customerId, $itemId, array_map(
                static fn (mixed $row): mixed => is_array($row) ? ($row['subprocess_quality_item_id'] ?? null) : null,
                $rows,
            ));

        $nodes = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $type = (string) ($row['type'] ?? QualityProcessBlueprint::NODE_STEP);

            $nodes[] = [
                'key' => $this->key((string) ($row['key'] ?? ''), $label, 'node', $seen),
                // A step that is itself a process. Kept only when it can be checked, which needs
                // to know whose process this is: without a customer there is no tenant to check
                // the target against and no graph to look for a cycle in, so the reference is
                // dropped rather than trusted. That is the interpreter's path — a model reading a
                // description never proposes one — and the user's path always has both.
                'subprocess_quality_item_id' => $subprocesses[(string) ($row['subprocess_quality_item_id'] ?? '')] ?? null,
                // An unknown lane resolves to the first rather than failing: it is what a node
                // whose lane was just renamed looks like, and losing the node would be worse.
                'lane' => in_array($row['lane'] ?? null, $laneKeys, true) ? (string) $row['lane'] : $fallbackLane,
                'type' => in_array($type, QualityProcessBlueprint::NODE_TYPES, true)
                    ? $type
                    : QualityProcessBlueprint::NODE_STEP,
                'label' => $label,
                'description' => $this->nullableText($row['description'] ?? null),
            ];
        }

        return $nodes;
    }

    /**
     * @param  list<string>  $nodeKeys
     * @return list<array<string, mixed>>
     */
    private function normaliseEdges(mixed $rows, array $nodeKeys): array
    {
        $edges = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $from = (string) ($row['from'] ?? '');
            $to = (string) ($row['to'] ?? '');

            // Both endpoints must still exist, and an edge from a node to itself says nothing a
            // reader can act on. Dropping beats refusing the save — see the class docblock.
            if ($from === $to || ! in_array($from, $nodeKeys, true) || ! in_array($to, $nodeKeys, true)) {
                continue;
            }

            $label = $this->nullableText($row['label'] ?? null);

            // Two identical arrows between the same pair draw on top of each other. Two differently
            // labelled ones are a real branch — "ja" and "nei" out of the same decision — so the
            // label is part of the identity.
            $identity = $from.'->'.$to.'#'.($label ?? '');

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $edges[] = ['from' => $from, 'to' => $to, 'label' => $label];
        }

        return $edges;
    }

    /**
     * A stable, unique, URL-safe key.
     *
     * The caller's own key is kept when it survives slugging and is still free, so a round trip
     * through the editor does not renumber everything and leave the diff unreadable.
     *
     * @param  array<string, bool>  $seen
     */
    private function key(string $preferred, string $label, string $prefix, array &$seen): string
    {
        $base = Str::slug($preferred) ?: Str::slug($label);

        if ($base === '') {
            $base = $prefix;
        }

        $base = Str::limit($base, 60, '');
        $candidate = $base;
        $suffix = 2;

        while (isset($seen[$candidate])) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        $seen[$candidate] = true;

        return $candidate;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function assertProcess(int $customerId, QualityItem $item): void
    {
        if ((int) $item->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'item' => __('procynia.quality.errors.item_not_found'),
            ]);
        }

        if ($item->quality_type !== QualityItem::TYPE_PROCESS) {
            throw ValidationException::withMessages([
                'quality_type' => __('procynia.quality.errors.blueprint_requires_process'),
            ]);
        }
    }
}
