<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditCheck;
use App\Models\ComplianceAuditCheckRequirement;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceFramework;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAssessmentService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\Library\ComplianceApplicabilityService;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Compliance\Library\ComplianceFrameworkLibraryImporter;
use App\Services\EnterpriseWiki\Knowledge\Sources\ComplianceAuditKnowledgeSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Revisjon 2.0: planning in one step, the automatic selection that takes only what applies, and
 * kontrollhandlinger — what an audit examined, documented even when nothing was found.
 *
 * What these tests defend:
 *
 *  - «Legg til fra kravkilde» (and the sources chosen on Ny revisjon) takes only requirements that
 *    apply: never Ikke relevant, never relevance open, an Annex A control only when Inkludert — and
 *    the count offered is exactly what is added. An explicit choice may still take any of them, and
 *    the scope says where each stands.
 *  - Ny revisjon carries purpose, omfang and the requirements in one step, all or none.
 *  - One check may cover several requirements, or none, and exist without findings; several findings
 *    may come from one check. Only requirements in scope, only checks of the same audit, only while
 *    in progress, only with compliance.audit; another customer's ids get a 404.
 *  - A check with findings is not deleted; a requirement a check names stays in scope; a handed-off
 *    finding's check is frozen. The database holds each of those too.
 *  - Nothing about an audit changes an assessment, and an audit from before has no checks and still
 *    opens as it was.
 */
