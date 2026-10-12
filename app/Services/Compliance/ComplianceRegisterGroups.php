<?php

namespace App\Services\Compliance;

use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kravregister, gruppert: the register's own filtered requirements, laid out as kravkilde → kapittel
 * → krav. Nothing here is a new structure. A chapter is the top section of the activated framework
 * the requirement came from (its own Kravbibliotek nodes); a source registered by hand has no
 * chapters, and its requirements sit directly under it.
 *
 * One exception keeps a reference set of controls readable: a top section that holds only themes
 * (no requirement of its own) and only selectable controls — ISO/IEC 27001 Vedlegg A — is grouped by
 * those themes, each carrying the section as context. A chapter of management-system requirements
 * (ISO 9001 chapter 9, whose children are all sub-clauses too) stays one chapter.
 *
 * The counts come from one aggregate over the query it is given — already scoped to the user's
 * customer and the active filters — so they always agree with what the list would show. Rows are
 * loaded only for the groups that are open, in one query.
 */
class ComplianceRegisterGroups
{
    /**
     * @param  Builder<ComplianceRequirement>  $filtered  the register's scoped, filtered query, unordered
     * @param  Collection<int, ComplianceSource>  $sources  the sources the user can read
     * @param  list<string>  $open  open keys: «12» a source, «12:345» a group in it
     * @param  callable(ComplianceRequirement): array<string, mixed>  $row
     * @return array{sources: list<array<string, mixed>>, total: int}
     */
    public function tree(Builder $filtered, Collection $sources, array $open, callable $row): array
    {
        $counts = (clone $filtered)->toBase()
            ->select('compliance_requirements.source_id', 'compliance_requirements.framework_node_id')
            ->selectRaw('count(*) as total')
            ->groupBy('compliance_requirements.source_id', 'compliance_requirements.framework_node_id')
            ->get();

        $sources = $sources->keyBy(fn (ComplianceSource $source): int => (int) $source->id);
        $frameworkIds = $counts->pluck('source_id')->unique()
            ->map(fn (mixed $id): ?int => $sources->get((int) $id)?->framework_id)
            ->filter()->unique()->values()->all();
        $groupOf = $this->groupMap($frameworkIds);

        // source id → ['total' => int, 'ungrouped' => int, 'groups' => [group node id => int]]
        $tally = [];
        foreach ($counts as $count) {
            $sourceId = (int) $count->source_id;
            $total = (int) $count->total;
            $group = $count->framework_node_id !== null ? ($groupOf[(int) $count->framework_node_id] ?? null) : null;

            $tally[$sourceId] ??= ['total' => 0, 'ungrouped' => 0, 'groups' => []];
            $tally[$sourceId]['total'] += $total;

            if ($group === null) {
                $tally[$sourceId]['ungrouped'] += $total;
            } else {
                $tally[$sourceId]['groups'][$group['id']] = ($tally[$sourceId]['groups'][$group['id']] ?? 0) + $total;
            }
        }

        $openSet = array_flip($open);
        $rows = $this->openRows($filtered, $tally, $openSet, $groupOf, $row);

        $result = [];
        $grandTotal = 0;
        // The sources come in the register's order (name, version), and only those with matches.
        foreach ($sources as $sourceId => $source) {
            if (! isset($tally[$sourceId])) {
                continue;
            }

            $sourceOpen = isset($openSet[(string) $sourceId]);
            $groups = [];
            foreach ($tally[$sourceId]['groups'] as $groupId => $count) {
                $group = $groupOf[$groupId];
                $key = "{$sourceId}:{$groupId}";
                $groupOpen = $sourceOpen && isset($openSet[$key]);
                $groups[] = [
                    'key' => $key,
                    'reference' => $group['clause'],
                    'title' => $group['title'],
                    'context' => $group['context'],
                    'position' => $group['position'],
                    'count' => $count,
                    'open' => $groupOpen,
                    'requirements' => $groupOpen ? ($rows[$key] ?? []) : null,
                ];
            }
            usort($groups, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            $ungrouped = $tally[$sourceId]['ungrouped'];
            $grandTotal += $tally[$sourceId]['total'];
            $result[] = [
                'key' => (string) $sourceId,
                'id' => $sourceId,
                'label' => $source->originLabel(),
                'name' => $source->name,
                'version' => $source->version,
                'from_library' => $source->isFromLibrary(),
                'count' => $tally[$sourceId]['total'],
                'open' => $sourceOpen,
                'groups' => array_map(fn (array $group): array => array_diff_key($group, ['position' => true]), $groups),
                // Requirements without a chapter — every requirement of a source registered by hand.
                'ungrouped_count' => $ungrouped,
                'requirements' => $sourceOpen && $ungrouped > 0 ? ($rows[(string) $sourceId] ?? []) : null,
            ];
        }

        return ['sources' => $result, 'total' => $grandTotal];
    }

    /**
     * Every node of the given frameworks mapped to the group it belongs in: its top section, or for
     * a section of themes of controls, the theme.
     *
     * @param  list<int>  $frameworkIds
     * @return array<int, array{id: int, clause: string, title: string, position: int, context: array{reference: string, title: string}|null}>
     */
    private function groupMap(array $frameworkIds): array
    {
        if ($frameworkIds === []) {
            return [];
        }

        $nodes = ComplianceFrameworkNode::query()
            ->whereIn('framework_id', $frameworkIds)
            ->get(['id', 'parent_id', 'clause', 'title', 'kind', 'position', 'applicability_rule'])
            ->keyBy('id');

        $children = [];
        foreach ($nodes as $node) {
            if ($node->parent_id !== null) {
                $children[(int) $node->parent_id][] = $node;
            }
        }

        // The path from each node up to its top section.
        $pathOf = function (ComplianceFrameworkNode $node) use ($nodes): array {
            $path = [$node];
            $seen = [(int) $node->id => true];
            while ($node->parent_id !== null && ($parent = $nodes->get((int) $node->parent_id)) !== null && ! isset($seen[(int) $parent->id])) {
                $seen[(int) $parent->id] = true;
                array_unshift($path, $parent);
                $node = $parent;
            }

            return $path;
        };

        $themed = [];
        foreach ($nodes as $node) {
            if ($node->parent_id === null) {
                $themed[(int) $node->id] = $this->isSectionOfControlThemes($node, $children);
            }
        }

        $map = [];
        foreach ($nodes as $node) {
            $path = $pathOf($node);
            $top = $path[0];
            $split = ($themed[(int) $top->id] ?? false) && isset($path[1]);
            $group = $split ? $path[1] : $top;

            $map[(int) $node->id] = [
                'id' => (int) $group->id,
                'clause' => (string) $group->clause,
                'title' => (string) $group->title,
                'position' => (int) $group->position,
                'context' => $split ? ['reference' => (string) $top->clause, 'title' => (string) $top->title] : null,
            ];
        }

        return $map;
    }

    /**
     * A top section that holds only sections, under which every requirement is a selectable control.
     *
     * @param  array<int, list<ComplianceFrameworkNode>>  $children
     */
    private function isSectionOfControlThemes(ComplianceFrameworkNode $top, array $children): bool
    {
        $themes = $children[(int) $top->id] ?? [];

        if ($themes === []) {
            return false;
        }

        $controls = 0;
        $stack = [];
        foreach ($themes as $theme) {
            if ($theme->kind !== ComplianceFrameworkNode::KIND_SECTION) {
                return false;
            }
            $stack[] = $theme;
        }

        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($children[(int) $node->id] ?? [] as $child) {
                if ($child->kind === ComplianceFrameworkNode::KIND_SECTION) {
                    $stack[] = $child;
                } elseif ($child->isControl()) {
                    $controls++;
                } else {
                    return false;
                }
            }
        }

        return $controls > 0;
    }

