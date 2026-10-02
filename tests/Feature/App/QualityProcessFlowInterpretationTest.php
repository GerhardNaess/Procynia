<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityProcessFlowValidator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
     * and the ambiguities reach the user intact.
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
            $props['flow_proposal']['ambiguities'],
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

        $this->assertSame([], $proposal['ambiguities']);
        $this->assertSame(['Kunde', 'Saksbehandler'], array_column($proposal['lanes'], 'label'));
        $this->assertCount(5, $proposal['nodes']);
        $this->assertCount(4, $proposal['edges']);
        $this->assertSame([null, null, null, null], array_column($proposal['edges'], 'label'));
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
            'ambiguities' => [],
        ];
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
            'ambiguities' => [
                ['question' => 'Hva regnes som en stor bestilling?'],
                ['question' => 'Hvem skal godkjenne en stor bestilling?'],
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
            'ambiguities' => [],
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
