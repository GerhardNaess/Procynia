<?php

namespace App\Services\Quality;

use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessStep;

/**
 * Proposes a flow for a process. Deterministic today — no model is called.
 *
 * WHAT "GENERER STRUKTUR" DOES RIGHT NOW.
 *
 * If the process already has steps, the flow is derived from them: each step becomes a node, the
 * role in `responsibility` becomes its lane, the inputs and outputs become the start and the end,
 * and the steps are chained in the order they are written in. That is a faithful reading of what
 * the document already says, so the result is something the kvalitetsleder can correct rather than
 * something they have to invent.
 *
 * If it has no steps there is nothing to read, so a worked example is seeded instead — ITIL
 * Incident Management, chosen because it is the shape a flow has to handle and a step list cannot:
 * four roles, two decisions with named outcomes, an escalation that comes back, and two paths that
 * join before the end. It is a starting point to edit, never a claim about the customer's process,
 * and `source` records which of the two happened.
 *
 * WHERE THE AI CALL WILL GO.
 *
 * Here, as a third branch returning the same payload shape, and nowhere else. Everything
 * downstream — normalisation, persistence, approval, layout — already treats the payload as
 * untrusted input and would not change. What a model must not be allowed to do is bypass
 * QualityProcessBlueprintService::normalise(), which is the only thing standing between a proposed
 * flow and a diagram that cannot be drawn.
 *
 * Nothing here writes. The caller stores what it gets back, so a proposal can be shown and
 * discarded without touching the database.
 */
class QualityProcessBlueprintGenerator
{
    /**
     * The example's structure. Labels live in the lang files — see label keys below — because a
     * seeded flow is read by the user and must follow their language like any other UI string.
     */
    private const EXAMPLE_LANES = [
        'sluttbruker',
        'servicedesk',
        'fagansvarlig',
        'prosesseier',
    ];

    /** key => [lane, node type]. */
    private const EXAMPLE_NODES = [
        'melding' => ['sluttbruker', QualityProcessBlueprint::NODE_START],
        'registrer' => ['servicedesk', QualityProcessBlueprint::NODE_STEP],
        'prioriter' => ['servicedesk', QualityProcessBlueprint::NODE_STEP],
        'kritisk' => ['servicedesk', QualityProcessBlueprint::NODE_DECISION],
        'varsle' => ['servicedesk', QualityProcessBlueprint::NODE_STEP],
        'forsok-losning' => ['servicedesk', QualityProcessBlueprint::NODE_STEP],
        'lost-i-forste-linje' => ['servicedesk', QualityProcessBlueprint::NODE_DECISION],
        'eskaler' => ['fagansvarlig', QualityProcessBlueprint::NODE_STEP],
        'feilsok' => ['fagansvarlig', QualityProcessBlueprint::NODE_STEP],
        'verifiser' => ['sluttbruker', QualityProcessBlueprint::NODE_STEP],
        'dokumenter' => ['servicedesk', QualityProcessBlueprint::NODE_STEP],
        'gjentakende' => ['prosesseier', QualityProcessBlueprint::NODE_DECISION],
        'problem-management' => ['prosesseier', QualityProcessBlueprint::NODE_STEP],
        'lukket' => ['servicedesk', QualityProcessBlueprint::NODE_END],
    ];

    /** [from, to, label key or null]. */
    private const EXAMPLE_EDGES = [
        ['melding', 'registrer', null],
        ['registrer', 'prioriter', null],
        ['prioriter', 'kritisk', null],
        ['kritisk', 'varsle', 'yes'],
        ['kritisk', 'forsok-losning', 'no'],
        ['varsle', 'forsok-losning', null],
        ['forsok-losning', 'lost-i-forste-linje', null],
        ['lost-i-forste-linje', 'verifiser', 'yes'],
        ['lost-i-forste-linje', 'eskaler', 'no'],
        ['eskaler', 'feilsok', null],
        ['feilsok', 'verifiser', null],
        ['verifiser', 'dokumenter', null],
        ['dokumenter', 'gjentakende', null],
        ['gjentakende', 'problem-management', 'yes'],
        ['gjentakende', 'lukket', 'no'],
        ['problem-management', 'lukket', null],
    ];

    /**
     * @return array{payload: array<string, mixed>, source: string}
     */
    public function generate(QualityItem $item): array
    {
        $steps = $item->relationLoaded('processSteps')
            ? $item->processSteps
            : $item->processSteps()->get();

        if ($steps->isEmpty()) {
            return ['payload' => $this->example(), 'source' => QualityProcessBlueprint::SOURCE_EXAMPLE];
        }

        return ['payload' => $this->fromSteps($item, $steps->all()), 'source' => QualityProcessBlueprint::SOURCE_DERIVED];
    }