    /**
     * The rows of the open groups (and of open sources' requirements without a chapter), in one
     * query and in register order, keyed by group key («12:345») or source key («12»).
     *
     * @param  array<int, array{total: int, ungrouped: int, groups: array<int, int>}>  $tally
     * @param  array<string, int>  $openSet
     * @param  array<int, array<string, mixed>>  $groupOf
     * @return array<string, list<array<string, mixed>>>
     */
    private function openRows(Builder $filtered, array $tally, array $openSet, array $groupOf, callable $row): array
    {
        $wantedGroups = [];
        $wantedUngrouped = [];
        foreach ($tally as $sourceId => $counts) {
            if (! isset($openSet[(string) $sourceId])) {
                continue;
            }
            if ($counts['ungrouped'] > 0) {
                $wantedUngrouped[] = $sourceId;
            }
            foreach (array_keys($counts['groups']) as $groupId) {
                if (isset($openSet["{$sourceId}:{$groupId}"])) {
                    $wantedGroups[$sourceId][] = $groupId;
                }
            }
        }

        if ($wantedGroups === [] && $wantedUngrouped === []) {
            return [];
        }

        // Every node that falls in a wanted group, per source.
        $nodeIds = [];
        foreach ($wantedGroups as $sourceId => $groupIds) {
            $wanted = array_flip($groupIds);
            foreach ($groupOf as $nodeId => $group) {
                if (isset($wanted[$group['id']])) {
                    $nodeIds[$sourceId][] = $nodeId;
                }
            }
        }

        $requirements = (clone $filtered)
            ->where(function (Builder $inner) use ($nodeIds, $wantedUngrouped): void {
                foreach ($nodeIds as $sourceId => $ids) {
                    $inner->orWhere(fn (Builder $one) => $one
                        ->where('compliance_requirements.source_id', $sourceId)
                        ->whereIn('compliance_requirements.framework_node_id', $ids));
                }
                if ($wantedUngrouped !== []) {
                    $inner->orWhere(fn (Builder $one) => $one
                        ->whereIn('compliance_requirements.source_id', $wantedUngrouped)
                        ->whereNull('compliance_requirements.framework_node_id'));
                }
            })
            ->inRegisterOrder()
            ->with(['source:id,name,version,framework_id', 'owner:id,name', 'frameworkNode:id,applicability_rule'])
            ->get();

        $rows = [];
        foreach ($requirements as $requirement) {
            $sourceId = (int) $requirement->source_id;
            $key = $requirement->framework_node_id !== null && isset($groupOf[(int) $requirement->framework_node_id])
                ? $sourceId.':'.$groupOf[(int) $requirement->framework_node_id]['id']
                : (string) $sourceId;
            $rows[$key][] = $row($requirement);
        }

        return $rows;
    }
}
