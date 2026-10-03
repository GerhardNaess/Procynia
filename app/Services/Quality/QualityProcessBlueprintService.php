<?php

namespace App\Services\Quality;

use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        private readonly QualityProcessFlowValidator $validator,
    ) {}

    /**
     * Store a blueprint against a process, replacing whatever it had.
     *
     * Saving always clears an approval. An approval is a statement about one specific flow, so it
     * cannot outlive an edit to that flow — keeping it would let an approved diagram show work
     * nobody approved. Re-approving an edited blueprint is one click, and an honest one. What was
     * approved before is not lost: it lives on as a QualityProcessRevision, which a save never
     * touches.
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
     * Vouch for the flow as it stands, and record exactly what was vouched for.
     *
     * The working version is held to the strict standard before it can be approved: normalise()
     * only guarantees a stored flow is drawable, and a drawable flow can still have no start, a dead
     * end or a decision with one outcome. The same validator an interpreted proposal must pass is
     * run on the stored payload — there is one definition of a coherent flow, not two.
     *
     * A valid approval writes an immutable revision with the next number for the process. The
     * blueprint row stays the working version and only learns that it currently matches what was
     * approved; the next edit sets it back to draft, and the revision stays where it is.
     *
     * Approving a flow that is already approved, and already recorded, is a no-op rather than a
     * second identical revision — a double click is not a second approval.
     */
    public function approve(int $customerId, QualityItem $item, ?User $actor = null): QualityProcessBlueprint
    {
        $this->assertProcess($customerId, $item);

        return DB::transaction(function () use ($customerId, $item, $actor): QualityProcessBlueprint {
            // Locked so two approvals of the same process serialise: the second sees the first's
            // revision and either no-ops or takes the next number.
            $blueprint = QualityProcessBlueprint::query()
                ->where('customer_id', $customerId)
                ->where('quality_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if ($blueprint === null) {
                throw ValidationException::withMessages([
                    'blueprint' => __('procynia.quality.errors.blueprint_not_found'),
                ]);
            }

            $problems = $this->validator->graphProblems($blueprint->payload ?? []);

            if ($problems !== []) {
                // One key per problem: the shared error bag carries only the first message of
                // each key to the page, and the user needs every concrete problem, not just one.
                $messages = ['blueprint' => __('procynia.quality.errors.blueprint_invalid')];

                foreach ($this->validator->describe($problems) as $index => $message) {
                    $messages['blueprint_problems.'.$index] = $message;
                }

                throw ValidationException::withMessages($messages);
            }

            $latest = $this->latestRevision($customerId, $item);

            // The working version already says exactly what is in force — whether it was never
            // touched or was edited and then edited back. Nothing new to publish, so no revision.
            if ($latest !== null && $latest->payload == $blueprint->payload) {
                if (! $blueprint->isApproved()) {
                    $blueprint->forceFill(['status' => QualityProcessBlueprint::STATUS_APPROVED])->save();
                }

                return $blueprint;
            }

            $approvedAt = now();

            QualityProcessRevision::query()->create([
                'customer_id' => $customerId,
                'quality_item_id' => $item->id,
                'revision_number' => ($latest?->revision_number ?? 0) + 1,
                'payload' => $blueprint->payload,
                'description' => $blueprint->description,
                'source' => $blueprint->source,
                'approved_by_user_id' => $actor?->id,
                'approved_by_name' => $actor?->name,
                'approved_at' => $approvedAt,
            ]);

            $blueprint->forceFill([
                'status' => QualityProcessBlueprint::STATUS_APPROVED,
                'approved_at' => $approvedAt,
                'approved_by_user_id' => $actor?->id,
            ])->save();

            // The first approval publishes the process. Later approvals leave the lifecycle alone:
            // an active or under-review process stays as it is, and a retired one stays retired —
            // retiring is an explicit decision an approval does not undo.
            if ($item->status !== QualityItem::STATUS_RETIRED
                && ($latest === null || $item->status === QualityItem::STATUS_DRAFT)) {
                $item->forceFill(['status' => QualityItem::STATUS_ACTIVE])->save();
            }

            return $blueprint;
        });
    }

    /**
     * The approved revision currently in force: the highest number, whatever the working version
     * looks like now.
     */
    public function latestRevision(int $customerId, QualityItem $item): ?QualityProcessRevision
    {
        return QualityProcessRevision::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $item->id)
            ->orderByDesc('revision_number')
            ->first();
    }

    /**
     * Where each process stands, read from its revisions — the one source of truth for what is in
     * force. Nothing here is stored: a stored copy would be a second answer that drifts from the
     * revisions on the first approval it missed.
     *
     *  - unpublished: no approved revision yet.
     *  - current: the latest revision is in force and the working version matches it.
     *  - current_with_changes: the latest revision is in force, and the working version has been
     *    changed since. The changes do not apply until they are approved.
     *  - retired: explicitly retired. The revisions stay as history but are not presented as current.
     *
     * Batched for the process list: one query for revisions and one for blueprints, whatever the
     * number of processes.
     *
     * @param  iterable<QualityItem>  $items
     * @return array<int, array{state: string, revision_number: ?int, has_unpublished_changes: bool}>
     */
    public function publicationStates(int $customerId, iterable $items): array
    {
        $processes = collect($items)
            ->filter(static fn (QualityItem $item): bool => $item->quality_type === QualityItem::TYPE_PROCESS)
            ->keyBy(static fn (QualityItem $item): int => (int) $item->id);

        if ($processes->isEmpty()) {
            return [];
        }

        $ids = $processes->keys()->all();

        $latest = QualityProcessRevision::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $ids)
            ->orderByDesc('revision_number')
            ->get(['quality_item_id', 'revision_number', 'payload'])
            ->unique('quality_item_id')
            ->keyBy('quality_item_id');

        $working = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->whereIn('quality_item_id', $ids)
            ->get(['quality_item_id', 'payload'])
            ->keyBy('quality_item_id');

        return $processes
            ->map(static function (QualityItem $item, int $id) use ($latest, $working): array {
                $revision = $latest->get($id);
                $blueprint = $working->get($id);
                $changed = $revision !== null && $blueprint !== null && $revision->payload != $blueprint->payload;

                $state = match (true) {
                    $item->status === QualityItem::STATUS_RETIRED => 'retired',
                    $revision === null => 'unpublished',
                    $changed => 'current_with_changes',
                    default => 'current',
                };

                return [
                    'state' => $state,
                    'revision_number' => $revision?->revision_number !== null ? (int) $revision->revision_number : null,
                    'has_unpublished_changes' => $changed,
                ];
            })
            ->all();
    }

    /**
     * @return array{state: string, revision_number: ?int, has_unpublished_changes: bool}
     */
    public function publicationState(int $customerId, QualityItem $item): array
    {
        return $this->publicationStates($customerId, [$item])[(int) $item->id];
    }

    /**
     * Every approved revision of the process, newest first. A plain read model — no diff, no
     * rollback.
     *
     * @return list<array{revision_number: int, approved_by_name: ?string, approved_at: ?string, snapshot: array<string, mixed>}>
     */
    public function history(int $customerId, QualityItem $item): array
    {
        return QualityProcessRevision::query()
            ->with('approvedBy:id,name')
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $item->id)
            ->orderByDesc('revision_number')
            ->get()
            ->map(static fn (QualityProcessRevision $revision): array => [
                'revision_number' => $revision->revision_number,
                'approved_by_name' => $revision->approvedBy?->name ?? $revision->approved_by_name,
                'approved_at' => $revision->approved_at?->toDateTimeString(),
                'snapshot' => [
                    'lanes' => array_values($revision->payload['lanes'] ?? []),
                    'nodes' => array_values($revision->payload['nodes'] ?? []),
                    'edges' => array_values($revision->payload['edges'] ?? []),
                    'description' => $revision->description,
                    'source' => $revision->source,
                ],
            ])
            ->all();
    }

    /**
     * Remove the flow, and nothing else.
     *
     * The process stays, with its number, owner, status, structure, files and Wiki links intact —
     * this is "start the flow over", not "delete the process", which is QualityItemService's job.
     * Deleting is tolerant of there being nothing to delete: the button is only shown when a flow
     * exists, but two tabs open on the same process would otherwise turn the second click into an
     * error about something the user had already achieved.
     *
     * The description goes with it. It is a column on the blueprint row, and it is the text THIS
     * flow was read out of — keeping it would leave the describe box pre-filled with the account of
     * a flow that no longer exists, which is the opposite of starting over.
     *
     * What survives on purpose: the articles the activities were the source of. Those rows point at
     * the process and an activity key, not at the blueprint, and the knowledge in Wiki is the
     * virksomhet's rather than the flow's. The projection drops their edges because there is no
     * longer an activity node for one to leave — see QualityGraphProjector::projectActivities — and
     * the rows and the pages are left standing. So do the approved revisions: they point at the
     * process, and they are the record of what was approved, not part of the working version.
     */
    public function delete(int $customerId, QualityItem $item): void
    {
        $this->assertProcess($customerId, $item);

        // Once a revision has been approved this is "Forkast arbeidsversjon", and nothing is
        // removed: the working version is reset to the revision in force — payload, description and
        // source — so it is identical to what applies and the user carries on editing from there.
        // No revision is written; they are only read here. An empty Flyt tab while a revision is in
        // force would say there is no process to follow, which is false.
        $latest = $this->latestRevision($customerId, $item);

        if ($latest !== null) {
            $this->resetToRevision($customerId, $item, $latest);

            return;
        }

        $blueprint = $this->forItem($customerId, $item);

        if ($blueprint === null) {
            return;
        }

        $blueprint->delete();

        // The activities were nodes in the graph. Reprojecting the item is what clears them: the
        // projector reads SQL, finds no blueprint, and replaces the activity set with an empty one.
        ProjectQualityItemToGraph::dispatch((int) $item->id)->afterCommit();
    }

    /**
     * Make the working version exactly the given revision again.
     *
     * Written straight from the revision rather than through store(): the payload was normalised and
     * validated when it was approved, and running it through normalise() again could reshape it —
     * a subprocess that has since been deleted, say — so that it no longer equals what is in force.
     * Upserted, so a process whose working version is already gone gets one back.
     */
    private function resetToRevision(int $customerId, QualityItem $item, QualityProcessRevision $revision): void
    {
        QualityProcessBlueprint::query()->updateOrCreate(
            ['quality_item_id' => $item->id],
            [
                'customer_id' => $customerId,
                'payload' => $revision->payload,
                'description' => $revision->description,
                'source' => $revision->source,
                'status' => QualityProcessBlueprint::STATUS_APPROVED,
                // A fresh timestamp is what tells the editor a new flow arrived and to reload from it.
                'generated_at' => now(),
                'approved_at' => $revision->approved_at,
                'approved_by_user_id' => $revision->approved_by_user_id,
            ],
        );

        ProjectQualityItemToGraph::dispatch((int) $item->id)->afterCommit();
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
