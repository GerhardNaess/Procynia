<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditProcess;
use App\Models\ComplianceAuditRequirement;
use App\Models\Customer;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon → a revisjon's scope: Krav i scope and Prosesser i scope.
 *
 * What these tests defend:
 *
 *  - Only ids are stored, as fixed rows. «Fra kravkilde» writes the source's active requirements
 *    once; a requirement added to the source afterwards never enters the audit by itself.
 *  - Any requirement the user can read may be in scope, a retired one too, shown as utgått; one of
 *    another customer, or a missing id, gets the same answer. A duplicate is refused.
 *  - Processes take Kvalitet read access on top of compliance.audit, which compliance.* never
 *    implies. Without it the page carries nothing about them — no names, no ids, no count — and
 *    nothing can be added or removed. Only a non-retired process of the own customer can be added.
 *  - The scope changes only while the audit is planned or in progress.
 *  - The database holds the tenant line and refuses duplicates; a requirement in scope cannot be
 *    deleted out from under the audit, a deleted process takes its link along.
 */
class ComplianceAuditScopeTest extends TestCase
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
    // Requirements
    // ---------------------------------------------------------------------

    public function test_an_auditor_adds_one_requirement_and_then_several(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $source = $this->complianceSource($customer);
        $first = $this->complianceRequirement($source, 'Tilgangsstyring', $auditor, 'A.5.15');
        $second = $this->complianceRequirement($source, 'Tilgangsrettigheter', $auditor, 'A.5.18');
        $third = $this->complianceRequirement($source, 'Autentisering', $auditor, 'A.8.5');

        $props = $this->props($auditor, $audit);
        $this->assertSame([], $props['requirements']);
        $this->assertTrue($props['permissions']['can_manage_requirements']);
        $this->assertSame([$first->id, $second->id, $third->id], array_column($props['requirement_options']['requirements'], 'id'));

        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$first->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Kravet er lagt til i scope.');
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$second->id, $third->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 krav er lagt til i scope.');

        $this->assertDatabaseHas('compliance_audit_requirements', ['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $first->id, 'created_by' => $auditor->id]);
        // Only ids: nothing about the requirement is copied onto the link, or onto the audit.
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'audit_id', 'requirement_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('compliance_audit_requirements'),
        );

        // Read live, in the register's order.
        $first->update(['title' => 'Tilgangsstyring v2']);
        $props = $this->props($auditor, $audit);
        $this->assertSame(['A.5.15', 'A.5.18', 'A.8.5'], array_column($props['requirements'], 'reference'));
        $this->assertSame('Tilgangsstyring v2', $props['requirements'][0]['title']);
        $this->assertSame('ISO 27001 (2022)', $props['requirements'][0]['source_label']);
        $this->assertSame(route('app.compliance.requirements.show', ['requirementId' => $first->id]), $props['requirements'][0]['url']);
        $this->assertSame([], $props['requirement_options']['requirements']);

        // Out of scope again: the requirement is left as it was.
        $this->actingAs($auditor)->delete($this->url($audit, "requirements/{$second->id}"))->assertSessionHas('success', 'Kravet er fjernet fra scope.');
        $this->assertSame(2, ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->count());
        $this->assertNotNull($second->fresh());
        $this->actingAs($auditor)->delete($this->url($audit, "requirements/{$second->id}"))->assertNotFound();
    }

    public function test_a_duplicate_is_refused_all_or_nothing(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $source = $this->complianceSource($customer);
        $first = $this->complianceRequirement($source, 'Første');
        $second = $this->complianceRequirement($source, 'Andre');
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$first->id]])->assertSessionHasNoErrors();

        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$second->id, $first->id]])
            ->assertSessionHasErrors(['requirement_ids' => 'Ett eller flere av kravene er allerede i scope.']);
        $this->assertSame([$first->id], ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all());

        // The same id twice in one request is one row.
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$second->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->count());

        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => []])->assertSessionHasErrors('requirement_ids');

        $this->assertRefused('a duplicate row', fn () => ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $first->id]));
    }

    public function test_another_customers_requirement_cannot_enter_scope(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $own = $this->complianceRequirement($this->complianceSource($customer), 'Eget krav');
        $foreignSource = $this->complianceSource($foreign, 'Fremmed kilde');
        $foreignRequirement = $this->complianceRequirement($foreignSource, 'Fremmed krav');

        $this->assertSame([$own->id], array_column($this->props($auditor, $audit)['requirement_options']['requirements'], 'id'));
        $this->assertNotContains($foreignSource->id, array_column($this->props($auditor, $audit)['requirement_options']['sources'], 'id'));

        foreach ([[$foreignRequirement->id], [$own->id, $foreignRequirement->id], [999999999]] as $ids) {
            $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => $ids])
                ->assertSessionHasErrors(['requirement_ids' => 'Velg krav fra listen.']);
        }
        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $foreignSource->id])
            ->assertSessionHasErrors(['source_id' => 'Velg en kravkilde fra listen.']);
        $this->assertSame(0, ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->count());

        // The database refuses it on its own.
        $this->assertRefused('a cross-tenant scope row', fn () => ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $foreignRequirement->id]));
        $this->assertRefused('a row in the other customer\'s name', fn () => ComplianceAuditRequirement::query()->create(['customer_id' => $foreign->id, 'audit_id' => $audit->id, 'requirement_id' => $foreignRequirement->id]));
    }

    public function test_the_source_shortcut_writes_fixed_rows_and_never_follows_the_source(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $source = $this->complianceSource($customer);
        $other = $this->complianceSource($customer, 'Personopplysningsloven', null, 'law');
        $already = $this->complianceRequirement($source, 'Allerede i scope', null, 'A.1');
        $active = $this->complianceRequirement($source, 'Aktivt', null, 'A.2');
        $retired = $this->complianceRequirement($source, 'Utgått', null, 'A.3');
        app(ComplianceRequirementLifecycleService::class)->retire($retired, $auditor, 'Erstattet.');
        $elsewhere = $this->complianceRequirement($other, 'Annen kilde');
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$already->id]])->assertSessionHasNoErrors();

        // Offered with the number of active requirements it would add.
        $sources = collect($this->props($auditor, $audit)['requirement_options']['sources'])->keyBy('id');
        $this->assertSame(1, $sources[$source->id]['count']);
        $this->assertSame('ISO 27001 (2022)', $sources[$source->id]['label']);

        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Kravet er lagt til i scope.');

        $inScope = fn (): array => ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->orderBy('requirement_id')->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all();
        // The active one; not the retired one, not one of another source.
        $this->assertSame([$already->id, $active->id], $inScope());
        $this->assertNotContains($elsewhere->id, $inScope());

        // Nothing more to add from it.
        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source->id])
            ->assertSessionHasErrors(['source_id' => 'Kravkilden har ingen aktive krav som ikke allerede er i scope.']);

        // A requirement added to the source later stays out until someone adds it.
        $this->complianceRequirement($source, 'Nytt i kilden', null, 'A.4');
        $this->assertSame([$already->id, $active->id], $inScope());
        // No source is remembered anywhere that could pull it in.
        $this->assertFalse(Schema::hasColumn('compliance_audits', 'source_id'));
        $this->assertFalse(Schema::hasColumn('compliance_audit_requirements', 'source_id'));
    }

    public function test_a_retired_requirement_can_be_in_scope_and_is_shown_as_retired(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $source = $this->complianceSource($customer);
        $historic = $this->complianceRequirement($source, 'Tidligere krav', null, 'A.9');
        $later = $this->complianceRequirement($source, 'Utgår etterpå', null, 'A.10');
        app(ComplianceRequirementLifecycleService::class)->retire($historic, $auditor, 'Erstattet av ny versjon.');

        // Offered, after the active ones, and marked.
        $options = $this->props($auditor, $audit)['requirement_options']['requirements'];
        $this->assertSame([$later->id, $historic->id], array_column($options, 'id'));
        $this->assertSame('retired', $options[1]['status']);

        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$historic->id, $later->id]])->assertSessionHasNoErrors();
        app(ComplianceRequirementLifecycleService::class)->retire($later, $auditor, 'Gjelder ikke lenger.');

        // Both stay in scope, both shown as retired — nothing removed them.
        $requirements = $this->props($auditor, $audit)['requirements'];
        $this->assertSame([$later->id, $historic->id], array_column($requirements, 'id'));
        $this->assertSame(['retired', 'retired'], array_column($requirements, 'status'));
    }

    public function test_a_requirement_in_scope_cannot_be_deleted_out_from_under_the_audit(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $manager = $this->complianceManager($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'I scope', $auditor);
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$requirement->id]])->assertSessionHasNoErrors();

        $this->assertFalse($requirement->isDeletable());
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertSessionHas('error');
        $this->assertNotNull($requirement->fresh());
        $this->assertRefused('deleting a requirement in scope', fn () => DB::table('compliance_requirements')->where('id', $requirement->id)->delete());

        // Out of scope again, it is a requirement registered by mistake like any other.
        $this->actingAs($auditor)->delete($this->url($audit, "requirements/{$requirement->id}"))->assertSessionHasNoErrors();
        $this->actingAs($manager)->delete("/app/compliance/requirements/{$requirement->id}")->assertSessionHasNoErrors();
        $this->assertNull($requirement->fresh());
    }

    public function test_the_scope_changes_only_while_the_audit_is_planned_or_in_progress(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $source = $this->complianceSource($customer);
        $inScope = $this->complianceRequirement($source, 'I scope');
        $candidate = $this->complianceRequirement($source, 'Kandidat');
        $process = $this->process($customer, 'Tilgangsprosess');
        $other = $this->process($customer, 'Annen prosess');
        $lifecycle = app(ComplianceAuditLifecycleService::class);

        $completed = $this->complianceAudit($customer, $auditor, 'Fullført');
        $cancelled = $this->complianceAudit($customer, $auditor, 'Avbrutt');
        foreach ([$completed, $cancelled] as $audit) {
            ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $inScope->id]);
            ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]);
        }
        $lifecycle->start($completed, $auditor);
        $lifecycle->complete($completed, $auditor, 'Ferdig.');
        $lifecycle->cancel($cancelled, $auditor, 'Avlyst.');

        foreach ([$completed, $cancelled] as $audit) {
            $props = $this->props($auditor, $audit);
            // Shown as it was, and nothing to change it with.
            $this->assertSame([$inScope->id], array_column($props['requirements'], 'id'));
            $this->assertSame([$process->id], array_column($props['processes'], 'id'));
            $this->assertFalse($props['permissions']['can_manage_requirements']);
            $this->assertFalse($props['permissions']['can_manage_processes']);
            $this->assertNull($props['requirement_options']);
            $this->assertNull($props['process_options']);

            $locked = 'Scope kan bare endres mens revisjonen er planlagt eller under arbeid.';
            $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$candidate->id]])->assertSessionHasErrors(['audit' => $locked]);
            $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source->id])->assertSessionHasErrors(['audit' => $locked]);
            $this->actingAs($auditor)->delete($this->url($audit, "requirements/{$inScope->id}"))->assertSessionHasErrors(['audit' => $locked]);
            $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $other->id])->assertSessionHasErrors(['audit' => $locked]);
            $this->actingAs($auditor)->delete($this->url($audit, "processes/{$process->id}"))->assertSessionHasErrors(['audit' => $locked]);

            $this->assertSame(1, ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->count());
            $this->assertSame(1, ComplianceAuditProcess::query()->where('audit_id', $audit->id)->count());
        }

        // Reopened, the scope is open again.
        $lifecycle->reopen($completed, $auditor, 'Mangler et krav.');
        $this->actingAs($auditor)->post($this->url($completed, 'requirements'), ['requirement_ids' => [$candidate->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, ComplianceAuditRequirement::query()->where('audit_id', $completed->id)->count());
    }

    // ---------------------------------------------------------------------
    // Processes
    // ---------------------------------------------------------------------

    public function test_an_auditor_with_kvalitet_adds_and_removes_a_process_without_touching_it(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor);
        $process = $this->process($customer, 'Tilgangsprosess');

        $props = $this->props($auditor, $audit);
        $this->assertSame([], $props['processes']);
        $this->assertTrue($props['permissions']['can_manage_processes']);
        $this->assertSame([$process->id], array_column($props['process_options'], 'id'));

        $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $process->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Prosessen er lagt til i scope.');
        $this->assertDatabaseHas('compliance_audit_processes', ['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id, 'created_by' => $auditor->id]);
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'audit_id', 'quality_process_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('compliance_audit_processes'),
        );

        // Read live from Kvalitet.
        $process->update(['title' => 'Tilgangsprosess v2']);
        $props = $this->props($auditor, $audit);
        $this->assertSame('Tilgangsprosess v2', $props['processes'][0]['title']);
        $this->assertSame(route('app.quality.items.show', ['item' => $process->id]), $props['processes'][0]['url']);
        $this->assertSame([], $props['process_options']);

        $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $process->id])
            ->assertSessionHasErrors(['quality_process_id' => 'Prosessen er allerede i scope.']);
        $this->assertRefused('a duplicate process row', fn () => ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]));

        $this->actingAs($auditor)->delete($this->url($audit, "processes/{$process->id}"))->assertSessionHas('success', 'Prosessen er fjernet fra scope.');
        $this->assertDatabaseMissing('compliance_audit_processes', ['audit_id' => $audit->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $process->id, 'title' => 'Tilgangsprosess v2', 'quality_type' => 'process']);
        $this->actingAs($auditor)->delete($this->url($audit, "processes/{$process->id}"))->assertNotFound();
    }

    public function test_only_a_non_retired_process_of_the_own_customer_can_be_added(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor);
        $retired = $this->process($customer, 'Gammel prosess', QualityItem::STATUS_RETIRED);
        $control = QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_CONTROL, 'title' => 'Kontroll, ikke prosess', 'status' => QualityItem::STATUS_ACTIVE]);
        $foreignProcess = $this->process($foreign, 'Fremmed prosess');

        $this->assertSame([], $this->props($auditor, $audit)['process_options']);

        foreach ([$retired->id, $control->id, $foreignProcess->id, 999999999] as $id) {
            $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $id])
                ->assertSessionHasErrors(['quality_process_id' => 'Velg en prosess fra listen.']);
        }
        $this->assertSame(0, ComplianceAuditProcess::query()->where('audit_id', $audit->id)->count());
        $this->assertRefused('a cross-tenant process row', fn () => ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $foreignProcess->id]));
    }

    public function test_without_kvalitet_the_processes_in_scope_are_invisible_and_cannot_be_changed(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $hidden = $this->process($customer, 'Hemmelig prosess '.Str::random(6));
        $candidate = $this->process($customer, 'Hemmelig kandidat '.Str::random(6));
        ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $hidden->id]);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav i scope');

        $props = $this->props($auditor, $audit);
        $this->assertNull($props['processes']);
        $this->assertNull($props['process_options']);
        $this->assertFalse($props['permissions']['can_manage_processes']);
        $json = json_encode(array_diff_key($props, ['translations' => true]), JSON_UNESCAPED_UNICODE);
        foreach ([$hidden, $candidate] as $process) {
            $this->assertStringNotContainsString($process->title, $json);
            $this->assertStringNotContainsString('/app/quality/items/'.$process->id, $json);
        }
        $this->assertStringNotContainsString('Hemmelig', $json);
        // Nor in the register.
        $this->assertStringNotContainsString('Hemmelig', json_encode(array_diff_key($this->indexProps($auditor), ['translations' => true]), JSON_UNESCAPED_UNICODE));

        $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $candidate->id])->assertForbidden();
        $this->actingAs($auditor)->delete($this->url($audit, "processes/{$hidden->id}"))->assertForbidden();
        // The same 403 whatever id is sent: no probing.
        $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => 999999999])->assertForbidden();
        $this->assertSame(1, ComplianceAuditProcess::query()->where('audit_id', $audit->id)->count());

        // The scope description and the requirements work as before.
        $this->assertSame('Tilgangsstyring og brukeradministrasjon for IT-avdelingen.', $props['audit']['scope_description']);
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$requirement->id]])->assertSessionHasNoErrors();
        $this->assertSame([$requirement->id], array_column($this->props($auditor, $audit)['requirements'], 'id'));
    }

    public function test_system_owner_with_an_audit_role_but_no_kvalitet_module_sees_no_process(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $audit = $this->complianceAudit($customer, $owner);
        $process = $this->process($customer, 'Prosess uten modul '.Str::random(6));
        ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]);
        $this->complianceGrant($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_AUDIT]);

        // System Owner reads Kvalitet through the administrator role while the customer holds it.
        $this->assertSame([$process->id], array_column($this->props($owner, $audit)['processes'], 'id'));

        // The customer holds Etterlevelse og revisjon but not Kvalitet.
        config()->set('procynia_modules.packages.iso.modules', ['risk', 'objectives', 'improvements', 'compliance']);

        $props = $this->props($owner, $audit);
        $this->assertNull($props['processes']);
        $this->assertNull($props['process_options']);
        $this->assertStringNotContainsString($process->title, json_encode(array_diff_key($props, ['translations' => true]), JSON_UNESCAPED_UNICODE));
        $this->actingAs($owner)->delete($this->url($audit, "processes/{$process->id}"))->assertForbidden();
    }

    public function test_kvalitet_never_sees_the_audit_and_a_deleted_process_takes_its_link_along(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor, 'Revisjon som Kvalitet ikke ser');
        $process = $this->process($customer, 'Prosess');
        ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]);

        $page = $this->actingAs($auditor)->get("/app/quality/items/{$process->id}")->assertOk()->viewData('page')['props'];
        $this->assertStringNotContainsString('Revisjon som Kvalitet ikke ser', json_encode(array_diff_key($page, ['translations' => true]), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('/app/compliance/audits', json_encode(array_diff_key($page, ['translations' => true]), JSON_UNESCAPED_UNICODE));

        $process->delete();
        $this->assertSame(0, ComplianceAuditProcess::query()->where('audit_id', $audit->id)->count());
        $this->assertNotNull($audit->fresh());
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_view_or_edit_without_audit_cannot_change_the_scope(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $editor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT, CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $editor);
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Krav');
        $process = $this->process($customer, 'Prosess');
        ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $requirement->id]);
        ComplianceAuditProcess::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'quality_process_id' => $process->id]);

        // Reads everything, including the processes — compliance.view and Kvalitet both hold.
        $props = $this->props($editor, $audit);
        $this->assertSame([$requirement->id], array_column($props['requirements'], 'id'));
        $this->assertSame([$process->id], array_column($props['processes'], 'id'));
        $this->assertNull($props['requirement_options']);
        $this->assertNull($props['process_options']);

        $this->actingAs($editor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$requirement->id]])->assertForbidden();
        $this->actingAs($editor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source->id])->assertForbidden();
        $this->actingAs($editor)->delete($this->url($audit, "requirements/{$requirement->id}"))->assertForbidden();
        $this->actingAs($editor)->post($this->url($audit, 'processes'), ['quality_process_id' => $process->id])->assertForbidden();
        $this->actingAs($editor)->delete($this->url($audit, "processes/{$process->id}"))->assertForbidden();

        $this->assertSame(1, ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->count());
        $this->assertSame(1, ComplianceAuditProcess::query()->where('audit_id', $audit->id)->count());
    }

    public function test_a_customer_leaving_takes_its_audits_and_scope_along(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $audit = $this->complianceAudit($customer, $auditor);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Krav');
        $process = $this->process($customer, 'Prosess');
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$requirement->id]])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post($this->url($audit, 'processes'), ['quality_process_id' => $process->id])->assertSessionHasNoErrors();
        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);

        Customer::query()->whereKey($customer->id)->delete();

        foreach (['compliance_audits', 'compliance_audit_status_changes', 'compliance_audit_requirements', 'compliance_audit_processes'] as $table) {
            $this->assertSame(0, DB::table($table)->where('customer_id', $customer->id)->count(), $table);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function process(Customer $customer, string $title, string $status = QualityItem::STATUS_ACTIVE): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
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

    private function url(ComplianceAudit $audit, string $action): string
    {
        return "/app/compliance/audits/{$audit->id}/{$action}";
    }

    /** @return array<string, mixed> */
    private function props(User $user, ComplianceAudit $audit): array
    {
        return $this->actingAs($user)->get("/app/compliance/audits/{$audit->id}")->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function indexProps(User $user): array
    {
        return $this->actingAs($user)->get('/app/compliance/audits')->assertOk()->viewData('page')['props'];
    }
}
