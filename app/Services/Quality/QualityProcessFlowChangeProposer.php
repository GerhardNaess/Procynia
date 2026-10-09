<?php

namespace App\Services\Quality;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Services\Ai\Quality\ProcessFlowChangeAiClient;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Support\Ai\AiCallContextScope;
use Illuminate\Support\Str;
use Throwable;

/**
 * An instruction about an existing flow in, a proposed set of changes out.
 *
 * WHERE THIS SITS.
 *
 *   working version + instruction -> ProcessFlowChangeAiClient -> apply -> normalise -> validate
 *
 * The model proposes operations; this class applies them to a copy of the working version and
 * judges the result with the same two tools an interpreted flow is judged with:
 * QualityProcessFlowValidator::referenceProblems() before normalise(), graphProblems() after. What
 * comes out is the same lanes/nodes/edges payload every other path produces — there is one flow
 * model, and a change is one more way to arrive at a version of it.
 *
 * WHY NOTHING HERE WRITES.
 *
 * The same reason QualityProcessFlowInterpreter gives. A change is shown before it is accepted, and
 * the working version must survive a proposal the user did not want. Accepting is a separate step:
 * accept() re-applies the operations and hands back a payload, and the controller stores it the way
 * every other edit is stored.
 *
 * WHY ONLY NEW PROBLEMS COUNT.
 *
 * A working version saved by hand is held to normalise()'s forgiving standard, not the validator's
 * strict one, so it can already contain a dead end or an unnamed branch. Refusing a change because
 * of a problem it did not cause would make every instruction about such a flow fail, and the repair
 * prompt would push the model to "fix" things it was never asked to touch. So the validator runs on
 * the flow before and after, and only what the change introduced is a problem.
 */
class QualityProcessFlowChangeProposer
{
    /** The lane a new role is given, numbered past whatever the flow already uses. */
    private const NEW_LANE_PREFIX = 'rolle';

    public function __construct(
        private readonly ProcessFlowChangeAiClient $client,
        private readonly QualityProcessBlueprintService $blueprints,
        private readonly QualityProcessFlowValidator $validator,
        private readonly AiCallContextScope $contextScope,
    ) {}

    /**
     * @return array{instruction: string, summary: string, changes: list<array<string, mixed>>, questions: list<string>, operations: list<array<string, mixed>>, payload: array<string, mixed>, base_hash: string, model: string, repaired: bool}
     *
     * @throws ProcessFlowInterpretationException
     */
    public function propose(int $customerId, QualityItem $item, QualityProcessBlueprint $blueprint, string $instruction, string $languageCode): array
    {
        $instruction = trim($instruction);

        return $this->contextScope->within(
            new AiCallContext(
                customerId: (int) $item->customer_id,
                feature: 'quality',
                operation: 'quality.propose_process_change',
                resourceType: 'quality_item',
                resourceId: (int) $item->id,
            ),
            fn (): array => $this->run($customerId, $item, $blueprint, $instruction, $languageCode),
        );
    }

    /**
     * "Godta endringer": the flow a proposal's operations produce, applied to the working version
     * as it is stored now.
     *
     * WHY THE OPERATIONS ARE APPLIED AGAIN. The browser sends back the operations and the
     * fingerprint, not the flow it was shown. A flow posted from the browser would be whatever the
     * browser says; operations re-applied here are the change the user read, on the version it was
     * written for, judged by the same validators as when it was proposed. No model is called.
     *
     * WHY A MOVED-ON FLOW REFUSES. Operations name steps and connections by key. Applied to a flow
     * somebody edited in between, "fjern forbindelsen A → B" can mean something other than what
     * the list said, or quietly undo the edit. So a fingerprint that no longer matches is a refusal
     * with one piece of advice — ask again — never a best-effort merge.
     *
     * Only problems the change introduces count, exactly as in propose(): the working version is
     * the same one, so the same proposal passes the same way.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return array<string, mixed> The lanes/nodes/edges payload to store as the working version.
     *
     * @throws ProcessFlowInterpretationException
     */
    public function accept(int $customerId, QualityItem $item, QualityProcessBlueprint $blueprint, array $operations, string $baseHash): array
    {
        $current = self::current($blueprint);

        if (! hash_equals(self::fingerprint($current), $baseHash)) {
            throw ProcessFlowInterpretationException::changeStale();
        }

        $checked = $this->check($customerId, $item, $current, array_values($operations), $this->baselineOf($current));

        if ($checked['problems'] !== []) {
            throw ProcessFlowInterpretationException::changeUnusable(
                $this->validator->describe($checked['problems']),
            );
        }

        if ($checked['changes'] === []) {
            throw ProcessFlowInterpretationException::changeEmpty();
        }

        return $checked['payload'];
    }

