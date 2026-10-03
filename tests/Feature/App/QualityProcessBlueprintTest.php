<?php

namespace Tests\Feature\App;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
     * The page must not send the user after a control that no longer exists.
     *
     * Removing the generator left its instruction behind. A process with no flow was told "Generer
     * struktur for å få et utkast å redigere" — a button deleted in the same change — while the one
     * control that does build a flow sat greyed out above it, waiting for a description field whose
     * only label was for a screen reader. The tab therefore read as broken on exactly the processes
     * it matters most on: the ones with no flow yet.
     *
     * Both locales, because the English copy carried the same instruction.
     */
    public function test_a_process_with_no_flow_is_pointed_at_the_description_not_at_a_removed_button(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['blueprint'], 'this is the flow-less case');

        $copy = $props['translations']['quality']['blueprint'];

        foreach (['no', 'en'] as $locale) {
            $empty = __('procynia.quality.blueprint.empty', [], $locale);
            $unavailable = __('procynia.quality.blueprint.ai_unavailable', [], $locale);

            foreach ([$empty, $unavailable] as $text) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'generer struktur',
                    $text,
                    "the retired generator must not be promised in {$locale}",
                );
                $this->assertStringNotContainsStringIgnoringCase(
                    'generate a structure',
                    $text,
                    "the retired generator must not be promised in {$locale}",
                );
            }
        }

        // What the empty state does point at: the field the description is written in, which is
        // the only way a flow-less process gets a flow.
        $this->assertStringContainsStringIgnoringCase('prosessbeskrivelse', $copy['empty']);

        // And that field is named on screen rather than only to a screen reader, so "Formål" — the
        // read-only card above it — is no longer the only thing on the tab called a description.
        $this->assertSame('Prosessbeskrivelse', $copy['ai_label']);
        $this->assertNotSame($copy['ai_label'], $copy['description_heading']);
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
    // Underprosesser
    // ---------------------------------------------------------------------

    /**
     * What these tests defend.
     *
     * A step can stand for another process. The node holds nothing but that process's id, so the
     * parent shows the subprocess as it is now rather than a copy of how it was — and the two rules
     * that cannot live in a JSON payload hold: nothing crosses a customer boundary, and A → B → A
     * is refused rather than drawn into a loop nobody can get out of.
     */
    public function test_a_node_can_point_at_another_process(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $child = $this->process($customer, 'Leverandørkontroll');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$parent->id}/blueprint", $this->flowWithSubprocess($child->id))
            ->assertSessionHasNoErrors();

        $stored = QualityProcessBlueprint::query()->where('quality_item_id', $parent->id)->sole();

        $this->assertSame(
            [null, $child->id, null],
            array_map(
                static fn (array $node): ?int => $node['subprocess_quality_item_id'],
                $stored->nodes(),
            ),
        );
    }

    /**
     * The reference is read when the page is opened, never cached on the parent. That is the whole
     * reason it is a reference: the parent's diagram has to say what the subprocess holds today.
     */
    public function test_the_parent_reports_the_subprocess_as_it_is_now(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $child = $this->process($customer, 'Leverandørkontroll');

        $this->blueprintFor($customer, $parent, $this->flowWithSubprocess($child->id));

        $node = $this->subprocessNode($owner, $parent);

        $this->assertSame($child->id, $node['subprocess']['id']);
        $this->assertSame('Leverandørkontroll', $node['subprocess']['title']);
        $this->assertSame(0, $node['subprocess']['step_count'], 'the subprocess has no flow yet');

        // The subprocess gets a flow of its own. Nothing on the parent was touched.
        $this->blueprintFor($customer, $child, $this->simpleFlow());

        $this->assertSame(3, $this->subprocessNode($owner, $parent)['subprocess']['step_count']);

        // And is renamed. Same answer, for the same reason.
        $child->forceFill(['title' => 'Kontroll av leverandør'])->save();

        $this->assertSame(
            'Kontroll av leverandør',
            $this->subprocessNode($owner, $parent)['subprocess']['title'],
        );
    }

    public function test_drilling_down_shows_the_subprocess_own_blueprint(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $child = $this->process($customer, 'Leverandørkontroll');

        $this->blueprintFor($customer, $parent, $this->flowWithSubprocess($child->id));
        $this->blueprintFor($customer, $child, $this->simpleFlow());

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$parent->id}?tab=flow&subprocess={$child->id}")
            ->viewData('page')['props'];

        $view = $props['subprocess_view'];

        $this->assertSame([['id' => $child->id, 'title' => 'Leverandørkontroll']], $view['trail']);
        $this->assertSame(
            ['Start', 'Vurder saken', 'Ferdig'],
            array_column($view['blueprint']['nodes'], 'label'),
        );

        // The parent's own flow still travels, so the breadcrumb back costs nothing.
        $this->assertSame('Vurder leverandøren', $props['blueprint']['nodes'][1]['label']);
    }

    /** Point 6: the parent shows the subprocess's new version without being edited itself. */
    public function test_an_edited_subprocess_is_shown_as_edited_when_the_parent_opens_it(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $child = $this->process($customer, 'Leverandørkontroll');

        $this->blueprintFor($customer, $parent, $this->flowWithSubprocess($child->id));
        $this->blueprintFor($customer, $child, $this->simpleFlow());

        $parentUpdatedAt = QualityProcessBlueprint::query()
            ->where('quality_item_id', $parent->id)->sole()->updated_at;

        $this->actingAs($owner)
            ->put("/app/quality/items/{$child->id}/blueprint", $this->incidentManagementFlow())
            ->assertSessionHasNoErrors();

        $view = $this->actingAs($owner)
            ->get("/app/quality/items/{$parent->id}?tab=flow&subprocess={$child->id}")
            ->viewData('page')['props']['subprocess_view'];

        $this->assertSame(
            ['Hendelse meldes inn', 'Registrer og kategoriser hendelsen', 'Hendelsen er lukket'],
            array_column($view['blueprint']['nodes'], 'label'),
        );

        $this->assertEquals(
            $parentUpdatedAt,
            QualityProcessBlueprint::query()->where('quality_item_id', $parent->id)->sole()->updated_at,
            'the parent is a reference, so nothing about it had to change',
        );
    }

    /**
     * A trail is a path the kvalitetssystem describes, not a list of ids anybody may ask for. Two
     * processes of the same customer, with no reference between them, are not a drill-down.
     */
    public function test_the_trail_may_only_follow_a_reference_that_is_written_down(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $unrelated = $this->process($customer, 'Rekruttering');

        $this->blueprintFor($customer, $parent, $this->simpleFlow());
        $this->blueprintFor($customer, $unrelated, $this->incidentManagementFlow());

        $this->assertNull(
            $this->actingAs($owner)
                ->get("/app/quality/items/{$parent->id}?tab=flow&subprocess={$unrelated->id}")
                ->viewData('page')['props']['subprocess_view'],
        );
    }

    /** Several levels down, each hop checked against the blueprint above it. */
    public function test_a_subprocess_can_itself_be_drilled_into(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $top = $this->process($customer, 'Innkjøp');
        $middle = $this->process($customer, 'Leverandørkontroll');
        $bottom = $this->process($customer, 'Sikkerhetsvurdering');

        $this->blueprintFor($customer, $top, $this->flowWithSubprocess($middle->id));
        $this->blueprintFor($customer, $middle, $this->flowWithSubprocess($bottom->id));
        $this->blueprintFor($customer, $bottom, $this->simpleFlow());

        $view = $this->actingAs($owner)
            ->get("/app/quality/items/{$top->id}?tab=flow&subprocess={$middle->id},{$bottom->id}")
            ->viewData('page')['props']['subprocess_view'];

        $this->assertSame(
            [
                ['id' => $middle->id, 'title' => 'Leverandørkontroll'],
                ['id' => $bottom->id, 'title' => 'Sikkerhetsvurdering'],
            ],
            $view['trail'],
        );

        $this->assertSame(
            ['Start', 'Vurder saken', 'Ferdig'],
            array_column($view['blueprint']['nodes'], 'label'),
        );

        // Skipping the middle hop is not a shortcut — the top does not point at the bottom.
        $this->assertNull(
            $this->actingAs($owner)
                ->get("/app/quality/items/{$top->id}?tab=flow&subprocess={$bottom->id}")
                ->viewData('page')['props']['subprocess_view'],
        );
    }

    /**
     * Unlinking a subprocess must not turn a saved link into an error page. The reader lands on the
     * deepest view that is still true, which at depth one is the process itself.
     */
    public function test_a_trail_through_a_reference_that_is_gone_truncates_rather_than_fails(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $top = $this->process($customer, 'Innkjøp');
        $middle = $this->process($customer, 'Leverandørkontroll');
        $bottom = $this->process($customer, 'Sikkerhetsvurdering');

        $this->blueprintFor($customer, $top, $this->flowWithSubprocess($middle->id));
        $this->blueprintFor($customer, $middle, $this->simpleFlow());

        $view = $this->actingAs($owner)
            ->get("/app/quality/items/{$top->id}?tab=flow&subprocess={$middle->id},{$bottom->id}")
            ->viewData('page')['props']['subprocess_view'];

        $this->assertSame([['id' => $middle->id, 'title' => 'Leverandørkontroll']], $view['trail']);

        // The whole reference goes.
        $this->actingAs($owner)
            ->put("/app/quality/items/{$top->id}/blueprint", $this->simpleFlow())
            ->assertSessionHasNoErrors();

        $this->assertNull(
            $this->actingAs($owner)
                ->get("/app/quality/items/{$top->id}?tab=flow&subprocess={$middle->id}")
                ->viewData('page')['props']['subprocess_view'],
        );
    }

    public function test_a_process_cannot_be_its_own_subprocess(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->flowWithSubprocess($process->id))
            ->assertSessionHasErrors('nodes');

        $this->assertSame(0, QualityProcessBlueprint::query()->count());
    }

    /**
     * A → B → A. The cycle is refused rather than dropped, because unlike a dangling edge it is a
     * statement the user has just made and can fix by choosing a different process — and drilling
     * into it would be a trail with no bottom.
     */
    public function test_a_subprocess_reference_that_closes_a_cycle_is_refused(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $a = $this->process($customer, 'Innkjøp');
        $b = $this->process($customer, 'Leverandørkontroll');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$a->id}/blueprint", $this->flowWithSubprocess($b->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put("/app/quality/items/{$b->id}/blueprint", $this->flowWithSubprocess($a->id))
            ->assertSessionHasErrors('nodes');

        $this->assertSame(0, QualityProcessBlueprint::query()->where('quality_item_id', $b->id)->count());

        // A longer way round is the same answer: A → B → C → A.
        $c = $this->process($customer, 'Sikkerhetsvurdering');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$b->id}/blueprint", $this->flowWithSubprocess($c->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put("/app/quality/items/{$c->id}/blueprint", $this->flowWithSubprocess($a->id))
            ->assertSessionHasErrors('nodes');
    }

    /** A process that would close a cycle is never offered, so the refusal above is a backstop. */
    public function test_a_process_that_would_close_a_cycle_is_not_offered(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $a = $this->process($customer, 'Innkjøp');
        $b = $this->process($customer, 'Leverandørkontroll');

        $this->blueprintFor($customer, $a, $this->flowWithSubprocess($b->id));

        $options = $this->actingAs($owner)
            ->get("/app/quality/items/{$b->id}?tab=flow")
            ->viewData('page')['props']['subprocess_options'];

        $this->assertSame([], array_column($options, 'id'), 'A points at B, so B may not point at A');

        $options = $this->actingAs($owner)
            ->get("/app/quality/items/{$a->id}?tab=flow")
            ->viewData('page')['props']['subprocess_options'];

        $this->assertSame([$b->id], array_column($options, 'id'));
    }

    /**
     * Tenancy. Another customer's process is not a thing to point at and not a thing to drill into,
     * and the reference is dropped rather than stored and filtered later — nothing about somebody
     * else's kvalitetssystem may sit in this customer's payload at all.
     */
    public function test_another_customers_process_cannot_be_referenced(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();

        $ours = $this->process($customer, 'Innkjøp');
        $theirs = $this->process($other, 'Deres prosess');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$ours->id}/blueprint", $this->flowWithSubprocess($theirs->id))
            ->assertSessionHasNoErrors();

        $stored = QualityProcessBlueprint::query()->where('quality_item_id', $ours->id)->sole();

        $this->assertSame(
            [null, null, null],
            array_map(
                static fn (array $node): ?int => $node['subprocess_quality_item_id'],
                $stored->nodes(),
            ),
        );

        $this->assertNull($this->subprocessNode($owner, $ours)['subprocess']);

        $this->assertNull(
            $this->actingAs($owner)
                ->get("/app/quality/items/{$ours->id}?tab=flow&subprocess={$theirs->id}")
                ->viewData('page')['props']['subprocess_view'],
        );

        $this->assertSame(
            [],
            array_column(
                $this->actingAs($owner)->get("/app/quality/items/{$ours->id}?tab=flow")
                    ->viewData('page')['props']['subprocess_options'],
                'id',
            ),
            'only this customer\'s processes are selectable',
        );
    }

    /** Only a process is a process. A checklist is not something a step opens into. */
    public function test_only_a_process_can_be_referenced(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $checklist = $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste for leveranse');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->flowWithSubprocess($checklist->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->subprocessNode($owner, $process)['subprocess']);
    }

    /**
     * Point 7 of the brief, as a test: a flow with no references behaves exactly as it did before
     * any of this existed. Every node reports no subprocess, and the tab is not drilled into.
     */
    public function test_a_flow_without_references_is_unchanged(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Avvikshåndtering');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        $this->assertNull($props['subprocess_view']);

        foreach ($props['blueprint']['nodes'] as $node) {
            $this->assertNull($node['subprocess_quality_item_id']);
            $this->assertNull($node['subprocess']);
        }
    }

    /**
     * A reader who cannot edit the flow is not sent the picker's contents — it is one dropdown in
     * the editor they do not have, and a list of other processes is not something the page owes them.
     */
    public function test_the_picker_is_only_sent_to_someone_who_can_edit_the_flow(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $parent = $this->process($customer, 'Innkjøp');
        $child = $this->process($customer, 'Leverandørkontroll');

        $this->blueprintFor($customer, $parent, $this->flowWithSubprocess($child->id));

        $reader = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        $props = $this->actingAs($reader)
            ->get("/app/quality/items/{$parent->id}?tab=flow&subprocess={$child->id}")
            ->viewData('page')['props'];

        $this->assertFalse($props['can_manage']);
        $this->assertSame([], $props['subprocess_options']);

        // Reading the subprocess is not an edit, so the drill-down itself still works.
        $this->assertSame(
            [['id' => $child->id, 'title' => 'Leverandørkontroll']],
            $props['subprocess_view']['trail'],
        );
    }

    /**
     * A model reading a plain-language description never proposes a subprocess, and must not be
     * able to smuggle an id in: the interpreter normalises without a customer, which is the one
     * state in which a reference cannot be checked, so it is dropped there by construction.
     */
    public function test_a_reference_is_dropped_when_there_is_no_customer_to_check_it_against(): void
    {
        $normalised = app(QualityProcessBlueprintService::class)->normalise($this->flowWithSubprocess(1));

        foreach ($normalised['nodes'] as $node) {
            $this->assertNull($node['subprocess_quality_item_id']);
        }
    }

    // ---------------------------------------------------------------------
    // En aktivitet som kilde til kunnskap i Wiki
    // ---------------------------------------------------------------------

    /**
     * What these tests defend.
     *
     * A prosessaktivitet is a SOURCE of knowledge, not a place to hang existing pages. The user
     * asks for an article from a step, corrects the draft, and what is created is an ordinary
     * Enterprise Wiki SOURCE DOCUMENT, handed to the ordinary ingest run.
     *
     * Why a source and not a page: concept pages, entity pages and summaries are planned by the
     * maintainer decision, and a maintainer decision only ever exists for an EnterpriseWikiDocument.
     * An activity that wrote its own page produced one page and nothing else — it skipped the whole
     * pipeline. Kvalitet owns no enrichment of its own and must never grow any.
     *
     * The invariant that cost this feature its first design: the provenance record survives the
     * flow being rewritten. A blueprint payload is replaced wholesale on every save and on every
     * adopted proposal, so provenance kept inside it would be destroyed by an ordinary edit.
     */
    public function test_an_activity_creates_an_ordinary_wiki_source_and_starts_the_ordinary_run(): void
    {
        Queue::fake();

        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'vurder',
                'title' => 'Sikkerhetskrav ved vurdering av leverandører',
                'markdown' => "Artikkelen dekker hva som skal kontrolleres.\n\n## Hva du ser etter\n\nDokumentasjon på styringssystem.",
            ])
            ->assertSessionHasNoErrors();

        // No page is written here. Which pages this source becomes is the maintainer decision's to
        // make, and it has not run yet.
        $this->assertSame(0, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();

        $this->assertSame('Sikkerhetskrav ved vurdering av leverandører.md', $document->original_filename);
        $this->assertSame(EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, $document->document_status);
        $this->assertSame((int) $owner->id, (int) $document->uploaded_by_user_id);
        // The title is written into the text as the document's own H1: the planner reads the text,
        // and a source whose subject is only in its filename is one it has to guess at.
        $this->assertStringContainsString('# Sikkerhetskrav ved vurdering av leverandører', (string) $document->extracted_text);
        $this->assertStringContainsString('## Hva du ser etter', (string) $document->extracted_text);

        // The bytes are on disk, because the document IS the file — re-ingest, download and the
        // source list all read it.
        $this->assertTrue(Storage::disk('local')->exists((string) $document->file_path));

        // And the ordinary run is queued on the ordinary queue. This is the step the old design
        // skipped, and the only thing that ever plans a concept, an entity or a summary.
        $run = EnterpriseWikiIngestRun::query()->sole();

        $this->assertSame(EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $run->source_type);
        $this->assertSame((int) $document->id, (int) $run->source_id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->status);

        Queue::assertPushed(
            RunEnterpriseWikiDocumentFlow::class,
            fn (RunEnterpriseWikiDocumentFlow $job): bool => $job->runId === (int) $run->id,
        );
    }

    /**
     * The user stays where they are, because there is nothing to open yet.
     *
     * The old flow redirected to the created page. There is no page now — the run builds them —
     * and sending somebody to a page that does not exist is worse than telling them what is
     * happening.
     */
    public function test_creating_an_article_keeps_the_user_on_the_flow_and_says_what_happens_next(): void
    {
        Queue::fake();

        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $this->actingAs($owner)
            ->from("/app/quality/items/{$process->id}?tab=flow")
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'vurder',
                'title' => 'Terskelverdier',
                'markdown' => 'Hva som gjelder.',
            ])
            ->assertRedirect("/app/quality/items/{$process->id}?tab=flow")
            ->assertSessionHas('success');
    }

    /**
     * The activity shows every page the run made of its source — not one.
     *
     * This is the whole point of the change. One source becomes an article, a summary, and the
     * concepts and entities the maintainer decision found in it, and all of them came out of this
     * step.
     */
    public function test_the_activity_shows_every_page_the_run_produced_from_its_source(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $document = $this->createArticleSource($owner, $process, 'Sikkerhetskrav');

        $article = $this->runProducedPage($customer, $document, 'Sikkerhetskrav', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $concept = $this->runProducedPage($customer, $document, 'Leverandørrisiko', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $node = $this->activityNode($owner, $process);

        $this->assertSame(['Sikkerhetskrav', 'Leverandørrisiko'], array_column($node['articles'], 'title'));
        $this->assertSame(['page', 'page'], array_column($node['articles'], 'kind'));
        $this->assertSame(
            [EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
            array_column($node['articles'], 'page_type'),
        );
        $this->assertStringEndsWith("/app/wiki/{$article->slug}", $node['articles'][0]['url']);
        $this->assertNotNull($node['articles'][0]['publication']);

        // Renamed in Wiki, which owns it. Nothing in Kvalitet is touched, and the activity shows
        // the new name — the whole reason nothing of the article is stored on the flow.
        $concept->update(['title' => 'Leverandørrisiko (konsept)']);

        $this->assertSame(
            ['Sikkerhetskrav', 'Leverandørrisiko (konsept)'],
            array_column($this->activityNode($owner, $process)['articles'], 'title'),
        );
    }

    /**
     * A page the run reused rather than created is not something this activity produced.
     *
     * Patching or regenerating a page the Wiki already had is the pipeline doing its job; claiming
     * the activity is its origin would be a provenance claim nobody made.
     */
    public function test_a_page_the_run_only_updated_is_not_claimed_by_the_activity(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $document = $this->createArticleSource($owner, $process, 'Sikkerhetskrav');

        $created = $this->runProducedPage($customer, $document, 'Sikkerhetskrav', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->runProducedPage(
            $customer,
            $document,
            'Innkjøpspolicy',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiIngestRunPage::ACTION_PATCHED,
        );

        $node = $this->activityNode($owner, $process);

        $this->assertSame([(int) $created->id], array_column($node['articles'], 'page_id'));
    }

    /**
     * While the run is working, the step says so rather than looking empty.
     *
     * An ingest takes minutes. A step that showed nothing for those minutes would read as "the
     * article was lost", which is the one thing it must not read as.
     */
    public function test_an_activity_whose_run_has_produced_nothing_yet_shows_the_source(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $this->createArticleSource($owner, $process, 'Terskelverdier');

        $entry = $this->activityNode($owner, $process)['articles'][0];

        $this->assertSame('source', $entry['kind']);
        $this->assertSame('Terskelverdier', $entry['title']);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $entry['status']);
        // Nothing to open: a link to a page that does not exist is worse than no link.
        $this->assertNull($entry['url']);
        $this->assertNull($entry['page_id']);
    }

    /**
     * The payload is rewritten wholesale on every save. An article's origin stored inside it would
     * be gone the first time somebody fixed a typo in a step's label.
     */
    public function test_rewriting_the_flow_does_not_lose_what_an_activity_produced(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $document = $this->createArticleSource($owner, $process, 'Sikkerhetskrav');
        $this->runProducedPage($customer, $document, 'Sikkerhetskrav', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $edited = $this->simpleFlow();
        $edited['nodes'][1]['label'] = 'Vurder anskaffelsen grundig';

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/blueprint", $edited)
            ->assertSessionHasNoErrors();

        $node = $this->activityNode($owner, $process);

        $this->assertSame('Vurder anskaffelsen grundig', $node['label']);
        $this->assertSame(['Sikkerhetskrav'], array_column($node['articles'], 'title'));
    }

    /**
     * Wiki is the source of truth, including about whether a page still exists. Deleting one
     * leaves the activity standing with one page fewer rather than a broken reference.
     */
    public function test_deleting_a_wiki_page_leaves_the_activity_standing(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $document = $this->createArticleSource($owner, $process, 'Anskaffelsesrutine');

        $kept = $this->runProducedPage($customer, $document, 'Anskaffelsesrutine', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $removed = $this->runProducedPage($customer, $document, 'Terskelverdier', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $removed->versions()->delete();
        $removed->delete();

        $node = $this->activityNode($owner, $process);

        $this->assertSame('Vurder saken', $node['label']);
        $this->assertSame(['Anskaffelsesrutine'], array_column($node['articles'], 'title'));
        $this->assertSame((int) $kept->id, (int) $node['articles'][0]['page_id']);

        // The provenance is unharmed: it is anchored to the source, which is still there.
        $this->assertSame(1, QualityActivityWikiPage::query()->where('quality_item_id', $process->id)->count());
    }

    /** Deleting the source deletes the record that an activity produced it. */
    public function test_deleting_the_source_takes_the_provenance_with_it(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $document = $this->createArticleSource($owner, $process, 'Terskelverdier');

        EnterpriseWikiIngestRun::query()->where('source_id', $document->id)->delete();
        $document->delete();

        $this->assertSame(0, QualityActivityWikiPage::query()->where('quality_item_id', $process->id)->count());
        $this->assertSame([], $this->activityNode($owner, $process)['articles']);
    }

    public function test_an_activity_that_has_produced_nothing_carries_an_empty_list(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props'];

        foreach ($props['blueprint']['nodes'] as $node) {
            $this->assertSame([], $node['articles']);
            // The retired direction: the flow holds no page references at all any more.
            $this->assertArrayNotHasKey('knowledge_page_ids', $node);
        }
    }

    /** A step that is not on the flow is not an activity, and nothing may be created from it. */
    public function test_an_unknown_activity_cannot_produce_an_article(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'finnes-ikke',
                'title' => 'Noe',
                'markdown' => 'Tekst.',
            ])
            ->assertNotFound();

        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_reader_cannot_create_an_article_from_an_activity(): void
    {
        ['customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $reader = $this->user($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($reader)
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'vurder',
                'title' => 'Noe',
                'markdown' => 'Tekst.',
            ])
            ->assertForbidden();

        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_an_article_cannot_be_created_on_another_customers_process(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $other] = $this->context();

        $process = $this->process($other, 'Andres innkjøp');
        $this->blueprintFor($other, $process, $this->simpleFlow());

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'vurder',
                'title' => 'Noe',
                'markdown' => 'Tekst.',
            ])
            ->assertNotFound();

        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $other->id)->count());
    }

    /**
     * Two activities may legitimately want the same name, and they get two sources.
     *
     * Identity is the text, not the title — the same answer the uploaded path gives a file. Two
     * articles called the same thing but saying different things are two sources.
     */
    public function test_two_articles_with_the_same_name_both_become_sources(): void
    {
        ['owner' => $owner, 'customer' => $customer] = $this->context();

        $process = $this->process($customer, 'Innkjøp');
        $this->blueprintFor($customer, $process, $this->simpleFlow());

        $first = $this->createArticleSource($owner, $process, 'Sikkerhetskrav', 'Første tekst.');
        $second = $this->createArticleSource($owner, $process, 'Sikkerhetskrav', 'Andre tekst.');

        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertSame(2, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, QualityActivityWikiPage::query()->where('quality_item_id', $process->id)->count());
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
     * The activity the articles hang off, as the page serves it.
     *
     * @return array<string, mixed>
     */
    private function activityNode(User $actor, QualityItem $process): array
    {
        return $this->actingAs($actor)
            ->get("/app/quality/items/{$process->id}?tab=flow")
            ->viewData('page')['props']['blueprint']['nodes'][1];
    }

    /**
     * One article created from the middle activity, the way a user creates one.
     *
     * Through the endpoint rather than the service, so every test that needs an article also
     * exercises the authorisation and the Wiki write that produce it. What comes back is the
     * SOURCE, because that is what an activity produces — the pages are the run's.
     */
    private function createArticleSource(
        User $actor,
        QualityItem $process,
        string $title,
        string $body = "Innledning.\n\n## Avsnitt\n\nInnhold.",
    ): EnterpriseWikiDocument {
        // The run is Wiki's, and these tests are about what Kvalitet hands it. Left real, the
        // job would run inline on the sync queue and make a chain of AI calls none of this file
        // is asking about.
        Queue::fake();

        $this->actingAs($actor)
            ->post("/app/quality/items/{$process->id}/activities/articles", [
                'activity_key' => 'vurder',
                'title' => $title,
                'markdown' => $body,
            ])
            ->assertSessionHasNoErrors();

        return EnterpriseWikiDocument::query()
            ->where('customer_id', $process->customer_id)
            ->where('original_filename', $title.'.md')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * A page the ingest run produced from that source, as the pipeline records it.
     *
     * Stands in for the run itself, which is a long chain of AI calls no unit of this file should
     * be making. What matters to Kvalitet is only the shape the run leaves behind: a page, and an
     * EnterpriseWikiIngestRunPage row saying this run created it.
     */
    private function runProducedPage(
        Customer $customer,
        EnterpriseWikiDocument $document,
        string $title,
        string $pageType,
        string $action = EnterpriseWikiIngestRunPage::ACTION_CREATED,
    ): EnterpriseWikiPage {
        $run = EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => (int) $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => (int) $run->id,
            'enterprise_wiki_page_id' => (int) $page->id,
            'action' => $action,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return $page;
    }

    /**
     * The same three steps, with the middle one standing for another process.
     *
     * @return array<string, mixed>
     */
    private function flowWithSubprocess(int $subprocessItemId): array
    {
        $flow = $this->simpleFlow();

        $flow['nodes'][1] = [
            'key' => 'vurder',
            'lane' => 'saksbehandler',
            'type' => 'step',
            'label' => 'Vurder leverandøren',
            'subprocess_quality_item_id' => $subprocessItemId,
        ];

        return $flow;
    }

    /** Store a flow the way a person would, through the one gate every write goes through. */
    private function blueprintFor(Customer $customer, QualityItem $item, array $payload): QualityProcessBlueprint
    {
        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $item,
            $payload,
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    /**
     * The node that carries the reference, as the page serves it.
     *
     * @return array<string, mixed>
     */
    private function subprocessNode(User $actor, QualityItem $parent): array
    {
        return $this->actingAs($actor)
            ->get("/app/quality/items/{$parent->id}?tab=flow")
            ->viewData('page')['props']['blueprint']['nodes'][1];
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
