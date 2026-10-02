<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityFlowClarificationDismissal;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Ai\Quality\ProcessFlowInterpretationAiClient;
use App\Services\Quality\QualityProcessFlowValidator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Describing a process in plain language, and what Procynia is allowed to do with it.
 *
 * What these tests defend:
 *
 *  - The chain holds end to end: description -> structure -> validation -> diagram -> correction ->
 *    stored flow, with the structured model as the only thing that is stored.
 *  - A proposal is a proposal. Interpreting writes nothing, so the flow a process already had
 *    survives a question the user asked and did not like the answer to.
 *  - Reproducibility: once adopted, the flow is data. Opening the page again draws it from the
 *    stored payload and calls no model — the single most expensive invariant to lose and the
 *    hardest to notice losing.
 *  - The model does not get to be the authority. Output that does not hold together as a process is
 *    repaired once and then refused, and the refusal says which part of the description is at fault.
 *  - Gaps are reported, not filled. An under-specified description produces questions, not invented
 *    approvers and thresholds.
 *  - Tenancy and the management gate are the same ones every other write to a blueprint passes.
 *
 * No live provider call is made anywhere in this file: every one is a faked Responses envelope.
 */
class QualityProcessFlowInterpretationTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const DESCRIPTION = 'Når en ny leverandør skal opprettes registrerer innkjøper leverandøren i systemet. Dersom leverandøren er kritisk skal sikkerhetsansvarlig kontrollere leverandøren. Deretter godkjenner økonomi leverandøren.';

    /**
     * The term the description branches on and never defines.
     *
     * The flow is complete without it — both paths are stated, nothing is guessed — so it is a
     * suggestion and not a gap. It is the worked example for the whole optional-clarification
     * rule: noticed, reported, and never in the way.
     */
    private const CRITICAL_SUPPLIER_QUESTION = 'Hva gjør at en leverandør regnes som kritisk?';

    /** What the user types into the one field "Avklar" opens. */
    private const CRITICAL_SUPPLIER_ANSWER = 'Når verdien av anskaffelsen er over kr. 100000.';

    /**
     * The description once the answer is part of it — the worked example from the brief.
     *
     * The defining clause has taken the place of the undefined word in the sentence that branched
     * on it. Every other sentence is untouched, the question is nowhere, and nothing stands at the
     * bottom explaining what was asked. That shape is the whole point of the rewrite: a process
     * description is read by people carrying the process out, not by the person who answered.
     */
    private const CLARIFIED_DESCRIPTION = 'Når en ny leverandør skal opprettes registrerer innkjøper leverandøren i systemet. Dersom verdien av anskaffelsen er over kr. 100 000, regnes leverandøren som kritisk og skal kontrolleres av sikkerhetsansvarlig. Deretter godkjenner økonomi leverandøren.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        config()->set('services.quality.flow_ai_enabled', true);
        config()->set('services.openai.api_key', 'test-key');

        // Inertia's SSR gateway is an HTTP client too, and Http::fake() would answer its call to
        // the render server with an OpenAI envelope. Server-side rendering is not what any test
        // here is about, and leaving it on makes every faked provider response a 500 on the next
        // page load.
        config()->set('inertia.ssr.enabled', false);

        // Nothing in this file may reach a provider. An unmatched request is a failing test, not a
        // slow one.
        Http::preventStrayRequests();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // The chain, end to end
    // ---------------------------------------------------------------------

    public function test_a_description_becomes_a_structured_proposal_without_being_stored(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());

        $props = $this->interpret($owner, $process);

        $this->assertNotNull($props['flow_proposal']);
        $this->assertNull($props['blueprint'], 'Interpreting a description must not write a blueprint.');
        $this->assertSame(0, QualityProcessBlueprint::query()->count());

        $proposal = $props['flow_proposal'];

        // Roles become lanes, in the order they first act.
        $this->assertSame(
            ['Innkjøper', 'Sikkerhetsansvarlig', 'Økonomi'],
            array_column($proposal['lanes'], 'label'),
        );

        // Start and end are built from trigger and outcome rather than asked for — see
        // QualityProcessFlowInterpreter::toPayload().
        $types = array_column($proposal['nodes'], 'type', 'key');
        $this->assertSame(QualityProcessBlueprint::NODE_START, $types['start']);
        $this->assertSame(QualityProcessBlueprint::NODE_END, $types['end']);
        $this->assertSame(QualityProcessBlueprint::NODE_DECISION, $types['kritisk-leverandor']);

        $labels = array_column($proposal['nodes'], 'label', 'key');
        $this->assertSame('Ny leverandør skal opprettes', $labels['start']);
        $this->assertSame('Leverandøren er godkjent', $labels['end']);

        // The branch keeps its named outcomes.
        $branch = array_values(array_filter(
            $proposal['edges'],
            static fn (array $edge): bool => $edge['from'] === 'kritisk-leverandor',
        ));
        $this->assertSame(['Ja', 'Nei'], array_column($branch, 'label'));
    }

    /**
     * The one thing a user cannot be asked to forgive: pressing the button and losing the flow they
     * already had, because the answer turned out to be worse than what they started with.
     */
    public function test_an_existing_flow_survives_a_proposal_the_user_does_not_adopt(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate")
            ->assertRedirect();

        $existing = QualityProcessBlueprint::query()->firstOrFail();

        $this->fakeResponse($this->supplierProposal());
        $this->interpret($owner, $process);

        $after = QualityProcessBlueprint::query()->firstOrFail();

        $this->assertSame($existing->id, $after->id);
        $this->assertSame($existing->payload, $after->payload);
        $this->assertSame(QualityProcessBlueprint::SOURCE_EXAMPLE, $after->source);
    }

    public function test_an_adopted_proposal_becomes_the_flow_and_records_where_it_came_from(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());
        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", [
            'lanes' => $proposal['lanes'],
            'nodes' => $proposal['nodes'],
            'edges' => $proposal['edges'],
            'description' => self::DESCRIPTION,
        ])->assertRedirect();

        $blueprint = QualityProcessBlueprint::query()->firstOrFail();

        $this->assertSame(QualityProcessBlueprint::SOURCE_AI, $blueprint->source);
        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $blueprint->status);
        $this->assertSame(self::DESCRIPTION, $blueprint->description);
        $this->assertSame((int) $owner->id, (int) $blueprint->generated_by_user_id);
        $this->assertCount(6, $blueprint->nodes());

        // No geometry, ever — the diagram is a pure function of this payload.
        $this->assertSame(['edges', 'lanes', 'nodes'], collect(array_keys($blueprint->payload))->sort()->values()->all());

        foreach ($blueprint->nodes() as $node) {
            $this->assertSame(['key', 'lane', 'type', 'label', 'description'], array_keys($node));
        }
    }

    /**
     * The correction step. What is adopted is what the user is looking at, not what the model
     * returned — otherwise "rett det som ikke stemmer" would be a lie.
     */
    public function test_a_correction_made_before_adopting_is_what_gets_stored(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());
        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $corrected = array_map(static function (array $node): array {
            if ($node['key'] === 'godkjenn-leverandor') {
                $node['label'] = 'Godkjenn leverandøren i leverandørregisteret';
                $node['lane'] = 'rolle-1';
            }

            return $node;
        }, $proposal['nodes']);

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", [
            'lanes' => $proposal['lanes'],
            'nodes' => $corrected,
            'edges' => $proposal['edges'],
            'description' => self::DESCRIPTION,
        ])->assertRedirect();

        $stored = collect(QualityProcessBlueprint::query()->firstOrFail()->nodes())
            ->firstWhere('key', 'godkjenn-leverandor');

        $this->assertSame('Godkjenn leverandøren i leverandørregisteret', $stored['label']);
        $this->assertSame('rolle-1', $stored['lane']);
    }

    // ---------------------------------------------------------------------
    // Reproducibility
    // ---------------------------------------------------------------------

    /**
     * The explicit requirement: a stored flow renders from the database, never from the text.
     *
     * Http::preventStrayRequests() is what actually proves it — if showing the page called a model,
     * there would be no fake registered to answer and the request would fail.
     */
    public function test_reopening_a_stored_flow_calls_no_model(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());
        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", [
            'lanes' => $proposal['lanes'],
            'nodes' => $proposal['nodes'],
            'edges' => $proposal['edges'],
            'description' => self::DESCRIPTION,
        ])->assertRedirect();

        Http::fake([]);
        Http::preventStrayRequests();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['flow_proposal'], 'A reload must not resurrect a proposal.');

        // Compared by content, not by key order: a jsonb round trip is free to reorder the keys of
        // an object, and the flow is the same flow either way.
        $this->assertEquals($proposal['nodes'], $props['blueprint']['nodes']);
        $this->assertEquals($proposal['edges'], $props['blueprint']['edges']);
        $this->assertSame(self::DESCRIPTION, $props['blueprint']['description']);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // The model is not the authority
    // ---------------------------------------------------------------------

    /**
     * A decision with one way out is the archetypal thing a model gets wrong, and the archetypal
     * thing a reader of the diagram cannot recover from. It is repaired once, with the problem
     * stated, and the repaired flow is what the user sees.
     */
    public function test_an_incoherent_proposal_is_repaired_once_and_the_repair_is_used(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([$this->oneWayDecisionProposal(), $this->supplierProposal()]);

        $props = $this->interpret($owner, $process);

        $this->assertNotNull($props['flow_proposal']);
        $this->assertNull($props['flow_error']);

        $branch = array_values(array_filter(
            $props['flow_proposal']['edges'],
            static fn (array $edge): bool => $edge['from'] === 'kritisk-leverandor',
        ));
        $this->assertCount(2, $branch);

        Http::assertSentCount(2);

        // The repair call is told what was wrong rather than asked again from scratch.
        Http::assertSent(function ($request): bool {
            $body = json_encode($request->data());

            return str_contains((string) $body, 'PROBLEMS FOUND IN IT')
                && str_contains((string) $body, 'only one way forward');
        });
    }

    public function test_a_proposal_that_cannot_be_repaired_is_refused_with_the_reason(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([$this->oneWayDecisionProposal(), $this->oneWayDecisionProposal()]);

        $props = $this->interpret($owner, $process);

        $this->assertNull($props['flow_proposal']);
        $this->assertNotNull($props['flow_error']);
        $this->assertNotEmpty($props['flow_error']['problems']);
        $this->assertStringContainsString('Er leverandøren kritisk?', implode(' ', $props['flow_error']['problems']));
        $this->assertSame(0, QualityProcessBlueprint::query()->count());

        // Two attempts and no more. A third identical request would cost the customer money to
        // tell them the same thing.
        Http::assertSentCount(2);
    }

    public function test_an_edge_to_a_step_that_does_not_exist_is_caught_before_it_is_silently_dropped(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $dangling = $this->supplierProposal();
        $dangling['flows'][] = ['from' => 'godkjenn_leverandor', 'to' => 'arkiver_avtalen', 'condition' => null];

        $this->fakeResponses([$dangling, $this->supplierProposal()]);

        $this->interpret($owner, $process);

        Http::assertSent(function ($request): bool {
            return str_contains((string) json_encode($request->data()), 'does not exist');
        });
    }

    public function test_a_provider_failure_is_reported_as_a_temporary_problem_and_writes_nothing(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        Http::fake(['*' => Http::response(['error' => ['message' => 'upstream']], 503)]);

        $props = $this->interpret($owner, $process);

        $this->assertNull($props['flow_proposal']);
        $this->assertSame([], $props['flow_error']['problems']);
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    // ---------------------------------------------------------------------
    // Gaps are reported, not filled
    // ---------------------------------------------------------------------

    /**
     * "Hvis bestillingen er stor må den godkjennes." — what counts as stor, and who approves, are
     * not in the text. The product rule is that Procynia says so rather than inventing an answer,
     * and the questions reach the user intact — blocking and optional kept apart.
     */
    public function test_an_under_specified_description_surfaces_questions_instead_of_invented_facts(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Bestilling');

        $this->fakeResponse($this->vagueOrderProposal());

        $props = $this->interpret(
            $owner,
            $process,
            'Hvis bestillingen er stor må den godkjennes før den sendes til leverandøren, ellers kan den sendes direkte.',
        );

        $this->assertSame(
            ['Hva regnes som en stor bestilling?', 'Hvem skal godkjenne en stor bestilling?'],
            $props['flow_proposal']['blocking_questions'],
        );

        $this->assertSame(
            ['Skal bestillingen registreres et sted før den sendes?'],
            $props['flow_proposal']['optional_clarifications'],
        );

        // The role nobody named stays unnamed: a lane, not a guess at who it is.
        $laneLabels = array_column($props['flow_proposal']['lanes'], 'label', 'key');
        $approval = collect($props['flow_proposal']['nodes'])->firstWhere('key', 'godkjenn');

        $this->assertSame('Uten angitt rolle', $laneLabels[$approval['lane']]);
    }

    /**
     * A role lifted from mid-sentence arrives lowercase. It is a heading on the diagram, and the
     * same role written two ways must not become two lanes.
     */
    public function test_a_role_echoed_from_mid_sentence_becomes_one_properly_cased_lane(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $proposal = $this->supplierProposal();
        $proposal['steps'][0]['role'] = 'innkjøper';
        $proposal['steps'][1]['role'] = 'Innkjøper';

        $this->fakeResponse($proposal);

        $proposed = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertSame(
            ['Innkjøper', 'Sikkerhetsansvarlig', 'Økonomi'],
            array_column($proposed['lanes'], 'label'),
        );

        $byKey = array_column($proposed['nodes'], 'lane', 'key');
        $this->assertSame($byKey['registrer-leverandor'], $byKey['kritisk-leverandor']);
    }

    public function test_a_linear_description_produces_a_linear_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Søknadsbehandling');

        $this->fakeResponse($this->applicationProposal());

        $props = $this->interpret(
            $owner,
            $process,
            'Kunden sender søknaden. Saksbehandler vurderer søknaden. Saksbehandler godkjenner søknaden.',
        );

        $proposal = $props['flow_proposal'];

        $this->assertSame([], $proposal['blocking_questions']);
        $this->assertSame([], $proposal['optional_clarifications']);
        $this->assertSame(['Kunde', 'Saksbehandler'], array_column($proposal['lanes'], 'label'));
        $this->assertCount(5, $proposal['nodes']);
        $this->assertCount(4, $proposal['edges']);
        $this->assertSame([null, null, null, null], array_column($proposal['edges'], 'label'));
    }

    // ---------------------------------------------------------------------
    // The simplest flow that is still faithful
    // ---------------------------------------------------------------------

    /**
     * The worked example, and the whole point of the simplicity rule.
     *
     * "Dersom leverandøren er kritisk, skal sikkerhetsansvarlig kontrollere leverandøren. Deretter
     * godkjenner økonomi leverandøren." is a complete description. A critical supplier is checked
     * and then approved; one that is not goes straight to approval. There is nothing missing from
     * it — so nothing is asked about it, and nothing is added to it.
     *
     * The two things that go wrong when a model is left to be thorough are both asserted here: the
     * check becoming a second decision with an invented rejection path, and a question about who
     * approves the suppliers the text already says økonomi approves.
     */
    public function test_a_conditional_step_rejoins_the_main_flow_without_inventing_a_second_decision(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());

        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        // One decision. The check is work being done, not a second branch.
        $types = array_column($proposal['nodes'], 'type', 'key');
        $this->assertSame(
            ['kritisk-leverandor'],
            array_keys(array_filter($types, static fn (string $type): bool => $type === QualityProcessBlueprint::NODE_DECISION)),
        );
        $this->assertSame(QualityProcessBlueprint::NODE_STEP, $types['kontroller-leverandor']);

        // Kritisk -> kontroll -> økonomi. Ikke kritisk -> økonomi. Both paths end at the same step.
        $this->assertSame(
            ['kontroller-leverandor', 'godkjenn-leverandor'],
            $this->targetsFrom($proposal['edges'], 'kritisk-leverandor'),
        );
        $this->assertSame(['godkjenn-leverandor'], $this->targetsFrom($proposal['edges'], 'kontroller-leverandor'));
        $this->assertSame(['end'], $this->targetsFrom($proposal['edges'], 'godkjenn-leverandor'));

        // Nothing the flow needed is missing, so nothing blocks. The undefined term it branches
        // on is reported beside the flow instead — see
        // test_an_undefined_term_a_decision_turns_on_is_reported_without_blocking_the_flow.
        $this->assertSame([], $proposal['blocking_questions']);
    }

    /**
     * The rules that keep the proposal simple live in the prompt, so the prompt is what has to be
     * defended. Not its wording — its load-bearing instructions, each of which exists because a
     * model without it produces the flow the user did not describe.
     */
    public function test_the_prompt_asks_for_the_simplest_faithful_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());
        $this->interpret($owner, $process);

        Http::assertSent(function ($request): bool {
            $sent = (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ([
                // The governing rule.
                'simplest valid flow that faithfully reproduces',
                'without answering anything',
                // No invented structure.
                'Never add a decision, branch, rejection, failure path',
                'Never add the opposite case',
                // Checks are work, not branches.
                'approve, sign off, validate and inspect name work being performed',
                'If the text does not branch, neither do you',
                // The sentence after the condition applies to both paths.
                'both paths lead to that next step',
                // Blocking is the exception, and an empty blocking list is the expected result.
                'At most two',
                'This list is normally empty',
                // Optional clarifications are one specific thing: the terms a decision turns on.
                'term, threshold or criterion it never defines',
                'At most three',
                'never holds anything up and never changes what you propose',
                // And still not a way back in for the invented process.
                'Never report a hypothetical in either list',
                'does not turn it into an observation',
            ] as $rule) {
                if (! str_contains($sent, $rule)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * The caps are a product rule, not a parsing detail. The schema states them and the provider
     * enforces them, but a proposal that puts six questions in front of the user is the endless
     * fine-tuning this feature exists to avoid — so the cap holds on the way out as well.
     */
    public function test_more_questions_than_the_caps_allow_are_cut_to_the_caps(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $proposal = $this->supplierProposal();
        $proposal['blocking_questions'] = [
            ['question' => 'Hvem godkjenner?'],
            ['question' => 'Hva er terskelen?'],
            ['question' => 'Hva skjer ved avslag?'],
            ['question' => 'Hvem eskalerer?'],
        ];
        $proposal['optional_clarifications'] = [
            ['question' => 'Bør registreringen deles i to steg?'],
            ['question' => 'Skal kontrollen dokumenteres?'],
            ['question' => 'Er det en frist?'],
            ['question' => 'Skal leverandøren varsles?'],
            ['question' => 'Hvem arkiverer avtalen?'],
        ];

        $this->fakeResponse($proposal);

        $proposed = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertSame(['Hvem godkjenner?', 'Hva er terskelen?'], $proposed['blocking_questions']);
        $this->assertSame(
            ['Bør registreringen deles i to steg?', 'Skal kontrollen dokumenteres?', 'Er det en frist?'],
            $proposed['optional_clarifications'],
        );
    }

    /**
     * A question that blocks is not also a suggestion. Shown in both panels it would read as two
     * outstanding things, and the optional list would look longer than the work actually is.
     */
    public function test_a_question_asked_in_both_lists_is_shown_only_as_blocking(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $proposal = $this->supplierProposal();
        $proposal['blocking_questions'] = [['question' => 'Hvem godkjenner kritiske leverandører?']];
        $proposal['optional_clarifications'] = [
            ['question' => 'Hvem godkjenner kritiske leverandører?'],
            ['question' => 'Skal kontrollen dokumenteres?'],
        ];

        $this->fakeResponse($proposal);

        $proposed = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertSame(['Hvem godkjenner kritiske leverandører?'], $proposed['blocking_questions']);
        $this->assertSame(['Skal kontrollen dokumenteres?'], $proposed['optional_clarifications']);
    }

    /** The caps as the provider sees them, since `strict` means it is the provider that enforces them. */
    public function test_the_response_contract_caps_both_question_lists(): void
    {
        $schema = ProcessFlowInterpretationAiClient::schema();

        $this->assertSame(2, $schema['properties']['blocking_questions']['maxItems']);
        $this->assertSame(3, $schema['properties']['optional_clarifications']['maxItems']);
        $this->assertContains('blocking_questions', $schema['required']);
        $this->assertContains('optional_clarifications', $schema['required']);
    }

    // ---------------------------------------------------------------------
    // Optional clarifications: noticed, never in the way, and dismissible for good
    // ---------------------------------------------------------------------

    /**
     * The example the whole rule is built on.
     *
     * "Dersom leverandøren er kritisk" sends the process one way or the other on a word the
     * description never defines. The flow is complete — both paths are stated — so there is nothing
     * to block on, and Procynia proposing a definition would be the invented process. Saying that
     * the word is undefined is neither: it is an observation about the text, and it belongs beside
     * the flow rather than in it.
     */
    public function test_an_undefined_term_a_decision_turns_on_is_reported_without_blocking_the_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());

        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertSame([self::CRITICAL_SUPPLIER_QUESTION], $proposal['optional_clarifications']);
        $this->assertSame([], $proposal['blocking_questions'], 'An undefined term must never block.');

        // And it changed nothing about the flow: the decision is still there, still branching on
        // the user's own word, with no guessed threshold attached to it.
        $decision = collect($proposal['nodes'])->firstWhere('key', 'kritisk-leverandor');
        $this->assertSame('Er leverandøren kritisk?', $decision['label']);
        $this->assertSame(
            ['kontroller-leverandor', 'godkjenn-leverandor'],
            $this->targetsFrom($proposal['edges'], 'kritisk-leverandor'),
        );
    }

    /**
     * A suggestion is a suggestion. Leaving it unanswered costs the user nothing — the flow is
     * adopted, stored and attributed exactly as it would be with no questions on screen.
     */
    public function test_a_flow_with_an_unanswered_clarification_can_still_be_adopted(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponse($this->supplierProposal());
        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertNotEmpty($proposal['optional_clarifications']);

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/adopt", [
            'lanes' => $proposal['lanes'],
            'nodes' => $proposal['nodes'],
            'edges' => $proposal['edges'],
            'description' => self::DESCRIPTION,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $blueprint = QualityProcessBlueprint::query()->firstOrFail();

        $this->assertSame(QualityProcessBlueprint::SOURCE_AI, $blueprint->source);
        $this->assertCount(6, $blueprint->nodes());
    }

    /**
     * "Avvis" is an answer too: this organisation has decided the word is left to judgement.
     *
     * What the endpoint owes the user is that the decision survives the click. The removal itself
     * happens in the browser — see ProcessFlowPanel — so what is asserted here is the half that
     * cannot be done there: it is recorded against the process, and the next reading of the same
     * description does not raise it again.
     */
    public function test_dismissing_a_suggestion_records_it_and_takes_it_off_the_next_proposal(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        // Both readings queued up front: Http::fake() adds stubs rather than replacing them, so a
        // second call would leave the first, now exhausted, sequence matching first.
        $this->fakeResponses([$this->supplierProposal(), $this->supplierProposal()]);

        $this->assertSame(
            [self::CRITICAL_SUPPLIER_QUESTION],
            $this->interpret($owner, $process)['flow_proposal']['optional_clarifications'],
        );

        $this->dismiss($owner, $process, self::CRITICAL_SUPPLIER_QUESTION, self::DESCRIPTION);

        $this->assertSame(1, QualityFlowClarificationDismissal::query()
            ->where('quality_item_id', $process->id)
            ->count());

        // Nothing about the flow was written by saying no.
        $this->assertSame(0, QualityProcessBlueprint::query()->count());

        $proposal = $this->interpret($owner, $process)['flow_proposal'];

        $this->assertSame([], $proposal['optional_clarifications']);
        $this->assertNotEmpty($proposal['nodes'], 'Dismissing a suggestion must not affect the flow.');
    }

    /**
     * How long a dismissal holds.
     *
     * Through ordinary regeneration, including the refinements that make up most of the work on a
     * description — otherwise "Avvis" means "not this time", and the user learns to ignore the
     * panel rather than use it. It also matches on the question rather than on its exact spelling,
     * because a model asked the same thing twice does not spell it the same way twice.
     *
     * Not through describing the process again from scratch. That text is not the one the user
     * answered about, and carrying old answers into it would silently suppress a question about
     * work nobody has looked at yet.
     */
    public function test_a_dismissed_suggestion_survives_regeneration_but_not_a_new_description(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->dismiss($owner, $process, self::CRITICAL_SUPPLIER_QUESTION, self::DESCRIPTION);

        // The same question asked in different words on the second reading: a dismissal that only
        // survives an exact string match is a dismissal the user watches fail.
        $reworded = $this->supplierProposal();
        $reworded['optional_clarifications'] = [['question' => 'Hva gjør at en leverandør regnes som KRITISK']];

        $this->fakeResponses([$this->supplierProposal(), $reworded, $this->supplierProposal()]);

        // Same description, generated again.
        $this->assertSame([], $this->interpret($owner, $process)['flow_proposal']['optional_clarifications']);

        // The same description with a sentence added — the shape most editing takes.
        $extended = self::DESCRIPTION.' Innkjøper arkiverer avtalen til slutt.';

        $this->assertSame([], $this->interpret($owner, $process, $extended)['flow_proposal']['optional_clarifications']);

        // A different process, described from scratch. The old answer was not about this text, so
        // the suggestion is worth making again — and the dismissal it lapsed from is cleared out
        // rather than left to suppress a future question. (The faked flow is unchanged; what is
        // under test is which questions reach the user, not what the model proposed.)
        $rewritten = 'Når et avvik meldes inn registrerer kvalitetsleder saken i avvikssystemet. Dersom saken gjelder HMS varsles verneombudet umiddelbart. Til slutt lukkes saken av kvalitetsleder.';

        $this->assertSame(
            [self::CRITICAL_SUPPLIER_QUESTION],
            $this->interpret($owner, $process, $rewritten)['flow_proposal']['optional_clarifications'],
        );
        $this->assertSame(0, QualityFlowClarificationDismissal::query()->count());
    }

    /** Saying no on someone else's process, or without the right to manage this one. */
    public function test_dismissing_a_suggestion_passes_the_same_gates_as_every_other_flow_write(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();

        $process = $this->process($customer, 'Leverandøropprettelse');
        $foreign = $this->process($other, 'Andres prosess');
        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/clarifications/dismiss", [
                'question' => self::CRITICAL_SUPPLIER_QUESTION,
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$foreign->id}/blueprint/clarifications/dismiss", [
                'question' => self::CRITICAL_SUPPLIER_QUESTION,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityFlowClarificationDismissal::query()->count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Answering a clarification: woven in, never transcribed
    // ---------------------------------------------------------------------

    /**
     * The brief's worked example, end to end.
     *
     * What is defended here is the difference between a process description and a transcript. The
     * answer belongs in the sentence that raised the question; the question belongs nowhere. A
     * description that accumulates "Hva gjør at en leverandør regnes som kritisk? Når verdien er
     * over …" at the bottom is still technically an answered clarification, and it is useless to
     * the person who has to carry the process out.
     */
    public function test_answering_a_clarification_weaves_the_answer_into_the_description(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        // The rewrite first, then the reading of what it produced.
        $this->fakeResponses([
            ['description' => self::CLARIFIED_DESCRIPTION],
            $this->definedSupplierProposal(),
        ]);

        $proposal = $this->answerClarification(
            $owner,
            $process,
            self::CRITICAL_SUPPLIER_QUESTION,
            self::CRITICAL_SUPPLIER_ANSWER,
        )['flow_proposal'];

        $this->assertSame(self::CLARIFIED_DESCRIPTION, $proposal['description']);

        // The answer is in the text, in the sentence that branched on the undefined word.
        $this->assertStringContainsString('over kr. 100 000', $proposal['description']);
        $this->assertStringContainsString('regnes leverandøren som kritisk', $proposal['description']);

        // And the rest of the description survived it: this is an integration, not a rewrite of
        // the process.
        $this->assertStringContainsString('registrerer innkjøper leverandøren', $proposal['description']);
        $this->assertStringContainsString('Deretter godkjenner økonomi leverandøren', $proposal['description']);

        // Still a proposal. Answering a question is not adopting a flow.
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    /**
     * The question itself never reaches the description, and neither does loose Q&A.
     *
     * Asserted on the text rather than on the prompt, because "do not write the question" is a
     * request to a model and this is the one promise the feature cannot keep by asking nicely. The
     * deterministic half lives in QualityProcessDescriptionClarifier; this is what it buys.
     */
    public function test_the_question_is_never_written_into_the_description(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([
            ['description' => self::CLARIFIED_DESCRIPTION],
            $this->definedSupplierProposal(),
        ]);

        $description = $this->answerClarification(
            $owner,
            $process,
            self::CRITICAL_SUPPLIER_QUESTION,
            self::CRITICAL_SUPPLIER_ANSWER,
        )['flow_proposal']['description'];

        $this->assertStringNotContainsString(self::CRITICAL_SUPPLIER_QUESTION, $description);
        $this->assertStringNotContainsString('Hva gjør at', $description);
        $this->assertStringNotContainsString('?', $description, 'A process description is not an interview.');

        // The model was told what was asked — it cannot weave an answer in without knowing what it
        // answers — and the flow was read from the revised text, not from the original.
        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        $rewrite = $this->sentText($requests[0][0]);
        $this->assertStringContainsString('Hva gjør at en leverandør regnes som kritisk', $rewrite);
        $this->assertStringContainsString('over kr. 100000', $rewrite);

        $this->assertStringContainsString(
            'Dersom verdien av anskaffelsen er over kr. 100 000',
            $this->sentText($requests[1][0]),
        );
    }

    /**
     * The suggestion is gone, and gone because it was answered rather than because it was silenced.
     *
     * No dismissal is recorded. That distinction is the whole behaviour: a dismissal suppresses a
     * question about a description that still raises it, whereas an answer changes the description
     * so there is nothing left to raise. Recording one here would hide the case below.
     */
    public function test_an_answered_clarification_is_gone_from_the_proposal_without_being_suppressed(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([
            ['description' => self::CLARIFIED_DESCRIPTION],
            $this->definedSupplierProposal(),
        ]);

        $proposal = $this->answerClarification(
            $owner,
            $process,
            self::CRITICAL_SUPPLIER_QUESTION,
            self::CRITICAL_SUPPLIER_ANSWER,
        )['flow_proposal'];

        $this->assertSame([], $proposal['optional_clarifications']);
        $this->assertSame([], $proposal['blocking_questions']);
        $this->assertSame(0, QualityFlowClarificationDismissal::query()->count());

        // The flow is still the flow. Defining the term changed what the decision is called, not
        // that the process branches or who does what.
        $this->assertSame(
            ['Innkjøper', 'Sikkerhetsansvarlig', 'Økonomi'],
            array_column($proposal['lanes'], 'label'),
        );
    }

    /**
     * And it stays gone when the process is read again.
     *
     * This is the regeneration case: the user answers, then presses "Generer prosessflyt" on the
     * revised description. Nothing suppresses the question — the text now defines the criterion,
     * so there is nothing to ask. A description that still left it open would raise it again, which
     * is the next test.
     */
    public function test_an_answered_clarification_does_not_return_when_the_revised_description_defines_the_term(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([
            ['description' => self::CLARIFIED_DESCRIPTION],
            $this->definedSupplierProposal(),
            $this->definedSupplierProposal(),
        ]);

        $description = $this->answerClarification(
            $owner,
            $process,
            self::CRITICAL_SUPPLIER_QUESTION,
            self::CRITICAL_SUPPLIER_ANSWER,
        )['flow_proposal']['description'];

        $regenerated = $this->interpret($owner, $process, $description)['flow_proposal'];

        $this->assertSame([], $regenerated['optional_clarifications']);
        $this->assertSame($description, $regenerated['description']);
        $this->assertSame(0, QualityFlowClarificationDismissal::query()->count());
    }

    /**
     * An answer that did not actually settle the question leaves the question standing.
     *
     * The honest outcome, and the reason answering records nothing. "Avvis" is how a user says a
     * term is left to judgement; "Avklar" is how they define it, and a definition that does not
     * define it has not earned the silence.
     */
    public function test_a_clarification_the_answer_did_not_cover_is_raised_again(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $vague = self::DESCRIPTION.' En kritisk leverandør vurderes særskilt.';

        $this->fakeResponses([
            ['description' => $vague],
            $this->supplierProposal(),
        ]);

        $proposal = $this->answerClarification(
            $owner,
            $process,
            self::CRITICAL_SUPPLIER_QUESTION,
            'Det vurderes i hvert enkelt tilfelle.',
        )['flow_proposal'];

        $this->assertSame([self::CRITICAL_SUPPLIER_QUESTION], $proposal['optional_clarifications']);
    }

    /**
     * A rewrite that put the question in the text is refused, and refusing costs the user nothing.
     *
     * The description stands exactly as they wrote it, no flow is read from the bad rewrite, and
     * the message tells them the one thing that can help. Repairing it instead would mean editing
     * their process description on a guess about which words were theirs.
     */
    public function test_a_rewrite_that_still_carries_the_question_is_refused_and_changes_nothing(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([[
            'description' => self::DESCRIPTION."\n\n".self::CRITICAL_SUPPLIER_QUESTION.' '.self::CRITICAL_SUPPLIER_ANSWER,
        ]]);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/clarifications/answer", [
                'description' => self::DESCRIPTION,
                'question' => self::CRITICAL_SUPPLIER_QUESTION,
                'answer' => self::CRITICAL_SUPPLIER_ANSWER,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['flow_proposal']);
        $this->assertNotNull($props['flow_error']);
        $this->assertSame(
            __('procynia.quality.errors.flow_clarification_failed'),
            $props['flow_error']['message'],
        );

        // The description the user wrote, unchanged — which is what lets the browser put the
        // suggestion back rather than lose it.
        $this->assertSame(self::DESCRIPTION, $props['flow_error']['description']);

        // And the flow was never read from it: one call made, not two.
        Http::assertSentCount(1);
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    /**
     * A rewrite that changed nothing is refused for the same reason.
     *
     * Re-reading an identical description would take the suggestion off the screen while changing
     * nothing about why it was raised — the user would believe they had answered it.
     */
    public function test_a_rewrite_that_left_the_description_untouched_is_refused(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        $this->fakeResponses([['description' => self::DESCRIPTION]]);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/clarifications/answer", [
                'description' => self::DESCRIPTION,
                'question' => self::CRITICAL_SUPPLIER_QUESTION,
                'answer' => self::CRITICAL_SUPPLIER_ANSWER,
            ])
            ->assertRedirect();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['flow_proposal']);
        $this->assertNotNull($props['flow_error']);
        Http::assertSentCount(1);
    }

    /** Answering on someone else's process, or without the right to manage this one. */
    public function test_answering_a_clarification_passes_the_same_gates_as_every_other_flow_write(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();

        $process = $this->process($customer, 'Leverandøropprettelse');
        $foreign = $this->process($other, 'Andres prosess');
        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        Http::fake([]);
        Http::preventStrayRequests();

        $payload = [
            'description' => self::DESCRIPTION,
            'question' => self::CRITICAL_SUPPLIER_QUESTION,
            'answer' => self::CRITICAL_SUPPLIER_ANSWER,
        ];

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/clarifications/answer", $payload)
            ->assertForbidden();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$foreign->id}/blueprint/clarifications/answer", $payload)
            ->assertNotFound();

        // An answer with nothing in it never reaches a provider either.
        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/clarifications/answer", [
                'description' => self::DESCRIPTION,
                'question' => self::CRITICAL_SUPPLIER_QUESTION,
                'answer' => '   ',
            ])
            ->assertSessionHasErrors('answer');

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Input rules, authorisation and tenancy
    // ---------------------------------------------------------------------

    public function test_a_description_too_short_to_be_a_process_is_refused_before_a_model_is_called(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/interpret", ['description' => 'Noe skjer.'])
            ->assertSessionHasErrors('description');

        Http::assertNothingSent();
    }

    public function test_only_a_process_can_have_its_flow_interpreted(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Kvalitetspolicy');

        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$policy->id}/blueprint/interpret", ['description' => self::DESCRIPTION])
            ->assertSessionHasErrors('quality_type');

        Http::assertNothingSent();
    }

    public function test_a_contributor_cannot_interpret_or_adopt_a_flow(): void
    {
        ['customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');
        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/interpret", ['description' => self::DESCRIPTION])
            ->assertForbidden();

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/adopt", [
                'lanes' => [['key' => 'rolle-1', 'label' => 'Innkjøper']],
                'nodes' => [['key' => 'a', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Gjør noe']],
                'edges' => [],
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    public function test_another_customers_process_cannot_be_interpreted_or_adopted(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $other] = $this->context();
        $foreign = $this->process($other, 'Andres prosess');

        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$foreign->id}/blueprint/interpret", ['description' => self::DESCRIPTION])
            ->assertNotFound();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$foreign->id}/blueprint/adopt", [
                'lanes' => [['key' => 'rolle-1', 'label' => 'Innkjøper']],
                'nodes' => [['key' => 'a', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Gjør noe']],
                'edges' => [],
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    public function test_a_proposal_for_one_process_is_never_shown_on_another(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');
        $other = $this->process($customer, 'Avvikshåndtering');

        $this->fakeResponse($this->supplierProposal());

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/interpret", ['description' => self::DESCRIPTION]);

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$other->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['flow_proposal']);
    }

    public function test_the_flow_entry_point_is_hidden_when_the_capability_is_switched_off(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        $process = $this->process($customer, 'Leverandøropprettelse');

        config()->set('services.quality.flow_ai_enabled', false);

        Http::fake([]);
        Http::preventStrayRequests();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertFalse($props['flow_ai_available']);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/interpret", ['description' => self::DESCRIPTION])
            ->assertSessionHasErrors('description');

        Http::assertNothingSent();
    }

    /**
     * A customer with no AI in their subscription is told that, not "prøv igjen om litt".
     *
     * The distinction matters commercially: one is a wait, the other is a purchase, and the
     * platform already has AiCostControlPresenter to tell them apart. This test is here to keep
     * that routing from being quietly collapsed into the generic failure path.
     */
    public function test_a_customer_without_ai_entitlement_is_told_why_rather_than_asked_to_retry(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context(withAiAccess: false);
        $process = $this->process($customer, 'Leverandøropprettelse');

        Http::fake([]);
        Http::preventStrayRequests();

        $props = $this->interpret($owner, $process);

        $this->assertNull($props['flow_proposal']);
        $this->assertSame(
            __('procynia.ai_quota.hard_stop.not_included'),
            $props['flow_error']['message'],
        );
        $this->assertNotSame(
            __('procynia.quality.errors.flow_ai_unavailable'),
            $props['flow_error']['message'],
        );

        Http::assertNothingSent();
        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    // ---------------------------------------------------------------------
    // The validator, directly
    // ---------------------------------------------------------------------

    public function test_the_validator_names_the_ways_a_flow_fails_to_be_a_process(): void
    {
        $validator = new QualityProcessFlowValidator;

        $keys = static fn (array $problems): array => array_column($problems, 'key');

        // Nothing reaches the orphan, and it leads nowhere.
        $this->assertSame(
            ['dead_end', 'orphan', 'unreachable', 'never_ends'],
            $keys($validator->graphProblems([
                'lanes' => [['key' => 'l', 'label' => 'Rolle']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'l', 'type' => 'start', 'label' => 'Start'],
                    ['key' => 'a', 'lane' => 'l', 'type' => 'step', 'label' => 'Steg A'],
                    ['key' => 'orphan', 'lane' => 'l', 'type' => 'step', 'label' => 'Glemt steg'],
                    ['key' => 'end', 'lane' => 'l', 'type' => 'end', 'label' => 'Ferdig'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'a', 'label' => null],
                    ['from' => 'a', 'to' => 'end', 'label' => null],
                ],
            ])),
        );

        // No start at all.
        $this->assertContains('no_start', $keys($validator->graphProblems([
            'lanes' => [['key' => 'l', 'label' => 'Rolle']],
            'nodes' => [
                ['key' => 'a', 'lane' => 'l', 'type' => 'step', 'label' => 'Steg A'],
                ['key' => 'end', 'lane' => 'l', 'type' => 'end', 'label' => 'Ferdig'],
            ],
            'edges' => [['from' => 'a', 'to' => 'end', 'label' => null]],
        ])));

        // A decision whose two ways out say the same thing.
        $this->assertContains('decision_outcomes_repeat', $keys($validator->graphProblems([
            'lanes' => [['key' => 'l', 'label' => 'Rolle']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'l', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'd', 'lane' => 'l', 'type' => 'decision', 'label' => 'Er den stor?'],
                ['key' => 'a', 'lane' => 'l', 'type' => 'step', 'label' => 'Steg A'],
                ['key' => 'end', 'lane' => 'l', 'type' => 'end', 'label' => 'Ferdig'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'd', 'label' => null],
                ['from' => 'd', 'to' => 'a', 'label' => 'Ja'],
                ['from' => 'd', 'to' => 'end', 'label' => 'ja'],
                ['from' => 'a', 'to' => 'end', 'label' => null],
            ],
        ])));

        // Duplicate identities and an endpoint that does not exist, found before normalisation.
        $this->assertSame(
            ['duplicate_node', 'unknown_reference'],
            $keys($validator->referenceProblems([
                'nodes' => [
                    ['key' => 'a', 'label' => 'Steg A'],
                    ['key' => 'a', 'label' => 'Steg A igjen'],
                ],
                'edges' => [['from' => 'a', 'to' => 'nowhere', 'label' => null]],
            ])),
        );
    }

    /**
     * A rework loop — "ikke godkjent, tilbake til steg 2" — is an ordinary process, not a mistake.
     * The sweeps are iterative for exactly this reason.
     */
    public function test_the_validator_accepts_a_flow_that_loops_back(): void
    {
        $this->assertSame([], (new QualityProcessFlowValidator)->graphProblems([
            'lanes' => [['key' => 'l', 'label' => 'Rolle']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'l', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'utfor', 'lane' => 'l', 'type' => 'step', 'label' => 'Utfør arbeidet'],
                ['key' => 'kontroll', 'lane' => 'l', 'type' => 'decision', 'label' => 'Godkjent?'],
                ['key' => 'end', 'lane' => 'l', 'type' => 'end', 'label' => 'Ferdig'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'utfor', 'label' => null],
                ['from' => 'utfor', 'to' => 'kontroll', 'label' => null],
                ['from' => 'kontroll', 'to' => 'end', 'label' => 'Ja'],
                ['from' => 'kontroll', 'to' => 'utfor', 'label' => 'Nei'],
            ],
        ]));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The steps one node leads to, in edge order.
     *
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    private function targetsFrom(array $edges, string $key): array
    {
        return array_values(array_map(
            static fn (array $edge): string => (string) $edge['to'],
            array_filter($edges, static fn (array $edge): bool => (string) $edge['from'] === $key),
        ));
    }

    /** "Avvis" on one suggestion, as the panel sends it. */
    private function dismiss(User $owner, QualityItem $item, string $question, ?string $description): void
    {
        $this->actingAs($owner)
            ->post("/app/quality/items/{$item->id}/blueprint/clarifications/dismiss", [
                'question' => $question,
                'description' => $description,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * What one request actually carried, with the provider's unicode escaping undone.
     *
     * The body is JSON, so "kritisk" travels as \u escapes and a naive assertion on it passes or
     * fails for reasons that have nothing to do with the prompt.
     */
    private function sentText(Request $request): string
    {
        return (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** "Bruk svaret" on one suggestion, as the panel sends it. */
    private function answerClarification(
        User $owner,
        QualityItem $item,
        string $question,
        string $answer,
        ?string $description = null,
    ): array {
        $this->actingAs($owner)
            ->post("/app/quality/items/{$item->id}/blueprint/clarifications/answer", [
                'description' => $description ?? self::DESCRIPTION,
                'question' => $question,
                'answer' => $answer,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $this->actingAs($owner)
            ->get("/app/quality/items/{$item->id}?tab=flow")
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function interpret(User $owner, QualityItem $item, ?string $description = null): array
    {
        $this->actingAs($owner)
            ->post("/app/quality/items/{$item->id}/blueprint/interpret", [
                'description' => $description ?? self::DESCRIPTION,
            ])
            ->assertRedirect();

        return $this->actingAs($owner)
            ->get("/app/quality/items/{$item->id}?tab=flow")
            ->viewData('page')['props'];
    }

    /** @param array<string, mixed> $proposal */
    private function fakeResponse(array $proposal): void
    {
        $this->fakeResponses([$proposal]);
    }

    /**
     * A Responses API envelope per call, in order — the second is the repair attempt.
     *
     * @param  list<array<string, mixed>>  $proposals
     */
    private function fakeResponses(array $proposals): void
    {
        $sequence = Http::sequence();

        foreach ($proposals as $proposal) {
            $sequence->pushResponse(Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($proposal, JSON_UNESCAPED_UNICODE)]],
                ]],
                'usage' => ['input_tokens' => 400, 'output_tokens' => 200],
            ], 200));
        }

        Http::fake(['*' => $sequence]);
    }

    /** The worked example from the brief: two roles, one branch that rejoins. */
    private function supplierProposal(): array
    {
        return [
            'trigger' => 'Ny leverandør skal opprettes',
            'outcome' => 'Leverandøren er godkjent',
            'steps' => [
                ['id' => 'registrer_leverandor', 'type' => 'activity', 'role' => 'Innkjøper', 'label' => 'Registrer leverandøren', 'description' => null],
                ['id' => 'kritisk_leverandor', 'type' => 'decision', 'role' => 'Innkjøper', 'label' => 'Er leverandøren kritisk?', 'description' => null],
                ['id' => 'kontroller_leverandor', 'type' => 'activity', 'role' => 'Sikkerhetsansvarlig', 'label' => 'Kontroller leverandøren', 'description' => null],
                ['id' => 'godkjenn_leverandor', 'type' => 'activity', 'role' => 'Økonomi', 'label' => 'Godkjenn leverandøren', 'description' => null],
            ],
            'flows' => [
                ['from' => 'start', 'to' => 'registrer_leverandor', 'condition' => null],
                ['from' => 'registrer_leverandor', 'to' => 'kritisk_leverandor', 'condition' => null],
                ['from' => 'kritisk_leverandor', 'to' => 'kontroller_leverandor', 'condition' => 'Ja'],
                ['from' => 'kritisk_leverandor', 'to' => 'godkjenn_leverandor', 'condition' => 'Nei'],
                ['from' => 'kontroller_leverandor', 'to' => 'godkjenn_leverandor', 'condition' => null],
                ['from' => 'godkjenn_leverandor', 'to' => 'end', 'condition' => null],
            ],
            'blocking_questions' => [],
            // "Kritisk" decides where the process goes and the description never says what it
            // means. Nothing is missing from the flow, so it is not blocking — but it is the one
            // class of observation the prompt does ask for. See CRITICAL_SUPPLIER_QUESTION.
            'optional_clarifications' => [['question' => self::CRITICAL_SUPPLIER_QUESTION]],
        ];
    }

    /**
     * The same flow read from a description that now says what "kritisk" means.
     *
     * The structure is identical — defining the criterion does not change who does what — and the
     * decision carries the threshold instead of the undefined word. Nothing is left to clarify.
     *
     * @return array<string, mixed>
     */
    private function definedSupplierProposal(): array
    {
        $proposal = $this->supplierProposal();

        $proposal['steps'][1]['label'] = 'Er verdien over kr. 100 000?';
        $proposal['optional_clarifications'] = [];

        return $proposal;
    }

    /** The same flow with the "Nei" branch missing — a decision that is not one. */
    private function oneWayDecisionProposal(): array
    {
        $proposal = $this->supplierProposal();

        $proposal['flows'] = array_values(array_filter(
            $proposal['flows'],
            static fn (array $flow): bool => $flow['condition'] !== 'Nei',
        ));

        return $proposal;
    }

    private function vagueOrderProposal(): array
    {
        return [
            'trigger' => 'Bestilling er klar til å sendes',
            'outcome' => 'Bestillingen er sendt',
            'steps' => [
                ['id' => 'stor_bestilling', 'type' => 'decision', 'role' => null, 'label' => 'Er bestillingen stor?', 'description' => null],
                ['id' => 'godkjenn', 'type' => 'activity', 'role' => null, 'label' => 'Godkjenn bestillingen', 'description' => null],
                ['id' => 'send', 'type' => 'activity', 'role' => null, 'label' => 'Send bestillingen', 'description' => null],
            ],
            'flows' => [
                ['from' => 'start', 'to' => 'stor_bestilling', 'condition' => null],
                ['from' => 'stor_bestilling', 'to' => 'godkjenn', 'condition' => 'Ja'],
                ['from' => 'stor_bestilling', 'to' => 'send', 'condition' => 'Nei'],
                ['from' => 'godkjenn', 'to' => 'send', 'condition' => null],
                ['from' => 'send', 'to' => 'end', 'condition' => null],
            ],
            'blocking_questions' => [
                ['question' => 'Hva regnes som en stor bestilling?'],
                ['question' => 'Hvem skal godkjenne en stor bestilling?'],
            ],
            'optional_clarifications' => [
                ['question' => 'Skal bestillingen registreres et sted før den sendes?'],
            ],
        ];
    }

    private function applicationProposal(): array
    {
        return [
            'trigger' => 'Søknad er mottatt',
            'outcome' => 'Søknaden er godkjent',
            'steps' => [
                ['id' => 'send_soknad', 'type' => 'activity', 'role' => 'Kunde', 'label' => 'Send søknaden', 'description' => null],
                ['id' => 'vurder_soknad', 'type' => 'activity', 'role' => 'Saksbehandler', 'label' => 'Vurder søknaden', 'description' => null],
                ['id' => 'godkjenn_soknad', 'type' => 'activity', 'role' => 'Saksbehandler', 'label' => 'Godkjenn søknaden', 'description' => null],
            ],
            'flows' => [
                ['from' => 'start', 'to' => 'send_soknad', 'condition' => null],
                ['from' => 'send_soknad', 'to' => 'vurder_soknad', 'condition' => null],
                ['from' => 'vurder_soknad', 'to' => 'godkjenn_soknad', 'condition' => null],
                ['from' => 'godkjenn_soknad', 'to' => 'end', 'condition' => null],
            ],
            'blocking_questions' => [],
            'optional_clarifications' => [],
        ];
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(bool $withAiAccess = true): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Tolkning AS',
            'slug' => 'kvalitet-tolkning-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        // Interpreting a description is a provider call, so it passes the same commercial guard as
        // every other one — AiCostControlService refuses a customer with no AI entitlement before
        // the request is built. See test_a_customer_without_ai_entitlement_is_told_why.
        if ($withAiAccess) {
            $customer->forceFill([
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
                'included_ai_credits' => 20,
            ])->save();
        }

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'quality'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        return [
            'customer' => $customer,
            'owner' => $this->user($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];
    }

    private function user(Customer $customer, string $role, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::upper(Str::random(4)),
            'email' => 'tolkning-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function item(Customer $customer, string $type, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function process(Customer $customer, string $title): QualityItem
    {
        return $this->item($customer, QualityItem::TYPE_PROCESS, $title);
    }
}