    /**
     * Read the process's own steps as a flow.
     *
     * Straight-line: steps are an ordered list and asserting a branch the document does not state
     * would be inventing structure. Branches are what the editor is for — the generator's job is to
     * get the roles, the order and the endpoints right so there is something to branch.
     *
     * @param  list<QualityProcessStep>  $steps
     * @return array<string, mixed>
     */
    private function fromSteps(QualityItem $item, array $steps): array
    {
        $lanes = $this->lanesFromResponsibilities($steps);
        // The catch-all lane, which lanesFromResponsibilities() always appends last.
        $unassignedLane = $lanes[count($lanes) - 1]['key'];

        $nodes = [];
        $edges = [];

        $io = $item->relationLoaded('processIo') ? $item->processIo : $item->processIo()->get();
        $start = $io->firstWhere('direction', 'input');
        $end = $io->firstWhere('direction', 'output');

        $firstLane = $this->laneKeyFor($steps[0]->responsibility, $lanes, $unassignedLane);

        $nodes[] = [
            'key' => 'start',
            'lane' => $firstLane,
            'type' => QualityProcessBlueprint::NODE_START,
            'label' => $start?->label ?? __('procynia.quality.blueprint.default_start'),
            'description' => $start?->description,
        ];
        $previous = 'start';

        foreach ($steps as $index => $step) {
            $key = 'steg-'.($index + 1);

            $nodes[] = [
                'key' => $key,
                'lane' => $this->laneKeyFor($step->responsibility, $lanes, $unassignedLane),
                'type' => QualityProcessBlueprint::NODE_STEP,
                'label' => $step->title,
                'description' => $step->description,
            ];

            $edges[] = ['from' => $previous, 'to' => $key, 'label' => null];
            $previous = $key;
        }

        $lastLane = $this->laneKeyFor($steps[count($steps) - 1]->responsibility, $lanes, $unassignedLane);

        $nodes[] = [
            'key' => 'slutt',
            'lane' => $lastLane,
            'type' => QualityProcessBlueprint::NODE_END,
            'label' => $end?->label ?? __('procynia.quality.blueprint.default_end'),
            'description' => $end?->description,
        ];
        $edges[] = ['from' => $previous, 'to' => 'slutt', 'label' => null];

        return ['lanes' => $lanes, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * One lane per distinct role, in the order the roles first appear.
     *
     * Order matters: it decides the vertical order of the bands, and first appearance puts the role
     * that starts the process at the top, which is how a swimlane is read. The catch-all lane is
     * always last, so steps nobody is named for sink to the bottom rather than splitting the roles.
     *
     * @param  list<QualityProcessStep>  $steps
     * @return list<array<string, string>>
     */
    private function lanesFromResponsibilities(array $steps): array
    {
        $lanes = [];
        $seen = [];

        foreach ($steps as $step) {
            $role = trim((string) ($step->responsibility ?? ''));

            if ($role === '' || isset($seen[mb_strtolower($role)])) {
                continue;
            }

            $seen[mb_strtolower($role)] = true;
            $lanes[] = ['key' => 'rolle-'.(count($lanes) + 1), 'label' => $role];
        }

        // Always present, always last. normalise() drops it again if every step named a role, so an
        // empty band never reaches the diagram.
        $lanes[] = ['key' => 'uten-rolle', 'label' => __('procynia.quality.blueprint.default_lane')];

        return $lanes;
    }

    /**
     * @param  list<array<string, string>>  $lanes
     */
    private function laneKeyFor(?string $responsibility, array $lanes, string $fallback): string
    {
        $role = mb_strtolower(trim((string) ($responsibility ?? '')));

        if ($role === '') {
            return $fallback;
        }

        foreach ($lanes as $lane) {
            if (mb_strtolower($lane['label']) === $role) {
                return $lane['key'];
            }
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function example(): array
    {
        $lanes = array_map(
            static fn (string $key): array => [
                'key' => $key,
                'label' => __('procynia.quality.blueprint.example.lanes.'.$key),
            ],
            self::EXAMPLE_LANES,
        );

        $nodes = [];

        foreach (self::EXAMPLE_NODES as $key => [$lane, $type]) {
            $nodes[] = [
                'key' => $key,
                'lane' => $lane,
                'type' => $type,
                'label' => __('procynia.quality.blueprint.example.nodes.'.$key),
                'description' => null,
            ];
        }

        $edges = array_map(
            static fn (array $edge): array => [
                'from' => $edge[0],
                'to' => $edge[1],
                'label' => $edge[2] === null ? null : __('procynia.quality.blueprint.example.edges.'.$edge[2]),
            ],
            self::EXAMPLE_EDGES,
        );

        return ['lanes' => $lanes, 'nodes' => $nodes, 'edges' => $edges];
    }
}
