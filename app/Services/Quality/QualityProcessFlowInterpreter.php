<?php

namespace App\Services\Quality;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Services\Ai\Quality\ProcessFlowInterpretationAiClient;
use App\Services\Quality\Exceptions\ProcessFlowInterpretationException;
use App\Support\Ai\AiCallContextScope;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plain language in, a proposed flow out.
 *
 * WHERE THIS SITS.
 *
 *   description -> ProcessFlowInterpretationAiClient -> this mapper -> normalise -> validate
 *
 * The model proposes roles and sentences; this class turns them into the lanes, nodes and edges the
 * rest of the system already knows how to store, draw and approve. Nothing downstream can tell
 * whether a payload came from here, from the deterministic generator or from the editor — which is
 * the point. There is one flow model, and AI is one of three ways to arrive at it.
 *
 * WHY NOTHING HERE WRITES.
 *
 * A proposal is shown before it is adopted, so it must be possible to produce one and throw it
 * away. Storing it first would overwrite the flow the user already had with something they have not
 * yet agreed to, and "regret" would mean reconstructing it by hand. The caller stores what it gets
 * back, and only when the user says so.
 *
 * WHY THE KEYS ARE SLUGGED HERE AND NOT LEFT TO normalise().
 *
 * normalise() slugs node keys but matches edges against the slugged result, so an id like
 * `register_supplier` would become `register-supplier` while its edges still pointed at the
 * original — and dangling edges are dropped, silently. Slugging up front makes normalise() a no-op
 * on identity, which is what keeps the proposed arrows attached to the proposed steps.
 */
class QualityProcessFlowInterpreter
{
    /** The catch-all lane, for steps whose role the description never named. */
    private const UNASSIGNED_LANE = 'uten-rolle';

    public function __construct(
        private readonly ProcessFlowInterpretationAiClient $client,
        private readonly QualityProcessBlueprintService $blueprints,
        private readonly QualityProcessFlowValidator $validator,
        private readonly QualityFlowClarificationService $clarifications,
        private readonly AiCallContextScope $contextScope,
    ) {}

    /**
     * Interpret a description into a flow the user can look at, correct and adopt.
     *
     * @return array{payload: array{lanes: list<array<string, string>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, blocking_questions: list<string>, optional_clarifications: list<string>, description: string, model: string, repaired: bool}
     *
     * @throws ProcessFlowInterpretationException
     */
    public function interpret(QualityItem $item, string $description, string $languageCode): array
    {
        $description = trim($description);

        return $this->contextScope->within(
            new AiCallContext(
                customerId: (int) $item->customer_id,
                feature: 'quality',
                operation: 'process_flow_interpretation',
                resourceType: 'quality_item',
                resourceId: (int) $item->id,
            ),
            fn (): array => $this->run($item, $description, $languageCode),
        );
    }

    /**
     * @return array{payload: array<string, mixed>, blocking_questions: list<string>, optional_clarifications: list<string>, description: string, model: string, repaired: bool}
     */
    private function run(QualityItem $item, string $description, string $languageCode): array
    {
        $title = (string) $item->title;

        $proposal = $this->attempt(fn (): array => $this->client->interpret($title, $description, $languageCode));
        $checked = $this->check($proposal);

        if ($checked['problems'] === []) {
            return $this->result($item, $checked['payload'], $proposal, $description, repaired: false);
        }

        // One repair, with the problems stated. See ProcessFlowInterpretationAiClient::repair() for
        // why there is no second.
        $repaired = $this->attempt(fn (): array => $this->client->repair(
            $title,
            $description,
            ['trigger' => $proposal['trigger'], 'outcome' => $proposal['outcome'], 'steps' => $proposal['steps'], 'flows' => $proposal['flows']],
            $this->validator->describe($checked['problems'], 'en'),
            $languageCode,
        ));

        $recheck = $this->check($repaired);

        if ($recheck['problems'] !== []) {
            throw ProcessFlowInterpretationException::unusable(
                $this->validator->describe($recheck['problems']),
            );
        }

        return $this->result($item, $recheck['payload'], $repaired, $description, repaired: true);
    }

