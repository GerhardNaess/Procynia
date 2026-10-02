<?php

namespace App\Services\Quality;

use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use Illuminate\Validation\ValidationException;

/**
 * A step that is itself a process.
 *
 * WHY A REFERENCE AND NOT NESTED CONTENT.
 *
 * "Vurder leverandøren" on the purchasing flow is not a description of how a supplier is assessed —
 * it is the name of a process that has its own owner, its own roles and its own approval. Copying
 * that flow into the parent's payload would make a second copy of it, and the second copy is wrong
 * the first time the real process changes. So the node holds nothing but the id of the other
 * process, and opening it reads that process's own blueprint. Change the subprocess and the parent
 * shows the change the next time it is opened, because there was never anything to keep in step.
 *
 * WHY IT LIVES IN THE PAYLOAD.
 *
 * The blueprint migration's docblock says the day to split lanes/nodes/edges into tables is the day
 * something outside the payload points at a single node. This is the opposite direction: the node
 * points out, by the id of a row that already exists and is already customer-scoped. So it is one
 * nullable integer on the node, `subprocess_quality_item_id`, and nothing else moved.
 *
 * WHAT THIS CLASS IS FOR.
 *
 * Three rules, none of which the payload can enforce on its own:
 *
 *  - The target is a process of the same customer. Anything else is dropped, not refused — a target
 *    that was deleted or retyped leaves a reference that can no longer resolve, and refusing the
 *    save would trap the user in the one edit they cannot make. Same answer the service already
 *    gives a dangling edge.
 *  - A → B → A is refused outright. A cycle is not an unresolvable leftover, it is a statement the
 *    user just made, it is fixable by picking a different process, and drilling into it would
 *    recurse forever. So this one is a validation error with a message naming the problem.
 *  - Drilling down may only follow a reference that is actually written down. The trail in the URL
 *    is checked hop by hop against the blueprints it claims to pass through, which is what stops a
 *    hand-edited link being a way into a flow nothing links to.
 */
class QualityProcessSubprocessService
{
    /**
     * What each of a flow's raw references resolves to, keyed by what was asked for.
     *
     * All of them at once rather than one at a time, because every one of them asks the same
     * question of the same graph and that graph is a table scan of the customer's blueprints. The
     * graph is read here and nowhere else in the call, so it cannot be stale: it is never held
     * between operations, which is the state it would be wrong in — a reference saved a moment ago
     * has to be part of the next save's answer.
     *
     * @param  int  $parentItemId  The process the nodes belong to — the one a cycle would close on.
     * @param  list<mixed>  $raw
     * @return array<string, int> raw value, as written, to the id it resolves to. Absent means null.
     *
     * @throws ValidationException when a reference would close a cycle.
     */
    public function resolveAll(int $customerId, int $parentItemId, array $raw): array
    {
        $map = $this->referenceMap($customerId);
        $resolved = [];

        foreach ($raw as $value) {
            $id = is_numeric($value) ? (int) $value : 0;

            if ($id <= 0 || isset($resolved[(string) $id])) {
                continue;
            }

            // A process cannot be its own subprocess. It is the shortest cycle there is, and it is
            // reported as one.
            if ($id === $parentItemId || $this->reaches($map, $id, $parentItemId)) {
                throw ValidationException::withMessages([
                    'nodes' => __('procynia.quality.errors.subprocess_cycle'),
                ]);
            }

            if (! $this->isProcessOfCustomer($customerId, $id)) {
                continue;
            }

            $resolved[(string) $id] = $id;
        }

        return $resolved;
    }

    /**
     * The processes this one may point at.
     *
     * Everything that cannot be chosen is left out rather than offered and then refused: a cycle is
     * a property of the two processes, not of the click, so the dropdown can tell the truth about it
     * before the user commits. `step_count` travels with the option so the editor can show the
     * indicator the moment a reference is picked, before anything is saved.
     *
     * @return list<array{id: int, title: string, code: ?string, step_count: int}>
     */
    public function options(int $customerId, QualityItem $item): array
    {
        $candidates = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->whereKeyNot($item->id)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $map = $this->referenceMap($customerId);
        $options = [];

        foreach ($candidates as $candidate) {
            if ($this->reaches($map, (int) $candidate->id, (int) $item->id)) {
                continue;
            }

            $options[] = [
                'id' => (int) $candidate->id,
                'title' => (string) $candidate->title,
                'code' => $candidate->code,
                'step_count' => $this->stepCount($customerId, (int) $candidate->id),
            ];
        }

        return $options;
    }