    /**
     * Which working version a proposal was made against.
     *
     * Carried with the proposal so the step that accepts it can tell whether the flow has moved on
     * in between — operations applied to a different flow than the one they were written for are
     * a different change.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        return sha1((string) json_encode([
            $payload['lanes'] ?? [],
            $payload['nodes'] ?? [],
            $payload['edges'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{instruction: string, summary: string, changes: list<array<string, mixed>>, questions: list<string>, payload: array<string, mixed>, base_hash: string, model: string, repaired: bool}
     */
    private function run(int $customerId, QualityItem $item, QualityProcessBlueprint $blueprint, string $instruction, string $languageCode): array
    {
        $current = self::current($blueprint);

        $title = (string) $item->title;
        $view = $this->modelView($current, $blueprint->description);
        $baseline = $this->baselineOf($current);

        $proposal = $this->attempt(fn (): array => $this->client->propose($title, $view, $instruction, $languageCode));
        $checked = $this->check($customerId, $item, $current, $proposal['operations'], $baseline);

        if ($checked['problems'] === []) {
            return $this->result($current, $checked, $proposal, $instruction, repaired: false);
        }

        $repaired = $this->attempt(fn (): array => $this->client->repair(
            $title,
            $view,
            $instruction,
            $proposal['operations'],
            $this->validator->describe($checked['problems'], 'en'),
            $languageCode,
        ));

        $recheck = $this->check($customerId, $item, $current, $repaired['operations'], $baseline);

        if ($recheck['problems'] !== []) {
            throw ProcessFlowInterpretationException::changeUnusable(
                $this->validator->describe($recheck['problems']),
            );
        }

        return $this->result($current, $recheck, $repaired, $instruction, repaired: true);
    }

    /**
     * @return array{lanes: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function current(QualityProcessBlueprint $blueprint): array
    {
        return [
            'lanes' => $blueprint->lanes(),
            'nodes' => $blueprint->nodes(),
            'edges' => $blueprint->edges(),
        ];
    }

    /**
     * What the working version already gets wrong, so a change is only held to what it adds.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, bool>
     */
    private function baselineOf(array $current): array
    {
        return $this->problemIdentities(array_merge(
            $this->validator->referenceProblems($current),
            $this->validator->graphProblems($current),
        ));
    }