    /**
     * Map, normalise and judge one proposal.
     *
     * Reference problems are collected before normalisation and graph problems after, because
     * normalisation is what removes the evidence of the first kind — see the validator.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{payload: array<string, mixed>, problems: list<array{key: string, replace: array<string, string>}>}
     */
    private function check(array $proposal): array
    {
        $raw = $this->toPayload($proposal);
        $problems = $this->validator->referenceProblems($raw);

        try {
            $payload = $this->blueprints->normalise($raw);
        } catch (Throwable $exception) {
            throw ProcessFlowInterpretationException::unusable([$exception->getMessage()]);
        }

        return [
            'payload' => $payload,
            'problems' => array_merge($problems, $this->validator->graphProblems($payload)),
        ];
    }

    /**
     * Turn the model's roles and sentences into lanes, nodes and edges.
     *
     * The start and end nodes are built here rather than asked for, from `trigger` and `outcome`.
     * It is the simplest model that always has a valid beginning and a valid ending: two of the
     * things the validator insists on stop being things the model can get wrong.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{lanes: list<array<string, string>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function toPayload(array $proposal): array
    {
        $steps = $proposal['steps'];
        $keys = $this->stepKeys($steps);
        $lanes = $this->lanes($steps);

        $nodes = [];

        foreach ($steps as $step) {
            $nodes[] = [
                'key' => $keys[$step['id']],
                'lane' => $this->laneFor($step['role'], $lanes),
                'type' => $step['type'] === ProcessFlowInterpretationAiClient::STEP_DECISION
                    ? QualityProcessBlueprint::NODE_DECISION
                    : QualityProcessBlueprint::NODE_STEP,
                'label' => $step['label'],
                'description' => $step['description'],
            ];
        }

        $edges = [];

        foreach ($proposal['flows'] as $flow) {
            $edges[] = [
                'from' => $keys[$flow['from']] ?? $flow['from'],
                'to' => $keys[$flow['to']] ?? $flow['to'],
                'label' => $flow['condition'],
            ];
        }

        array_unshift($nodes, [
            'key' => ProcessFlowInterpretationAiClient::START,
            // The start belongs beside whoever acts first, which is what makes the top of the
            // diagram read as the beginning rather than as a band of its own.
            'lane' => $nodes[0]['lane'] ?? $lanes[0]['key'],
            'type' => QualityProcessBlueprint::NODE_START,
            'label' => $proposal['trigger'] !== '' ? $proposal['trigger'] : __('procynia.quality.blueprint.default_start'),
            'description' => null,
        ]);

        $nodes[] = [
            'key' => ProcessFlowInterpretationAiClient::END,
            'lane' => $this->endLane($nodes, $edges, $lanes),
            'type' => QualityProcessBlueprint::NODE_END,
            'label' => $proposal['outcome'] !== '' ? $proposal['outcome'] : __('procynia.quality.blueprint.default_end'),
            'description' => null,
        ];

        return ['lanes' => $lanes, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A URL-safe key per step id, keeping the model's own naming where it survives slugging.
     *
     * A step that claims a reserved endpoint id is renamed rather than refused: it is a mistake the
     * user cannot see and could not fix, and the rename costs nothing because the flows are
     * rewritten through this same map. Duplicates are left colliding on purpose — the validator
     * reports them, and a repair attempt is a better answer than a silent renumbering that makes
     * two different steps look like two versions of one.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, string>
     */
    private function stepKeys(array $steps): array
    {
        $reserved = [ProcessFlowInterpretationAiClient::START, ProcessFlowInterpretationAiClient::END];
        $keys = [];

        foreach ($steps as $index => $step) {
            $key = Str::limit(Str::slug((string) $step['id']), 60, '');

            if ($key === '' || in_array($key, $reserved, true)) {
                $key = 'steg-'.($index + 1);
            }

            $keys[$step['id']] = $key;
        }

        return $keys;
    }

