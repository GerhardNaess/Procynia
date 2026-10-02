<?php

namespace App\Services\Quality;

use App\Models\QualityProcessBlueprint;

/**
 * Asks whether a flow is coherent — not whether it is drawable.
 *
 * WHY THIS IS SEPARATE FROM QualityProcessBlueprintService::normalise().
 *
 * normalise() is deliberately forgiving: a person mid-edit has deleted a node an arrow still points
 * at, and refusing the save would trap them in the one state they cannot leave. It therefore drops
 * what it cannot resolve and guarantees only that the result can be drawn.
 *
 * A model's proposal is the opposite situation. Nobody is mid-edit, there is nothing to trap, and
 * silently dropping half of what was proposed would hand the user a diagram that quietly disagrees
 * with the description they just wrote. So an interpreted flow is held to the stronger standard
 * this class states: it must start somewhere, end somewhere, and every node must sit on a path
 * between the two.
 *
 * It reports rather than throws. The caller uses the list twice — once to ask the model to repair
 * its own proposal, and once, if that fails, to tell the user what was wrong in their own language.
 * Each problem is a translation key plus its replacements, never a built sentence, because the
 * second of those two audiences reads it.
 */
class QualityProcessFlowValidator
{
    /**
     * Reference problems in a payload the model produced, found before normalisation.
     *
     * Order matters: normalise() resolves an edge to a deleted node by dropping it, so by the time
     * a payload is drawable the evidence that the model invented a reference is gone. This runs on
     * the raw mapping, where a `to` that points nowhere is still visible.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, replace: array<string, string>}>
     */
    public function referenceProblems(array $payload): array
    {
        $problems = [];
        $keys = [];
        $seen = [];

        foreach ($payload['nodes'] ?? [] as $node) {
            $key = (string) ($node['key'] ?? '');

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $problems[] = $this->problem('duplicate_node', ['key' => $key]);

                continue;
            }

            $seen[$key] = true;
            $keys[] = $key;
        }

        foreach ($payload['edges'] ?? [] as $edge) {
            foreach (['from', 'to'] as $end) {
                $reference = (string) ($edge[$end] ?? '');

                if (! in_array($reference, $keys, true)) {
                    $problems[] = $this->problem('unknown_reference', ['key' => $reference === '' ? '—' : $reference]);
                }
            }
        }

