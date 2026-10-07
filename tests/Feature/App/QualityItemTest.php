<?php

namespace Tests\Feature\App;

use App\Jobs\Quality\ProjectQualityItemToGraph;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityChecklistItem;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessIo;
use App\Models\QualityProcessStep;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Quality as its own domain.
 *
 * What these tests defend, in the order the rebuild set out to fix things:
 *
 *  - A styrende dokument exists on its own terms. It is created, owned and reviewed without any
 *    Wiki page being involved, and it is never a Wiki page wearing a type.
 *  - Wiki is reached into, not taken over: attaching a page changes nothing about the page, and the
 *    same page may back several documents or none.
 *  - A relation may only join a pair of types the matrix allows — including the case independent
 *    from/to lists got wrong, process -> work instruction.
 *  - Structure belongs to the type that has it.
 *  - Documents belong to the virksomhet, not to one item: attaching one copies no bytes, the same
 *    file serves several items, and detaching removes the connection alone.
 *  - Kvalitet is a paid module, and the gate is the backend's, not the rail's.
 *  - Nothing crosses a customer boundary.
 */
class QualityItemTest extends TestCase
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
    // Entitlement
    // ---------------------------------------------------------------------

    public function test_quality_is_refused_to_a_customer_without_the_module(): void
    {
        ['owner' => $owner] = $this->context(grantQuality: false);

        $this->actingAs($owner)
            ->get('/app/quality')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_the_write_actions_are_gated_by_the_same_entitlement_as_the_page(): void
    {
        ['owner' => $owner] = $this->context(grantQuality: false);

        // The guard is applied to the route group by name prefix, so a POST is refused exactly as
        // the GET is — a bookmarked form must not be a way past the commercial boundary.
        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_POLICY,
                'title' => 'Innkjopspolicy',
            ])
            ->assertRedirect(route('app.dashboard'));

        $this->assertSame(0, QualityItem::query()->count());
    }

    public function test_every_step_of_the_ladder_opens_quality(): void
    {
        foreach (['basis', 'governance', 'iso', 'grc'] as $package) {
            ['customer' => $customer, 'owner' => $owner] = $this->context(grantQuality: false);
            $this->grant($customer, $package);

            $this->actingAs($owner)->get('/app/quality')->assertOk();
        }
    }

    // ---------------------------------------------------------------------
    // The item itself
    // ---------------------------------------------------------------------

    public function test_a_quality_item_is_created_without_any_wiki_page(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_POLICY,
                'title' => 'Innkjopspolicy',
                'code' => 'POL-01',
                'purpose' => 'Setter rammene for alle anskaffelser.',
                'owner_user_id' => $owner->id,
                'status' => QualityItem::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $item = QualityItem::query()->where('customer_id', $customer->id)->sole();

        // The whole premise of the rebuild: a policy exists because the organisation has one, not
        // because somebody wrote a Wiki page about it.
        $this->assertSame(QualityItem::TYPE_POLICY, $item->quality_type);
        $this->assertSame('POL-01', $item->code);
        $this->assertSame($owner->id, $item->owner_user_id);
        $this->assertSame(0, QualityItemWikiLink::query()->where('quality_item_id', $item->id)->count());

        Queue::assertPushed(ProjectQualityItemToGraph::class);
    }

    public function test_a_document_number_identifies_one_document_per_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy', 'POL-01');

        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => 'Anskaffelsesprosess',
                'code' => 'pol-01',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, QualityItem::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_next_review_date_is_derived_rather_than_typed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $item = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", [
                'review_interval_months' => 12,
                'last_reviewed_at' => '2026-01-15',
            ])
            ->assertRedirect();

        $item->refresh();

        // A date somebody has to remember to move is a date that is wrong.
        $this->assertSame('2027-01-15', $item->next_review_at?->toDateString());
        $this->assertSame($owner->id, $item->last_reviewed_by_user_id);
    }

    public function test_an_items_type_cannot_be_changed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $item = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", [
                'title' => 'Anskaffelsesprosess',
                'quality_type' => QualityItem::TYPE_CHECKLIST,
            ]);

        // Retyping could only mean "delete the structure and keep the title", which the caller can
        // do explicitly and should have to.
        $this->assertSame(QualityItem::TYPE_PROCESS, $item->refresh()->quality_type);
    }

    public function test_an_owner_must_belong_to_the_same_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['owner' => $foreignOwner] = $this->context();

        $item = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->patch("/app/quality/items/{$item->id}", ['owner_user_id' => $foreignOwner->id])
            ->assertSessionHasErrors('owner_user_id');

        $this->assertNull($item->refresh()->owner_user_id);
    }

    public function test_another_customers_item_is_not_found(): void
    {
        ['owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $foreignItem = $this->item($otherCustomer, QualityItem::TYPE_POLICY, 'Fremmed policy');

        $this->actingAs($owner)->get("/app/quality/items/{$foreignItem->id}")->assertNotFound();
        $this->actingAs($owner)->patch("/app/quality/items/{$foreignItem->id}", ['title' => 'Kapret'])->assertNotFound();
        $this->actingAs($owner)->delete("/app/quality/items/{$foreignItem->id}")->assertNotFound();

        $this->assertSame('Fremmed policy', $foreignItem->refresh()->title);
    }

    // ---------------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------------

    public function test_a_process_owns_its_steps_inputs_and_outputs(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$process->id}/structure", [
                'steps' => [
                    ['title' => 'Behovsvurdering', 'responsibility' => 'Innkjopsansvarlig'],
                    ['title' => 'Markedsdialog', 'description' => 'Kartlegg tilbydere.'],
                    // Blank rows are dropped rather than stored empty, which is what makes
                    // "remove a step" a client-side splice.
                    ['title' => '   '],
                ],
                'inputs' => [['label' => 'Godkjent budsjett']],
                'outputs' => [['label' => 'Signert kontrakt']],
            ])
            ->assertRedirect();

        $steps = QualityProcessStep::query()->where('quality_item_id', $process->id)->orderBy('position')->get();

        $this->assertCount(2, $steps);
        $this->assertSame([1, 2], $steps->pluck('position')->map(fn ($p): int => (int) $p)->all());
        $this->assertSame('Innkjopsansvarlig', $steps[0]->responsibility);

        $this->assertSame(
            ['Godkjent budsjett'],
            QualityProcessIo::query()
                ->where('quality_item_id', $process->id)
                ->where('direction', QualityProcessIo::DIRECTION_INPUT)
                ->pluck('label')
                ->all(),
        );
        $this->assertSame(
            ['Signert kontrakt'],
            QualityProcessIo::query()
                ->where('quality_item_id', $process->id)
                ->where('direction', QualityProcessIo::DIRECTION_OUTPUT)
                ->pluck('label')
                ->all(),
        );
    }

    public function test_structure_is_replaced_as_a_set_so_positions_stay_contiguous(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/structure", [
            'steps' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']],
        ]);

        // The middle step is gone and the order has changed — exactly what a reorder looks like
        // from the client, and what row-by-row updates could not express without violating the
        // unique index on (item, position) halfway through.
        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/structure", [
            'steps' => [['title' => 'C'], ['title' => 'A']],
        ]);

        $steps = QualityProcessStep::query()->where('quality_item_id', $process->id)->orderBy('position')->get();

        $this->assertSame(['C', 'A'], $steps->pluck('title')->all());
        $this->assertSame([1, 2], $steps->pluck('position')->map(fn ($p): int => (int) $p)->all());
    }

    public function test_a_checklist_owns_its_items(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $checklist = $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste tilbud');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$checklist->id}/structure", [
                'checklist_items' => [
                    ['text' => 'Signatur er innhentet', 'is_required' => true],
                    ['text' => 'Referanser er vurdert', 'is_required' => false],
                ],
            ])
            ->assertRedirect();

        $items = QualityChecklistItem::query()->where('quality_item_id', $checklist->id)->orderBy('position')->get();

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->is_required);
        $this->assertFalse($items[1]->is_required);
    }

    public function test_a_control_gets_its_detail_row_from_the_moment_it_exists(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)->post('/app/quality/items', [
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => 'Stikkprove innkjop',
        ]);

        $control = QualityItem::query()->where('customer_id', $customer->id)->sole();

        // Present but empty, so a later edit updates a row rather than discovering one is missing.
        $this->assertNotNull(QualityControlDetail::query()->where('quality_item_id', $control->id)->first());

        $this->actingAs($owner)
            ->put("/app/quality/items/{$control->id}/structure", [
                'control' => [
                    'criterion' => 'Alle kjop over 100 000 har dokumentert konkurranse.',
                    'responsibility' => 'Controller',
                    'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
                ],
            ])
            ->assertRedirect();

        $detail = QualityControlDetail::query()->where('quality_item_id', $control->id)->sole();

        $this->assertSame('Controller', $detail->responsibility);
        $this->assertSame(QualityControlDetail::FREQUENCY_QUARTERLY, $detail->frequency);
    }

    public function test_structure_belongs_to_the_type_that_has_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        $this->actingAs($owner)
            ->put("/app/quality/items/{$policy->id}/structure", [
                'steps' => [['title' => 'Et steg']],
            ])
            ->assertSessionHasErrors('steps');

        $this->assertSame(0, QualityProcessStep::query()->where('quality_item_id', $policy->id)->count());
    }

    // ---------------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------------

    public function test_a_legal_relation_is_stored_once_in_the_direction_it_is_true_in(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $policy->id,
                'to_item_id' => $process->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertRedirect();

        // One row, not a mirrored pair: "a policy governs a process" is not true in reverse.
        $this->assertSame(1, QualityItemRelation::query()->where('customer_id', $customer->id)->count());

        $relation = QualityItemRelation::query()->sole();
        $this->assertSame($policy->id, (int) $relation->from_item_id);
        $this->assertSame($process->id, (int) $relation->to_item_id);
    }

    public function test_a_relation_the_matrix_forbids_is_refused(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $instruction = $this->item($customer, QualityItem::TYPE_WORK_INSTRUCTION, 'Signering i ERP');

        // The case ordered pairs exist for: `uses` is legal procedure -> work instruction and
        // process -> checklist, but a process reaches an instruction through its procedure.
        // Independent from/to lists would have allowed this.
        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $process->id,
                'to_item_id' => $instruction->id,
                'relation_type' => QualityItemRelation::TYPE_USES,
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertSame(0, QualityItemRelation::query()->count());
    }

    public function test_a_relation_cannot_reach_another_customers_item(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $foreignProcess = $this->item($otherCustomer, QualityItem::TYPE_PROCESS, 'Fremmed prosess');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $policy->id,
                'to_item_id' => $foreignProcess->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityItemRelation::query()->count());
    }

    public function test_a_process_shows_its_governing_documents_and_the_policy_shows_the_process(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $linked = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy', 'POL-01');
        $unlinked = $this->item($customer, QualityItem::TYPE_POLICY, 'Informasjonssikkerhetspolicy');
        $this->item($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste');
        $this->item($otherCustomer, QualityItem::TYPE_POLICY, 'Fremmed policy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->post('/app/quality/relations', [
                'from_item_id' => $linked->id,
                'to_item_id' => $process->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertRedirect();

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['governing_documents']);
        $this->assertSame((int) $linked->id, $props['governing_documents'][0]['other_item_id']);
        $this->assertSame('POL-01', $props['governing_documents'][0]['other_code']);

        // The picker offers this customer's policies that are not yet linked — never a checklist,
        // never another customer's policy, never the one already governing the process.
        $this->assertSame([(int) $unlinked->id], array_column($props['governing_document_options'], 'id'));

        // The other end reads the same row: no mirrored relation, no copied content.
        $policyProps = $this->actingAs($owner)
            ->get("/app/quality/items/{$linked->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $policyProps['governing_documents']);
        $this->assertCount(1, $policyProps['governed_processes']);
        $this->assertSame((int) $process->id, $policyProps['governed_processes'][0]['other_item_id']);
        $this->assertSame([], $props['governed_processes']);

        // Neither page ships the generic relation list: "Fra", "Til" and a relation type are the
        // model's words, not the user's.
        $this->assertArrayNotHasKey('relations', $props);
        $this->assertArrayNotHasKey('relations', $policyProps);
        $this->assertArrayNotHasKey('relation_types', $this->app['translator']->get('procynia.quality'));
        $this->assertSame(1, QualityItemRelation::query()->where('customer_id', $customer->id)->count());
    }

    public function test_removing_a_governing_document_removes_only_the_link(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $relation = QualityItemRelation::query()->create([
            'customer_id' => $customer->id,
            'from_item_id' => $policy->id,
            'to_item_id' => $process->id,
            'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            'source' => QualityItemRelation::SOURCE_MANUAL,
        ]);

        $this->actingAs($owner)->delete("/app/quality/relations/{$relation->id}")->assertRedirect();

        $this->assertSame(0, QualityItemRelation::query()->count());
        $this->assertNotNull($policy->fresh());
        $this->assertNotNull($process->fresh());
    }

    public function test_another_customers_governing_link_cannot_be_removed(): void
    {
        ['customer' => $customer] = $this->context();
        ['owner' => $otherOwner] = $this->context();

        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $relation = QualityItemRelation::query()->create([
            'customer_id' => $customer->id,
            'from_item_id' => $policy->id,
            'to_item_id' => $process->id,
            'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            'source' => QualityItemRelation::SOURCE_MANUAL,
        ]);

        $this->actingAs($otherOwner)->delete("/app/quality/relations/{$relation->id}");

        $this->assertNotNull($relation->fresh());
    }

    // ---------------------------------------------------------------------
    // The seam to Wiki
    // ---------------------------------------------------------------------

    public function test_attaching_a_wiki_page_changes_nothing_about_the_page(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        // The raw row, not the model's attribute array: a freshly-created model and a reloaded one
        // differ in key order and in which columns were hydrated, neither of which is a change to
        // the page.
        $before = (array) DB::table('enterprise_wiki_pages')->where('id', $page->id)->sole();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/wiki-links", [
                'enterprise_wiki_page_id' => $page->id,
                'link_type' => QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
            ])
            ->assertRedirect();

        $this->assertSame(1, QualityItemWikiLink::query()->where('quality_item_id', $process->id)->count());

        // The retired model wrote a type onto the page. Nothing may now.
        $this->assertSame($before, (array) DB::table('enterprise_wiki_pages')->where('id', $page->id)->sole());
    }

    public function test_one_wiki_page_may_back_several_quality_items(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        foreach ([$policy, $process] as $item) {
            $this->actingAs($owner)
                ->post("/app/quality/items/{$item->id}/wiki-links", [
                    'enterprise_wiki_page_id' => $page->id,
                ])
                ->assertRedirect();
        }

        // Impossible under the retired model, where the link was a unique column on the page.
        $this->assertSame(2, QualityItemWikiLink::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    public function test_a_wiki_page_from_another_customer_cannot_be_attached(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $foreignPage = $this->page($otherCustomer, 'Fremmed side');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/wiki-links", [
                'enterprise_wiki_page_id' => $foreignPage->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityItemWikiLink::query()->count());
    }

    public function test_deleting_an_item_leaves_the_wiki_page_standing(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/wiki-links", [
            'enterprise_wiki_page_id' => $page->id,
        ]);

        $this->actingAs($owner)->delete("/app/quality/items/{$process->id}")->assertRedirect();

        $this->assertNull(QualityItem::query()->find($process->id));
        $this->assertSame(0, QualityItemWikiLink::query()->where('quality_item_id', $process->id)->count());
        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
    }

    /**
     * Deleting a process takes the process away and leaves the knowledge standing.
     *
     * The two halves are deliberately asserted together. Everything a process owns — the flow, the
     * activities on it, the record of which activity was the source of what — is the process's own
     * and goes with it. The article that came out of an activity, and the source document behind
     * it, belong to the virksomhet: they were knowledge before the process was deleted and they are
     * knowledge after. A delete that swept them up would mean a kvalitetsleder could not tidy a
     * flow without losing what the flow produced.
     */
    public function test_deleting_a_process_removes_its_flow_and_keeps_the_knowledge_it_produced(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshandtering');
        $page = $this->page($customer, 'Avviksrutine');
        $document = $this->document($customer, 'avviksrutine.pdf');

        $this->actingAs($owner)->put("/app/quality/items/{$process->id}/blueprint", [
            'lanes' => [
                ['key' => 'saksbehandler', 'label' => 'Saksbehandler'],
            ],
            'nodes' => [
                ['key' => 'start', 'lane' => 'saksbehandler', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'vurder', 'lane' => 'saksbehandler', 'type' => 'step', 'label' => 'Vurder avviket'],
                ['key' => 'ferdig', 'lane' => 'saksbehandler', 'type' => 'end', 'label' => 'Ferdig'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'vurder', 'label' => null],
                ['from' => 'vurder', 'to' => 'ferdig', 'label' => null],
            ],
        ])->assertRedirect();

        $this->assertNotNull(QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->first());

        // What the activity "Vurder avviket" was the source of, in both shapes the table holds: the
        // source document it hands over today, and the page a row written before that direction
        // changed still names. One target per row — the check constraint says so — so they are two
        // rows. Written directly rather than through the article endpoint: this is about what a
        // delete does to the row, not about getting an AI draft back.
        QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => 'vurder',
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $owner->id,
        ]);

        QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => 'vurder',
            'enterprise_wiki_page_id' => $page->id,
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->delete("/app/quality/items/{$process->id}")
            ->assertRedirect('/app/quality');

        $this->assertNull(QualityItem::query()->find($process->id));
        $this->assertSame(0, QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->count());
        $this->assertSame(0, QualityActivityWikiPage::query()->where('quality_item_id', $process->id)->count());

        // The knowledge the process produced is untouched.
        $this->assertNotNull(EnterpriseWikiPage::query()->find($page->id));
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));

        // And the graph is told, carrying the customer the deleted row can no longer be asked for.
        // Neo4jGraphProjectionService::deleteQualityItem detaches the activity nodes with the
        // process; nothing on the Wiki side is touched.
        Queue::assertPushed(
            ProjectQualityItemToGraph::class,
            fn (ProjectQualityItemToGraph $job): bool => $job->itemId === (int) $process->id
                && $job->deletedForCustomerId === (int) $customer->id,
        );
    }

    /**
     * The second half of the same decision: the knowledge may go with the process, when asked.
     *
     * Asked, never assumed — the request has to carry delete_wiki_pages, and the test above proves
     * that without it nothing in Wiki moves. What this one pins is the boundary of "produced":
     * only a page the run CREATED from this process's own source document, which is
     * QualityActivityKnowledgeResolver's rule rather than one invented for deletion. A page the
     * same run merely updated was already the Wiki's before this process touched it, and a page
     * from another process is not this one's at all. Both stay, and so does the source document.
     */
    public function test_deleting_a_process_can_take_the_wiki_pages_it_produced_with_it(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshandtering');
        $document = $this->document($customer, 'avviksrutine.pdf');

        $produced = $this->page($customer, 'Avviksrutine');
        $updatedByTheSameRun = $this->page($customer, 'Hendelsesbegrepet');
        $someoneElses = $this->page($customer, 'Leverandorrutine');

        // The run the Wiki made of this process's source. One page it created, one it only
        // patched — the second is exactly the page a naive "same run" rule would sweep up.
        $run = $this->ingestRun($customer, $document);
        $this->runPage($run, $produced, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->runPage($run, $updatedByTheSameRun, EnterpriseWikiIngestRunPage::ACTION_UPDATED);

        QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => 'vurder',
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->delete("/app/quality/items/{$process->id}?delete_wiki_pages=1")
            ->assertRedirect('/app/quality')
            ->assertSessionHas('success');

        $this->assertNull(QualityItem::query()->find($process->id));
        $this->assertNull(EnterpriseWikiPage::query()->find($produced->id), 'the page this process produced goes with it');

        // Everything the process did not produce stays, including the file it was written into.
        $this->assertNotNull(EnterpriseWikiPage::query()->find($updatedByTheSameRun->id), 'a page the run only updated was never this process\'s to delete');
        $this->assertNotNull(EnterpriseWikiPage::query()->find($someoneElses->id));
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id), 'the source document is the virksomhet\'s');
    }

    /**
     * Deleting a Wiki page is Wiki's authority, and quality.delete does not carry it.
     *
     * A user whose customer role grants quality.delete may delete a process — but may not delete a
     * Wiki page they do not own. Rather than quietly deleting the process and keeping the pages
     * they asked to be rid of, the request is refused whole: nothing is deleted, and the message
     * says to choose "keep" instead. This is the invariant that a permission never stands in for
     * object security.
     */
    public function test_a_user_who_may_manage_quality_but_not_delete_wiki_pages_is_refused(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $qa = User::query()->create([
            'name' => 'Kvalitetsleder',
            'email' => 'qa-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_qa' => true,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Kvalitetsleder',
            'is_active' => true,
        ]);
        $role->syncPermissions([
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_DELETE,
        ]);
        $qa->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshandtering');
        $document = $this->document($customer, 'avviksrutine.pdf');
        $produced = $this->page($customer, 'Avviksrutine');

        $run = $this->ingestRun($customer, $document);
        $this->runPage($run, $produced, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        QualityActivityWikiPage::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => 'vurder',
            'enterprise_wiki_document_id' => $document->id,
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($qa)
            ->delete("/app/quality/items/{$process->id}?delete_wiki_pages=1")
            ->assertRedirect('/app/quality')
            ->assertSessionHas('error');

        // Nothing moved: refusing is all-or-nothing.
        $this->assertNotNull(QualityItem::query()->find($process->id));
        $this->assertNotNull(EnterpriseWikiPage::query()->find($produced->id));

        // The same user may still delete the process while keeping the knowledge.
        $this->actingAs($qa)
            ->delete("/app/quality/items/{$process->id}")
            ->assertRedirect('/app/quality')
            ->assertSessionHas('success');

        $this->assertNull(QualityItem::query()->find($process->id));
        $this->assertNotNull(EnterpriseWikiPage::query()->find($produced->id));
    }

    /**
     * Deleting is reachable from the Prosesser list as well as from the process's own page, so the
     * redirect keeps the tab the request came from. A user clearing out two processes should not
     * have to find their way back to Prosesser between them.
     */
    public function test_deleting_from_a_tab_comes_back_to_that_tab(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Leverandorkontroll');

        $this->actingAs($owner)
            ->delete("/app/quality/items/{$process->id}?tab=processes")
            ->assertRedirect('/app/quality?tab=processes');

        $this->assertNull(QualityItem::query()->find($process->id));
    }

    /**
     * A tab the index does not have is dropped rather than refused. The rule lives in one place —
     * QualityController::TABS — and a hand-edited URL must not be able to put a value the index
     * would ignore into the address the user lands on.
     */
    public function test_an_unknown_tab_falls_back_to_the_quality_index(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Leverandorkontroll');

        $this->actingAs($owner)
            ->delete("/app/quality/items/{$process->id}?tab=finnes-ikke")
            ->assertRedirect('/app/quality');
    }

    // ---------------------------------------------------------------------
    // The seam to the document store
    // ---------------------------------------------------------------------

    public function test_an_existing_document_is_attached_without_being_changed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $document = $this->document($customer, 'innkjopspolicy.pdf');

        $before = (array) DB::table('enterprise_wiki_documents')->where('id', $document->id)->sole();

        $this->actingAs($owner)
            ->post("/app/quality/items/{$policy->id}/document-links", [
                'enterprise_wiki_document_id' => $document->id,
                'relation_type' => QualityItemDocument::RELATION_TYPE_SOURCE,
                'note' => 'Signert versjon',
            ])
            ->assertRedirect();

        $link = QualityItemDocument::query()->where('quality_item_id', $policy->id)->sole();

        $this->assertSame((int) $document->id, (int) $link->enterprise_wiki_document_id);
        $this->assertSame(QualityItemDocument::RELATION_TYPE_SOURCE, $link->relation_type);
        $this->assertSame('Signert versjon', $link->note);
        $this->assertSame((int) $customer->id, (int) $link->customer_id);

        // Attaching reaches the file; it never writes to it.
        $this->assertSame($before, (array) DB::table('enterprise_wiki_documents')->where('id', $document->id)->sole());
    }

    public function test_one_document_may_belong_to_several_quality_items(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'kvalitetshandbok.pdf');

        foreach ([$policy, $process] as $item) {
            $this->actingAs($owner)
                ->post("/app/quality/items/{$item->id}/document-links", [
                    'enterprise_wiki_document_id' => $document->id,
                ])
                ->assertRedirect();
        }

        $this->assertSame(
            2,
            QualityItemDocument::query()->where('enterprise_wiki_document_id', $document->id)->count(),
        );
        // One row on disk, two connections to it.
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_same_document_may_serve_one_item_in_two_capacities_but_not_twice_in_one(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'skjema.docx');

        foreach ([
            QualityItemDocument::RELATION_TYPE_TEMPLATE,
            QualityItemDocument::RELATION_TYPE_EVIDENCE,
            // The repeat is the point: firstOrCreate must not raise a unique violation.
            QualityItemDocument::RELATION_TYPE_TEMPLATE,
        ] as $relationType) {
            $this->actingAs($owner)
                ->post("/app/quality/items/{$process->id}/document-links", [
                    'enterprise_wiki_document_id' => $document->id,
                    'relation_type' => $relationType,
                ])
                ->assertRedirect();
        }

        $this->assertSame(2, QualityItemDocument::query()->where('quality_item_id', $process->id)->count());
    }

    public function test_an_unknown_relation_type_is_refused(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'rutine.pdf');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/document-links", [
                'enterprise_wiki_document_id' => $document->id,
                'relation_type' => 'something_else',
            ])
            ->assertSessionHasErrors('relation_type');

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_a_document_from_another_customer_cannot_be_attached(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $foreignDocument = $this->document($otherCustomer, 'fremmed.pdf');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/document-links", [
                'enterprise_wiki_document_id' => $foreignDocument->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_detaching_a_document_removes_the_connection_and_keeps_the_file(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'kvalitetshandbok.pdf');

        foreach ([$policy, $process] as $item) {
            $this->actingAs($owner)->post("/app/quality/items/{$item->id}/document-links", [
                'enterprise_wiki_document_id' => $document->id,
            ]);
        }

        $link = QualityItemDocument::query()->where('quality_item_id', $policy->id)->sole();

        $this->actingAs($owner)
            ->delete("/app/quality/document-links/{$link->id}")
            ->assertRedirect();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $policy->id)->count());
        // The file survives, and so does every other item's claim on it.
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
        $this->assertSame(1, QualityItemDocument::query()->where('quality_item_id', $process->id)->count());
    }

    public function test_another_customers_document_link_cannot_be_removed(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer, 'owner' => $otherOwner] = $this->context();

        $foreignItem = $this->item($otherCustomer, QualityItem::TYPE_POLICY, 'Fremmed policy');
        $foreignDocument = $this->document($otherCustomer, 'fremmed.pdf');

        $this->actingAs($otherOwner)->post("/app/quality/items/{$foreignItem->id}/document-links", [
            'enterprise_wiki_document_id' => $foreignDocument->id,
        ]);

        $link = QualityItemDocument::query()->where('quality_item_id', $foreignItem->id)->sole();

        $this->actingAs($owner)
            ->delete("/app/quality/document-links/{$link->id}")
            ->assertNotFound();

        $this->assertNotNull(QualityItemDocument::query()->find($link->id));
    }

    public function test_deleting_an_item_leaves_the_document_standing(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'rutine.pdf');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/document-links", [
            'enterprise_wiki_document_id' => $document->id,
        ]);

        $this->actingAs($owner)->delete("/app/quality/items/{$process->id}")->assertRedirect();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $process->id)->count());
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
    }

    public function test_deleting_the_file_in_wiki_takes_its_quality_links_with_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'rutine.pdf');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/document-links", [
            'enterprise_wiki_document_id' => $document->id,
        ]);

        // Not through the Wiki deletion flow — this asserts the FK itself, so a link row can never
        // outlive the file it points at and show a quality item a document that is gone.
        DB::table('enterprise_wiki_documents')->where('id', $document->id)->delete();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $process->id)->count());
        $this->assertNotNull(QualityItem::query()->find($process->id));
    }

    /**
     * The create form carries the document itself.
     *
     * One flow, one submit: the styrende dokument and the file that is it come into being together,
     * because that is how a user thinks of "opprett dokumentet". What is defended here is that the
     * file goes into the existing shared store rather than anywhere new, that it arrives as the
     * item's `source`, and that the item page can then hand it back — the whole point of attaching
     * it being that somebody can open it later.
     */
    public function test_creating_an_item_with_a_file_attaches_it_in_the_same_flow(): void
    {
        Queue::fake();
        Storage::fake('local');

        ['customer' => $customer, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_PROCEDURE,
                'title' => 'Avvikshandtering',
                'code' => 'PRO-07',
                'purpose' => 'Hvordan avvik meldes og lukkes.',
                'owner_user_id' => $owner->id,
                'status' => QualityItem::STATUS_ACTIVE,
                'file' => UploadedFile::fake()->createWithContent('avvikshandtering.pdf', 'Prosedyre for avvikshandtering.'),
            ])
            ->assertRedirect();

        $item = QualityItem::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('Avvikshandtering', $item->title);

        // The file went into the shared store on its own path, not a quality-specific one.
        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('avvikshandtering.pdf', $document->original_filename);
        $this->assertStringStartsWith("customers/{$customer->id}/wiki-documents/", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        // And it hangs on the item as its source, in the same flow that created the item.
        $link = QualityItemDocument::query()->where('quality_item_id', $item->id)->sole();
        $this->assertSame((int) $document->id, (int) $link->enterprise_wiki_document_id);
        $this->assertSame(QualityItemDocument::RELATION_TYPE_SOURCE, $link->relation_type);

        // The item page shows it, with a way to open it.
        $this->actingAs($owner)
            ->get("/app/quality/items/{$item->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.filename', 'avvikshandtering.pdf')
                ->where('documents.0.download_url', route('app.wiki.sources.download', ['document' => $document->id]))
            );
    }

    public function test_an_item_is_created_without_a_file(): void
    {
        Queue::fake();
        Storage::fake('local');

        ['customer' => $customer, 'owner' => $owner] = $this->context();

        // The file is optional on purpose: a styrende dokument is registered the moment the
        // organisation decides it has one, routinely before anybody has written it.
        $this->actingAs($owner)
            ->post('/app/quality/items', [
                'quality_type' => QualityItem::TYPE_POLICY,
                'title' => 'Innkjopspolicy',
            ])
            ->assertRedirect();

        $item = QualityItem::query()->where('customer_id', $customer->id)->sole();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $item->id)->count());
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
    }

    public function test_uploading_a_file_stores_it_once_and_attaches_it(): void
    {
        Queue::fake();
        Storage::fake('local');

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('rutine.pdf', 'Rutinebeskrivelse for anskaffelser.'),
                'relation_type' => QualityItemDocument::RELATION_TYPE_SOURCE,
            ])
            ->assertRedirect();

        $document = EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole();

        $this->assertSame('rutine.pdf', $document->original_filename);
        $this->assertSame((int) $owner->id, (int) $document->uploaded_by_user_id);
        // The shared store's own path, not a quality-specific one.
        $this->assertStringStartsWith("customers/{$customer->id}/wiki-documents/", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        $link = QualityItemDocument::query()->where('quality_item_id', $process->id)->sole();
        $this->assertSame((int) $document->id, (int) $link->enterprise_wiki_document_id);
    }

    public function test_re_uploading_the_same_file_attaches_the_copy_the_customer_already_has(): void
    {
        Queue::fake();
        Storage::fake('local');

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        foreach ([$policy, $process] as $item) {
            $this->actingAs($owner)
                ->post("/app/quality/items/{$item->id}/documents", [
                    // Same bytes, uploaded twice — which is what actually happens when two people
                    // attach the quality manual to their own document.
                    'file' => UploadedFile::fake()->createWithContent('handbok.pdf', 'Kvalitetshandbok, revisjon 4.'),
                ])
                ->assertRedirect();
        }

        // One file on disk, one row, two connections.
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, QualityItemDocument::query()->count());
    }

    public function test_attaching_a_document_is_gated_by_the_same_entitlement_as_the_page(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context(grantQuality: false);
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $document = $this->document($customer, 'rutine.pdf');

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/document-links", [
                'enterprise_wiki_document_id' => $document->id,
            ])
            ->assertRedirect(route('app.dashboard'));

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_the_detail_page_shows_documents_and_wiki_knowledge_as_two_separate_things(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->page($customer, 'Anskaffelsesrutine');
        $document = $this->document($customer, 'rutine.pdf');

        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/wiki-links", [
            'enterprise_wiki_page_id' => $page->id,
        ]);
        $this->actingAs($owner)->post("/app/quality/items/{$process->id}/document-links", [
            'enterprise_wiki_document_id' => $document->id,
        ]);

        $props = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['documents']);
        $this->assertSame('rutine.pdf', $props['documents'][0]['filename']);
        $this->assertNotNull($props['documents'][0]['download_url']);

        // Two seams, two props. A Wiki page never appears as a document, or the other way round.
        $this->assertCount(1, $props['wiki_links']);
        $this->assertSame((int) $page->id, $props['wiki_links'][0]['page_id']);
        $this->assertSame(QualityItemDocument::GENERAL_RELATION_TYPES, $props['document_relation_types']);
    }

    public function test_the_document_picker_is_scoped_to_the_customer_and_searchable(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $otherCustomer] = $this->context();

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $this->document($customer, 'anskaffelsesrutine.pdf');
        $this->document($customer, 'personalhandbok.pdf');
        $this->document($otherCustomer, 'anskaffelsesrutine-hos-andre.pdf');

        $all = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}")
            ->viewData('page')['props']['document_options'];

        $this->assertSame(
            ['anskaffelsesrutine.pdf', 'personalhandbok.pdf'],
            collect($all)->pluck('filename')->sort()->values()->all(),
        );

        $found = $this->actingAs($owner)
            ->get("/app/quality/items/{$process->id}?document_search=PERSONAL")
            ->viewData('page')['props']['document_options'];

        $this->assertSame(['personalhandbok.pdf'], collect($found)->pluck('filename')->all());
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

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
            'name' => 'Kvalitet Test AS',
            'slug' => 'kvalitet-test-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'owner-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        if ($grantQuality) {
            $this->grant($customer, 'basis');
        }

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function grant(Customer $customer, string $package): void
    {
        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => $package],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );
    }

    private function item(Customer $customer, string $type, string $title, ?string $code = null): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'code' => $code,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function page(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function ingestRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
    }

    private function runPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $action): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
    }

    /**
     * A file already in the virksomhet's store. Deliberately not written through the upload
     * endpoint: most of these tests are about the link, not about getting bytes onto a disk.
     */
    private function document(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'extracted_text' => 'Innhold i '.$filename,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