    /**
     * The working version as the model is shown it: real keys, plain role names, and the model's own
     * vocabulary for step types, so the flow it reads and the operations it writes speak one
     * language. The description is context — it is what the flow was read from — and is never
     * something the operations can change.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function modelView(array $current, ?string $description): array
    {
        $roles = array_column($current['lanes'], 'label', 'key');

        return [
            'description' => $description,
            'roles' => array_values($roles),
            'steps' => array_map(static fn (array $node): array => [
                'id' => (string) $node['key'],
                'type' => self::modelType((string) $node['type']),
                'role' => $roles[$node['lane'] ?? ''] ?? null,
                'label' => (string) $node['label'],
                'description' => $node['description'] ?? null,
            ], $current['nodes']),
            'flows' => array_map(static fn (array $edge): array => [
                'from' => (string) $edge['from'],
                'to' => (string) $edge['to'],
                'condition' => $edge['label'] ?? null,
            ], $current['edges']),
        ];
    }

    /**
     * Apply, normalise and judge one set of operations.
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $operations
     * @param  array<string, bool>  $baseline
     * @return array{payload: array<string, mixed>, changes: list<array<string, mixed>>, problems: list<array{key: string, replace: array<string, string>}>}
     */
    private function check(int $customerId, QualityItem $item, array $current, array $operations, array $baseline): array
    {
        $applied = $this->apply($current, $operations);

        if ($applied['problems'] !== []) {
            return ['payload' => $current, 'changes' => [], 'problems' => $applied['problems']];
        }

        $problems = $this->validator->referenceProblems($applied['payload']);

        try {
            $payload = $this->blueprints->normalise($applied['payload'], $customerId, (int) $item->id);
        } catch (Throwable $exception) {
            throw ProcessFlowInterpretationException::changeUnusable([$exception->getMessage()]);
        }

        $introduced = array_values(array_filter(
            array_merge($problems, $this->validator->graphProblems($payload)),
            fn (array $problem): bool => ! isset($baseline[$this->identity($problem)]),
        ));

        return ['payload' => $payload, 'changes' => $applied['changes'], 'problems' => $introduced];
    }