        return $this->unique($problems);
    }

    /**
     * Graph problems in a normalised payload: where it starts, where it ends, and whether every
     * node is on a path between them.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, replace: array<string, string>}>
     */
    public function graphProblems(array $payload): array
    {
        $nodes = array_values($payload['nodes'] ?? []);
        $edges = array_values($payload['edges'] ?? []);

        if ($nodes === []) {
            return [$this->problem('no_nodes', [])];
        }

        $problems = array_merge(
            $this->endpointProblems($nodes, $edges),
            $this->decisionProblems($nodes, $edges),
            $this->connectivityProblems($nodes, $edges),
        );

        return $this->unique($problems);
    }

    /**
     * Exactly one start, at least one end, and neither pointing the wrong way.
     *
     * Several ends are ordinary — "løst" and "eskalert" are both endings — but two starts are not a
     * flow with two beginnings, they are two flows that have been described as one.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array{key: string, replace: array<string, string>}>
     */
    private function endpointProblems(array $nodes, array $edges): array
    {
        $problems = [];

        $starts = $this->ofType($nodes, QualityProcessBlueprint::NODE_START);
        $ends = $this->ofType($nodes, QualityProcessBlueprint::NODE_END);

        if ($starts === []) {
            $problems[] = $this->problem('no_start', []);
        }

        if (count($starts) > 1) {
            $problems[] = $this->problem('several_starts', ['count' => (string) count($starts)]);
        }

        if ($ends === []) {
            $problems[] = $this->problem('no_end', []);
        }

        foreach ($nodes as $node) {
            $key = (string) $node['key'];
            $type = (string) $node['type'];
            $outgoing = $this->outgoing($edges, $key);
            $incoming = $this->incoming($edges, $key);

            // An end with something after it is not an end, and a node with nothing after it is one
            // whether it says so or not. Both are the same mistake seen from either side: the model
            // labelled the ending in the wrong place.
            if ($type === QualityProcessBlueprint::NODE_END && $outgoing !== []) {
                $problems[] = $this->problem('end_continues', ['label' => (string) $node['label']]);
            }

            if ($type !== QualityProcessBlueprint::NODE_END && $outgoing === []) {
                $problems[] = $this->problem('dead_end', ['label' => (string) $node['label']]);
            }

            if ($type !== QualityProcessBlueprint::NODE_START && $incoming === []) {
                $problems[] = $this->problem('orphan', ['label' => (string) $node['label']]);
            }

            if ($type === QualityProcessBlueprint::NODE_START && $incoming !== []) {
                $problems[] = $this->problem('start_has_incoming', ['label' => (string) $node['label']]);
            }
        }

        return $problems;
    }

    /**
     * A decision that branches one way is not a decision.
     *
     * The outcomes have to be named, and named differently, or the reader of the diagram cannot
     * tell which arrow their case follows — which is the entire reason the branch is drawn.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array{key: string, replace: array<string, string>}>
     */
    private function decisionProblems(array $nodes, array $edges): array
    {
        $problems = [];

        foreach ($this->ofType($nodes, QualityProcessBlueprint::NODE_DECISION) as $node) {
            $outgoing = $this->outgoing($edges, (string) $node['key']);
            $label = (string) $node['label'];

            if (count($outgoing) < 2) {
                $problems[] = $this->problem('decision_needs_two_outcomes', ['label' => $label]);

                continue;
            }

            $labels = array_map(
                static fn (array $edge): string => trim((string) ($edge['label'] ?? '')),
                $outgoing,
            );

            if (in_array('', $labels, true)) {
                $problems[] = $this->problem('decision_outcome_unnamed', ['label' => $label]);
            }

            $named = array_filter($labels, static fn (string $text): bool => $text !== '');

            if (count(array_unique(array_map('mb_strtolower', $named))) !== count($named)) {
                $problems[] = $this->problem('decision_outcomes_repeat', ['label' => $label]);
            }
        }

        return $problems;
    }

    /**
     * Every node reachable from the start, and every node able to reach an end.
     *
     * Two sweeps rather than one: forward from the start, then backward from every end. A node that
     * fails the first was never going to be executed; a node that fails the second is work the
     * process never comes back from. They are different mistakes and the user fixes them
     * differently, so they are reported as different problems even when one node has both.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<array{key: string, replace: array<string, string>}>
     */
    private function connectivityProblems(array $nodes, array $edges): array
    {
        $starts = array_column($this->ofType($nodes, QualityProcessBlueprint::NODE_START), 'key');
        $ends = array_column($this->ofType($nodes, QualityProcessBlueprint::NODE_END), 'key');

        // Without an endpoint to sweep from there is nothing to say here that endpointProblems()
        // has not already said, and reporting every node as unreachable would bury it.
        if ($starts === [] || $ends === []) {
            return [];
        }

        $forward = [];
        $backward = [];

        foreach ($edges as $edge) {
            $forward[(string) $edge['from']][] = (string) $edge['to'];
            $backward[(string) $edge['to']][] = (string) $edge['from'];
        }

        $reachable = $this->reach($starts, $forward);
        $productive = $this->reach($ends, $backward);

        $problems = [];

        foreach ($nodes as $node) {
            $key = (string) $node['key'];
            $label = (string) $node['label'];

            if (! isset($reachable[$key])) {
                $problems[] = $this->problem('unreachable', ['label' => $label]);
            }

            if (! isset($productive[$key])) {
                $problems[] = $this->problem('never_ends', ['label' => $label]);
            }
        }

        return $problems;
    }

    /**
     * Iterative breadth-first sweep. Iterative rather than recursive because a rework loop — "ikke
     * godkjent, tilbake til steg 2" — is an ordinary thing for a process to contain, and the
     * visited set is what stops it rather than the call stack.
     *
     * @param  list<string>  $seeds
     * @param  array<string, list<string>>  $adjacency
     * @return array<string, bool>
     */
    private function reach(array $seeds, array $adjacency): array
    {
        $seen = [];
        $queue = $seeds;

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            foreach ($adjacency[$key] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return $seen;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function ofType(array $nodes, string $type): array
    {
        return array_values(array_filter(
            $nodes,
            static fn (array $node): bool => (string) ($node['type'] ?? '') === $type,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function outgoing(array $edges, string $key): array
    {
        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => (string) ($edge['from'] ?? '') === $key,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function incoming(array $edges, string $key): array
    {
        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => (string) ($edge['to'] ?? '') === $key,
        ));
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{key: string, replace: array<string, string>}
     */
    private function problem(string $key, array $replace): array
    {
        return ['key' => $key, 'replace' => $replace];
    }

    /**
     * The same problem found twice is one problem. It happens honestly — a node can be both
     * unreachable and a dead end — and listing it twice makes the repair prompt and the error
     * message read as though there were more wrong than there is.
     *
     * @param  list<array{key: string, replace: array<string, string>}>  $problems
     * @return list<array{key: string, replace: array<string, string>}>
     */
    private function unique(array $problems): array
    {
        $seen = [];
        $unique = [];

        foreach ($problems as $problem) {
            $identity = $problem['key'].'#'.implode('|', $problem['replace']);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $unique[] = $problem;
        }

        return $unique;
    }

    /**
     * The problems as sentences, for a repair prompt and for the user.
     *
     * @param  list<array{key: string, replace: array<string, string>}>  $problems
     * @return list<string>
     */
    public function describe(array $problems, ?string $locale = null): array
    {
        return array_map(
            static fn (array $problem): string => __(
                'procynia.quality.flow_problems.'.$problem['key'],
                $problem['replace'],
                $locale,
            ),
            $problems,
        );
    }
}