    /**
     * One lane per distinct role, in the order the roles first appear.
     *
     * First appearance puts whoever starts the process at the top, which is how a swimlane is read.
     * Matching is case-insensitive so "Innkjøper" and "innkjøper" do not become two bands for one
     * person. The catch-all is always present and always last; normalise() drops it again when
     * every step named a role, so an empty band never reaches the diagram.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, string>>
     */
    private function lanes(array $steps): array
    {
        $lanes = [];
        $seen = [];

        foreach ($steps as $step) {
            $role = trim((string) ($step['role'] ?? ''));

            if ($role === '' || isset($seen[mb_strtolower($role)])) {
                continue;
            }

            $seen[mb_strtolower($role)] = true;
            // A role echoed from mid-sentence arrives lowercase — "registrerer innkjøper
            // leverandøren" gives "innkjøper". As a lane band and a row in the roles editor it is
            // a heading, so it is capitalised. Only the first letter: anything more would mangle
            // "IT-avdelingen" and "leder for innkjøp".
            $lanes[] = ['key' => 'rolle-'.(count($lanes) + 1), 'label' => Str::ucfirst($role)];
        }

        $lanes[] = ['key' => self::UNASSIGNED_LANE, 'label' => __('procynia.quality.blueprint.default_lane')];

        return $lanes;
    }

    /**
     * @param  list<array<string, string>>  $lanes
     */
    private function laneFor(?string $role, array $lanes): string
    {
        $needle = mb_strtolower(trim((string) ($role ?? '')));

        if ($needle === '') {
            return self::UNASSIGNED_LANE;
        }

        foreach ($lanes as $lane) {
            if (mb_strtolower($lane['label']) === $needle) {
                return $lane['key'];
            }
        }

        return self::UNASSIGNED_LANE;
    }

    /**
     * The lane the end sits in: whoever's work the process finishes with.
     *
     * Read from the arrows rather than from the step order, because the last step in the list is
     * often a branch's dead end rather than the one that actually concludes the process.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  list<array<string, string>>  $lanes
     */
    private function endLane(array $nodes, array $edges, array $lanes): string
    {
        $byKey = array_column($nodes, 'lane', 'key');

        foreach ($edges as $edge) {
            if ($edge['to'] === ProcessFlowInterpretationAiClient::END && isset($byKey[$edge['from']])) {
                return $byKey[$edge['from']];
            }
        }

        return $nodes[count($nodes) - 1]['lane'] ?? $lanes[0]['key'];
    }

    /**
     * The proposal as the user will see it.
     *
     * Optional clarifications pass through what the user has already settled for this process:
     * a suggestion they dismissed or answered is not raised again while the description they
     * settled it against still stands. Blocking questions deliberately do not — those say the flow
     * cannot be believed, and nobody gets to switch that off, least of all by clicking past it
     * once.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $proposal
     * @return array{payload: array<string, mixed>, blocking_questions: list<string>, optional_clarifications: list<string>, description: string, model: string, repaired: bool}
     */
    private function result(QualityItem $item, array $payload, array $proposal, string $description, bool $repaired): array
    {
        return [
            'payload' => $payload,
            'blocking_questions' => $proposal['blocking_questions'],
            'optional_clarifications' => $this->clarifications->remaining(
                $item,
                $description,
                $proposal['optional_clarifications'],
            ),
            'description' => $description,
            'model' => $proposal['model'],
            'repaired' => $repaired,
        ];
    }

    /**
     * The provider failing, refusing or answering unreadably is one thing to the user: the flow
     * could not be read. The detail belongs in the log the client already writes, not in a message
     * about their process description.
     *
     * A commercial hard stop is the exception. "Prøv igjen om litt" would be false — the quota does
     * not refill in a minute and an entitlement gap never does — and the platform already has a
     * presenter whose whole job is saying which of those it was, so the exception travels intact to
     * the caller that knows how to show it.
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
            throw ProcessFlowInterpretationException::unavailable($exception);
        }
    }
}
