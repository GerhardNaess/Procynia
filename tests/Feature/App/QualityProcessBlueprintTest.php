<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessIo;
use App\Models\QualityProcessStep;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Prosessflyt — the "Flyt" tab on one process.
 *
 * What these tests defend:
 *
 *  - The blueprint is the source of truth. It is normalised on the way in so that what is stored is
 *    always drawable, and no geometry is ever persisted.
 *  - "Generer struktur" reads the process's own steps when it has them, and only falls back to the
 *    worked example when there is nothing to read. It calls no model.
 *  - Approval is a statement about one specific flow, so editing the flow clears it.
 *  - The flow belongs to a process and to nothing else — neither the tab nor the endpoints exist
 *    for a policy or a control.
 *  - Nothing crosses a customer boundary, and the module gate is the same one the page has.
 */
class QualityProcessBlueprintTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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
    // The tab
    // ---------------------------------------------------------------------

    public function test_only_a_process_gets_the_flow_tab(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Kvalitetspolicy');

        $this->assertTrue(
            $this->actingAs($owner)->get("/app/quality/items/{$process->id}")
                ->viewData('page')['props']['has_flow'],
        );

        $this->assertFalse(
            $this->actingAs($owner)->get("/app/quality/items/{$policy->id}")
                ->viewData('page')['props']['has_flow'],
        );
    }

    /**
     * A link to ?tab=flow on something that is not a process must land somewhere real. The document
     * used to be the only tab, so such a link is a plausible thing to have lying around.
     */
    public function test_the_flow_tab_cannot_be_opened_on_a_document_that_has_no_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Kvalitetspolicy');

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$policy->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertSame('document', $props['active_tab']);
        $this->assertNull($props['blueprint']);
    }

    public function test_the_page_opens_on_the_document_tab_by_default(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->assertSame(
            'document',
            $this->actingAs($owner)->get("/app/quality/items/{$process->id}")
                ->viewData('page')['props']['active_tab'],
        );

        $this->assertSame(
            'flow',
            $this->actingAs($owner)->get("/app/quality/items/{$process->id}?tab=flow")
                ->viewData('page')['props']['active_tab'],
        );
    }

    public function test_a_process_without_a_flow_reports_none_rather_than_an_empty_one(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->assertNull(
            $this->actingAs($owner)->get("/app/quality/items/{$process->id}?tab=flow")
                ->viewData('page')['props']['blueprint'],
        );
    }

    // ---------------------------------------------------------------------
    // Generating
    // ---------------------------------------------------------------------

    /**
     * The generator reads the document, it does not invent one. Roles become lanes in the order
     * they first appear, steps become nodes in the order they are written, and the input and output
     * become the ends of the flow.
     */
    public function test_generating_reads_the_processs_own_steps(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->step($process, 1, 'Registrer avviket', 'Servicedesk');
        $this->step($process, 2, 'Vurder alvorlighet', 'Servicedesk');
        $this->step($process, 3, 'Iverksett tiltak', 'Fagansvarlig');
        $this->io($process, 'input', 'Avvik meldt inn');
        $this->io($process, 'output', 'Avviket er lukket');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/generate")
            ->assertRedirect();

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::SOURCE_DERIVED, $blueprint->source);
        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $blueprint->status);

        // One lane per distinct role, in first-appearance order. The catch-all lane is dropped,
        // because every step named a role.
        $this->assertSame(['Servicedesk', 'Fagansvarlig'], array_column($blueprint->lanes(), 'label'));

        $this->assertSame(
            ['Avvik meldt inn', 'Registrer avviket', 'Vurder alvorlighet', 'Iverksett tiltak', 'Avviket er lukket'],
            array_column($blueprint->nodes(), 'label'),
        );

        $types = array_column($blueprint->nodes(), 'type');
        $this->assertSame(QualityProcessBlueprint::NODE_START, $types[0]);
        $this->assertSame(QualityProcessBlueprint::NODE_END, $types[4]);

        // Chained, so the flow is drawable the moment it is generated.
        $this->assertCount(4, $blueprint->edges());
    }

    public function test_a_step_without_a_named_role_lands_in_the_catch_all_lane(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->step($process, 1, 'Registrer avviket', 'Servicedesk');
        $this->step($process, 2, 'Følg opp', null);

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();
        $lanes = collect($blueprint->lanes())->keyBy('key');
        $nodes = collect($blueprint->nodes())->keyBy('label');

        $this->assertCount(2, $lanes);
        $this->assertNotSame($nodes['Registrer avviket']['lane'], $nodes['Følg opp']['lane']);
    }

    /**
     * No steps means nothing to read, so a worked example is seeded instead — something to edit
     * rather than a blank page. `source` is what keeps the two apart afterwards.
     */
    public function test_a_process_with_no_steps_is_seeded_from_the_incident_management_example(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Incident Management');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::SOURCE_EXAMPLE, $blueprint->source);
        $this->assertCount(4, $blueprint->lanes());
        $this->assertCount(14, $blueprint->nodes());
        $this->assertCount(16, $blueprint->edges());

        // The example earns its place by having the shapes a step list cannot express.
        $types = array_column($blueprint->nodes(), 'type');
        $this->assertContains(QualityProcessBlueprint::NODE_DECISION, $types);
        $this->assertContains(QualityProcessBlueprint::NODE_START, $types);
        $this->assertContains(QualityProcessBlueprint::NODE_END, $types);

        $outcomes = array_values(array_filter(array_column($blueprint->edges(), 'label')));
        $this->assertNotEmpty($outcomes, 'a branch with unnamed outcomes is unreadable');
    }

    public function test_generating_again_replaces_the_flow_and_clears_its_approval(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->step($process, 1, 'Registrer avviket', 'Servicedesk');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/approve");

        $this->assertSame(
            QualityProcessBlueprint::STATUS_APPROVED,
            QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole()->status,
        );

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $blueprint->status);
        $this->assertNull($blueprint->approved_at);
        $this->assertNull($blueprint->approved_by_user_id);
    }

    public function test_one_process_has_at_most_one_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/generate");

        $this->assertSame(
            1,
            QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->count(),
        );
    }

    // ---------------------------------------------------------------------
    // Editing
    // ---------------------------------------------------------------------

    public function test_an_edited_flow_is_stored_as_given(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertRedirect();

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::SOURCE_MANUAL, $blueprint->source);
        $this->assertSame(['Saksbehandler'], array_column($blueprint->lanes(), 'label'));
        $this->assertSame(['Start', 'Vurder saken', 'Ferdig'], array_column($blueprint->nodes(), 'label'));
        $this->assertCount(2, $blueprint->edges());
    }

    /**
     * The guarantee the renderer depends on: nothing drawable is ever stored. An edge whose target
     * the user just deleted is dropped rather than refused — refusing would trap them, because the
     * only way out is the edit they cannot make.
     */
    public function test_an_edge_to_a_node_that_no_longer_exists_is_dropped_not_refused(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['edges'][] = ['from' => 'start', 'to' => 'slettet', 'label' => 'Nei'];

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertCount(2, $blueprint->edges());
        $this->assertNotContains('slettet', array_column($blueprint->edges(), 'to'));
    }

    public function test_a_node_in_a_lane_that_does_not_exist_is_moved_rather_than_lost(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['nodes'][] = ['key' => 'hjemløs', 'lane' => 'borte', 'type' => 'step', 'label' => 'Uten rolle'];

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $payload);

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();
        $laneKeys = array_column($blueprint->lanes(), 'key');
        $node = collect($blueprint->nodes())->firstWhere('label', 'Uten rolle');

        $this->assertNotNull($node);
        $this->assertContains($node['lane'], $laneKeys);
    }

    public function test_a_lane_nothing_happens_in_is_dropped(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['lanes'][] = ['key' => 'tom', 'label' => 'Ingen gjør noe her'];

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $payload);

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(['Saksbehandler'], array_column($blueprint->lanes(), 'label'));
    }

    public function test_a_blank_node_is_dropped_the_way_a_blank_structure_row_is(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['nodes'][] = ['key' => 'tom', 'lane' => 'saksbehandler', 'type' => 'step', 'label' => '   '];

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $payload);

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertCount(3, $blueprint->nodes());
    }

    public function test_a_flow_with_nothing_in_it_is_refused(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", ['lanes' => [], 'nodes' => [], 'edges' => []])
            ->assertSessionHasErrors('nodes');

        $this->assertSame(0, QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->count());
    }

    public function test_two_identical_arrows_between_the_same_pair_become_one(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['edges'][] = ['from' => 'start', 'to' => 'vurder', 'label' => null];

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $payload);

        $this->assertCount(
            2,
            QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole()->edges(),
        );
    }

    /**
     * Two differently labelled arrows between the same pair are a real branch, not a duplicate —
     * which is why the outcome is part of an edge's identity.
     */
    public function test_two_differently_labelled_arrows_between_the_same_pair_both_survive(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $payload = $this->simpleFlow();
        $payload['edges'][] = ['from' => 'start', 'to' => 'vurder', 'label' => 'Ja'];
        $payload['edges'][] = ['from' => 'start', 'to' => 'vurder', 'label' => 'Nei'];

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $payload);

        $edges = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole()->edges();

        $this->assertCount(4, $edges);
        $this->assertSame(
            ['Ja', 'Nei'],
            collect($edges)->pluck('label')->filter()->sort()->values()->all(),
        );
    }

    // ---------------------------------------------------------------------
    // Approval
    // ---------------------------------------------------------------------

    public function test_approving_records_who_vouched_for_the_flow_and_when(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow());

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertRedirect();

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::STATUS_APPROVED, $blueprint->status);
        $this->assertSame((int) $owner->id, (int) $blueprint->approved_by_user_id);
        $this->assertNotNull($blueprint->approved_at);
    }

    /**
     * The rule that keeps an approval honest: it covers the flow as it stood, so an edit undoes it.
     */
    public function test_editing_an_approved_flow_sends_it_back_to_draft(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow());
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/approve");

        $changed = $this->simpleFlow();
        $changed['nodes'][1]['label'] = 'Vurder saken grundig';

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $changed);

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $blueprint->status);
        $this->assertNull($blueprint->approved_at);
    }

    public function test_a_process_with_no_flow_has_nothing_to_approve(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertSessionHasErrors('blueprint');
    }

    public function test_the_approved_flow_reaches_the_page_with_who_approved_it(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow());
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/blueprint/approve");

        $blueprint = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props']['blueprint'];

        $this->assertSame('approved', $blueprint['status']);
        $this->assertSame($owner->name, $blueprint['approved_by_name']);
        $this->assertCount(3, $blueprint['nodes']);

        // Geometry is never stored — the diagram is a function of this payload and nothing else.
        $this->assertSame([], array_intersect(
            ['x', 'y', 'width', 'height', 'positions', 'layout', 'svg'],
            array_keys($blueprint),
        ));
    }

    // ---------------------------------------------------------------------
    // Who may do it, and to what
    // ---------------------------------------------------------------------

    public function test_a_flow_belongs_to_a_process_and_to_nothing_else(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $checklist = $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste tilbud');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$checklist->id}/blueprint/generate")
            ->assertSessionHasErrors('quality_type');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$checklist->id}/blueprint", $this->simpleFlow())
            ->assertSessionHasErrors('quality_type');
    }

    public function test_a_contributor_without_the_quality_authority_cannot_change_the_flow(): void
    {
        ['customer' => $customer] = $this->context();

        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);
        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/generate")
            ->assertForbidden();

        $this->actingAs($contributor)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertForbidden();

        $this->actingAs($contributor)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertForbidden();
    }

    public function test_another_customers_process_has_no_flow_to_reach(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $other] = $this->context();

        $theirs = $this->process($other, 'Deres prosess');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$theirs->id}/blueprint/generate")
            ->assertNotFound();

        $this->actingAs($owner)
            ->put("/app/quality/items/{$theirs->id}/blueprint", $this->simpleFlow())
            ->assertNotFound();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$theirs->id}/blueprint/approve")
            ->assertNotFound();
    }

    /**
     * The guard is on the route group by name prefix, so the flow endpoints are refused exactly as
     * the page is — turned away to the dashboard, not 403'd. A bookmarked form must not be a way
     * past the commercial boundary.
     */
    public function test_the_flow_endpoints_are_gated_by_the_same_entitlement_as_the_page(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context(grantQuality: false);

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/generate")
            ->assertRedirect(route('app.dashboard'));

        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * The smallest flow that is still a flow: one lane, a start, a step and an end.
     *
     * @return array<string, mixed>
     */
    private function simpleFlow(): array
    {
        return [
            'lanes' => [
                ['key' => 'saksbehandler', 'label' => 'Saksbehandler'],
            ],
            'nodes' => [
                ['key' => 'start', 'lane' => 'saksbehandler', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'vurder', 'lane' => 'saksbehandler', 'type' => 'step', 'label' => 'Vurder saken'],
                ['key' => 'ferdig', 'lane' => 'saksbehandler', 'type' => 'end', 'label' => 'Ferdig'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'vurder', 'label' => null],
                ['from' => 'vurder', 'to' => 'ferdig', 'label' => null],
            ],
        ];
    }

    /**
     * @return array{customer: Customer, owner: User}
     */
    private function context(bool $grantQuality = true): array
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
            'name' => 'Kvalitet Flyt AS',
            'slug' => 'kvalitet-flyt-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER);

        if ($grantQuality) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => 'quality'],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function user(Customer $customer, string $role, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::upper(Str::random(4)),
            'email' => 'flyt-'.Str::lower(Str::random(10)).'@procynia.local',
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

    private function step(QualityItem $process, int $position, string $title, ?string $responsibility): void
    {
        QualityProcessStep::query()->create([
            'customer_id' => $process->customer_id,
            'quality_item_id' => $process->id,
            'position' => $position,
            'title' => $title,
            'responsibility' => $responsibility,
        ]);
    }

    private function io(QualityItem $process, string $direction, string $label): void
    {
        QualityProcessIo::query()->create([
            'customer_id' => $process->customer_id,
            'quality_item_id' => $process->id,
            'direction' => $direction,
            'position' => 1,
            'label' => $label,
        ]);
    }
}
