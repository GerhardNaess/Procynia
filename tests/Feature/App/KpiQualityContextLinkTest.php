<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiActivity;
use App\Models\KpiProcess;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\Risk;
use App\Models\RiskActivity;
use App\Models\User;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * KPI → måler → Kvalitet-prosess / -aktivitet.
 *
 * What these tests defend:
 *
 *  - Only ids are stored, in two small tables; names are read live from Kvalitet. The database
 *    itself refuses a link across customers and a duplicate link.
 *  - A KPI can measure whole processes and activities, several of each; an activity link carries
 *    its process, so it needs no process link beside it.
 *  - Changing links takes objective.edit in the objective's area AND Kvalitet read access; neither
 *    implies the other. Without Kvalitet read neither the KPI nor the objective says anything about
 *    context — not even that there is some.
 *  - Only a process of the KPI's own customer, and only a step in that process's flow, is accepted.
 *  - A step leaving the flow, the flow, or the process removes the link — through the same hook
 *    that prunes risk links — and a later step with the same key does not inherit it.
 *  - Nothing about the link reaches Kvalitet.
 */
class KpiQualityContextLinkTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const OBJECTIVE_EDITOR = [
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_EDIT,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Bus::fake();
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
    // Linking and reading
    // ---------------------------------------------------------------------

    public function test_a_kpi_measures_processes_and_activities_read_live(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $objective = $this->objective($customer, $it, 'Stabil drift');
        $kpi = $this->kpi($objective, 'Løsningstid');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse', 'Klassifiser alvorlighet', 'Lukk hendelse']);
        $backup = $this->process($customer, $owner, 'Backup og gjenoppretting', ['Test gjenoppretting']);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::OBJECTIVE_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $props = $this->kpiProps($user, $kpi);
        $this->assertSame([], $props['quality_context']);
        $this->assertTrue($props['permissions']['can_link_context']);
        $this->assertSame(['Backup og gjenoppretting', 'Incidenthåndtering'], array_column($props['quality_context_options'], 'title'));
        $incidentOption = collect($props['quality_context_options'])->firstWhere('id', $incident->id);
        // Steps only — start and end are markers, not activities.
        $this->assertSame(['registrer-hendelse', 'klassifiser-alvorlighet', 'lukk-hendelse'], array_column($incidentOption['activities'], 'key'));

        // Activities without the whole process: no process link is needed beside them.
        $this->actingAs($user)->put($this->url($kpi, $incident), ['whole_process' => false, 'activity_keys' => ['registrer-hendelse', 'klassifiser-alvorlighet']])
            ->assertRedirect()->assertSessionHas('success');
        // The whole of another process.
        $this->actingAs($user)->put($this->url($kpi, $backup), ['whole_process' => true, 'activity_keys' => []])->assertRedirect();
        // Saving the same choice again changes nothing.
        $this->actingAs($user)->put($this->url($kpi, $incident), ['whole_process' => false, 'activity_keys' => ['registrer-hendelse', 'klassifiser-alvorlighet', 'registrer-hendelse']])->assertRedirect();

        $this->assertSame(0, KpiProcess::query()->where('kpi_id', $kpi->id)->where('quality_process_id', $incident->id)->count());
        $this->assertSame(1, KpiProcess::query()->where('kpi_id', $kpi->id)->count());
        $this->assertSame(2, KpiActivity::query()->where('kpi_id', $kpi->id)->count());
        $this->assertDatabaseHas('kpi_activities', ['kpi_id' => $kpi->id, 'quality_process_id' => $incident->id, 'activity_key' => 'registrer-hendelse', 'customer_id' => $customer->id, 'created_by' => $user->id]);

        // Only ids: nothing about the process or the step is copied.
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'kpi_id', 'quality_process_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('kpi_processes'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'kpi_id', 'quality_process_id', 'activity_key', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('kpi_activities'),
        );

        // Read live from Kvalitet, grouped by process.
        $incident->update(['title' => 'Hendelseshåndtering']);
        $context = $this->kpiProps($user, $kpi)['quality_context'];
        $this->assertSame(['Backup og gjenoppretting', 'Hendelseshåndtering'], array_column($context, 'title'));
        [$backupRow, $incidentRow] = $context;
        $this->assertTrue($backupRow['whole_process']);
        $this->assertSame([], $backupRow['activities']);
        $this->assertFalse($incidentRow['whole_process']);
        $this->assertSame(['Registrer hendelse', 'Klassifiser alvorlighet'], array_column($incidentRow['activities'], 'label'));
        $this->assertSame(route('app.quality.items.show', ['item' => $incident->id]).'?tab=flow&activity=registrer-hendelse', $incidentRow['activities'][0]['url']);

        // Change one process: drop an activity, add another, take the whole process too.
        $this->actingAs($user)->put($this->url($kpi, $incident), ['whole_process' => true, 'activity_keys' => ['lukk-hendelse']])->assertRedirect();
        $incidentRow = collect($this->kpiProps($user, $kpi)['quality_context'])->firstWhere('id', $incident->id);
        $this->assertTrue($incidentRow['whole_process']);
        $this->assertSame(['Lukk hendelse'], array_column($incidentRow['activities'], 'label'));

        // Clearing every box removes the process from the KPI; the process stays in Kvalitet.
        $this->actingAs($user)->put($this->url($kpi, $backup), ['whole_process' => false])->assertRedirect();
        $this->assertSame(['Hendelseshåndtering'], array_column($this->kpiProps($user, $kpi)['quality_context'], 'title'));
        $this->assertDatabaseHas('quality_items', ['id' => $backup->id]);

        // A link is not a measurement and does not stop the KPI from being deleted.
        $this->assertTrue($kpi->fresh()->isDeletable());
    }

    public function test_the_objective_shows_the_processes_its_active_kpis_measure_deduplicated(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $objective = $this->objective($customer, $it, 'Stabil drift');
        $first = $this->kpi($objective, 'Løsningstid');
        $second = $this->kpi($objective, 'Andel P1');
        $retired = $this->kpi($objective, 'Gammel KPI');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        $backup = $this->process($customer, $owner, 'Backup og gjenoppretting', ['Test gjenoppretting']);
        $archive = $this->process($customer, $owner, 'Arkivering', ['Arkiver']);
        $this->linkProcess($first, $incident);
        $this->linkActivity($second, $incident, 'registrer-hendelse');
        $this->linkActivity($second, $backup, 'test-gjenoppretting');
        $this->linkProcess($retired, $archive);
        $retired->forceFill(['status' => Kpi::STATUS_RETIRED])->save();

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $affected = $this->objectiveProps($user, $objective)['affected_processes'];
        // Once each, by title; a retired KPI no longer measures anything.
        $this->assertSame(['Backup og gjenoppretting', 'Incidenthåndtering'], array_column($affected, 'title'));
        $this->assertSame(route('app.quality.items.show', ['item' => $backup->id]), $affected[0]['url']);
        // Derived, never stored: no process column or pivot on the objective.
        $this->assertFalse(Schema::hasTable('objective_processes'));
    }

    // ---------------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------------

    public function test_the_database_refuses_links_across_customers_and_duplicates(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context();
        $kpi = $this->kpi($this->objective($customer, $this->area($customer, 'IT'), 'Mål'), 'KPI');
        $theirKpi = $this->kpi($this->objective($other, $this->area($other, 'IT'), 'Andres mål'), 'Andres KPI');
        $process = $this->process($customer, $owner, 'Egen prosess', ['Steg']);
        $theirProcess = $this->process($other, $otherOwner, 'Andres prosess', ['Steg']);

        // Same customer: accepted.
        $this->assertFalse($this->refused(fn () => $this->linkProcess($kpi, $process)));
        $this->assertFalse($this->refused(fn () => $this->linkActivity($kpi, $process, 'steg')));

        $row = fn (Kpi $k, QualityItem $p, int $customerId): array => ['customer_id' => $customerId, 'kpi_id' => $k->id, 'quality_process_id' => $p->id, 'created_at' => now(), 'updated_at' => now()];

        // Another customer's process, under either customer id.
        $this->assertTrue($this->refused(fn () => DB::table('kpi_processes')->insert($row($kpi, $theirProcess, (int) $customer->id))));
        $this->assertTrue($this->refused(fn () => DB::table('kpi_processes')->insert($row($kpi, $theirProcess, (int) $other->id))));
        $this->assertTrue($this->refused(fn () => DB::table('kpi_activities')->insert($row($kpi, $theirProcess, (int) $customer->id) + ['activity_key' => 'steg'])));
        // Another customer's KPI on our process.
        $this->assertTrue($this->refused(fn () => DB::table('kpi_activities')->insert($row($theirKpi, $process, (int) $customer->id) + ['activity_key' => 'steg'])));

        // Duplicates.
        $this->assertTrue($this->refused(fn () => DB::table('kpi_processes')->insert($row($kpi, $process, (int) $customer->id))));
        $this->assertTrue($this->refused(fn () => DB::table('kpi_activities')->insert($row($kpi, $process, (int) $customer->id) + ['activity_key' => 'steg'])));

        $this->assertSame(1, KpiProcess::query()->count());
        $this->assertSame(1, KpiActivity::query()->count());

        // Deleting the KPI takes its links along.
        $kpi->delete();
        $this->assertSame(0, KpiProcess::query()->count() + KpiActivity::query()->count());
    }

    public function test_only_a_process_of_the_own_customer_and_a_step_in_its_flow_is_accepted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $it, 'Mål'), 'KPI');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        $this->process($customer, $owner, 'Endringshåndtering', ['Godkjenn endring']);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::OBJECTIVE_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $control = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => 'Avstemming',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->context();
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Utenlandsk prosess', ['Utenlandsk steg']);

        foreach ([$control->id, $foreignProcess->id, 999999999] as $id) {
            $this->actingAs($user)->put("/app/objectives/{$kpi->objective_id}/kpis/{$kpi->id}/processes/{$id}", ['whole_process' => true])
                ->assertSessionHasErrors('quality_process_id');
        }

        // The activity must be a step in the chosen process: not another process's step, not a
        // start/end marker, not another customer's step, not a made-up key.
        foreach (['godkjenn-endring', 'start', 'slutt', 'utenlandsk-steg', 'finnes-ikke'] as $key) {
            $this->actingAs($user)->put($this->url($kpi, $incident), ['activity_keys' => ['registrer-hendelse', $key]])
                ->assertSessionHasErrors('activity_keys');
        }

        $this->assertSame(0, KpiProcess::query()->count() + KpiActivity::query()->count());
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_objective_edit_without_quality_view_reveals_nothing_and_cannot_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $objective = $this->objective($customer, $it, 'Stabil drift');
        $kpi = $this->kpi($objective, 'Løsningstid');
        $process = $this->process($customer, $owner, 'Hemmelig prosesstittel', ['Hemmelig aktivitet']);
        $this->linkProcess($kpi, $process);
        $this->linkActivity($kpi, $process, 'hemmelig-aktivitet');

        $user = $this->member($customer);
        $this->grant($customer, $user, self::OBJECTIVE_EDITOR, [$it]);

        $response = $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['quality_context']);
        $this->assertSame([], $props['quality_context_options']);
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertStringNotContainsString('Hemmelig', $response->getContent());

        $objectiveResponse = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk();
        $this->assertNull($objectiveResponse->viewData('page')['props']['affected_processes']);
        $this->assertStringNotContainsString('Hemmelig', $objectiveResponse->getContent());

        $this->actingAs($user)->put($this->url($kpi, $process), ['whole_process' => false])->assertForbidden();
        $this->assertSame(1, KpiProcess::query()->count());
        $this->assertSame(1, KpiActivity::query()->count());
    }

    public function test_quality_view_with_objective_view_reads_but_needs_objective_edit_to_change(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $it, 'Stabil drift'), 'Løsningstid');
        $process = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        $this->linkActivity($kpi, $process, 'registrer-hendelse');

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $props = $this->kpiProps($reader, $kpi);
        $this->assertSame(['Incidenthåndtering'], array_column($props['quality_context'], 'title'));
        $this->assertSame(['Registrer hendelse'], array_column($props['quality_context'][0]['activities'], 'label'));
        $this->assertFalse($props['permissions']['can_link_context']);
        $this->assertSame([], $props['quality_context_options']);

        $this->actingAs($reader)->put($this->url($kpi, $process), ['whole_process' => true])->assertForbidden();

        // Kvalitet access alone does not open Mål og KPI.
        $qualityOnly = $this->member($customer);
        $this->grant($customer, $qualityOnly, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT], [$it]);
        $this->actingAs($qualityOnly)->get($this->kpiUrl($kpi))->assertForbidden();
        $this->actingAs($qualityOnly)->put($this->url($kpi, $process), ['whole_process' => true])->assertForbidden();

        $this->assertSame(0, KpiProcess::query()->count());
        $this->assertSame(1, KpiActivity::query()->count());
    }

    public function test_the_wrong_area_and_another_customer_are_a_404(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $board = $this->area($customer, 'Styre');
        $hidden = $this->kpi($this->objective($customer, $board, 'Fusjon'), 'Konfidensiell KPI');
        $process = $this->process($customer, $owner, 'Styrebehandling', ['Forbered sak']);
        $this->linkProcess($hidden, $process);

        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::OBJECTIVE_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $this->actingAs($user)->get($this->kpiUrl($hidden))->assertNotFound();
        $this->actingAs($user)->get("/app/objectives/{$hidden->objective_id}")->assertNotFound();
        $this->actingAs($user)->put($this->url($hidden, $process), ['whole_process' => false])->assertNotFound();

        // objective.view there is not enough to change: objective.edit must reach the same area.
        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$board]);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::OBJECTIVE_EDIT], [$it]);
        $this->actingAs($viewer)->put($this->url($hidden, $process), ['whole_process' => false])->assertForbidden();

        // A visible KPI cannot be reached under a hidden objective's id.
        $visible = $this->kpi($this->objective($customer, $it, 'Synlig'), 'Synlig KPI');
        $this->actingAs($user)->put("/app/objectives/{$hidden->objective_id}/kpis/{$visible->id}/processes/{$process->id}", ['whole_process' => true])->assertNotFound();

        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->context();
        $foreignArea = $this->area($foreign, 'IT');
        $theirs = $this->kpi($this->objective($foreign, $foreignArea, 'Andres mål'), 'Andres KPI');
        $theirProcess = $this->process($foreign, $foreignOwner, 'Andres prosess', ['Steg']);
        $this->actingAs($user)->get($this->kpiUrl($theirs))->assertNotFound();
        $this->actingAs($user)->put($this->url($theirs, $theirProcess), ['whole_process' => true])->assertNotFound();

        $this->assertSame(1, KpiProcess::query()->count());
        $this->assertSame(0, KpiActivity::query()->count());
    }

    public function test_system_owner_needs_a_role_reaching_the_area(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $it, 'Mål'), 'KPI');
        $process = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);

        // System Owner holds quality.view and every objective key, but no fagområde.
        $this->actingAs($owner)->get($this->kpiUrl($kpi))->assertNotFound();
        $this->actingAs($owner)->put($this->url($kpi, $process), ['whole_process' => true])->assertNotFound();

        $this->grant($customer, $owner, self::OBJECTIVE_EDITOR, [$it]);
        $this->assertTrue($this->kpiProps($owner, $kpi)['permissions']['can_link_context']);
        $this->actingAs($owner)->put($this->url($kpi, $process), ['whole_process' => true])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, KpiProcess::query()->where('kpi_id', $kpi->id)->count());
    }

    public function test_a_closed_objective_or_a_retired_kpi_is_not_relinked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $objective = $this->objective($customer, $it, 'Mål');
        $kpi = $this->kpi($objective, 'KPI');
        $process = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::OBJECTIVE_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        $kpi->forceFill(['status' => Kpi::STATUS_RETIRED])->save();
        $this->assertFalse($this->kpiProps($user, $kpi)['permissions']['can_link_context']);
        $this->actingAs($user)->put($this->url($kpi, $process), ['whole_process' => true])->assertRedirect()->assertSessionHas('error');

        $kpi->forceFill(['status' => Kpi::STATUS_ACTIVE])->save();
        app(ObjectiveLifecycleService::class)->close($objective, $user, Objective::STATUS_ACHIEVED, null);
        $this->assertFalse($this->kpiProps($user, $kpi)['permissions']['can_link_context']);
        $this->actingAs($user)->put($this->url($kpi, $process), ['whole_process' => true])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, KpiProcess::query()->count());
    }

    // ---------------------------------------------------------------------
    // Cleanup
    // ---------------------------------------------------------------------

    public function test_removing_a_step_the_flow_or_the_process_removes_kpi_and_risk_links_alike(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $it, 'Stabil drift'), 'Løsningstid');
        $incident = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse', 'Klassifiser alvorlighet']);
        $this->linkProcess($kpi, $incident);
        $this->linkActivity($kpi, $incident, 'registrer-hendelse');
        $this->linkActivity($kpi, $incident, 'klassifiser-alvorlighet');
        // A risk on the same step: one hook prunes both.
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $it->id, 'title' => 'Risiko', 'status' => Risk::STATUS_IDENTIFIED]);
        RiskActivity::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $incident->id, 'activity_key' => 'registrer-hendelse']);

        // A Kvalitet user who knows nothing about the KPI.
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT, CustomerPermissionCatalog::QUALITY_DELETE]);

        // Remove one step through Kvalitet's own editor: that link goes, the other stays.
        $this->actingAs($editor)->put("/app/quality/items/{$incident->id}/blueprint", $this->payload(['Klassifiser alvorlighet']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['klassifiser-alvorlighet'], KpiActivity::query()->where('kpi_id', $kpi->id)->pluck('activity_key')->all());
        $this->assertSame(0, RiskActivity::query()->where('risk_id', $risk->id)->count());

        // A later step that gets the removed key back does not inherit the KPI.
        $this->actingAs($editor)->put("/app/quality/items/{$incident->id}/blueprint", $this->payload(['Registrer hendelse', 'Klassifiser alvorlighet']))
            ->assertRedirect();
        $this->assertSame(['klassifiser-alvorlighet'], KpiActivity::query()->where('kpi_id', $kpi->id)->pluck('activity_key')->all());

        // Removing the flow removes every activity link; the whole-process link and the KPI stay.
        $this->actingAs($editor)->delete("/app/quality/items/{$incident->id}/blueprint")->assertRedirect();
        $this->assertSame(0, KpiActivity::query()->where('kpi_id', $kpi->id)->count());
        $this->assertSame(1, KpiProcess::query()->where('kpi_id', $kpi->id)->count());

        // Deleting the process is not refused because of a link it cannot see.
        $backup = $this->process($customer, $owner, 'Backup', ['Test gjenoppretting']);
        $this->linkActivity($kpi, $backup, 'test-gjenoppretting');
        $this->actingAs($editor)->delete("/app/quality/items/{$incident->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($editor)->delete("/app/quality/items/{$backup->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, KpiProcess::query()->where('kpi_id', $kpi->id)->count() + KpiActivity::query()->where('kpi_id', $kpi->id)->count());
        $this->assertDatabaseHas('kpis', ['id' => $kpi->id, 'title' => 'Løsningstid']);
    }

    // ---------------------------------------------------------------------
    // No reverse visibility
    // ---------------------------------------------------------------------

    public function test_nothing_about_the_link_reaches_kvalitet(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $it, 'Konfidensiell måltittel'), 'Konfidensiell KPI-tittel');
        $process = $this->process($customer, $owner, 'Incidenthåndtering', ['Registrer hendelse']);
        $this->linkProcess($kpi, $process);
        $this->linkActivity($kpi, $process, 'registrer-hendelse');

        // Even someone who can see the KPI does not see it from Kvalitet.
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::OBJECTIVE_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$it]);

        foreach ([
            "/app/quality/items/{$process->id}",
            "/app/quality/items/{$process->id}?tab=flow",
            "/app/quality/items/{$process->id}?tab=flow&activity=registrer-hendelse",
            '/app/quality?tab=overview',
            '/app/quality?tab=processes',
            // Searched by another word of the title: the query itself is echoed back in the page.
            '/app/quality?tab=processes&search=KPI-tittel',
        ] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Konfidensiell', $content, $url);
            $this->assertStringNotContainsString('kpi_processes', $content, $url);
            $this->assertStringNotContainsString('kpi_activities', $content, $url);
            $this->assertStringNotContainsString("/kpis/{$kpi->id}", $content, $url);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function refused(callable $write): bool
    {
        try {
            DB::transaction(fn () => $write());

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    /**
     * A process with a working flow: start → the given steps → end, all steps in one lane.
     *
     * @param  list<string>  $steps
     */
    private function process(Customer $customer, User $actor, string $title, array $steps): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);

        app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            $this->payload($steps),
            QualityProcessBlueprint::SOURCE_MANUAL,
            $actor,
        );

        return $process;
    }

    /**
     * @param  list<string>  $steps
     * @return array{lanes: list<array<string, string>>, nodes: list<array<string, string>>, edges: list<array<string, string>>}
     */
    private function payload(array $steps): array
    {
        $nodes = [['key' => 'start', 'lane' => 'drift', 'type' => 'start', 'label' => 'Start']];

        foreach ($steps as $step) {
            $nodes[] = ['key' => Str::slug($step), 'lane' => 'drift', 'type' => 'step', 'label' => $step];
        }

        $nodes[] = ['key' => 'slutt', 'lane' => 'drift', 'type' => 'end', 'label' => 'Slutt'];

        $edges = [];

        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['from' => $nodes[$i - 1]['key'], 'to' => $nodes[$i]['key']];
        }

        return [
            'lanes' => [['key' => 'drift', 'label' => 'Driftsansvarlig']],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function linkProcess(Kpi $kpi, QualityItem $process): KpiProcess
    {
        return KpiProcess::query()->create(['customer_id' => $kpi->customer_id, 'kpi_id' => $kpi->id, 'quality_process_id' => $process->id]);
    }

    private function linkActivity(Kpi $kpi, QualityItem $process, string $key): KpiActivity
    {
        return KpiActivity::query()->create(['customer_id' => $kpi->customer_id, 'kpi_id' => $kpi->id, 'quality_process_id' => $process->id, 'activity_key' => $key]);
    }

    private function url(Kpi $kpi, QualityItem $process): string
    {
        return "{$this->kpiUrl($kpi)}/processes/{$process->id}";
    }

    private function kpiUrl(Kpi $kpi): string
    {
        return "/app/objectives/{$kpi->objective_id}/kpis/{$kpi->id}";
    }

    /** @return array<string, mixed> */
    private function kpiProps(User $user, Kpi $kpi): array
    {
        return $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function objectiveProps(User $user, Objective $objective): array
    {
        return $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
    }

    private function objective(Customer $customer, BusinessArea $area, string $title): Objective
    {
        return Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
        ]);
    }

    private function kpi(Objective $objective, string $title): Kpi
    {
        return Kpi::query()->create([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '95',
            'tolerance' => '1',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kpi-kontekst-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
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
            'name' => 'KPI Kontekst AS',
            'slug' => 'kpi-kontekst-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        // GRC carries Risiko as well as Kvalitet and Mål og KPI, which the cleanup test needs.
        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'grc'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'kpi-kontekst-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
