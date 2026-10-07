<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\QualityItem;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → revisjonsfunn on an audit.
 *
 * What these tests defend:
 *
 *  - A finding is an avvik, an observasjon or a forbedringsmulighet with a title and a description,
 *    and nothing else of its own: no status, severity, frist, owner or tiltak.
 *  - It is recorded, changed and deleted with compliance.audit while the audit is in progress —
 *    not before, not after completion, never on a cancelled audit. A handed-off finding is frozen.
 *  - The requirement, Kvalitet process and Kvalitet control are optional context. Only what the
 *    user can reach can be chosen; a control must be a control; another customer's id gets the
 *    same answer as a missing one. Kvalitet context is invisible — no name, no id, no key — without
 *    Kvalitet read access, and survives an edit by someone who cannot see it.
 *  - The database holds the type, the tenant line on every reference, the kind of Kvalitet item,
 *    the frozen hand-off, and keeps the finding when Kvalitet deletes its process or control.
 */
class ComplianceAuditFindingTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
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
    // Recording
    // ---------------------------------------------------------------------

    public function test_an_auditor_records_each_kind_of_finding_with_title_and_description_only(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);

        foreach ([
            ComplianceAuditFinding::TYPE_NONCONFORMITY => 'Tilganger fjernes ikke ved fratredelse',
            ComplianceAuditFinding::TYPE_OBSERVATION => 'Rutinen er ikke kjent for alle',
            ComplianceAuditFinding::TYPE_OPPORTUNITY => 'Automatisere tilgangsgjennomgang',
        ] as $type => $title) {
            $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload($type, $title))
                ->assertSessionHasNoErrors()->assertSessionHas('success', 'Funnet er registrert.');
        }

        $findings = ComplianceAuditFinding::query()->where('audit_id', $audit->id)->orderBy('id')->get();
        $this->assertSame(ComplianceAuditFinding::TYPES, $findings->pluck('finding_type')->all());
        $this->assertSame((int) $customer->id, (int) $findings[0]->customer_id);
        $this->assertSame((int) $auditor->id, (int) $findings[0]->created_by);
        $this->assertNull($findings[0]->requirement_id);
        $this->assertNull($findings[0]->improvement_case_id);

        // An observation, not a follow-up system: none of these exist on a finding.
        $columns = Schema::getColumnListing('compliance_audit_findings');
        foreach (['status', 'severity', 'due_date', 'owner_user_id', 'action', 'verification'] as $column) {
            $this->assertNotContains($column, $columns);
        }

        $props = $this->props($auditor, $audit);
        $this->assertSame(['Tilganger fjernes ikke ved fratredelse', 'Rutinen er ikke kjent for alle', 'Automatisere tilgangsgjennomgang'], array_column($props['findings'], 'title'));
        $this->assertSame(ComplianceAuditFinding::TYPES, $props['finding_types']);
        $this->assertTrue($props['permissions']['can_record_findings']);
        $this->assertSame(['deviation', 'improvement', 'improvement'], array_column($props['findings'], 'improvement_type'));
        $this->assertFalse($props['findings'][0]['handed_off']);
        $this->assertSame(['can_edit' => true, 'can_delete' => true, 'can_hand_off' => true], $props['findings'][0]['permissions']);
    }

    public function test_type_title_and_description_are_required(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);

        $this->actingAs($auditor)->post($this->url($audit), ['finding_type' => '', 'title' => ' ', 'description' => ''])
            ->assertSessionHasErrors(['finding_type', 'title', 'description']);
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload('severe'))
            ->assertSessionHasErrors(['finding_type' => 'Velg en gyldig verdi for Type.']);

        $this->assertSame(0, ComplianceAuditFinding::query()->where('audit_id', $audit->id)->count());
    }

    public function test_a_requirement_process_and_control_can_each_be_linked_optionally(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring', null, 'A.5.15');
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Brukeradministrasjon');
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Kvartalsvis tilgangsgjennomgang');

        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['requirement_id' => $requirement->id])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['quality_process_id' => $process->id])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['control_item_id' => $control->id])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + [
            'requirement_id' => $requirement->id, 'quality_process_id' => $process->id, 'control_item_id' => $control->id,
        ])->assertSessionHasNoErrors();

        $rows = ComplianceAuditFinding::query()->where('audit_id', $audit->id)->orderBy('id')->get(['requirement_id', 'quality_process_id', 'control_item_id'])
            ->map(fn (ComplianceAuditFinding $f): array => [(int) $f->requirement_id ?: null, (int) $f->quality_process_id ?: null, (int) $f->control_item_id ?: null])->all();
        $this->assertSame([
            [$requirement->id, null, null],
            [null, $process->id, null],
            [null, null, $control->id],
            [$requirement->id, $process->id, $control->id],
        ], $rows);

        $last = $this->props($auditor, $audit)['findings'][3];
        $this->assertSame('A.5.15', $last['requirement']['reference']);
        $this->assertSame('Brukeradministrasjon', $last['quality_process']['title']);
        $this->assertSame('Kvartalsvis tilgangsgjennomgang', $last['control']['title']);
        $this->assertSame(route('app.quality.items.show', ['item' => $control->id]), $last['control']['url']);
    }

    public function test_the_form_offers_what_is_in_scope_first(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $source = $this->complianceSource($customer);
        $outside = $this->complianceRequirement($source, 'Utenfor', null, 'A.1');
        $inside = $this->complianceRequirement($source, 'Innenfor', null, 'A.9');
        $processOutside = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'A-prosess');
        $processInside = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'B-prosess');
        $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Utgått prosess', QualityItem::STATUS_RETIRED);
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Kontroll');
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/requirements", ['requirement_ids' => [$inside->id]])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/processes", ['quality_process_id' => $processInside->id])->assertSessionHasNoErrors();

        $options = $this->props($auditor, $audit)['finding_options'];

        $this->assertSame([$inside->id, $outside->id], array_column($options['requirements'], 'id'));
        $this->assertSame([true, false], array_column($options['requirements'], 'in_scope'));
        $this->assertSame([$processInside->id, $processOutside->id], array_column($options['processes'], 'id'));
        $this->assertSame([$control->id], array_column($options['controls'], 'id'));
    }

    public function test_only_a_real_control_of_the_own_customer_can_be_the_control(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Prosess');
        $policy = $this->qualityItem($customer, QualityItem::TYPE_POLICY, 'Policy');
        $retired = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Utgått kontroll', QualityItem::STATUS_RETIRED);
        $foreignControl = $this->qualityItem($foreign, QualityItem::TYPE_CONTROL, 'Fremmed kontroll');
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Kontroll');

        foreach ([$process, $policy, $retired, $foreignControl] as $item) {
            $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['control_item_id' => $item->id])
                ->assertSessionHasErrors(['control_item_id' => 'Velg en kontroll fra listen.']);
        }
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['control_item_id' => 999999999])
            ->assertSessionHasErrors(['control_item_id' => 'Velg en kontroll fra listen.']);
        // And the process field takes only a process.
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['quality_process_id' => $control->id])
            ->assertSessionHasErrors(['quality_process_id' => 'Velg en prosess fra listen.']);

        $this->assertSame(0, ComplianceAuditFinding::query()->where('audit_id', $audit->id)->count());

        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['control_item_id' => $control->id])->assertSessionHasNoErrors();
    }

    public function test_another_customers_requirement_or_audit_cannot_be_reached(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $foreignAuditor = $this->complianceAuditor($foreign);
        $foreignAudit = $this->complianceAudit($foreign, $foreignAuditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $foreignRequirement = $this->complianceRequirement($this->complianceSource($foreign), 'Fremmed krav');
        $foreignFinding = $this->finding($foreignAudit);

        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['requirement_id' => $foreignRequirement->id])
            ->assertSessionHasErrors(['requirement_id' => 'Velg et krav fra listen.']);
        $this->actingAs($auditor)->post($this->url($foreignAudit), $this->findingPayload())->assertNotFound();
        $this->actingAs($auditor)->patch($this->url($foreignAudit, $foreignFinding), $this->findingPayload())->assertNotFound();
        // A finding of another audit, through this audit's URL, is no finding.
        $this->actingAs($auditor)->patch($this->url($audit, $foreignFinding), $this->findingPayload())->assertNotFound();
        $this->actingAs($auditor)->delete($this->url($audit, $foreignFinding))->assertNotFound();

        $this->assertSame(1, ComplianceAuditFinding::query()->where('customer_id', $foreign->id)->count());
        $this->assertSame(0, ComplianceAuditFinding::query()->where('customer_id', $customer->id)->count());
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_findings_are_recorded_only_while_the_audit_is_in_progress(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);

        foreach ([ComplianceAudit::STATUS_PLANNED, ComplianceAudit::STATUS_COMPLETED, ComplianceAudit::STATUS_CANCELLED] as $status) {
            $audit = $this->complianceAudit($customer, $auditor, status: $status);

            $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload())
                ->assertSessionHasErrors(['audit' => 'Funn kan bare registreres, endres og slettes mens revisjonen er under arbeid.']);
            $this->assertSame(0, ComplianceAuditFinding::query()->where('audit_id', $audit->id)->count(), $status);

            $props = $this->props($auditor, $audit);
            $this->assertFalse($props['permissions']['can_record_findings'], $status);
            $this->assertNull($props['finding_options'], $status);
        }

        $running = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $this->actingAs($auditor)->post($this->url($running), $this->findingPayload())->assertSessionHasNoErrors();
    }

    public function test_a_completed_audit_locks_its_findings_and_a_reopened_one_unlocks_them(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/complete", ['conclusion' => 'Ett avvik.'])->assertSessionHasNoErrors();

        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret'))->assertSessionHasErrors('audit');
        $this->actingAs($auditor)->delete($this->url($audit, $finding))->assertSessionHasErrors('audit');
        $this->assertSame('Tilganger fjernes ikke', $finding->fresh()->title);

        $props = $this->props($auditor, $audit);
        $this->assertSame(['can_edit' => false, 'can_delete' => false, 'can_hand_off' => true], $props['findings'][0]['permissions']);

        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/reopen", ['reason' => 'Rette funn.'])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret'))->assertSessionHasNoErrors();
        $this->assertSame('Endret', $finding->fresh()->title);
    }

    public function test_a_cancelled_audit_shows_its_findings_read_only(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $finding = $this->finding($audit);
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/cancel", ['reason' => 'Utsatt.'])->assertSessionHasNoErrors();

        $props = $this->props($auditor, $audit);
        $this->assertSame([$finding->id], array_column($props['findings'], 'id'));
        $this->assertSame(['can_edit' => false, 'can_delete' => false, 'can_hand_off' => false], $props['findings'][0]['permissions']);
        $this->assertNull($props['handoff']);
        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret'))->assertSessionHasErrors('audit');
    }

    public function test_a_finding_is_edited_and_deleted_before_hand_off(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $finding = $this->finding($audit);

        $this->actingAs($auditor)->patch($this->url($audit, $finding), [
            'finding_type' => ComplianceAuditFinding::TYPE_OBSERVATION,
            'title' => '  Rutinen er uklar  ',
            'description' => 'Ny beskrivelse.',
            'requirement_id' => $requirement->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Funnet er oppdatert.');

        $finding->refresh();
        $this->assertSame([ComplianceAuditFinding::TYPE_OBSERVATION, 'Rutinen er uklar', 'Ny beskrivelse.', (int) $requirement->id], [$finding->finding_type, $finding->title, $finding->description, (int) $finding->requirement_id]);

        // Emptied again.
        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload() + ['requirement_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($finding->fresh()->requirement_id);

        $this->actingAs($auditor)->delete($this->url($audit, $finding))->assertSessionHas('success', 'Funnet er slettet.');
        $this->assertNull($finding->fresh());
    }

    public function test_a_handed_off_finding_can_neither_be_edited_nor_deleted(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $finding = $this->finding($audit);
        $this->markHandedOff($finding, $auditor);

        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret'))
            ->assertSessionHasErrors(['finding' => 'Funnet er overført til Avvik og forbedringer og kan ikke endres eller slettes.']);
        $this->actingAs($auditor)->delete($this->url($audit, $finding))->assertSessionHasErrors('finding');

        $this->assertSame('Tilganger fjernes ikke', $finding->fresh()->title);
        $props = $this->props($auditor, $audit);
        $this->assertSame(['can_edit' => false, 'can_delete' => false, 'can_hand_off' => false], $props['findings'][0]['permissions']);
        $this->assertTrue($props['findings'][0]['handed_off']);
    }

    public function test_view_or_edit_without_audit_cannot_touch_findings(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $finding = $this->finding($audit);

        foreach ([$this->complianceReader($customer), $this->complianceEditor($customer)] as $user) {
            $this->actingAs($user)->post($this->url($audit), $this->findingPayload())->assertForbidden();
            $this->actingAs($user)->patch($this->url($audit, $finding), $this->findingPayload())->assertForbidden();
            $this->actingAs($user)->delete($this->url($audit, $finding))->assertForbidden();
            $this->actingAs($user)->post($this->url($audit, $finding).'/handoff', [])->assertForbidden();

            // They read the findings, with no actions.
            $props = $this->props($user, $audit);
            $this->assertSame([$finding->id], array_column($props['findings'], 'id'));
            $this->assertSame(['can_edit' => false, 'can_delete' => false, 'can_hand_off' => false], $props['findings'][0]['permissions']);
            $this->assertNull($props['handoff']);
        }

        $outsider = $this->complianceMember($customer);
        $this->actingAs($outsider)->post($this->url($audit), $this->findingPayload())->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Kvalitet context is never leaked
    // ---------------------------------------------------------------------

    public function test_without_kvalitet_a_findings_process_and_control_are_invisible_and_survive_an_edit(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $withQuality = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $withoutQuality = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $withQuality, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Hemmelig prosess');
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Hemmelig kontroll');
        $this->actingAs($withQuality)->post($this->url($audit), $this->findingPayload() + ['quality_process_id' => $process->id, 'control_item_id' => $control->id])->assertSessionHasNoErrors();
        $finding = ComplianceAuditFinding::query()->where('audit_id', $audit->id)->firstOrFail();

        $response = $this->actingAs($withoutQuality)->get("/app/compliance/audits/{$audit->id}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertArrayNotHasKey('quality_process', $props['findings'][0]);
        $this->assertArrayNotHasKey('control', $props['findings'][0]);
        $this->assertNull($props['finding_options']['processes']);
        $this->assertNull($props['finding_options']['controls']);
        $this->assertFalse($props['handoff']['can_link_process'] ?? false);
        // Nowhere on the page: not in the findings, the form options or the hand-off.
        $this->assertStringNotContainsString('Hemmelig', json_encode($props));
        $json = json_encode([$props['findings'], $props['finding_options'], $props['handoff']]);
        foreach (['"quality_process":', '"control":', '"quality_process_id"', '"control_item_id"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        // An edit by someone who cannot see the context leaves it as it was — even if they send ids.
        $this->actingAs($withoutQuality)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret') + ['quality_process_id' => '', 'control_item_id' => ''])
            ->assertSessionHasNoErrors();
        $finding->refresh();
        $this->assertSame('Endret', $finding->title);
        $this->assertSame([(int) $process->id, (int) $control->id], [(int) $finding->quality_process_id, (int) $finding->control_item_id]);

        // And they cannot write it either.
        $other = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Annen prosess');
        $this->actingAs($withoutQuality)->post($this->url($audit), $this->findingPayload() + ['quality_process_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertNull(ComplianceAuditFinding::query()->where('audit_id', $audit->id)->latest('id')->first()->quality_process_id);
    }

    public function test_a_process_or_control_retired_since_may_stay_on_the_finding(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Prosess');
        $this->actingAs($auditor)->post($this->url($audit), $this->findingPayload() + ['quality_process_id' => $process->id])->assertSessionHasNoErrors();
        $finding = ComplianceAuditFinding::query()->where('audit_id', $audit->id)->firstOrFail();
        $process->forceFill(['status' => QualityItem::STATUS_RETIRED])->save();

        $this->actingAs($auditor)->patch($this->url($audit, $finding), $this->findingPayload(title: 'Endret') + ['quality_process_id' => $process->id])->assertSessionHasNoErrors();
        $this->assertSame((int) $process->id, (int) $finding->fresh()->quality_process_id);
        $this->assertSame('retired', $this->props($auditor, $audit)['findings'][0]['quality_process']['status']);
    }

    // ---------------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------------

    public function test_the_database_refuses_an_unknown_type_and_blank_text(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $audit = $this->complianceAudit($customer, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $row = ['customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => 'nonconformity', 'title' => 'T', 'description' => 'D', 'created_at' => now(), 'updated_at' => now()];

        $this->assertRefused('an unknown type', fn () => DB::table('compliance_audit_findings')->insert(['finding_type' => 'severe'] + $row));
        $this->assertRefused('a blank title', fn () => DB::table('compliance_audit_findings')->insert(['title' => '  '] + $row));
        $this->assertRefused('a blank description', fn () => DB::table('compliance_audit_findings')->insert(['description' => ''] + $row));
        // A hand-off is both a case and a moment.
        $this->assertRefused('a hand-off time without a case', fn () => DB::table('compliance_audit_findings')->insert(['handed_off_at' => now()] + $row));
    }

    public function test_every_reference_is_held_to_the_findings_own_customer(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $audit = $this->complianceAudit($customer, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $foreignAudit = $this->complianceAudit($foreign, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $foreignRequirement = $this->complianceRequirement($this->complianceSource($foreign), 'Fremmed');
        $foreignProcess = $this->qualityItem($foreign, QualityItem::TYPE_PROCESS, 'Fremmed prosess');
        $foreignControl = $this->qualityItem($foreign, QualityItem::TYPE_CONTROL, 'Fremmed kontroll');
        $foreignArea = $this->area($foreign, 'Fremmed område');
        $foreignCase = $this->improvementCase($foreign, $foreignArea, 'Fremmed sak');
        $row = ['customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => 'nonconformity', 'title' => 'T', 'description' => 'D'];

        $this->assertRefused('another customer\'s audit', fn () => ComplianceAuditFinding::query()->create(['audit_id' => $foreignAudit->id] + $row));
        $this->assertRefused('another customer\'s requirement', fn () => ComplianceAuditFinding::query()->create(['requirement_id' => $foreignRequirement->id] + $row));
        $this->assertRefused('another customer\'s process', fn () => ComplianceAuditFinding::query()->create(['quality_process_id' => $foreignProcess->id] + $row));
        $this->assertRefused('another customer\'s control', fn () => ComplianceAuditFinding::query()->create(['control_item_id' => $foreignControl->id] + $row));
        $this->assertRefused('another customer\'s case', function () use ($row, $foreignCase): void {
            $finding = ComplianceAuditFinding::query()->create($row);
            $finding->forceFill(['improvement_case_id' => $foreignCase->id, 'handed_off_at' => now()])->save();
        });
    }

    public function test_the_database_holds_the_kind_of_kvalitet_item(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $audit = $this->complianceAudit($customer, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Prosess');
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Kontroll');
        $checklist = $this->qualityItem($customer, QualityItem::TYPE_CHECKLIST, 'Sjekkliste');
        $row = ['customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => 'nonconformity', 'title' => 'T', 'description' => 'D'];

        $this->assertRefused('a checklist as the control', fn () => ComplianceAuditFinding::query()->create(['control_item_id' => $checklist->id] + $row));
        $this->assertRefused('a process as the control', fn () => ComplianceAuditFinding::query()->create(['control_item_id' => $process->id] + $row));
        $this->assertRefused('a control as the process', fn () => ComplianceAuditFinding::query()->create(['quality_process_id' => $control->id] + $row));

        $finding = ComplianceAuditFinding::query()->create(['quality_process_id' => $process->id, 'control_item_id' => $control->id] + $row);
        $this->assertRefused('changing the control to a non-control', fn () => $finding->forceFill(['control_item_id' => $checklist->id])->save());
    }

    public function test_the_finding_survives_kvalitet_deleting_its_process_or_control(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $process = $this->qualityItem($customer, QualityItem::TYPE_PROCESS, 'Prosess');
        $control = $this->qualityItem($customer, QualityItem::TYPE_CONTROL, 'Kontroll');
        $open = $this->finding($audit, ['quality_process_id' => $process->id, 'control_item_id' => $control->id]);
        $frozen = $this->finding($audit, ['quality_process_id' => $process->id, 'control_item_id' => $control->id]);
        $this->markHandedOff($frozen, $auditor);

        $process->delete();
        $control->delete();

        foreach ([$open, $frozen] as $finding) {
            $finding->refresh();
            $this->assertSame('Tilganger fjernes ikke', $finding->title);
            $this->assertNull($finding->quality_process_id);
            $this->assertNull($finding->control_item_id);
            // Only the Kvalitet column was nulled — not the tenant.
            $this->assertSame((int) $customer->id, (int) $finding->customer_id);
        }
        $this->assertNotNull($frozen->improvement_case_id);
    }

    public function test_a_requirement_a_finding_names_cannot_be_deleted(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $audit = $this->complianceAudit($customer, null, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring');
        $this->assertTrue($requirement->isDeletable());
        $this->finding($audit, ['requirement_id' => $requirement->id]);

        $this->assertFalse($requirement->fresh()->isDeletable());
        $this->assertRefused('deleting the requirement', fn () => ComplianceRequirement::query()->whereKey($requirement->id)->delete());
    }

    public function test_the_database_freezes_a_handed_off_finding(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $finding = $this->finding($audit);
        $this->markHandedOff($finding, $auditor);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');

        foreach (['title' => 'Endret', 'description' => 'Endret', 'finding_type' => 'observation', 'requirement_id' => $requirement->id, 'improvement_case_id' => null] as $column => $value) {
            $this->assertRefused("changing {$column}", fn () => DB::table('compliance_audit_findings')->where('id', $finding->id)->update(
                $column === 'improvement_case_id' ? ['improvement_case_id' => null, 'handed_off_at' => null] : [$column => $value],
            ));
        }
        $this->assertRefused('deleting it', fn () => DB::table('compliance_audit_findings')->where('id', $finding->id)->delete());

        // The person who handed it off leaving only nulls the reference.
        $auditor->delete();
        $this->assertNull($finding->fresh()->handed_off_by_user_id);
    }

    public function test_a_customer_leaving_takes_its_findings_along(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, status: ComplianceAudit::STATUS_IN_PROGRESS);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $this->finding($audit, ['requirement_id' => $requirement->id]);
        $frozen = $this->finding($audit);
        $this->markHandedOff($frozen, $auditor);

        // NO ACTION on the requirement and the case, so one statement takes everything; the findings'
        // trigger lets a departing customer's handed-off finding go.
        Customer::query()->whereKey($customer->id)->delete();

        $this->assertSame(0, ComplianceAuditFinding::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, ImprovementCase::query()->where('customer_id', $customer->id)->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function findingPayload(string $type = ComplianceAuditFinding::TYPE_NONCONFORMITY, string $title = 'Tilganger fjernes ikke'): array
    {
        return [
            'finding_type' => $type,
            'title' => $title,
            'description' => 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang.',
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function finding(ComplianceAudit $audit, array $extra = []): ComplianceAuditFinding
    {
        return ComplianceAuditFinding::query()->create($extra + [
            'customer_id' => $audit->customer_id,
            'audit_id' => $audit->id,
            'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY,
            'title' => 'Tilganger fjernes ikke',
            'description' => 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang.',
        ]);
    }

    /** Marks the finding handed off to a fresh case, the way the hand-off leaves it. */
    private function markHandedOff(ComplianceAuditFinding $finding, User $by): ImprovementCase
    {
        $customer = Customer::query()->findOrFail($finding->customer_id);
        $case = $this->improvementCase($customer, $this->area($customer, 'Område '.$finding->id), $finding->title);
        $finding->forceFill(['improvement_case_id' => $case->id, 'handed_off_at' => now(), 'handed_off_by_user_id' => $by->id])->save();

        return $case;
    }

    private function qualityItem(Customer $customer, string $type, string $title, string $status = QualityItem::STATUS_ACTIVE): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'status' => $status,
        ]);
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

    private function url(ComplianceAudit $audit, ?ComplianceAuditFinding $finding = null): string
    {
        return "/app/compliance/audits/{$audit->id}/findings".($finding !== null ? "/{$finding->id}" : '');
    }

    /** @return array<string, mixed> */
    private function props(User $user, ComplianceAudit $audit): array
    {
        return $this->actingAs($user)->get("/app/compliance/audits/{$audit->id}")->assertOk()->viewData('page')['props'];
    }
}