    /**
     * Apply operations to a copy of the working version.
     *
     * Steps first, then connections, each in the order given: a connection to a step added further
     * down the list is an ordinary thing for a model to write, and refusing it would make the order
     * of a JSON array part of the contract. A new step with no role named takes the role of the
     * step that leads into it — the same default the diagram's "+" offers — which is only known once
     * the connections are in.
     *
     * Every operation that cannot be applied as written is a problem, never a silent skip: an
     * operation the user is shown and that did nothing would be a lie in the list.
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $operations
     * @return array{payload: array<string, mixed>, changes: list<array<string, mixed>>, problems: list<array{key: string, replace: array<string, string>}>}
     */
    private function apply(array $current, array $operations): array
    {
        $lanes = $current['lanes'];
        $nodes = [];

        foreach ($current['nodes'] as $node) {
            $nodes[(string) $node['key']] = $node;
        }

        $edges = array_values($current['edges']);
        $existingKeys = array_keys($nodes);
        $aliases = [];
        $removed = [];
        $roleless = [];
        $changes = [];
        $problems = [];

        $resolve = static function (?string $reference) use (&$aliases, &$nodes): ?string {
            if ($reference === null) {
                return null;
            }

            $key = $aliases[$reference] ?? $reference;

            return isset($nodes[$key]) ? $key : null;
        };

        $stepOps = array_filter($operations, static fn (array $op): bool => in_array($op['op'], [
            ProcessFlowChangeAiClient::OP_ADD_STEP,
            ProcessFlowChangeAiClient::OP_UPDATE_STEP,
            ProcessFlowChangeAiClient::OP_REMOVE_STEP,
        ], true));
        $flowOps = array_diff_key($operations, $stepOps);

        foreach ($stepOps as $index => $op) {
            $number = ['number' => (string) ($index + 1)];

            switch ($op['op']) {
                case ProcessFlowChangeAiClient::OP_ADD_STEP:
                    if ($op['step'] === null || $op['label'] === null) {
                        $problems[] = $this->problem('change_incomplete', $number);

                        break;
                    }

                    if (isset($aliases[$op['step']]) || in_array($op['step'], $existingKeys, true)) {
                        $problems[] = $this->problem('change_step_exists', ['key' => $op['step']]);

                        break;
                    }

                    $key = $this->freshKey($op['step'], array_merge($existingKeys, array_keys($nodes), array_keys($removed)));
                    $aliases[$op['step']] = $key;

                    $lane = $op['role'] === null ? null : $this->laneFor($op['role'], $lanes, $changes, $index);

                    if ($lane === null) {
                        $roleless[] = $key;
                    }

                    $nodes[$key] = [
                        'key' => $key,
                        'lane' => $lane ?? '',
                        'type' => self::storedType($op['type'] ?? 'activity'),
                        'label' => $op['label'],
                        'description' => $op['description'],
                    ];
                    $changes[] = ['order' => $index, 'kind' => 'add_step', 'key' => $key, 'type' => self::modelType($nodes[$key]['type']), 'description' => $op['description']];

                    break;

                case ProcessFlowChangeAiClient::OP_UPDATE_STEP:
                    $key = $resolve($op['step']);

                    if ($key === null) {
                        $problems[] = $this->problem('change_unknown_step', ['key' => (string) ($op['step'] ?? '—')]);

                        break;
                    }

                    if ($op['label'] === null && $op['role'] === null && $op['description'] === null) {
                        $problems[] = $this->problem('change_incomplete', $number);

                        break;
                    }

                    $before = $nodes[$key];
                    $fields = [];

                    if ($op['label'] !== null && $op['label'] !== $before['label']) {
                        $nodes[$key]['label'] = $op['label'];
                        $fields[] = ['field' => 'label', 'from' => $before['label'], 'to' => $op['label']];
                    }

                    if ($op['role'] !== null) {
                        $lane = $this->laneFor($op['role'], $lanes, $changes, $index);

                        if ($lane !== $before['lane']) {
                            $nodes[$key]['lane'] = $lane;
                            $fields[] = [
                                'field' => 'role',
                                'from' => $this->laneLabel($lanes, (string) $before['lane']),
                                'to' => $this->laneLabel($lanes, $lane),
                            ];
                        }
                    }

                    if ($op['description'] !== null && $op['description'] !== ($before['description'] ?? null)) {
                        $nodes[$key]['description'] = $op['description'];
                        $fields[] = ['field' => 'description', 'from' => $before['description'] ?? null, 'to' => $op['description']];
                    }

                    // An update that restates what is already there changes nothing, so there is
                    // nothing to show for it. Not a problem: the flow is exactly what was asked for.
                    if ($fields !== []) {
                        $changes[] = ['order' => $index, 'kind' => 'update_step', 'key' => $key, 'previous_label' => $before['label'], 'type' => self::modelType((string) $before['type']), 'fields' => $fields];
                    }

                    break;

                case ProcessFlowChangeAiClient::OP_REMOVE_STEP:
                    $key = $resolve($op['step']);

                    if ($key === null) {
                        $problems[] = $this->problem('change_unknown_step', ['key' => (string) ($op['step'] ?? '—')]);

                        break;
                    }

                    if ($nodes[$key]['type'] === QualityProcessBlueprint::NODE_START) {
                        $problems[] = $this->problem('change_cannot_remove_start', []);

                        break;
                    }

                    $removed[$key] = $nodes[$key];
                    unset($nodes[$key]);

                    // Its connections go with it. Leaving them would be dangling edges, which
                    // normalise() drops anyway — saying so here is what puts them in the list.
                    $edges = array_values(array_filter(
                        $edges,
                        static fn (array $edge): bool => $edge['from'] !== $key && $edge['to'] !== $key,
                    ));

                    $changes[] = ['order' => $index, 'kind' => 'remove_step', 'key' => $key, 'type' => self::modelType((string) $removed[$key]['type'])];

                    break;
            }
        }

        foreach ($flowOps as $index => $op) {
            $from = $resolve($op['from']);
            $to = $resolve($op['to']);

            if ($op['from'] === null || $op['to'] === null) {
                $problems[] = $this->problem('change_incomplete', ['number' => (string) ($index + 1)]);

                continue;
            }

            if ($from === null || $to === null) {
                $problems[] = $this->problem('change_unknown_step', ['key' => $from === null ? $op['from'] : $op['to']]);

                continue;
            }

            $matching = array_keys(array_filter(
                $edges,
                static fn (array $edge): bool => $edge['from'] === $from && $edge['to'] === $to,
            ));

            switch ($op['op']) {
                case ProcessFlowChangeAiClient::OP_ADD_FLOW:
                    if ($from === $to) {
                        $problems[] = $this->problem('change_self_flow', ['key' => $op['from']]);

                        break;
                    }

                    foreach ($matching as $position) {
                        if (($edges[$position]['label'] ?? null) === $op['condition']) {
                            $problems[] = $this->problem('change_flow_exists', ['from' => $op['from'], 'to' => $op['to']]);

                            break 2;
                        }
                    }

                    $edges[] = ['from' => $from, 'to' => $to, 'label' => $op['condition']];
                    $changes[] = ['order' => $index, 'kind' => 'add_flow', 'from' => $from, 'to' => $to, 'condition' => $op['condition']];

                    break;

                case ProcessFlowChangeAiClient::OP_UPDATE_FLOW:
                    if ($matching === []) {
                        $problems[] = $this->problem('change_unknown_flow', ['from' => $op['from'], 'to' => $op['to']]);

                        break;
                    }

                    if (count($matching) > 1) {
                        $problems[] = $this->problem('change_ambiguous_flow', ['from' => $op['from'], 'to' => $op['to']]);

                        break;
                    }

                    $previous = $edges[$matching[0]]['label'] ?? null;

                    if ($previous !== $op['condition']) {
                        $edges[$matching[0]]['label'] = $op['condition'];
                        $changes[] = ['order' => $index, 'kind' => 'update_flow', 'from' => $from, 'to' => $to, 'previous_condition' => $previous, 'condition' => $op['condition']];
                    }

                    break;

                case ProcessFlowChangeAiClient::OP_REMOVE_FLOW:
                    if ($matching === []) {
                        $problems[] = $this->problem('change_unknown_flow', ['from' => $op['from'], 'to' => $op['to']]);

                        break;
                    }

                    foreach ($matching as $position) {
                        $changes[] = ['order' => $index, 'kind' => 'remove_flow', 'from' => $from, 'to' => $to, 'condition' => $edges[$position]['label'] ?? null];
                        unset($edges[$position]);
                    }

                    $edges = array_values($edges);

                    break;
            }
        }

        foreach ($roleless as $key) {
            if (! isset($nodes[$key])) {
                continue;
            }

            $nodes[$key]['lane'] = $this->inheritedLane($key, $nodes, $edges) ?? (string) ($lanes[0]['key'] ?? '');
        }

        $payload = ['lanes' => $lanes, 'nodes' => array_values($nodes), 'edges' => $edges];

        return [
            'payload' => $payload,
            'changes' => $this->describeChanges($changes, $nodes, $removed, $lanes),
            'problems' => $this->unique($problems),
        ];
    }

