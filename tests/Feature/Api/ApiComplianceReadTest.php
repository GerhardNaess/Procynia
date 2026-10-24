<?php

namespace Tests\Feature\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditCheck;
use App\Models\ComplianceAuditCheckRequirement;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditRequirement;
use App\Models\ComplianceFramework;
use App\Models\ComplianceRequirement;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Compliance\Library\ComplianceFrameworkActivationService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Database\Seeders\ComplianceFrameworkLibrarySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Etterlevelse og revisjon through the API (ETR-01–05): the kravregister, the status per source,
 * the revisjoner with scope, kontrollhandlinger and findings, the requirement library and the
 * attention codes — what ComplianceAccessService lets the identity read (its customer, the module and
 * compliance.view through a role of its own), in structured fields only, without writing anything.
 */
class ApiComplianceReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use CreatesComplianceScenarios;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const REQUIREMENT_KEYS = [
        'id', 'reference', 'title', 'status', 'source', 'from_library', 'is_control', 'applicability', 'owner',
        'review_interval_months', 'compliance', 'attention', 'created_at', 'updated_at',
    ];

    private const AUDIT_KEYS = [
        'id', 'title', 'audit_type', 'status', 'responsible', 'planned_start_date', 'planned_end_date', 'counts',
        'attention', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_the_kravregister_and_one_requirement_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $owner = $this->complianceMember($customer, 'Eva Eier');
        $assessor = $this->complianceMember($customer, 'Vera Vurderer');
        $source = $this->complianceSource($customer, 'Personvernforordningen', null, 'law');
        $source->forceFill(['description' => 'FRITEKST-KILDE'])->save();
        $assessed = $this->complianceRequirement($source, 'Behandlingsprotokoll', $owner, 'Art. 30', 12);
        $earlier = $this->assess($assessed, $assessor, ComplianceAssessment::RESULT_NON_COMPLIANT, now()->subMonths(14));
        $latest = $this->assess($assessed, $assessor, ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT, now()->subMonths(13));
        $unassessed = $this->complianceRequirement($source, 'Personvernombud');
        $this->complianceRequirement($this->complianceSource($this->customerWithCompliance('Annen kunde')), 'Annen kundes krav');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $list = $this->get('/api/v1/compliance/requirements', $headers);

        $list->assertOk();
        $list->assertExactJsonStructure(['data' => ['*' => self::REQUIREMENT_KEYS], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $this->assertSame([$assessed->id, $unassessed->id], array_column($list->json('data'), 'id'));
        $list->assertJsonPath('data.0', [
            'id' => $assessed->id,
            'reference' => 'Art. 30',
            'title' => 'Behandlingsprotokoll',
            'status' => 'active',
            'source' => ['id' => $source->id, 'label' => 'Personvernforordningen', 'kind' => 'law', 'from_library' => false],
            'from_library' => false,
            'is_control' => false,
            'applicability' => null,
            'owner' => ['id' => $owner->id, 'name' => 'Eva Eier'],
            'review_interval_months' => 12,
            'compliance' => [
                'status' => 'partially_compliant',
                'group' => 'partially_compliant',
                'last_assessed_at' => $latest->assessed_at->utc()->toIso8601ZuluString(),
                'next_review_on' => $latest->assessed_at->copy()->addMonthsNoOverflow(12)->toDateString(),
                'is_overdue' => true,
                'changed_since_assessment' => false,
            ],
            // ComplianceAttentionService's codes, in its order.
            'attention' => ['partially_compliant', 'review_overdue'],
            'created_at' => $assessed->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $assessed->updated_at->utc()->toIso8601ZuluString(),
        ]);
        $list->assertJsonPath('data.1.compliance.status', 'not_assessed');
        $list->assertJsonPath('data.1.attention', ['not_assessed']);

        $detail = $this->get("/api/v1/compliance/requirements/{$assessed->id}", $headers);
        $detail->assertOk()->assertExactJsonStructure(['data' => [...self::REQUIREMENT_KEYS, 'assessments'], 'meta' => ['as_of', 'request_id']]);
        $detail->assertJsonPath('data.assessments', [
            ['id' => $latest->id, 'result' => 'partially_compliant', 'assessed_at' => $latest->assessed_at->utc()->toIso8601ZuluString()],
            ['id' => $earlier->id, 'result' => 'non_compliant', 'assessed_at' => $earlier->assessed_at->utc()->toIso8601ZuluString()],
        ]);

        foreach ([$list, $detail] as $response) {
            $body = (string) $response->getContent();

            foreach (['FRITEKST', 'Kravtekst', 'Vera', 'requirement_text', 'rationale', 'documentation', 'Annen kunde', '/app/'] as $absent) {
                $this->assertStringNotContainsString($absent, $body);
            }
        }

        $this->assertSame([$unassessed->id], array_column($this->get('/api/v1/compliance/requirements?limit=1&cursor='.$this->get('/api/v1/compliance/requirements?limit=1', $headers)->json('meta.next_cursor'), $headers)->json('data'), 'id'));
        $this->get('/api/v1/compliance/requirements?status=deleted', $headers)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_the_status_per_source_counts_as_etterlevelsesvurdering(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $person = $this->complianceMember($customer);
        $iso = $this->complianceSource($customer, 'ISO 27001', '2022');
        $law = $this->complianceSource($customer, 'Arkivloven', null, 'law');
        $this->assess($this->complianceRequirement($iso, 'Tilgangsstyring'), $person, ComplianceAssessment::RESULT_COMPLIANT, now()->subDay());
        $this->assess($this->complianceRequirement($iso, 'Logging'), $person, ComplianceAssessment::RESULT_NON_COMPLIANT, now()->subDay());
        $this->complianceRequirement($law, 'Arkivplan');
        $retired = $this->complianceRequirement($law, 'Utgått');
        $retired->forceFill(['status' => ComplianceRequirement::STATUS_RETIRED])->save();
        $this->grantReader($client, $customer);

        $response = $this->get('/api/v1/compliance/status', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertJsonPath('data.total.assessable', 3);
        $response->assertJsonPath('data.total.assessed', 2);
        $response->assertJsonPath('data.total.compliant', 1);
        $response->assertJsonPath('data.total.non_compliant', 1);
        $response->assertJsonPath('data.total.not_assessed', 1);
        $this->assertSame([$law->id, $iso->id], array_column(array_column($response->json('data.per_source'), 'source'), 'id'));
        $response->assertJsonPath('data.per_source.0.stats.not_assessed', 1);
        $response->assertJsonPath('data.per_source.1.stats.compliant', 1);
    }

    public function test_an_audit_with_its_scope_checks_and_findings_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $responsible = $this->complianceMember($customer, 'Rita Revisor');
        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', null, 'A.5.15');
        $audit = $this->complianceAudit($customer, $responsible, 'Internrevisjon IT', ComplianceAudit::STATUS_COMPLETED);
        $audit->forceFill(['purpose' => 'FRITEKST-FORMÅL', 'auditor_name' => 'Ekstern Erling'])->save();
        ComplianceAuditRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'requirement_id' => $requirement->id]);
        $check = ComplianceAuditCheck::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'title' => 'Stikkprøve av tilganger', 'observation' => 'FRITEKST-OBSERVASJON', 'evidence' => 'FRITEKST-BEVIS']);
        ComplianceAuditCheckRequirement::query()->create(['customer_id' => $customer->id, 'audit_id' => $audit->id, 'check_id' => $check->id, 'requirement_id' => $requirement->id]);
        $open = ComplianceAuditFinding::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $audit->id, 'check_id' => $check->id, 'requirement_id' => $requirement->id,
            'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY, 'title' => 'Tilganger ikke fjernet', 'description' => 'FRITEKST-FUNN',
        ]);
        $driftArea = $this->businessArea($customer, 'Drift');
        $readable = ImprovementCase::query()->create(['customer_id' => $customer->id, 'business_area_id' => $driftArea->id, 'type' => 'deviation', 'title' => 'Sak fra revisjonen', 'description' => 'x']);
        $handed = ComplianceAuditFinding::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY,
            'title' => 'Mangler logging', 'description' => 'FRITEKST-FUNN',
        ]);
        $handed->forceFill(['improvement_case_id' => $readable->id, 'handed_off_at' => now(), 'handed_off_by_user_id' => $responsible->id])->save();
        $hiddenCase = ImprovementCase::query()->create(['customer_id' => $customer->id, 'business_area_id' => $this->businessArea($customer, 'Økonomi')->id, 'type' => 'deviation', 'title' => 'Skjult sak', 'description' => 'x']);
        $hidden = ComplianceAuditFinding::query()->create([
            'customer_id' => $customer->id, 'audit_id' => $audit->id, 'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY,
            'title' => 'Svak rutine', 'description' => 'FRITEKST-FUNN',
        ]);
        $hidden->forceFill(['improvement_case_id' => $hiddenCase->id, 'handed_off_at' => now(), 'handed_off_by_user_id' => $responsible->id])->save();
        $identity = $this->grantReader($client, $customer)->integrationUser;
        $this->giveRole($identity, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$driftArea]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $list = $this->get('/api/v1/compliance/audits', $headers);
        $list->assertOk()->assertExactJsonStructure(['data' => [self::AUDIT_KEYS], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $list->assertJsonPath('data.0', [
            'id' => $audit->id,
            'title' => 'Internrevisjon IT',
            'audit_type' => 'internal',
            'status' => 'completed',
            'responsible' => ['id' => $responsible->id, 'name' => 'Rita Revisor'],
            'planned_start_date' => '2026-11-01',
            'planned_end_date' => '2026-11-15',
            'counts' => ['requirements' => 1, 'checks' => 1, 'findings' => 3],
            // A completed audit with a nonconformity not followed up.
            'attention' => ['nonconformity_without_follow_up'],
            'created_at' => $audit->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $audit->fresh()->updated_at->utc()->toIso8601ZuluString(),
        ]);

        $detail = $this->get("/api/v1/compliance/audits/{$audit->id}", $headers);
        $detail->assertOk();
        $detail->assertJsonPath('data.requirements', [[
            'id' => $requirement->id, 'reference' => 'A.5.15', 'title' => 'Tilgangsstyring', 'status' => 'active',
            'relevance' => 'applies', 'is_control' => false, 'source_id' => $source->id, 'check_count' => 1, 'finding_count' => 1,
        ]]);
        $detail->assertJsonPath('data.checks', [['id' => $check->id, 'title' => 'Stikkprøve av tilganger', 'requirement_ids' => [$requirement->id], 'finding_ids' => [$open->id]]]);
        $findings = collect($detail->json('data.findings'))->keyBy('id');
        $this->assertSame([
            'id' => $open->id, 'finding_type' => 'nonconformity', 'title' => 'Tilganger ikke fjernet', 'requirement_id' => $requirement->id,
            'check_id' => $check->id, 'improvement_type' => 'deviation', 'handed_off' => false, 'handed_off_at' => null, 'improvement_case_id' => null,
        ], $findings[$open->id]);
        $this->assertSame($readable->id, $findings[$handed->id]['improvement_case_id']);
        // Handed off to a case in a fagområde the identity cannot read: it says so, but not which case.
        $this->assertTrue($findings[$hidden->id]['handed_off']);
        $this->assertNull($findings[$hidden->id]['improvement_case_id']);

        $body = (string) $detail->getContent();

        foreach (['FRITEKST', 'Erling', 'Skjult sak', 'Sak fra revisjonen', 'observation', 'evidence', 'description', 'quality_process', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_the_library_and_one_standard_with_the_customers_register_entries(): void
    {
        $this->seed(ComplianceFrameworkLibrarySeeder::class);
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $editor = $this->complianceEditor($customer);
        $framework = ComplianceFramework::query()->where('key', 'iso9001:2015')->sole();
        $source = app(ComplianceFrameworkActivationService::class)->activate($framework, $editor);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $list = $this->get('/api/v1/compliance/frameworks', $headers)->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $framework->id);
        $this->assertSame($source->id, $row['source_id']);
        $this->assertSame(['id', 'key', 'reference', 'edition', 'label', 'name', 'content_verified', 'source_id', 'requirement_count', 'has_controls'], array_keys($row));
        $this->assertNull(collect($list->json('data'))->firstWhere('key', 'iso27001:2022')['source_id'] ?? null);

        $detail = $this->get("/api/v1/compliance/frameworks/{$framework->id}", $headers)->assertOk();
        $requirementNodes = collect($detail->json('data.nodes'))->where('kind', 'requirement');
        $this->assertNotEmpty($requirementNodes);
        $registered = ComplianceRequirement::query()->where('source_id', $source->id)->pluck('id')->sort()->values()->all();
        $this->assertSame($registered, $requirementNodes->pluck('requirement_id')->filter()->sort()->values()->all());
        $this->assertSame(['id', 'parent_id', 'clause', 'kind', 'depth', 'title', 'requirement_type', 'applicability_rule', 'withdrawn', 'requirement_id'], array_keys($requirementNodes->first()));
        $this->assertStringNotContainsString('guidance', (string) $detail->getContent());
        $this->get('/api/v1/compliance/frameworks/999999999', $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_another_customers_records_are_not_found_and_reading_needs_the_module_and_the_explicit_grant(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $other = $this->customerWithCompliance('Annen kunde');
        $foreignRequirement = $this->complianceRequirement($this->complianceSource($other), 'Annen kundes krav');
        $foreignAudit = $this->complianceAudit($other, null, 'Annen kundes revisjon');
        $grant = $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach (["/api/v1/compliance/requirements/{$foreignRequirement->id}", "/api/v1/compliance/audits/{$foreignAudit->id}", '/api/v1/compliance/requirements/999999999'] as $url) {
            $this->get($url, $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        $this->assertSame([], $this->get('/api/v1/compliance/requirements?source_id='.$foreignRequirement->source_id, $headers)->json('data'));

        $urls = ['/api/v1/compliance/requirements', '/api/v1/compliance/status', '/api/v1/compliance/audits', '/api/v1/compliance/frameworks'];

        // Without compliance.view — only other modules' keys — nothing is readable.
        $withoutKey = $this->customerWithCompliance('Uten nøkkel');
        $identity = $this->integrationIdentity($withoutKey);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::COMPLIANCE_EDIT]);
        $this->grantTo($client, $withoutKey, $identity);

        // Without the module, even with the key.
        $withoutModule = $this->apiCustomer('Uten modul');
        $this->grantReader($client, $withoutModule);

        foreach ($urls as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutKey))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get('/api/v1/compliance/requirements', $headers)->assertForbidden();

        $client->forceFill(['is_enabled' => false])->save();
        $this->get('/api/v1/compliance/requirements', $this->asCustomer($this->accessToken(), $withoutModule))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_attention_includes_compliance_and_reading_writes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->customerWithCompliance();
        $requirement = $this->complianceRequirement($this->complianceSource($customer), 'Ikke vurdert');
        $audit = $this->complianceAudit($customer, null, 'Forsinket revisjon');
        $audit->forceFill(['planned_start_date' => now()->subWeek()->toDateString(), 'planned_end_date' => now()->subDay()->toDateString()])->save();
        $this->complianceAudit($customer, null, 'Avlyst', ComplianceAudit::STATUS_CANCELLED);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $attention = $this->get('/api/v1/attention', $headers)->assertOk();
        $attention->assertJsonPath('data.modules.compliance', true);
        $attention->assertJsonPath('data.items', [
            ['type' => 'compliance_requirement', 'id' => $requirement->id, 'title' => 'Ikke vurdert', 'objective_id' => null, 'business_area' => null, 'attention' => ['not_assessed']],
            ['type' => 'compliance_audit', 'id' => $audit->id, 'title' => 'Forsinket revisjon', 'objective_id' => null, 'business_area' => null, 'attention' => ['overdue']],
        ]);
        $this->get('/api/v1/me', $headers)->assertJsonPath('data.modules.compliance', ['entitled' => true, 'readable' => true, 'business_areas' => null]);

        foreach (['compliance/requirements', "compliance/requirements/{$requirement->id}", 'compliance/status', 'compliance/audits', "compliance/audits/{$audit->id}", 'compliance/frameworks'] as $path) {
            $this->get("/api/v1/{$path}", $headers)->assertOk();

            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['status' => 'completed'], $headers)->assertStatus(405);
            }
        }

        $this->assertSame($before, $this->domainState($customer));
    }

    private function customerWithCompliance(string $name = 'Kunde'): Customer
    {
        $customer = $this->apiCustomer($name);
        app(ModuleEntitlementService::class)->activatePackage($customer, 'compliance');

        return $customer;
    }

    /** An integration identity with compliance.view through a role of its own (an explicit-grant domain). */
    private function grantReader($client, Customer $customer): ApiClientCustomerGrant
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::COMPLIANCE_VIEW]);

        return $this->grantTo($client, $customer, $identity);
    }

    private function assess(ComplianceRequirement $requirement, User $by, string $result, $at): ComplianceAssessment
    {
        return ComplianceAssessment::query()->create([
            'customer_id' => $requirement->customer_id,
            'requirement_id' => $requirement->id,
            'result' => $result,
            'rationale' => 'FRITEKST-BEGRUNNELSE',
            'documentation' => 'FRITEKST-DOKUMENTASJON',
            'assessed_by_user_id' => $by->id,
            'assessed_at' => $at,
            'requirement_reference' => $requirement->reference,
            'requirement_title' => $requirement->title,
            'requirement_text' => $requirement->requirement_text,
            'source_name' => $requirement->source->name,
            'source_version' => $requirement->source->version,
        ]);
    }

    /** @return array<string, mixed> every Etterlevelse og revisjon row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['compliance_sources', 'compliance_requirements', 'compliance_assessments', 'compliance_audits', 'compliance_audit_findings', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
