<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Prosessflyt — the "Flyt" tab on one process.
 *
 * What these tests defend:
 *
 *  - The blueprint is the source of truth. It is normalised on the way in so that what is stored is
 *    always drawable, and no geometry is ever persisted.
 *  - Nothing generates a flow. A blueprint is written only by a person — adopting a proposal, or
 *    saving the editor — and no seeded flow can displace one.
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
    // No generator
    // ---------------------------------------------------------------------

    /**
     * The regression this section exists for.
     *
     * A deterministic generator sat behind a "Generer struktur på nytt" button. When a process had
     * no steps to read it seeded a worked ITIL Incident Management example instead — and because a
     * blueprint is keyed on its process, storing it replaced whatever flow was already there. A
     * user who had described their own process, corrected the proposal and adopted it could lose
     * all of it to one click, and be left looking at servicedesk steps that had nothing to do with
     * their work.
     *
     * The button, the endpoint and the generator are gone. These tests prove it stays that way:
     * the route does not exist, and the one gate every write goes through refuses a seeded source
     * outright — so a future example, demo or step seeder cannot quietly take the old one's place.
     */
    public function test_there_is_no_endpoint_that_generates_a_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->assertFalse(
            Route::has('app.quality.items.blueprint.generate'),
            'the generate endpoint is gone and must stay gone',
        );

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/generate")
            ->assertNotFound();

        $this->assertSame(0, QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->count());
    }

    /**
     * The guarantee itself, at the only place a blueprint is ever written: an adopted flow cannot
     * be replaced by a seeded one, whatever calls store().
     */
    public function test_a_seeded_flow_cannot_replace_a_flow_the_user_adopted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $process = $this->process($customer, 'Leverandøropprettelse');
        $service = app(QualityProcessBlueprintService::class);

        $adopted = $service->store(
            (int) $customer->id,
            $process,
            $this->simpleFlow(),
            QualityProcessBlueprint::SOURCE_AI,
            $owner,
        );

        foreach (QualityProcessBlueprint::RETIRED_SOURCES as $seeded) {
            try {
                $service->store((int) $customer->id, $process, $this->incidentManagementFlow(), $seeded, $owner);
                $this->fail("a blueprint with source [{$seeded}] must be refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source', $exception->errors());
            }
        }

        $after = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->sole();

        $this->assertSame($adopted->id, $after->id);
        $this->assertSame(QualityProcessBlueprint::SOURCE_AI, $after->source);
        $this->assertSame(['Start', 'Vurder saken', 'Ferdig'], array_column($after->nodes(), 'label'));
    }

    /**
     * Not only "cannot overwrite" — cannot be stored at all. An empty process is where the example
     * used to land, and a seeded flow on a blank page is still a claim about work nobody described.
     */
    public function test_a_seeded_flow_cannot_be_stored_on_a_process_that_has_none(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->expectException(ValidationException::class);

        try {
            app(QualityProcessBlueprintService::class)->store(
                (int) $customer->id,
                $process,
                $this->incidentManagementFlow(),
                QualityProcessBlueprint::SOURCE_EXAMPLE,
                $owner,
            );
        } finally {
            $this->assertSame(0, QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->count());
        }
    }

    public function test_one_process_has_at_most_one_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow());
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow());

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
            ->put("/app/quality/items/{$checklist->id}/blueprint", $this->simpleFlow())
            ->assertSessionHasErrors('quality_type');
    }

    public function test_a_contributor_without_the_quality_authority_cannot_change_the_flow(): void
    {
        ['customer' => $customer] = $this->context();

        $contributor = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);
        $process = $this->process($customer, 'Avvikshåndtering');

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
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
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
     * A stand-in for the flow the retired generator used to seed: somebody else's process, with
     * somebody else's roles, landing on a process that never described any of it.
     *
     * @return array<string, mixed>
     */
    private function incidentManagementFlow(): array
    {
        return [
            'lanes' => [
                ['key' => 'servicedesk', 'label' => 'Servicedesk (1. linje)'],
            ],
            'nodes' => [
                ['key' => 'melding', 'lane' => 'servicedesk', 'type' => 'start', 'label' => 'Hendelse meldes inn'],
                ['key' => 'registrer', 'lane' => 'servicedesk', 'type' => 'step', 'label' => 'Registrer og kategoriser hendelsen'],
                ['key' => 'lukket', 'lane' => 'servicedesk', 'type' => 'end', 'label' => 'Hendelsen er lukket'],
            ],
            'edges' => [
                ['from' => 'melding', 'to' => 'registrer', 'label' => null],
                ['from' => 'registrer', 'to' => 'lukket', 'label' => null],
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
}