    /**
     * The lane a role name belongs to, creating it when the flow has no such role.
     *
     * Matched case-insensitively, as the interpreter matches, so "økonomi" and "Økonomi" stay one
     * band. A new role is itself a change the user should see, so it goes into the list where it was
     * first named.
     *
     * @param  list<array<string, string>>  $lanes
     * @param  list<array<string, mixed>>  $changes
     */
    private function laneFor(string $role, array &$lanes, array &$changes, int $order): string
    {
        $needle = mb_strtolower(trim($role));

        foreach ($lanes as $lane) {
            if (mb_strtolower($lane['label']) === $needle) {
                return $lane['key'];
            }
        }

        $keys = array_column($lanes, 'key');
        $number = count($lanes) + 1;

        while (in_array(self::NEW_LANE_PREFIX.'-'.$number, $keys, true)) {
            $number++;
        }

        $label = Str::ucfirst(trim($role));
        $lanes[] = ['key' => self::NEW_LANE_PREFIX.'-'.$number, 'label' => $label];
        $changes[] = ['order' => $order, 'kind' => 'add_role', 'label' => $label];

        return self::NEW_LANE_PREFIX.'-'.$number;
    }

    /**
     * @param  list<array<string, string>>  $lanes
     */
    private function laneLabel(array $lanes, string $key): ?string
    {
        foreach ($lanes as $lane) {
            if ($lane['key'] === $key) {
                return $lane['label'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function inheritedLane(string $key, array $nodes, array $edges): ?string
    {
        foreach ($edges as $edge) {
            if ($edge['to'] === $key && isset($nodes[$edge['from']]) && $nodes[$edge['from']]['lane'] !== '') {
                return (string) $nodes[$edge['from']]['lane'];
            }
        }

        return null;
    }

    /**
     * The changes as the user reads them: in the order the model gave them, with every step named by
     * its label rather than its key.
     *
     * Labels are read once everything is applied, so a connection to a step that was renamed in the
     * same proposal shows the new name — which is the name the user will see in the diagram.
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $removed
     * @param  list<array<string, string>>  $lanes
     * @return list<array<string, mixed>>
     */
    private function describeChanges(array $changes, array $nodes, array $removed, array $lanes): array
    {
        $label = static fn (string $key): string => (string) ($nodes[$key]['label'] ?? $removed[$key]['label'] ?? $key);
        $role = fn (string $key): ?string => $this->laneLabel($lanes, (string) ($nodes[$key]['lane'] ?? $removed[$key]['lane'] ?? ''));

        usort($changes, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static function (array $change) use ($label, $role): array {
            unset($change['order']);

            if (isset($change['key'])) {
                $change['label'] = $label($change['key']);
                $change['role'] = $role($change['key']);
                unset($change['key']);
            }

            if (isset($change['from'])) {
                $change['from'] = $label($change['from']);
                $change['to'] = $label($change['to']);
            }

            return $change;
        }, $changes);
    }

    /**
     * @param  list<string>  $taken
     */
    private function freshKey(string $preferred, array $taken): string
    {
        $base = Str::limit(Str::slug($preferred), 60, '') ?: 'steg';
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private static function modelType(string $stored): string
    {
        return match ($stored) {
            QualityProcessBlueprint::NODE_STEP => 'activity',
            default => $stored,
        };
    }

    private static function storedType(string $model): string
    {
        return match ($model) {
            'decision' => QualityProcessBlueprint::NODE_DECISION,
            'end' => QualityProcessBlueprint::NODE_END,
            default => QualityProcessBlueprint::NODE_STEP,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array{payload: array<string, mixed>, changes: list<array<string, mixed>>}  $checked
     * @param  array{summary: string, operations: list<array<string, mixed>>, questions: list<string>, model: string}  $proposal
     * @return array{instruction: string, summary: string, changes: list<array<string, mixed>>, questions: list<string>, operations: list<array<string, mixed>>, payload: array<string, mixed>, base_hash: string, model: string, repaired: bool}
     */
    private function result(array $current, array $checked, array $proposal, string $instruction, bool $repaired): array
    {
        return [
            'instruction' => $instruction,
            'summary' => $proposal['summary'],
            'changes' => $checked['changes'],
            'questions' => $proposal['questions'],
            // The operations behind the list — the repaired ones when there was a repair — so
            // accepting re-applies exactly what was shown.
            'operations' => $proposal['operations'],
            'payload' => $checked['payload'],
            'base_hash' => self::fingerprint($current),
            'model' => $proposal['model'],
            'repaired' => $repaired,
        ];
    }

    /**
     * @param  list<array{key: string, replace: array<string, string>}>  $problems
     * @return array<string, bool>
     */
    private function problemIdentities(array $problems): array
    {
        $identities = [];

        foreach ($problems as $problem) {
            $identities[$this->identity($problem)] = true;
        }

        return $identities;
    }

    /** @param array{key: string, replace: array<string, string>} $problem */
    private function identity(array $problem): string
    {
        return $problem['key'].'#'.implode('|', $problem['replace']);
    }

    /**
     * @param  list<array{key: string, replace: array<string, string>}>  $problems
     * @return list<array{key: string, replace: array<string, string>}>
     */
    private function unique(array $problems): array
    {
        $unique = [];

        foreach ($problems as $problem) {
            $unique[$this->identity($problem)] = $problem;
        }

        return array_values($unique);
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
     * See QualityProcessFlowInterpreter::attempt(): a commercial stop travels intact, everything
     * else is "try again later".
     *
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function attempt(callable $call): array
    {
        try {
            return $call();
        } catch (ProcessFlowInterpretationException|AiCostControlException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ProcessFlowInterpretationException::changeUnavailable($exception);
        }
    }
}
