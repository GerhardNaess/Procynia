<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityActivityControl;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Controls on the activities of a process.
 *
 * A control added on an activity is an ordinary `control` quality item, criterion included, placed
 * on the activity by its key. The flow payload and the revisions are never written; quality.edit
 * adds and removes, quality.view reads; removing leaves the control in the register.
 */
class QualityActivityControlTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_editor_adds_a_control_to_an_activity_without_touching_the_flow(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $blueprint = $this->blueprintFor($customer, $process);
        $payloadBefore = $blueprint->fresh()->payload;

        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", [
                'activity_key' => 'vurder',
                'title' => 'Fire øyne på alvorlighetsgrad',
                'criterion' => 'En annen enn saksbehandler bekrefter alvorlighetsgraden.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $link = QualityActivityControl::query()->where('quality_item_id', $process->id)->sole();
        $this->assertSame('vurder', $link->activity_key);
        $this->assertSame((int) $customer->id, (int) $link->customer_id);

        // The control is a register item of its own, with the description as its criterion.
        $control = $link->control()->with('controlDetail')->first();
        $this->assertSame(QualityItem::TYPE_CONTROL, $control->quality_type);
        $this->assertSame('Fire øyne på alvorlighetsgrad', $control->title);
        $this->assertSame('En annen enn saksbehandler bekrefter alvorlighetsgraden.', $control->controlDetail->criterion);

        // The flow and its revisions are untouched.
        $this->assertEquals($payloadBefore, $blueprint->fresh()->payload);
        $this->assertSame(0, QualityProcessRevision::query()->where('quality_item_id', $process->id)->count());

        // The page shows it on the activity it was placed on, and only there.
        $nodes = collect($this->actingAs($editor)->get("/app/quality/items/{$process->id}?tab=flow")
            ->assertOk()->viewData('page')['props']['blueprint']['nodes'])->keyBy('key');

        $this->assertSame(['Fire øyne på alvorlighetsgrad'], array_column($nodes['vurder']['controls'], 'title'));
        $this->assertSame((int) $control->id, $nodes['vurder']['controls'][0]['control_item_id']);
        $this->assertSame([], $nodes['start']['controls']);
    }

    public function test_quality_view_reads_controls_but_cannot_add_or_remove_them(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $nodes = collect($this->actingAs($reader)->get("/app/quality/items/{$process->id}?tab=flow")
            ->assertOk()->viewData('page')['props']['blueprint']['nodes'])->keyBy('key');
        $this->assertSame(['Stikkprøve'], array_column($nodes['vurder']['controls'], 'title'));

        $this->actingAs($reader)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Ny'])
            ->assertForbidden();
        $this->actingAs($reader)->delete("/app/quality/activity-controls/{$link->id}")->assertForbidden();

        $this->assertSame(1, QualityActivityControl::query()->where('quality_item_id', $process->id)->count());
    }

    public function test_removing_takes_the_control_off_the_activity_and_keeps_it_in_the_register(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $this->actingAs($editor)->delete("/app/quality/activity-controls/{$link->id}")->assertRedirect();

        $this->assertNull($link->fresh());
        $this->assertNotNull(QualityItem::query()->find($link->control_item_id));
    }

    public function test_a_control_needs_a_name_and_a_saved_activity_on_a_process(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);

        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => ''])
            ->assertSessionHasErrors('title');

        // A key the saved flow does not have — an activity that exists only in the editor.
        $this->actingAs($editor)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'ukjent', 'title' => 'Kontroll'])
            ->assertNotFound();

        $policy = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_POLICY,
            'title' => 'Policy',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        $this->actingAs($editor)
            ->post("/app/quality/items/{$policy->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Kontroll'])
            ->assertSessionHasErrors('quality_type');

        $this->assertSame(0, QualityActivityControl::query()->count());
        $this->assertSame(0, QualityItem::query()->where('customer_id', $customer->id)->where('quality_type', QualityItem::TYPE_CONTROL)->count());
    }

    public function test_controls_do_not_cross_the_tenant_boundary(): void
    {
        $customer = $this->customer();
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Stikkprøve');

        $other = $this->customer();
        $outsider = $this->member($other, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($outsider)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => 'vurder', 'title' => 'Inntrenger'])
            ->assertNotFound();
        $this->actingAs($outsider)->delete("/app/quality/activity-controls/{$link->id}")->assertNotFound();

        $this->assertNotNull($link->fresh());
    }

    public function test_the_controls_tab_is_a_register_of_every_control_and_where_it_is_applied(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $blueprint = $this->blueprintFor($customer, $process);
        $placed = $this->placeControl($customer, $process, 'vurder', 'Fire øyne');
        $unplaced = $this->placeControl($customer, $process, 'vurder', 'Tatt av igjen');
        $unplaced->delete();
        $orphaned = $this->placeControl($customer, $process, 'start', 'På en aktivitet som forsvinner');

        // The activity the third control sat on is removed from the flow; the link row remains.
        $payload = $blueprint->fresh()->payload;
        $payload['nodes'] = array_values(array_filter($payload['nodes'], fn (array $node): bool => $node['key'] !== 'start'));
        $payload['edges'] = array_values(array_filter($payload['edges'], fn (array $edge): bool => $edge['from'] !== 'start'));
        QualityProcessBlueprint::query()->whereKey($blueprint->id)->update(['payload' => json_encode($payload)]);

        $other = $this->customer();
        $this->placeControl($other, $this->blueprintedProcess($other), 'vurder', 'Annen kunde');

        $props = $this->actingAs($reader)->get('/app/quality?tab=controls')->assertOk()->viewData('page')['props'];

        $titles = array_column($props['items'], 'title', 'id');
        $this->assertEqualsCanonicalizing(['Fire øyne', 'Tatt av igjen', 'På en aktivitet som forsvinner'], array_values($titles));

        $register = $props['control_register'];

        $placement = $register[$placed->control_item_id]['placements'][0];
        $this->assertSame((int) $process->id, $placement['process_id']);
        $this->assertSame('Avvikshåndtering', $placement['process_title']);
        $this->assertSame('Vurder avviket', $placement['activity_label']);
        $this->assertSame('Kvalitetsleder', $placement['activity_role']);
        $this->assertTrue($placement['activity_exists']);
        $this->assertSame("/app/quality/items/{$process->id}?tab=flow&activity=vurder", $placement['url']);

        // Taken off every activity: still in the register, with nowhere to point.
        $this->assertSame([], $register[$unplaced->control_item_id]['placements']);

        // Its activity left the flow: still listed, marked as gone rather than hidden.
        $gone = $register[$orphaned->control_item_id]['placements'][0];
        $this->assertFalse($gone['activity_exists']);
        $this->assertNull($gone['activity_label']);
    }

    public function test_the_register_describes_each_control_as_a_control_on_overview_and_kontroller(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $described = $this->control($customer);
        QualityControlDetail::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $described->id,
            'criterion' => 'Tilgangene stemmer med rollene',
            'responsibility' => 'IT-sjef',
            'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
            'method' => 'Stikkprøve',
        ]);
        foreach (['Protokoll Q1', 'Protokoll Q2'] as $title) {
            QualityItemDocument::query()->create([
                'customer_id' => $customer->id,
                'quality_item_id' => $described->id,
                'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
                'title' => $title,
            ]);
        }
        $bare = $this->control($customer);

        // Another customer's evidence never counts towards this one's control.
        $other = $this->customer();
        QualityItemDocument::query()->create([
            'customer_id' => $other->id,
            'quality_item_id' => $this->control($other)->id,
            'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
            'title' => 'Annen kunde',
        ]);

        foreach (['overview', 'controls'] as $tab) {
            $register = $this->actingAs($reader)->get("/app/quality?tab={$tab}")
                ->assertOk()->viewData('page')['props']['control_register'];

            $this->assertSame('Tilgangene stemmer med rollene', $register[$described->id]['criterion'], $tab);
            $this->assertSame('IT-sjef', $register[$described->id]['responsibility'], $tab);
            $this->assertSame(QualityControlDetail::FREQUENCY_QUARTERLY, $register[$described->id]['frequency'], $tab);
            $this->assertSame('Stikkprøve', $register[$described->id]['method'], $tab);
            $this->assertSame(2, $register[$described->id]['evidence_count'], $tab);

            $this->assertNull($register[$bare->id]['responsibility'], $tab);
            $this->assertNull($register[$bare->id]['frequency'], $tab);
            $this->assertSame(0, $register[$bare->id]['evidence_count'], $tab);
            $this->assertCount(2, $register, $tab);
        }

        // Oversikt carries no generic relation editor, so it ships no relation data either.
        $overview = $this->actingAs($reader)->get('/app/quality')->viewData('page')['props'];
        foreach (['relations', 'relation_types', 'relation_item_options'] as $prop) {
            $this->assertArrayNotHasKey($prop, $overview);
        }

        // Prosesser lists no controls, so it is not read there.
        $this->assertSame([], (array) $this->actingAs($reader)->get('/app/quality?tab=processes')
            ->viewData('page')['props']['control_register']);
    }

    public function test_a_control_is_confirmed_as_a_control_never_as_a_document(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_CREATE,
            CustomerPermissionCatalog::QUALITY_EDIT,
            CustomerPermissionCatalog::QUALITY_DELETE,
        ]);

        $this->actingAs($editor)
            ->post('/app/quality/items', ['quality_type' => QualityItem::TYPE_CONTROL, 'title' => 'Tilgangskontroll'])
            ->assertSessionHas('success', 'Kontrollen er opprettet.');
        $control = QualityItem::query()->where('customer_id', $customer->id)->where('title', 'Tilgangskontroll')->sole();

        $this->actingAs($editor)
            ->patch("/app/quality/items/{$control->id}", ['title' => 'Tilgangskontroll kvartal'])
            ->assertSessionHas('success', 'Kontrollen er oppdatert.');

        $this->actingAs($editor)
            ->delete("/app/quality/items/{$control->id}")
            ->assertSessionHas('success', 'Kontrollen er slettet.');

        // A policy is still a document.
        $this->actingAs($editor)
            ->post('/app/quality/items', ['quality_type' => QualityItem::TYPE_POLICY, 'title' => 'Informasjonssikkerhet'])
            ->assertSessionHas('success', 'Dokumentet er opprettet.');
    }

    public function test_a_control_page_lists_the_activities_it_is_used_in_and_the_flow_opens_on_one(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);
        $link = $this->placeControl($customer, $process, 'vurder', 'Fire øyne');

        $props = $this->actingAs($reader)->get("/app/quality/items/{$link->control_item_id}")
            ->assertOk()->viewData('page')['props'];
        $this->assertSame(['Vurder avviket'], array_column($props['control_placements'], 'activity_label'));

        $url = $props['control_placements'][0]['url'];
        $this->assertSame('vurder', $this->actingAs($reader)->get($url)->assertOk()->viewData('page')['props']['focus_activity_key']);

        // Only the flow tab opens an activity.
        $this->assertNull($this->actingAs($reader)->get("/app/quality/items/{$process->id}?activity=vurder")
            ->viewData('page')['props']['focus_activity_key']);
    }

    // ---------------------------------------------------------------------
    // Evidence on a control
    // ---------------------------------------------------------------------

    public function test_an_editor_records_evidence_with_a_name_and_description_and_no_file(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $control = $this->control($customer);

        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/evidence", [
                'title' => 'Protokoll ledelsens gjennomgang 2026',
                'description' => 'Ligger i styreportalen.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $evidence = QualityItemDocument::query()->where('quality_item_id', $control->id)->sole();
        $this->assertSame(QualityItemDocument::RELATION_TYPE_EVIDENCE, $evidence->relation_type);
        $this->assertNull($evidence->enterprise_wiki_document_id);
        $this->assertSame((int) $customer->id, (int) $evidence->customer_id);

        $props = $this->actingAs($editor)->get("/app/quality/items/{$control->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('Protokoll ledelsens gjennomgang 2026', $props['control_evidence'][0]['title']);
        $this->assertSame('Ligger i styreportalen.', $props['control_evidence'][0]['description']);
        $this->assertNull($props['control_evidence'][0]['download_url']);
    }

    public function test_evidence_may_point_at_an_existing_document_and_removing_it_keeps_the_file(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $control = $this->control($customer);
        $document = $this->document($customer, 'kontrollskjema-q1.pdf');

        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/evidence", [
                'title' => 'Signert kontrollskjema Q1',
                'enterprise_wiki_document_id' => $document->id,
            ])
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($editor)->get("/app/quality/items/{$control->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('kontrollskjema-q1.pdf', $props['control_evidence'][0]['filename']);
        $this->assertNotNull($props['control_evidence'][0]['download_url']);
        // One place for evidence on a control: the general document list leaves it out.
        $this->assertSame([], $props['documents']);
        $this->assertNotContains(QualityItemDocument::RELATION_TYPE_EVIDENCE, $props['document_relation_types']);

        // The same file twice as evidence for one control is refused, not silently merged.
        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/evidence", [
                'title' => 'Igjen',
                'enterprise_wiki_document_id' => $document->id,
            ])
            ->assertSessionHasErrors('enterprise_wiki_document_id');

        $evidenceId = $props['control_evidence'][0]['id'];
        $this->actingAs($editor)->delete("/app/quality/document-links/{$evidenceId}")->assertRedirect();

        $this->assertSame(0, QualityItemDocument::query()->where('quality_item_id', $control->id)->count());
        $this->assertNotNull(EnterpriseWikiDocument::query()->find($document->id));
    }

    public function test_deleting_the_file_keeps_the_evidence_and_only_drops_the_file(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $control = $this->control($customer);
        $process = $this->process($customer);
        $document = $this->document($customer, 'kontrollskjema-q1.pdf');

        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/evidence", [
                'title' => 'Signert kontrollskjema Q1',
                'description' => 'Signert av kvalitetsleder.',
                'enterprise_wiki_document_id' => $document->id,
            ])
            ->assertSessionHasNoErrors();
        $evidence = QualityItemDocument::query()->where('quality_item_id', $control->id)->sole();

        // Evidence attached as a plain file, before evidence had a name, keeps the file's name.
        $unnamed = QualityItemDocument::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'enterprise_wiki_document_id' => $document->id,
            'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
            'source' => QualityItemDocument::SOURCE_MANUAL,
        ]);
        // Every other capacity still follows the file.
        $source = QualityItemDocument::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'enterprise_wiki_document_id' => $document->id,
            'relation_type' => QualityItemDocument::RELATION_TYPE_SOURCE,
            'source' => QualityItemDocument::SOURCE_MANUAL,
        ]);

        $result = app(EnterpriseWikiDocumentDeletionService::class)->delete($document, $editor);
        $this->assertFalse($result['blocked']);
        $this->assertNull(EnterpriseWikiDocument::query()->find($document->id));

        $evidence->refresh();
        $this->assertNull($evidence->enterprise_wiki_document_id);
        $this->assertNotNull($evidence->document_removed_at);
        $this->assertSame('Signert kontrollskjema Q1', $evidence->title);
        $this->assertSame('Signert av kvalitetsleder.', $evidence->note);
        $this->assertSame((int) $editor->id, (int) $evidence->created_by_user_id);
        $this->assertNotNull($evidence->created_at);

        $this->assertSame('kontrollskjema-q1.pdf', $unnamed->refresh()->title);
        $this->assertNull(QualityItemDocument::query()->find($source->id));

        $props = $this->actingAs($editor)->get("/app/quality/items/{$control->id}")->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['control_evidence']);
        $row = $props['control_evidence'][0];
        $this->assertSame('Signert kontrollskjema Q1', $row['title']);
        $this->assertSame('Signert av kvalitetsleder.', $row['description']);
        $this->assertSame($editor->name, $row['added_by']);
        $this->assertNotNull($row['added_at']);
        $this->assertNull($row['filename']);
        $this->assertNull($row['download_url']);
        $this->assertTrue($row['document_removed']);
    }

    public function test_quality_view_sees_evidence_but_cannot_add_or_remove_it(): void
    {
        $customer = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $control = $this->control($customer);

        $this->actingAs($editor)->post("/app/quality/items/{$control->id}/evidence", ['title' => 'Logg over tilgangsgjennomgang']);
        $evidence = QualityItemDocument::query()->where('quality_item_id', $control->id)->sole();

        $props = $this->actingAs($reader)->get("/app/quality/items/{$control->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['Logg over tilgangsgjennomgang'], array_column($props['control_evidence'], 'title'));

        $this->actingAs($reader)->post("/app/quality/items/{$control->id}/evidence", ['title' => 'Ny'])->assertForbidden();
        $this->actingAs($reader)->delete("/app/quality/document-links/{$evidence->id}")->assertForbidden();

        $this->assertSame(1, QualityItemDocument::query()->where('quality_item_id', $control->id)->count());
    }

    public function test_evidence_needs_a_name_a_control_and_the_customers_own_document(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $control = $this->control($customer);

        $this->actingAs($editor)->post("/app/quality/items/{$control->id}/evidence", ['title' => ''])
            ->assertSessionHasErrors('title');

        $process = $this->process($customer);
        $this->actingAs($editor)->post("/app/quality/items/{$process->id}/evidence", ['title' => 'Ikke en kontroll'])
            ->assertSessionHasErrors('title');

        $foreignDocument = $this->document($other, 'fremmed.pdf');
        $this->actingAs($editor)
            ->post("/app/quality/items/{$control->id}/evidence", ['title' => 'Fremmed', 'enterprise_wiki_document_id' => $foreignDocument->id])
            ->assertNotFound();

        $outsider = $this->member($other, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $this->actingAs($outsider)->post("/app/quality/items/{$control->id}/evidence", ['title' => 'Inntrenger'])
            ->assertNotFound();

        $this->assertSame(0, QualityItemDocument::query()->count());
    }

    public function test_only_evidence_may_exist_without_a_file(): void
    {
        $customer = $this->customer();
        $control = $this->control($customer);

        $this->expectException(QueryException::class);

        DB::transaction(fn () => QualityItemDocument::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $control->id,
            'enterprise_wiki_document_id' => null,
            'relation_type' => QualityItemDocument::RELATION_TYPE_TEMPLATE,
            'title' => 'Mal uten fil',
        ]));
    }

    // ---------------------------------------------------------------------
    // Fixtures

    private function blueprintedProcess(Customer $customer): QualityItem
    {
        $process = $this->process($customer);
        $this->blueprintFor($customer, $process);

        return $process;
    }
    // ---------------------------------------------------------------------

    private function placeControl(Customer $customer, QualityItem $process, string $activityKey, string $title): QualityActivityControl
    {
        $owner = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/activities/controls", ['activity_key' => $activityKey, 'title' => $title])
            ->assertSessionHasNoErrors();

        return QualityActivityControl::query()
            ->where('quality_item_id', $process->id)
            ->where('activity_key', $activityKey)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function member(Customer $customer, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kontroll-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function control(Customer $customer): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => 'Kvartalsvis tilgangsgjennomgang',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

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

    private function process(Customer $customer): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Avvikshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
    }

    private function blueprintFor(Customer $customer, QualityItem $item): QualityProcessBlueprint
    {
        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $item,
            [
                'lanes' => [['key' => 'rolle-1', 'label' => 'Kvalitetsleder']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'rolle-1', 'type' => 'start', 'label' => 'Start'],
                    ['key' => 'vurder', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Vurder avviket'],
                    ['key' => 'slutt', 'lane' => 'rolle-1', 'type' => 'end', 'label' => 'Slutt'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'vurder'],
                    ['from' => 'vurder', 'to' => 'slutt'],
                ],
            ],
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    private function customer(): Customer
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
            'name' => 'Kvalitet Kontroll AS',
            'slug' => 'kvalitet-kontroll-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'basis'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        return $customer;
    }
}