    /**
     * How a referenced process is named and sized on the parent's diagram.
     *
     * Read fresh on every page load, which is the whole point of holding a reference: the parent
     * shows what the subprocess is now, not what it was when the link was made.
     *
     * @return array{id: int, title: string, code: ?string, step_count: int}|null
     */
    public function describe(int $customerId, mixed $raw): ?array
    {
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($id <= 0) {
            return null;
        }

        $item = QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->whereKey($id)
            ->first(['id', 'title', 'code']);

        if ($item === null) {
            return null;
        }

        return [
            'id' => (int) $item->id,
            'title' => (string) $item->title,
            'code' => $item->code,
            'step_count' => $this->stepCount($customerId, (int) $item->id),
        ];
    }

    /**
     * The chain of processes a drill-down trail actually reaches, from the root downwards.
     *
     * Every hop has to be written down in the blueprint above it. That is the authorization: a
     * trail is not a list of ids the user may read, it is a path the kvalitetssystem itself
     * describes, and a process nothing links to is not reachable by guessing at the URL.
     *
     * A hop that no longer resolves truncates the trail rather than failing it. Unlinking a
     * subprocess must not turn every link anyone saved into an error page; it drops the reader back
     * to the deepest view that is still true.
     *
     * @param  list<int>  $requested
     * @return list<QualityItem>
     */
    public function trail(int $customerId, QualityItem $root, array $requested): array
    {
        $map = $this->referenceMap($customerId);
        $trail = [];
        $current = $root;

        foreach ($requested as $id) {
            $id = (int) $id;

            if ($id <= 0 || ! in_array($id, $map[(int) $current->id] ?? [], true)) {
                break;
            }

            $next = QualityItem::query()
                ->where('customer_id', $customerId)
                ->where('quality_type', QualityItem::TYPE_PROCESS)
                ->whereKey($id)
                ->first();

            if ($next === null) {
                break;
            }

            $trail[] = $next;
            $current = $next;
        }

        return $trail;
    }

    /**
     * The processes whose own flow has a step standing for this one.
     *
     * The reference is written on the parent, so this is the only way up: a subprocess holds nothing
     * saying what it is part of, and nothing should — it is a process in its own right, and the day
     * two flows both point at it is the day a stored parent would be a lie.
     *
     * Several answers are legitimate. Ordered by title so the answer is stable, and bounded, because
     * it is read to give context and not to be complete.
     *
     * @return list<array{id: int, title: string}>
     */
    public function parentsOf(int $customerId, int $itemId, int $limit = 3): array
    {
        $map = $this->referenceMap($customerId);
        $parentIds = [];

        foreach ($map as $parentId => $children) {
            if (in_array($itemId, $children, true)) {
                $parentIds[] = (int) $parentId;
            }
        }

        if ($parentIds === []) {
            return [];
        }

        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $parentIds)
            ->orderBy('title')
            ->limit(max(1, $limit))
            ->get(['id', 'title'])
            ->map(static fn (QualityItem $item): array => [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
            ])
            ->all();
    }

    /** How many nodes the referenced process's own flow holds. Zero when it has no flow yet. */
    public function stepCount(int $customerId, int $itemId): int
    {
        $blueprint = QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->where('quality_item_id', $itemId)
            ->first(['payload']);

        return $blueprint === null ? 0 : count($blueprint->nodes());
    }

    /**
     * Can $fromItemId be followed down to $targetItemId?
     *
     * Breadth-first over the stored references. $targetItemId's own outgoing references are never
     * traversed — arriving there is the answer — which is also why replacing the target's blueprint
     * cannot make this question lie about the blueprint being replaced.
     *
     * @param  array<int, list<int>>  $map
     */
    private function reaches(array $map, int $fromItemId, int $targetItemId): bool
    {
        $queue = [$fromItemId];
        $seen = [$fromItemId => true];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $targetItemId) {
                return true;
            }

            foreach ($map[$current] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * Who points at whom, across one customer's processes, as stored right now.
     *
     * Read once per operation and thrown away with it. Holding it between operations is the one way
     * it could be wrong, and it would be wrong in the way that matters: a reference stored a moment
     * ago is exactly what the next save has to see to refuse the cycle it would close.
     *
     * @return array<int, list<int>>
     */
    private function referenceMap(int $customerId): array
    {
        $map = [];

        QualityProcessBlueprint::query()
            ->where('customer_id', $customerId)
            ->get(['quality_item_id', 'payload'])
            ->each(function (QualityProcessBlueprint $blueprint) use (&$map): void {
                $ids = [];

                foreach ($blueprint->nodes() as $node) {
                    $id = (int) ($node['subprocess_quality_item_id'] ?? 0);

                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }

                $map[(int) $blueprint->quality_item_id] = array_map('intval', array_keys($ids));
            });

        return $map;
    }

    private function isProcessOfCustomer(int $customerId, int $itemId): bool
    {
        return QualityItem::query()
            ->where('customer_id', $customerId)
            ->where('quality_type', QualityItem::TYPE_PROCESS)
            ->whereKey($itemId)
            ->exists();
    }
}
