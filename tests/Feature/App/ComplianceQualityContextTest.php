<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementControl;
use App\Models\ComplianceRequirementProcess;
use App\Models\Customer;
use App\Models\QualityActivityControl;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → Hvordan kravet oppfylles: Krav → Kvalitet-prosess / -kontroll, with
 * the control's evidence read-only.
 *
 * What these tests defend:
 *
 *  - Only ids are stored. Process, control and evidence are read live from Kvalitet, and unlinking
 *    leaves them exactly as they were.
 *  - Two access questions. The requirement through ComplianceAccessService (404 hidden/foreign,
 *    403 without compliance.view); anything about Kvalitet only with Kvalitet read access, which
 *    compliance.* never implies. Without it the page carries nothing — no names, no ids, no count.
 *  - Linking and unlinking take compliance.edit AND Kvalitet read access AND an active requirement.
 *  - Only a non-retired `process` / `control` QualityItem of the requirement's own customer can be
 *    linked; every other id gets the same answer.
 *  - System Owner is no bypass: no compliance role, no compliance; a compliance role but no
 *    Kvalitet, no Kvalitet data.
 *  - Evidence never decides etterlevelse.
 *  - The database holds the tenant line and refuses duplicates; deletes leave no dangling rows.
 */
class ComplianceQualityContextTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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
    // Processes
    // ---------------------------------------------------------------------

    public function test_an_editor_links_a_readable_process_and_unlinks_it_without_touching_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Tilgangsstyring prosess', ['Tildel tilgang']);

        $props = $this->props($editor, $requirement);
        $this->assertSame(['processes' => [], 'controls' => []], $props['quality_context']);
        $this->assertTrue($props['permissions']['can_manage_quality_links']);
        $this->assertSame([$process->id], array_column($props['quality_options']['processes'], 'id'));

        $this->actingAs($editor)->post($this->url($requirement, 'processes'), ['quality_process_id' => $process->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('compliance_requirement_processes', [
            'customer_id' => $customer->id,
            'requirement_id' => $requirement->id,
            'quality_process_id' => $process->id,
            'created_by' => $editor->id,
        ]);

        // Only ids: nothing about the process is copied onto the link.
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'requirement_id', 'quality_process_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('compliance_requirement_processes'),
        );

        // Read live from Kvalitet.
        $process->update(['title' => 'Tilgangsstyring v2']);
        $props = $this->props($editor, $requirement);
        $this->assertSame('Tilgangsstyring v2', $props['quality_context']['processes'][0]['title']);
        $this->assertSame(route('app.quality.items.show', ['item' => $process->id]), $props['quality_context']['processes'][0]['url']);
        $this->assertSame([], $props['quality_options']['processes']);

        // A second link to the same process is refused, not stored twice.
        $this->actingAs($editor)->post($this->url($requirement, 'processes'), ['quality_process_id' => $process->id])
            ->assertSessionHasErrors('quality_process_id');
        $this->assertSame(1, ComplianceRequirementProcess::query()->where('requirement_id', $requirement->id)->count());

        $this->actingAs($editor)->delete($this->url($requirement, 'processes', $process->id))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $process->id, 'title' => 'Tilgangsstyring v2', 'quality_type' => 'process']);

        // Unlinking what is not linked is a 404.
        $this->actingAs($editor)->delete($this->url($requirement, 'processes', $process->id))->assertNotFound();
    }

    public function test_only_a_non_retired_process_of_the_own_customer_can_be_linked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $retired = $this->process($customer, $owner, 'Gammel prosess', ['Steg'], QualityItem::STATUS_RETIRED);
        $control = $this->control($customer, 'Kontroll, ikke prosess');
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Fremmed prosess', ['Steg']);

        $this->assertSame([], $this->props($editor, $requirement)['quality_options']['processes']);

        $answers = [];
        foreach ([$retired->id, $control->id, $foreignProcess->id, 999999999] as $id) {
            $response = $this->actingAs($editor)->post($this->url($requirement, 'processes'), ['quality_process_id' => $id]);
            $response->assertSessionHasErrors('quality_process_id');
            $answers[] = session('errors')->first('quality_process_id');
        }

        // The same answer for every one, so the form cannot probe ids.
        $this->assertCount(1, array_unique($answers));
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
    }

    // ---------------------------------------------------------------------
    // Controls and evidence
    // ---------------------------------------------------------------------

    public function test_a_control_is_linked_and_shown_with_its_fields_placement_and_evidence_read_only(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Lønnsprosess', ['Avstem lønn']);
        $control = $this->control($customer, 'Månedlig tilgangsgjennomgang', [
            'criterion' => 'Alle tilganger er godkjent.',
            'method' => 'Stikkprøve av 10 brukere.',
            'frequency' => QualityControlDetail::FREQUENCY_MONTHLY,
            'responsibility' => 'IT-sjef',
        ]);
        $this->place($customer, $process, 'avstem-lonn', $control);
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Gjennomgang mars', 'Signert av IT-sjef.', null, $owner);

        $props = $this->props($editor, $requirement);
        $this->assertSame([$control->id], array_column($props['quality_options']['controls'], 'id'));
        $this->assertSame(['Lønnsprosess › Avstem lønn'], $props['quality_options']['controls'][0]['placements']);

        $this->actingAs($editor)->post($this->url($requirement, 'controls'), ['control_item_id' => $control->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'requirement_id', 'control_item_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('compliance_requirement_controls'),
        );

        $props = $this->props($editor, $requirement);
        $row = $props['quality_context']['controls'][0];
        $this->assertSame($control->id, $row['id']);
        $this->assertSame('Månedlig tilgangsgjennomgang', $row['title']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('Alle tilganger er godkjent.', $row['criterion']);
        $this->assertSame('Stikkprøve av 10 brukere.', $row['method']);
        $this->assertSame('monthly', $row['frequency']);
        $this->assertSame('IT-sjef', $row['responsibility']);
        $this->assertSame(['Lønnsprosess › Avstem lønn'], $row['placements']);
        $this->assertCount(1, $row['evidence']);
        $this->assertSame($evidence->id, $row['evidence'][0]['id']);
        $this->assertSame('Gjennomgang mars', $row['evidence'][0]['title']);
        $this->assertSame('Signert av IT-sjef.', $row['evidence'][0]['description']);
        $this->assertSame($owner->name, $row['evidence'][0]['added_by']);
        $this->assertNull($row['evidence'][0]['download_url']);
        $this->assertSame([], $props['quality_options']['controls']);

        // Evidence decides nothing: the requirement is still not assessed.
        $this->assertSame('not_assessed', $props['compliance']['status']);

        // Duplicate refused.
        $this->actingAs($editor)->post($this->url($requirement, 'controls'), ['control_item_id' => $control->id])
            ->assertSessionHasErrors('control_item_id');
        $this->assertSame(1, ComplianceRequirementControl::query()->where('requirement_id', $requirement->id)->count());

        // No route on the requirement writes evidence.
        $this->actingAs($editor)->post("/app/compliance/requirements/{$requirement->id}/evidence", ['title' => 'x'])->assertNotFound();

        $this->actingAs($editor)->delete($this->url($requirement, 'controls', $control->id))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('compliance_requirement_controls', ['requirement_id' => $requirement->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $control->id, 'title' => 'Månedlig tilgangsgjennomgang', 'status' => 'active']);
        $this->assertDatabaseHas('quality_item_documents', ['id' => $evidence->id, 'quality_item_id' => $control->id, 'title' => 'Gjennomgang mars']);
        $this->assertDatabaseHas('quality_activity_controls', ['control_item_id' => $control->id]);
    }

    public function test_only_a_non_retired_control_of_the_own_customer_can_be_linked(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $policy = QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_POLICY, 'title' => 'Policy', 'status' => QualityItem::STATUS_ACTIVE]);
        $process = $this->process($customer, $owner, 'Prosess, ikke kontroll', ['Steg']);
        $retired = $this->control($customer, 'Utgått kontroll', [], QualityItem::STATUS_RETIRED);
        $foreignControl = $this->control($foreign, 'Fremmed kontroll');

        $this->assertSame([], $this->props($editor, $requirement)['quality_options']['controls']);

        $answers = [];
        foreach ([$policy->id, $process->id, $retired->id, $foreignControl->id, 999999999] as $id) {
            $this->actingAs($editor)->post($this->url($requirement, 'controls'), ['control_item_id' => $id])
                ->assertSessionHasErrors('control_item_id');
            $answers[] = session('errors')->first('control_item_id');
        }

        $this->assertCount(1, array_unique($answers));
        $this->assertDatabaseMissing('compliance_requirement_controls', ['requirement_id' => $requirement->id]);
    }

    public function test_a_control_retired_after_linking_stays_linked_shown_as_retired_and_no_assessment_changes(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $control = $this->control($customer, 'Logggjennomgang');
        $this->link($requirement, $control);

        $control->update(['status' => QualityItem::STATUS_RETIRED]);

        $props = $this->props($editor, $requirement);
        $this->assertSame([$control->id], array_column($props['quality_context']['controls'], 'id'));
        $this->assertSame('retired', $props['quality_context']['controls'][0]['status']);
        // Not offered again, and the requirement's etterlevelse is not touched.
        $this->assertSame([], $props['quality_options']['controls']);
        $this->assertSame('not_assessed', $props['compliance']['status']);
        $this->assertSame([], $props['assessments']);
    }

    public function test_evidence_shows_only_to_someone_who_can_read_kvalitet(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Hemmelig prosess '.Str::random(6), ['Steg']);
        $control = $this->control($customer, 'Hemmelig kontroll '.Str::random(6), ['criterion' => 'Hemmelig kriterium']);
        app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Hemmelig evidens', 'Hemmelig notat', null, $owner);
        $this->link($requirement, $control);
        $this->link($requirement, $process);

        $withQuality = $this->complianceMember($customer);
        $this->complianceGrant($customer, $withQuality, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW]);
        $props = $this->props($withQuality, $requirement);
        $this->assertSame('Hemmelig evidens', $props['quality_context']['controls'][0]['evidence'][0]['title']);
        // Reading is not managing.
        $this->assertFalse($props['permissions']['can_manage_quality_links']);
        $this->assertNull($props['quality_options']);

        $this->assertQualityInvisible($this->complianceReader($customer), $requirement, [$process, $control]);
    }

    // ---------------------------------------------------------------------
    // Access and security
    // ---------------------------------------------------------------------

    public function test_view_without_edit_cannot_manage_links(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $control = $this->control($customer, 'Kontroll');
        $this->link($requirement, $process);
        $viewer = $this->complianceMember($customer);
        $this->complianceGrant($customer, $viewer, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($viewer)->post($this->url($requirement, 'processes'), ['quality_process_id' => $process->id])->assertForbidden();
        $this->actingAs($viewer)->post($this->url($requirement, 'controls'), ['control_item_id' => $control->id])->assertForbidden();
        $this->actingAs($viewer)->delete($this->url($requirement, 'processes', $process->id))->assertForbidden();
        $this->assertDatabaseHas('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
        $this->assertDatabaseMissing('compliance_requirement_controls', ['requirement_id' => $requirement->id]);
    }

    public function test_compliance_edit_without_kvalitet_read_can_neither_see_nor_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Skjult prosess '.Str::random(6), ['Steg']);
        $control = $this->control($customer, 'Skjult kontroll '.Str::random(6));
        $linked = $this->control($customer, 'Koblet kontroll '.Str::random(6));
        $this->link($requirement, $linked);
        $editor = $this->complianceEditor($customer);

        $this->assertQualityInvisible($editor, $requirement, [$process, $control, $linked]);

        // The same 403 for a real id, a foreign one and nonsense: nothing is told about the object.
        foreach ([$process->id, 999999999] as $id) {
            $this->actingAs($editor)->post($this->url($requirement, 'processes'), ['quality_process_id' => $id])->assertForbidden();
        }
        $this->actingAs($editor)->post($this->url($requirement, 'controls'), ['control_item_id' => $control->id])->assertForbidden();
        $this->actingAs($editor)->delete($this->url($requirement, 'controls', $linked->id))->assertForbidden();
        $this->actingAs($editor)->delete($this->url($requirement, 'controls', 999999999))->assertForbidden();
        $this->assertSame(1, ComplianceRequirementControl::query()->where('requirement_id', $requirement->id)->count());
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
    }

    public function test_kvalitet_read_without_compliance_view_does_not_reach_through_compliance(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $qualityOnly = $this->complianceMember($customer);
        $this->complianceGrant($customer, $qualityOnly, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT, CustomerPermissionCatalog::COMPLIANCE_EDIT]);

        $this->actingAs($qualityOnly)->get("/app/compliance/requirements/{$requirement->id}")->assertForbidden();
        $this->actingAs($qualityOnly)->post($this->url($requirement, 'processes'), ['quality_process_id' => $process->id])->assertForbidden();
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
    }

    public function test_another_customers_requirement_is_a_404_for_linking_and_unlinking(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->complianceContext();
        $editor = $this->linker($customer);
        $foreignRequirement = $this->requirementFor($foreign);
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Fremmed', ['Steg']);
        $this->link($foreignRequirement, $foreignProcess);
        $ownProcess = $this->process($customer, $owner, 'Egen', ['Steg']);

        $this->actingAs($editor)->post($this->url($foreignRequirement, 'processes'), ['quality_process_id' => $ownProcess->id])->assertNotFound();
        $this->actingAs($editor)->delete($this->url($foreignRequirement, 'processes', $foreignProcess->id))->assertNotFound();
        $this->assertSame(1, ComplianceRequirementProcess::query()->where('requirement_id', $foreignRequirement->id)->count());
    }

    public function test_system_owner_is_no_bypass(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $this->link($requirement, $process);

        // No compliance role: no compliance, whatever Kvalitet access the role of administrator gives.
        $this->actingAs($owner)->get("/app/compliance/requirements/{$requirement->id}")->assertForbidden();
        $this->actingAs($owner)->post($this->url($requirement, 'processes'), ['quality_process_id' => $process->id])->assertForbidden();
        $this->actingAs($owner)->delete($this->url($requirement, 'processes', $process->id))->assertForbidden();

        // A compliance role, and Kvalitet through the administrator role: then, and only then.
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);
        $this->assertSame([$process->id], array_column($this->props($owner, $requirement)['quality_context']['processes'], 'id'));
    }

    public function test_system_owner_with_a_compliance_role_but_no_kvalitet_sees_no_kvalitet_data(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess uten modul '.Str::random(6), ['Steg']);
        $control = $this->control($customer, 'Kontroll uten modul '.Str::random(6));
        $this->link($requirement, $process);
        $this->link($requirement, $control);
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);

        // The customer holds Etterlevelse og revisjon but not Kvalitet.
        config()->set('procynia_modules.packages.grc.modules', ['risk', 'objectives', 'improvements', 'compliance']);

        $this->assertQualityInvisible($owner, $requirement, [$process, $control]);
        $this->actingAs($owner)->post($this->url($requirement, 'controls'), ['control_item_id' => $control->id])->assertForbidden();
        $this->actingAs($owner)->delete($this->url($requirement, 'processes', $process->id))->assertForbidden();
        $this->assertDatabaseHas('compliance_requirement_processes', ['requirement_id' => $requirement->id]);
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_a_retired_requirement_shows_its_links_read_only_and_refuses_changes(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $editor = $this->linker($customer);
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $other = $this->process($customer, $owner, 'Annen prosess', ['Steg']);
        $control = $this->control($customer, 'Kontroll');
        $this->link($requirement, $process);
        $this->link($requirement, $control);
        app(ComplianceRequirementLifecycleService::class)->retire($requirement, $editor, 'Standarden er erstattet.');

        $props = $this->props($editor, $requirement);
        $this->assertSame([$process->id], array_column($props['quality_context']['processes'], 'id'));
        $this->assertSame([$control->id], array_column($props['quality_context']['controls'], 'id'));
        $this->assertFalse($props['permissions']['can_manage_quality_links']);
        $this->assertNull($props['quality_options']);

        $this->actingAs($editor)->post($this->url($requirement, 'processes'), ['quality_process_id' => $other->id])->assertSessionHasErrors('requirement');
        $this->actingAs($editor)->delete($this->url($requirement, 'processes', $process->id))->assertSessionHasErrors('requirement');
        $this->actingAs($editor)->delete($this->url($requirement, 'controls', $control->id))->assertSessionHasErrors('requirement');
        $this->assertSame(1, ComplianceRequirementProcess::query()->where('requirement_id', $requirement->id)->count());
        $this->assertSame(1, ComplianceRequirementControl::query()->where('requirement_id', $requirement->id)->count());

        app(ComplianceRequirementLifecycleService::class)->reopen($requirement->fresh(), $editor, 'Gjelder igjen.');
        $this->assertTrue($this->props($editor, $requirement)['permissions']['can_manage_quality_links']);
    }

    // ---------------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------------

    public function test_the_database_holds_the_tenant_line_and_refuses_duplicates(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        ['customer' => $foreign, 'owner' => $foreignOwner] = $this->complianceContext();
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $control = $this->control($customer, 'Kontroll');
        $foreignProcess = $this->process($foreign, $foreignOwner, 'Fremmed prosess', ['Steg']);
        $foreignControl = $this->control($foreign, 'Fremmed kontroll');

        $this->assertRefused('a cross-tenant process link', fn () => ComplianceRequirementProcess::query()->create([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'quality_process_id' => $foreignProcess->id,
        ]));
        $this->assertRefused('a cross-tenant control link', fn () => ComplianceRequirementControl::query()->create([
            'customer_id' => $customer->id, 'requirement_id' => $requirement->id, 'control_item_id' => $foreignControl->id,
        ]));
        $this->assertRefused('a link claiming the other customer for the requirement', fn () => ComplianceRequirementProcess::query()->create([
            'customer_id' => $foreign->id, 'requirement_id' => $requirement->id, 'quality_process_id' => $foreignProcess->id,
        ]));
        $this->assertRefused('a control link claiming the other customer for the requirement', fn () => ComplianceRequirementControl::query()->create([
            'customer_id' => $foreign->id, 'requirement_id' => $requirement->id, 'control_item_id' => $foreignControl->id,
        ]));

        $this->link($requirement, $process);
        $this->link($requirement, $control);
        $this->assertRefused('a duplicate process link', fn () => $this->link($requirement, $process));
        $this->assertRefused('a duplicate control link', fn () => $this->link($requirement, $control));
    }

    public function test_deleting_either_side_leaves_no_dangling_links(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $manager = $this->complianceManager($customer);
        $requirement = $this->requirementFor($customer);
        $process = $this->process($customer, $owner, 'Prosess', ['Steg']);
        $control = $this->control($customer, 'Kontroll');
        $this->link($requirement, $process);
        $this->link($requirement, $control);

        // Kvalitet deletes its item without asking compliance; the link goes with it.
        $control->delete();
        $this->assertDatabaseMissing('compliance_requirement_controls', ['requirement_id' => $requirement->id]);
        QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->delete();
        $process->delete();
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $requirement->id]);

        // Deleting a requirement registered by mistake takes its links along, never the Kvalitet items.
        $second = $this->requirementFor($customer);
        $otherProcess = $this->process($customer, $owner, 'Annen prosess', ['Steg']);
        $otherControl = $this->control($customer, 'Annen kontroll');
        $this->link($second, $otherProcess);
        $this->link($second, $otherControl);
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$second->id}")->assertRedirect();
        $this->assertDatabaseMissing('compliance_requirements', ['id' => $second->id]);
        $this->assertDatabaseMissing('compliance_requirement_processes', ['requirement_id' => $second->id]);
        $this->assertDatabaseMissing('compliance_requirement_controls', ['requirement_id' => $second->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $otherProcess->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $otherControl->id]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The page carries nothing about the links: no section data, no options, no way to manage, and
     * none of the names or evidence anywhere in the props.
     *
     * @param  list<QualityItem>  $items
     */
    private function assertQualityInvisible(User $user, ComplianceRequirement $requirement, array $items): void
    {
        $props = $this->props($user, $requirement);

        $this->assertNull($props['quality_context']);
        $this->assertNull($props['quality_options']);
        $this->assertFalse($props['permissions']['can_manage_quality_links']);

        $json = json_encode(array_diff_key($props, ['translations' => true]), JSON_UNESCAPED_UNICODE);
        foreach ($items as $item) {
            $this->assertStringNotContainsString($item->title, $json);
            $this->assertStringNotContainsString('/app/quality/items/'.$item->id, $json);
        }
        $this->assertStringNotContainsString('Hemmelig', $json);
        $this->assertStringNotContainsString('evidence', $json);
        $this->assertStringNotContainsString('placements', $json);
    }

    private function assertRefused(string $what, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("The database must refuse {$what}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** compliance.view + compliance.edit + quality.view: everything linking takes. */
    private function linker(Customer $customer): User
    {
        $user = $this->complianceMember($customer);
        $this->complianceGrant($customer, $user, [
            CustomerPermissionCatalog::COMPLIANCE_VIEW,
            CustomerPermissionCatalog::COMPLIANCE_EDIT,
            CustomerPermissionCatalog::QUALITY_VIEW,
        ]);

        return $user;
    }

    private function requirementFor(Customer $customer): ComplianceRequirement
    {
        return $this->complianceRequirement($this->complianceSource($customer, 'ISO 27001 '.Str::random(4)), 'Tilgangsstyring');
    }

    private function link(ComplianceRequirement $requirement, QualityItem $item): void
    {
        if ($item->quality_type === QualityItem::TYPE_PROCESS) {
            ComplianceRequirementProcess::query()->create(['customer_id' => $requirement->customer_id, 'requirement_id' => $requirement->id, 'quality_process_id' => $item->id]);

            return;
        }

        ComplianceRequirementControl::query()->create(['customer_id' => $requirement->customer_id, 'requirement_id' => $requirement->id, 'control_item_id' => $item->id]);
    }

    /** @param  array<string, string>  $detail */
    private function control(Customer $customer, string $title, array $detail = [], string $status = QualityItem::STATUS_ACTIVE): QualityItem
    {
        $control = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => $title,
            'status' => $status,
        ]);

        if ($detail !== []) {
            QualityControlDetail::query()->create(['customer_id' => $customer->id, 'quality_item_id' => $control->id, ...$detail]);
        }

        return $control;
    }

    /** @param  list<string>  $steps */
    private function process(Customer $customer, User $actor, string $title, array $steps, string $status = QualityItem::STATUS_DRAFT): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => $status,
        ]);

        $nodes = [['key' => 'start', 'lane' => 'eier', 'type' => 'start', 'label' => 'Start']];
        foreach ($steps as $step) {
            $nodes[] = ['key' => Str::slug($step), 'lane' => 'eier', 'type' => 'step', 'label' => $step];
        }
        $nodes[] = ['key' => 'slutt', 'lane' => 'eier', 'type' => 'end', 'label' => 'Slutt'];
        $edges = [];
        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['from' => $nodes[$i - 1]['key'], 'to' => $nodes[$i]['key']];
        }

        app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            ['lanes' => [['key' => 'eier', 'label' => 'Prosesseier']], 'nodes' => $nodes, 'edges' => $edges],
            QualityProcessBlueprint::SOURCE_MANUAL,
            $actor,
        );

        return $process;
    }

    private function place(Customer $customer, QualityItem $process, string $activityKey, QualityItem $control): void
    {
        QualityActivityControl::query()->create([
            'customer_id' => $customer->id,
            'quality_item_id' => $process->id,
            'activity_key' => $activityKey,
            'control_item_id' => $control->id,
        ]);
    }

    private function url(ComplianceRequirement $requirement, string $kind, ?int $id = null): string
    {
        return "/app/compliance/requirements/{$requirement->id}/{$kind}".($id !== null ? "/{$id}" : '');
    }

    /** @return array<string, mixed> */
    private function props(User $user, ComplianceRequirement $requirement): array
    {
        /** @var TestResponse $response */
        $response = $this->actingAs($user)->get("/app/compliance/requirements/{$requirement->id}")->assertOk();

        return $response->viewData('page')['props'];
    }
}