class ComplianceAuditChecksTest extends TestCase
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
    // Automatic selection
    // ---------------------------------------------------------------------

    public function test_the_source_shortcut_takes_only_annex_a_controls_that_are_included(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements] = $this->activated('iso27001:2022');
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);

        $controls = $requirements->filter(fn (ComplianceRequirement $requirement): bool => str_starts_with((string) $requirement->reference, 'A.'));
        $clauses = $requirements->reject(fn (ComplianceRequirement $requirement): bool => str_starts_with((string) $requirement->reference, 'A.'));
        $this->assertNotEmpty($controls);
        $included = $requirements['A.5.19'];
        $excluded = $requirements['A.5.20'];
        app(ComplianceApplicabilityService::class)->set($included, $editor, ComplianceRequirement::APPLICABILITY_APPLIES, 'Leverandører behandler informasjon.');
        app(ComplianceApplicabilityService::class)->set($excluded, $editor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Ingen leverandøravtaler med informasjonssikkerhetskrav.');
        $notChosen = $requirements['A.5.21'];
        $this->assertSame(ComplianceRequirement::APPLICABILITY_NOT_ASSESSED, $notChosen->fresh()->applicability);

        // Offered with exactly the number it would add: the clauses and the one control Inkludert.
        $applies = $clauses->filter(fn (ComplianceRequirement $requirement): bool => $requirement->applicability === ComplianceRequirement::APPLICABILITY_APPLIES)->count() + 1;
        $source = collect($this->props($auditor, $audit)['requirement_options']['sources'])->sole();
        $this->assertSame($applies, $source['count']);

        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source['id']])->assertSessionHasNoErrors();

        $inScope = $this->scopeIds($audit);
        $this->assertCount($applies, $inScope);
        $this->assertContains((int) $included->id, $inScope);
        $this->assertNotContains((int) $excluded->id, $inScope);
        $this->assertNotContains((int) $notChosen->id, $inScope);
        // No other control: neither Utelatt nor not chosen yet.
        $this->assertSame([], $controls->filter(fn (ComplianceRequirement $control): bool => $control->reference !== 'A.5.19' && in_array((int) $control->id, $inScope, true))
            ->pluck('reference')->values()->all());

        // Nothing more applies, so the source is no longer offered, and the shortcut refuses.
        $this->assertSame([], $this->props($auditor, $audit)['requirement_options']['sources']);
        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source['id']])
            ->assertSessionHasErrors(['source_id' => 'Kravkilden har ingen krav som gjelder virksomheten og ikke allerede er i omfanget.']);

        // An explicit choice still may — and the scope says where it stands.
        $this->actingAs($auditor)->post($this->url($audit, 'requirements'), ['requirement_ids' => [$excluded->id, $notChosen->id]])->assertSessionHasNoErrors();
        $rows = collect($this->props($auditor, $audit)['requirements'])->keyBy('id');
        $this->assertSame(['not_applicable', true], [$rows[$excluded->id]['relevance'], $rows[$excluded->id]['is_control']]);
        $this->assertSame(['relevance_open', true], [$rows[$notChosen->id]['relevance'], $rows[$notChosen->id]['is_control']]);
        $this->assertSame(['applies', true], [$rows[$included->id]['relevance'], $rows[$included->id]['is_control']]);
        $this->assertSame('ISO/IEC 27001:2022', $rows[$included->id]['origin']['source']);
        $this->assertSame('A.5.19', $rows[$included->id]['origin']['reference']);
    }

    public function test_the_source_shortcut_leaves_out_what_is_not_relevant_or_still_open(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $requirements] = $this->activated('iso9001:2015');
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);

        $conditional = $requirements->filter(fn (ComplianceRequirement $requirement): bool => $requirement->applicability === ComplianceRequirement::APPLICABILITY_NOT_ASSESSED)->values();
        $this->assertGreaterThanOrEqual(2, $conditional->count());
        [$notRelevant, $applies] = [$conditional[0], $conditional[1]];
        app(ComplianceApplicabilityService::class)->set($notRelevant, $editor, ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE, 'Vi har ingen slike målinger.');
        app(ComplianceApplicabilityService::class)->set($applies, $editor, ComplianceRequirement::APPLICABILITY_APPLIES, 'Gjelder oss.');

        $sourceId = (int) $requirements['8.4.1']->source_id;
        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $sourceId])->assertSessionHasNoErrors();

        $inScope = $this->scopeIds($audit);
        $this->assertContains((int) $requirements['8.4.1']->id, $inScope);
        $this->assertContains((int) $applies->id, $inScope);
        $this->assertNotContains((int) $notRelevant->id, $inScope);
        foreach ($conditional->slice(2) as $open) {
            $this->assertNotContains((int) $open->id, $inScope, "{$open->reference} has open relevance and must not be added automatically.");
        }
    }

    public function test_a_requirement_assessed_not_relevant_by_hand_is_left_out_too(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $assessor = $this->complianceManager($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $source = $this->complianceSource($customer, 'Internt regelverk', null, 'internal');
        $applies = $this->complianceRequirement($source, 'Gjelder', null, 'R.1');
        $notRelevant = $this->complianceRequirement($source, 'Gjelder ikke oss', null, 'R.2');
        app(ComplianceAssessmentService::class)->assess($notRelevant, $assessor, ComplianceAssessment::RESULT_NOT_APPLICABLE, 'Vi har ingen slik virksomhet.');

        $this->assertSame(1, collect($this->props($auditor, $audit)['requirement_options']['sources'])->sole()['count']);
        $this->actingAs($auditor)->post($this->url($audit, 'requirements/from-source'), ['source_id' => $source->id])->assertSessionHasNoErrors();

        $this->assertSame([(int) $applies->id], $this->scopeIds($audit));
    }

    // ---------------------------------------------------------------------
    // Planning
    // ---------------------------------------------------------------------

    public function test_an_audit_is_planned_with_purpose_omfang_and_requirements_in_one_step(): void
    {
        ['customer' => $customer, 'editor' => $editor, 'requirements' => $iso27001] = $this->activated('iso27001:2022');
        $iso9001 = $this->activate($customer, $editor, 'iso9001:2015');
        app(ComplianceApplicabilityService::class)->set($iso27001['A.5.19'], $editor, ComplianceRequirement::APPLICABILITY_APPLIES, 'Leverandører behandler informasjon.');
        $auditor = $this->complianceAuditor($customer);

        // Ny revisjon offers the requirements and sources before the audit exists.
        $options = $this->actingAs($auditor)->get('/app/compliance/audits')->assertOk()->viewData('page')['props']['requirement_options'];
        $this->assertContains((int) $iso9001['8.4.1']->id, array_column($options['requirements'], 'id'));
        $this->assertCount(2, $options['sources']);

        $this->actingAs($auditor)->post('/app/compliance/audits', [
            'purpose' => 'Kontrollere at leverandørstyringen etterlever relevante krav.',
            'scope_description' => 'Innkjøpsprosessen og leverandøroppfølging.',
            'requirement_ids' => [$iso9001['8.4.1']->id, $iso27001['A.5.19']->id],
        ] + $this->auditPayload($auditor, 'Internrevisjon av leverandørstyring'))->assertSessionHasNoErrors()->assertRedirect();

        $audit = ComplianceAudit::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('Kontrollere at leverandørstyringen etterlever relevante krav.', $audit->purpose);
        $this->assertSame('Innkjøpsprosessen og leverandøroppfølging.', $audit->scope_description);
        $this->assertEqualsCanonicalizing([(int) $iso9001['8.4.1']->id, (int) $iso27001['A.5.19']->id], $this->scopeIds($audit));

        $props = $this->props($auditor, $audit);
        $this->assertSame('Kontrollere at leverandørstyringen etterlever relevante krav.', $props['audit']['purpose']);
        $this->assertEqualsCanonicalizing(['ISO 9001:2015 · 8.4.1', 'ISO/IEC 27001:2022 · A.5.19'], array_map(
            fn (array $row): string => $row['origin']['source'].' · '.$row['origin']['reference'],
            $props['requirements'],
        ));
    }

    public function test_sources_chosen_on_ny_revisjon_follow_the_automatic_rules_and_all_or_none(): void
    {
        ['customer' => $customer, 'requirements' => $requirements] = $this->activated('iso27001:2022');
        $auditor = $this->complianceAuditor($customer);
        $sourceId = (int) $requirements['A.5.19']->source_id;

        $this->actingAs($auditor)->post('/app/compliance/audits', [
            'source_ids' => [$sourceId],
            // Explicitly as well: an Annex A control not chosen yet, deliberately.
            'requirement_ids' => [$requirements['A.5.21']->id],
        ] + $this->auditPayload($auditor))->assertSessionHasNoErrors();

        $audit = ComplianceAudit::query()->where('customer_id', $customer->id)->sole();
        $inScope = $this->scopeIds($audit);
        $this->assertContains((int) $requirements['A.5.21']->id, $inScope);
        $this->assertNotContains((int) $requirements['A.5.19']->id, $inScope);
        $this->assertContains((int) $requirements['9.2.1']->id, $inScope);

        // A source of another customer, or one with nothing to add: no audit at all.
        ['customer' => $foreign] = $this->complianceContext();
        $foreignSource = $this->complianceSource($foreign);
        $this->complianceRequirement($foreignSource, 'Fremmed');
        $empty = $this->complianceSource($customer, 'Tom kilde', null, 'internal');
        foreach ([[$foreignSource->id], [$empty->id]] as $sources) {
            $this->actingAs($auditor)->post('/app/compliance/audits', ['source_ids' => $sources] + $this->auditPayload($auditor, 'Skal ikke finnes'))
                ->assertSessionHasErrors('source_ids');
        }
        $this->actingAs($auditor)->post('/app/compliance/audits', ['requirement_ids' => [999999999]] + $this->auditPayload($auditor, 'Skal ikke finnes'))
            ->assertSessionHasErrors('requirement_ids');
        $this->assertSame(0, ComplianceAudit::query()->where('title', 'Skal ikke finnes')->count());
    }

    // ---------------------------------------------------------------------
    // Kontrollhandlinger
    // ---------------------------------------------------------------------

    public function test_one_check_covers_two_standards_and_documents_an_audit_without_findings(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit, 'first' => $first, 'second' => $second, 'outside' => $outside] = $this->running();

        $this->actingAs($auditor)->post($this->url($audit, 'checks'), [
            'title' => 'Gjennomgang av leverandørvurderinger',
            'observation' => 'Tre leverandørvurderinger fra inneværende år ble gjennomgått. Innkjøpsansvarlig ble intervjuet. Alle tre var dokumentert.',
            'evidence' => 'Leverandørregister 2026, Wiki: Leverandøroppfølging',
            'requirement_ids' => [$first->id, $second->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Kontrollhandlingen er registrert.');

        $check = ComplianceAuditCheck::query()->where('audit_id', $audit->id)->sole();
        $this->assertSame((int) $customer->id, (int) $check->customer_id);
        $this->assertSame((int) $auditor->id, (int) $check->created_by);
        $this->assertEqualsCanonicalizing([(int) $first->id, (int) $second->id], $check->requirementLinks()->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all());

        $props = $this->props($auditor, $audit);
        $row = $props['checks'][0];
        $this->assertSame('Gjennomgang av leverandørvurderinger', $row['title']);
        $this->assertSame('Leverandørregister 2026, Wiki: Leverandøroppfølging', $row['evidence']);
        $this->assertSame([], $row['findings']);
        $this->assertCount(2, $row['requirements']);
        // Each with its origin, ordered by it.
        $this->assertSame(['ISO 27001 (2022) · A.5.19', 'ISO 9001 (2015) · 8.4'], array_map(fn (array $requirement): string => $requirement['origin']['source'].' · '.$requirement['origin']['reference'], $row['requirements']));
        $this->assertTrue($row['permissions']['can_delete']);
        $this->assertTrue($props['permissions']['can_record_checks']);

        // Coverage: both requirements are examined; the third in scope is not.
        $coverage = collect($props['requirements'])->keyBy('id');
        $this->assertSame(1, $coverage[$first->id]['check_count']);
        $this->assertSame(1, $coverage[$second->id]['check_count']);
        $this->assertSame(0, $coverage[$outside->id]['check_count']);

        // Completing with no findings at all, and without every requirement checked.
        $this->actingAs($auditor)->post($this->url($audit, 'complete'), ['conclusion' => 'Leverandørstyringen etterleves. Ingen avvik.'])->assertSessionHasNoErrors();
        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $audit->fresh()->status);
        $this->assertSame(0, ComplianceAuditFinding::query()->where('audit_id', $audit->id)->count());

        // Locked with the audit.
        $this->actingAs($auditor)->post($this->url($audit, 'checks'), ['title' => 'Etter fullføring', 'observation' => 'x'])
            ->assertSessionHasErrors(['audit' => 'Kontrollhandlinger kan bare registreres, endres og slettes mens revisjonen er under arbeid.']);
        $this->assertFalse($this->props($auditor, $audit)['checks'][0]['permissions']['can_edit']);
    }

    public function test_a_check_without_requirements_is_fine_and_only_scope_requirements_can_be_named(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit, 'first' => $first] = $this->running();
        $notInScope = $this->complianceRequirement($this->complianceSource($customer, 'Annen kilde', null, 'internal'), 'Ikke i omfanget');

        $this->actingAs($auditor)->post($this->url($audit, 'checks'), [
            'title' => 'Gjennomgang av intern rutine for nøkkelhåndtering',
            'observation' => 'Rutinen er gjennomgått med driftsansvarlig.',
        ])->assertSessionHasNoErrors();
        $check = ComplianceAuditCheck::query()->where('audit_id', $audit->id)->sole();
        $this->assertNull($check->evidence);
        $this->assertSame(0, $check->requirementLinks()->count());

        $this->actingAs($auditor)->patch($this->url($audit, "checks/{$check->id}"), [
            'title' => 'Gjennomgang av intern rutine',
            'observation' => 'Rutinen er gjennomgått.',
            'requirement_ids' => [$first->id, $notInScope->id],
        ])->assertSessionHasErrors(['requirement_ids' => 'Velg krav fra revisjonens omfang.']);
        $this->assertSame('Gjennomgang av intern rutine for nøkkelhåndtering', $check->fresh()->title);

        $this->actingAs($auditor)->patch($this->url($audit, "checks/{$check->id}"), [
            'title' => 'Gjennomgang av intern rutine',
            'observation' => 'Rutinen er gjennomgått.',
            'requirement_ids' => [$first->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Kontrollhandlingen er oppdatert.');
        $this->assertSame([(int) $first->id], $check->requirementLinks()->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all());

        foreach (['title', 'observation'] as $field) {
            $this->actingAs($auditor)->post($this->url($audit, 'checks'), [$field => '   '] + ['title' => 'Tittel', 'observation' => 'Observert'])->assertSessionHasErrors($field);
        }

        // The database holds the scope rule and the text.
        $this->assertRefused('a requirement outside the scope', fn () => ComplianceAuditCheckRequirement::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $audit->id, 'check_id' => $check->id, 'requirement_id' => $notInScope->id,
        ]));
        $this->assertRefused('a blank observation', fn () => DB::table('compliance_audit_checks')->where('id', $check->id)->update(['observation' => ' ']));

        // A requirement a check names stays in scope until the check lets go of it.
        $this->actingAs($auditor)->delete($this->url($audit, "requirements/{$first->id}"))
            ->assertSessionHasErrors(['audit' => 'Kravet er knyttet til en kontrollhandling og kan ikke fjernes fra omfanget. Fjern kravet fra kontrollhandlingen først.']);
        $this->assertContains((int) $first->id, $this->scopeIds($audit));
        $this->assertRefused('taking a named requirement out of scope', fn () => ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->where('requirement_id', $first->id)->delete());
    }

    public function test_several_findings_come_from_one_check_and_keep_it(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit, 'first' => $first] = $this->running();
        $check = $this->check($audit, $auditor, [$first->id]);

        foreach ([ComplianceAuditFinding::TYPE_NONCONFORMITY => 'Vurdering mangler', ComplianceAuditFinding::TYPE_OPPORTUNITY => 'Mal for vurdering'] as $type => $title) {
            $this->actingAs($auditor)->post($this->url($audit, 'findings'), [
                'finding_type' => $type, 'title' => $title, 'description' => 'Beskrivelse', 'requirement_id' => $first->id, 'check_id' => $check->id,
            ])->assertSessionHasNoErrors();
        }

        $props = $this->props($auditor, $audit);
        $this->assertSame(['Vurdering mangler', 'Mal for vurdering'], array_column($props['checks'][0]['findings'], 'title'));
        $this->assertSame(['id' => (int) $check->id, 'title' => $check->title], $props['findings'][0]['check']);
        $this->assertSame([['id' => (int) $check->id, 'title' => $check->title]], $props['finding_options']['checks']);
        $this->assertSame(2, collect($props['requirements'])->firstWhere('id', $first->id)['finding_count']);

        // Not deleted while findings came from it — by the service and by the database.
        $this->assertFalse($props['checks'][0]['permissions']['can_delete']);
        $this->actingAs($auditor)->delete($this->url($audit, "checks/{$check->id}"))
            ->assertSessionHasErrors(['check' => 'Det er registrert funn fra kontrollhandlingen, så den kan ikke slettes.']);
        $this->assertRefused('deleting a check with findings', fn () => ComplianceAuditCheck::query()->whereKey($check->id)->delete());

        // A check of another audit is not offered and cannot be named.
        $other = $this->complianceAudit($customer, $auditor, 'Annen revisjon');
        app(ComplianceAuditLifecycleService::class)->start($other, $auditor);
        $otherCheck = $this->check($other, $auditor);
        $this->actingAs($auditor)->post($this->url($audit, 'findings'), [
            'finding_type' => ComplianceAuditFinding::TYPE_OBSERVATION, 'title' => 'Feil kontroll', 'description' => 'x', 'check_id' => $otherCheck->id,
        ])->assertSessionHasErrors(['check_id' => 'Velg en kontrollhandling fra listen.']);
        $this->assertRefused('a finding naming a check of another audit', fn () => DB::table('compliance_audit_findings')
            ->where('audit_id', $audit->id)->limit(1)->update(['check_id' => $otherCheck->id]));

        // A check with no findings is deleted, links and all.
        $this->actingAs($auditor)->delete($this->url($other, "checks/{$otherCheck->id}"))->assertSessionHasNoErrors()->assertSessionHas('success', 'Kontrollhandlingen er slettet.');
        $this->assertNull($otherCheck->fresh());
    }

    public function test_a_handed_off_findings_check_is_frozen(): void
    {
        ['auditor' => $auditor, 'audit' => $audit] = $this->running();
        $check = $this->check($audit, $auditor);
        $another = $this->check($audit, $auditor, [], 'Annen kontrollhandling');
        $finding = ComplianceAuditFinding::query()->create([
            'customer_id' => $audit->customer_id, 'audit_id' => $audit->id, 'check_id' => $check->id,
            'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY, 'title' => 'Avvik', 'description' => 'Beskrivelse',
        ]);
        $case = $this->improvementCase($audit);
        DB::table('compliance_audit_findings')->where('id', $finding->id)->update(['improvement_case_id' => $case, 'handed_off_at' => now()]);

        $this->assertRefused('moving a handed-off finding to another check', fn () => DB::table('compliance_audit_findings')->where('id', $finding->id)->update(['check_id' => $another->id]));
        $this->assertRefused('unlinking a handed-off finding from its check', fn () => DB::table('compliance_audit_findings')->where('id', $finding->id)->update(['check_id' => null]));
    }

    public function test_checks_are_tenant_bound_and_take_compliance_audit(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit] = $this->running();
        $check = $this->check($audit, $auditor);
        ['customer' => $foreignCustomer] = $this->complianceContext();
        $foreigner = $this->complianceAuditor($foreignCustomer);
        $foreignAudit = $this->complianceAudit($foreignCustomer, $foreigner, 'Fremmed revisjon');
        app(ComplianceAuditLifecycleService::class)->start($foreignAudit, $foreigner);

        // Another customer's audit and check: the same 404 as an id that does not exist.
        $this->actingAs($foreigner)->post($this->url($audit, 'checks'), ['title' => 'x', 'observation' => 'y'])->assertNotFound();
        $this->actingAs($foreigner)->patch($this->url($foreignAudit, "checks/{$check->id}"), ['title' => 'x', 'observation' => 'y'])->assertNotFound();
        $this->actingAs($foreigner)->delete($this->url($foreignAudit, "checks/{$check->id}"))->assertNotFound();
        $this->assertSame([], $this->props($foreigner, $foreignAudit)['checks']);

        // Reading takes compliance.view; recording takes compliance.audit.
        $reader = $this->complianceReader($customer);
        $editor = $this->complianceEditor($customer);
        $readerProps = $this->props($reader, $audit);
        $this->assertCount(1, $readerProps['checks']);
        $this->assertFalse($readerProps['permissions']['can_record_checks']);
        $this->assertFalse($readerProps['checks'][0]['permissions']['can_edit']);
        foreach ([$reader, $editor] as $user) {
            $this->actingAs($user)->post($this->url($audit, 'checks'), ['title' => 'x', 'observation' => 'y'])->assertForbidden();
            $this->actingAs($user)->patch($this->url($audit, "checks/{$check->id}"), ['title' => 'x', 'observation' => 'y'])->assertForbidden();
            $this->actingAs($user)->delete($this->url($audit, "checks/{$check->id}"))->assertForbidden();
        }
        $this->assertSame(1, ComplianceAuditCheck::query()->where('audit_id', $audit->id)->count());

        // The database holds the tenant line.
        $this->assertRefused('a check on another customer\'s audit', fn () => ComplianceAuditCheck::query()->create([
            'customer_id' => $foreignCustomer->id, 'audit_id' => $audit->id, 'title' => 'x', 'observation' => 'y',
        ]));

        // A customer leaving takes its checks and links along.
        Customer::query()->whereKey($customer->id)->delete();
        foreach (['compliance_audit_checks', 'compliance_audit_check_requirements'] as $table) {
            $this->assertSame(0, DB::table($table)->where('customer_id', $customer->id)->count(), $table);
        }
    }

    public function test_a_planned_audit_has_no_checks_yet(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);

        $this->assertFalse($this->props($auditor, $audit)['permissions']['can_record_checks']);
        $this->actingAs($auditor)->post($this->url($audit, 'checks'), ['title' => 'For tidlig', 'observation' => 'x'])
            ->assertSessionHasErrors('audit');
        $this->assertSame(0, ComplianceAuditCheck::query()->where('audit_id', $audit->id)->count());
    }

    // ---------------------------------------------------------------------
    // What stays as it was
    // ---------------------------------------------------------------------

    public function test_an_audit_from_before_opens_as_it_was_and_nothing_changes_an_assessment(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $assessor = $this->complianceManager($customer);
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Tilgangsstyring', null, 'A.5.15');
        app(ComplianceAssessmentService::class)->assess($requirement, $assessor, ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT, 'Delvis på plass.');
        $assessments = fn (): array => ComplianceAssessment::query()->where('requirement_id', $requirement->id)->orderBy('id')->get(['id', 'result', 'rationale'])->toArray();
        $before = $assessments();

        // As an audit registered before Revisjon 2.0 looks: scope text, no purpose, no checks.
        $audit = $this->complianceAudit($customer, $auditor);
        ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $requirement->id]);
        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);
        app(ComplianceAuditLifecycleService::class)->complete($audit, $auditor, 'Ingen vesentlige avvik.');

        $props = $this->props($auditor, $audit);
        $this->assertNull($props['audit']['purpose']);
        $this->assertSame('Tilgangsstyring og brukeradministrasjon for IT-avdelingen.', $props['audit']['scope_description']);
        $this->assertSame([], $props['checks']);
        $this->assertSame(0, $props['requirements'][0]['check_count']);
        $this->assertSame('Ingen vesentlige avvik.', $props['audit']['conclusion']);
        $this->assertCount(2, $props['status_history']);

        // Reopened, documented and completed again: the assessment is exactly as it was.
        app(ComplianceAuditLifecycleService::class)->reopen($audit, $auditor, 'Mangler dokumentasjon av undersøkelsen.');
        $this->check($audit, $auditor, [$requirement->id]);
        app(ComplianceAuditLifecycleService::class)->complete($audit, $auditor, 'Undersøkt og dokumentert.');
        $this->assertSame($before, $assessments());
    }

    public function test_the_wiki_draft_carries_purpose_and_what_was_examined(): void
    {
        ['auditor' => $auditor, 'audit' => $audit] = $this->running();
        $audit->forceFill(['purpose' => 'Kontrollere leverandørstyringen.'])->save();
        $this->check($audit, $auditor);

        $sections = collect(app(ComplianceAuditKnowledgeSource::class)->draft($auditor, $audit->fresh())->toArray()['sections'])->keyBy('key');
        $this->assertContains('Formål: Kontrollere leverandørstyringen.', $sections['about']['lines']);
        $this->assertSame('Kontrollhandlinger', $sections['checks']['heading']);
        $this->assertSame(['Gjennomgang av leverandørvurderinger — Tre vurderinger ble gjennomgått.'], $sections['checks']['lines']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * An audit in progress with three requirements in scope: one from each of two standards and a
     * third nobody examines.
     *
     * @return array{customer: Customer, auditor: User, audit: ComplianceAudit, first: ComplianceRequirement, second: ComplianceRequirement, outside: ComplianceRequirement}
     */
    private function running(): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor, 'Internrevisjon av leverandørstyring');
        $first = $this->complianceRequirement($this->complianceSource($customer, 'ISO 9001', '2015'), 'Styring av eksternt leverte prosesser', null, '8.4');
        $second = $this->complianceRequirement($this->complianceSource($customer), 'Informasjonssikkerhet i leverandørforhold', null, 'A.5.19');
        $outside = $this->complianceRequirement($this->complianceSource($customer, 'ISO 14001', '2026'), 'Miljøaspekter', null, '6.1.2');

        foreach ([$first, $second, $outside] as $requirement) {
            ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $requirement->id]);
        }

        app(ComplianceAuditLifecycleService::class)->start($audit, $auditor);

        return ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit->fresh(), 'first' => $first, 'second' => $second, 'outside' => $outside];
    }

    /** @param  list<int>  $requirementIds */
    private function check(ComplianceAudit $audit, User $auditor, array $requirementIds = [], string $title = 'Gjennomgang av leverandørvurderinger'): ComplianceAuditCheck
    {
        $this->actingAs($auditor)->post($this->url($audit, 'checks'), [
            'title' => $title,
            'observation' => 'Tre vurderinger ble gjennomgått.',
            'requirement_ids' => $requirementIds,
        ])->assertSessionHasNoErrors();

        return ComplianceAuditCheck::query()->where('audit_id', $audit->id)->where('title', $title)->latest('id')->firstOrFail();
    }

    /** An improvement case of the audit's customer, to hand a finding off to — written directly. */
    private function improvementCase(ComplianceAudit $audit): int
    {
        $area = DB::table('business_areas')->insertGetId(['customer_id' => $audit->customer_id, 'name' => 'Område', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('improvement_cases')->insertGetId([
            'customer_id' => $audit->customer_id, 'business_area_id' => $area, 'type' => 'deviation',
            'title' => 'Sak', 'description' => 'Beskrivelse', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{customer: Customer, editor: User, requirements: Collection<string, ComplianceRequirement>} */
    private function activated(string $key): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $editor = $this->complianceEditor($customer);

        return ['customer' => $customer, 'editor' => $editor, 'requirements' => $this->activate($customer, $editor, $key)];
    }

    /** @return Collection<string, ComplianceRequirement> the standard's requirements in the customer's register, by reference */
    private function activate(Customer $customer, User $editor, string $key): Collection
    {
        $file = ['iso9001:2015' => 'iso9001_2015.php', 'iso27001:2022' => 'iso27001_2022.php'][$key];
        $framework = ComplianceFramework::query()->where('key', $key)->first()
            ?? app(ComplianceFrameworkLibraryImporter::class)->import(ComplianceFrameworkLibraryImporter::definitionFromFile(database_path("data/compliance_frameworks/{$file}")))['framework'];
        app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);

        return ComplianceRequirement::query()
            ->where('customer_id', $customer->id)
            ->whereHas('frameworkNode', fn ($query) => $query->where('framework_id', $framework->id))
            ->get()
            ->keyBy('reference');
    }

    /** @return list<int> */
    private function scopeIds(ComplianceAudit $audit): array
    {
        return ComplianceAuditRequirement::query()->where('audit_id', $audit->id)->orderBy('requirement_id')->pluck('requirement_id')->map(fn ($id): int => (int) $id)->all();
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
}
