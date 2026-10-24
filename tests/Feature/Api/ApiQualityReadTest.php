<?php

namespace Tests\Feature\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\Kpi;
use App\Models\KpiActivity;
use App\Models\KpiProcess;
use App\Models\Objective;
use App\Models\QualityActivityControl;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityTool;
use App\Models\Risk;
use App\Models\RiskActivity;
use App\Models\RiskControl;
use App\Models\RiskProcess;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthorsQualityFlows;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\QualityFlowVersion;
use Tests\TestCase;

/**
 * Kvalitet through the API (KVA-01, KVA-03, KVA-04) and the Kvalitet links of a risk (RIS-02) and a
 * KPI (MOK-06): what QualityProcessContextReader::canRead() lets the identity read (the module and
 * quality.view), in structured fields only — and nothing read from a process flow, whose version the
 * API may show is not decided (KVA-02).
 */
class ApiQualityReadTest extends TestCase
{
    use AuthorsQualityFlows;
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ROW_KEYS = [
        'id', 'quality_type', 'title', 'code', 'status', 'owner', 'review_interval_months', 'last_reviewed_on',
        'next_review_on', 'attention', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_the_register_and_the_controls_in_structured_fields_only_with_flow_independent_attention(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $process = $this->process($customer, 'Lønnskjøring', ['Beregn lønn', 'Godkjenn utbetaling']);
        $process->forceFill(['purpose' => 'FRITEKST-FORMÅL', 'code' => 'P-01', 'next_review_at' => now()->subDay()->toDateString(), 'review_interval_months' => 12])->save();
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Personvernpolicy');
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Tilgangsgjennomgang');
        QualityControlDetail::query()->create([
            'customer_id' => $customer->id, 'quality_item_id' => $control->id, 'criterion' => 'FRITEKST-KRITERIUM',
            'method' => 'FRITEKST-METODE', 'responsibility' => 'FRITEKST-ANSVAR', 'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
        ]);
        $unplaced = $this->item($customer, QualityItem::TYPE_CONTROL, 'Uten bevis');
        $evidence = QualityItemDocument::query()->create([
            'customer_id' => $customer->id, 'quality_item_id' => $control->id, 'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
            'title' => 'Gjennomgang Q3', 'note' => 'FRITEKST-BEVIS',
        ]);
        $tool = $this->tool($customer, 'Sjekkliste for tilgang');
        QualityItemDocument::query()->create([
            'customer_id' => $customer->id, 'quality_item_id' => $control->id, 'enterprise_wiki_document_id' => $tool->enterprise_wiki_document_id,
            'relation_type' => QualityItemDocument::RELATION_TYPE_TOOL, 'title' => $tool->title,
        ]);
        $this->item($this->apiCustomer('Annen kunde'), QualityItem::TYPE_POLICY, 'Annen kundes policy');
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $items = $this->get('/api/v1/quality/items', $headers);

        $items->assertOk();
        $items->assertExactJsonStructure(['data' => ['*' => self::ROW_KEYS], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $this->assertSame([$process->id, $policy->id, $control->id, $unplaced->id], array_column($items->json('data'), 'id'));
        $rows = collect($items->json('data'))->keyBy('id');
        $this->assertSame([
            'id' => $process->id, 'quality_type' => 'process', 'title' => 'Lønnskjøring', 'code' => 'P-01', 'status' => 'draft', 'owner' => null,
            'review_interval_months' => 12, 'last_reviewed_on' => null, 'next_review_on' => now()->subDay()->toDateString(),
            'attention' => ['processes_without_governing_policy', 'processes_overdue_for_review'],
            'created_at' => $process->created_at->utc()->toIso8601ZuluString(), 'updated_at' => $process->fresh()->updated_at->utc()->toIso8601ZuluString(),
        ], $rows[$process->id]);
        // controls_without_activity is judged against the working flow, so it is never given.
        $this->assertSame(['controls_without_evidence'], $rows[$unplaced->id]['attention']);
        $this->assertSame([], $rows[$control->id]['attention']);
        $this->assertSame([$policy->id], array_column($this->get('/api/v1/quality/items?type=policy', $headers)->json('data'), 'id'));

        $controls = $this->get('/api/v1/quality/controls', $headers);
        $controls->assertOk();
        $controls->assertExactJsonStructure(['data' => ['*' => [...self::ROW_KEYS, 'frequency', 'evidence', 'tools']], 'meta' => ['as_of', 'request_id', 'limit', 'next_cursor']]);
        $this->assertSame([$control->id, $unplaced->id], array_column($controls->json('data'), 'id'));
        $controls->assertJsonPath('data.0.frequency', 'quarterly');
        $controls->assertJsonPath('data.0.evidence', ['count' => 1, 'latest_added_at' => $evidence->created_at->utc()->toIso8601ZuluString()]);
        $controls->assertJsonPath('data.0.tools', [['id' => $tool->id, 'title' => 'Sjekkliste for tilgang', 'category' => null]]);
        $controls->assertJsonPath('data.1.evidence', ['count' => 0, 'latest_added_at' => null]);

        foreach ([$items, $controls] as $response) {
            $body = (string) $response->getContent();

            foreach (['FRITEKST', 'Annen kunde', 'purpose', 'criterion', 'method', 'blueprint', 'publication', 'placement', 'Beregn lønn', 'Godkjenn', 'filename', 'download', '/app/'] as $absent) {
                $this->assertStringNotContainsString($absent, $body);
            }
        }
    }

    public function test_a_risk_and_a_kpi_show_their_kvalitet_links_at_process_level_only_to_a_kvalitet_reader(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $process = $this->process($customer, 'Lønnskjøring', ['Beregn lønn', 'Godkjenn utbetaling']);
        $whole = $this->process($customer, 'Innkjøp', ['Bestill']);
        // Approved: the activities are named from the approved flow (D15).
        $this->approve($customer, $process);
        $this->approve($customer, $whole);
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Tilgangsgjennomgang');
        QualityControlDetail::query()->create(['customer_id' => $customer->id, 'quality_item_id' => $control->id, 'criterion' => 'FRITEKST-KRITERIUM']);
        QualityActivityControl::query()->create(['customer_id' => $customer->id, 'quality_item_id' => $process->id, 'activity_key' => 'beregn-lonn', 'control_item_id' => $control->id]);
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Feil lønn', 'status' => Risk::STATUS_IDENTIFIED]);
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $control->id]);
        RiskProcess::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $whole->id]);
        RiskActivity::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $process->id, 'activity_key' => 'beregn-lonn']);
        $objective = Objective::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Riktig lønn']);
        $kpi = Kpi::query()->create(['customer_id' => $customer->id, 'objective_id' => $objective->id, 'title' => 'Feilutbetalinger', 'unit' => Kpi::UNIT_COUNT, 'target_max' => '0']);
        KpiProcess::query()->create(['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'quality_process_id' => $whole->id]);
        KpiActivity::query()->create(['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'quality_process_id' => $process->id, 'activity_key' => 'beregn-lonn']);
        KpiActivity::query()->create(['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'quality_process_id' => $process->id, 'activity_key' => 'godkjenn-utbetaling']);

        $reader = $this->integrationIdentity($customer);
        $this->giveRole($reader, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW]);
        $this->grantTo($client, $customer, $reader);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $riskDetail = $this->get("/api/v1/risks/{$risk->id}", $headers)->assertOk();
        $riskDetail->assertJsonPath('data.controls', [['id' => $control->id, 'title' => 'Tilgangsgjennomgang', 'code' => null, 'status' => 'active']]);
        $processes = collect($riskDetail->json('data.processes'))->keyBy('id');
        $this->assertSame(['id' => $whole->id, 'title' => 'Innkjøp', 'code' => null, 'whole_process' => true, 'activity_count' => 0, 'activities' => []], $processes[$whole->id]);
        $this->assertSame([
            'id' => $process->id, 'title' => 'Lønnskjøring', 'code' => null, 'whole_process' => false, 'activity_count' => 1,
            'activities' => [['key' => 'beregn-lonn', 'label' => 'Beregn lønn', 'role' => 'Lønnsansvarlig']],
        ], $processes[$process->id]);

        $kpiDetail = $this->get("/api/v1/kpis/{$kpi->id}", $headers)->assertOk();
        $kpiDetail->assertJsonPath('data.id', $kpi->id);
        $kpiProcesses = collect($kpiDetail->json('data.processes'))->keyBy('id');
        $this->assertSame(2, $kpiProcesses[$process->id]['activity_count']);
        $this->assertSame(['Beregn lønn', 'Godkjenn utbetaling'], array_column($kpiProcesses[$process->id]['activities'], 'label'));
        $this->assertTrue($kpiProcesses[$whole->id]['whole_process']);
        // The rest of the KPI is exactly its row in GET /kpis.
        $listed = $this->get('/api/v1/kpis', $headers)->json('data.0');
        $this->assertSame($listed, array_diff_key($kpiDetail->json('data'), ['processes' => true]));

        foreach ([$riskDetail, $kpiDetail] as $response) {
            $body = (string) $response->getContent();

            foreach (['FRITEKST', 'placements', 'criterion', 'has_unpublished_changes', '/app/'] as $absent) {
                $this->assertStringNotContainsString($absent, $body);
            }
        }
    }

    public function test_without_quality_view_or_the_module_kvalitet_is_refused_and_the_links_are_null(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Hemmelig kontroll');
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Risiko', 'status' => Risk::STATUS_IDENTIFIED]);
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $control->id]);
        $objective = Objective::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Mål']);
        $kpi = Kpi::query()->create(['customer_id' => $customer->id, 'objective_id' => $objective->id, 'title' => 'KPI', 'unit' => Kpi::UNIT_NUMBER, 'target_min' => '1']);
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $grant = $this->grantTo($client, $customer, $identity);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach (['/api/v1/quality/items', '/api/v1/quality/controls'] as $url) {
            $response = $this->get($url, $headers);
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        $risked = $this->get("/api/v1/risks/{$risk->id}", $headers)->assertOk();
        $risked->assertJsonPath('data.controls', null)->assertJsonPath('data.processes', null);
        $this->assertStringNotContainsString('Hemmelig', (string) $risked->getContent());
        $this->get("/api/v1/kpis/{$kpi->id}", $headers)->assertOk()->assertJsonPath('data.processes', null);
        $this->get('/api/v1/me', $headers)->assertJsonPath('data.modules.quality', ['entitled' => true, 'readable' => false, 'business_areas' => null]);
        $this->get('/api/v1/kpis/999999999', $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->grantReader($client, $withoutModule);
        $this->get('/api/v1/quality/items', $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->get("/api/v1/kpis/{$kpi->id}", $headers)->assertForbidden();
        $client->forceFill(['is_enabled' => false])->save();
        $this->get('/api/v1/quality/items', $this->asCustomer($this->accessToken(), $withoutModule))->assertUnauthorized();
    }

    public function test_attention_includes_kvalitet_and_reading_writes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Uten bevis');
        $this->process($customer, 'Lønnskjøring', ['Beregn lønn']);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $attention = $this->get('/api/v1/attention', $headers)->assertOk();
        $attention->assertJsonPath('data.modules.quality', true);
        $items = collect($attention->json('data.items'))->where('type', 'quality_item')->keyBy('id');
        $this->assertSame(['controls_without_evidence'], $items[$control->id]['attention']);
        $this->assertNull($items[$control->id]['business_area']);
        $this->assertStringNotContainsString('controls_without_activity', (string) $attention->getContent());

        foreach (['quality/items', 'quality/controls'] as $path) {
            $this->get("/api/v1/{$path}", $headers)->assertOk();

            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $this->{$method.'Json'}("/api/v1/{$path}", ['status' => 'retired'], $headers)->assertStatus(405);
            }
        }

        $this->assertSame($before, $this->domainState($customer));
    }

    public function test_one_process_shows_only_its_approved_flow_never_the_working_version(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $process = $this->process($customer, 'Lønnskjøring', ['Beregn lønn', 'Godkjenn utbetaling']);
        $this->approve($customer, $process);
        $approvedAt = $process->fresh()->updated_at;
        // Changed after approval: a step renamed and one added. None of it is approved.
        $this->storeFlow($customer, $process, ['Beregn lønn ARBEIDSVERSJON', 'Godkjenn utbetaling', 'Ny kontroll ARBEIDSVERSJON'], ['beregn-lonn', 'godkjenn-utbetaling', 'ny-kontroll']);
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Lønnskontroll');
        QualityActivityControl::query()->create(['customer_id' => $customer->id, 'quality_item_id' => $process->id, 'activity_key' => 'beregn-lonn', 'control_item_id' => $control->id]);
        QualityActivityControl::query()->create(['customer_id' => $customer->id, 'quality_item_id' => $process->id, 'activity_key' => 'ny-kontroll', 'control_item_id' => $control->id]);
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Lønnspolicy');
        QualityItemRelation::query()->create(['customer_id' => $customer->id, 'from_item_id' => $policy->id, 'to_item_id' => $process->id, 'relation_type' => QualityItemRelation::TYPE_GOVERNS]);
        $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $response = $this->get("/api/v1/quality/items/{$process->id}", $headers);

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => [...self::ROW_KEYS, 'relations', 'publication', 'approved_flow' => ['lanes', 'nodes', 'edges'], 'placed_controls'], 'meta' => ['as_of', 'request_id']]);
        $response->assertJsonPath('data.publication.state', 'approved');
        $response->assertJsonPath('data.publication.revision_number', 1);
        $this->assertNotNull($response->json('data.publication.approved_at'));
        $response->assertJsonPath('data.approved_flow.lanes', [['key' => 'lonn', 'label' => 'Lønnsansvarlig']]);
        $steps = collect($response->json('data.approved_flow.nodes'))->where('type', 'step')->values();
        // The approved labels and steps — not the renamed one, not the added one.
        $this->assertSame(['Beregn lønn', 'Godkjenn utbetaling'], $steps->pluck('label')->all());
        $this->assertSame(['key' => 'beregn-lonn', 'type' => 'step', 'label' => 'Beregn lønn', 'lane' => 'lonn', 'subprocess_item_id' => null], $steps[0]);
        $response->assertJsonPath('data.placed_controls', [['activity_key' => 'beregn-lonn', 'control_id' => $control->id]]);
        $response->assertJsonPath('data.relations', [[
            'relation_type' => 'governs', 'direction' => 'incoming',
            'item' => ['id' => $policy->id, 'quality_type' => 'policy', 'title' => 'Lønnspolicy', 'code' => null, 'status' => 'active'],
        ]]);

        // The control is placed where the approved flow has it, and nowhere else.
        $this->get("/api/v1/quality/items/{$control->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.placements', [['process_id' => $process->id, 'activity_key' => 'beregn-lonn', 'activity_label' => 'Beregn lønn', 'activity_role' => 'Lønnsansvarlig']]);

        $body = (string) $response->getContent().$this->get("/api/v1/quality/items/{$control->id}", $headers)->getContent();

        foreach (['ARBEIDSVERSJON', 'ny-kontroll', 'has_unpublished_changes', 'current_with_changes', 'FRITEKST', 'description', 'approved_by', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_a_process_without_an_approved_flow_or_retired_has_no_flow_and_links_name_only_approved_activities(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $draft = $this->process($customer, 'Utkast', ['Første steg UTKAST']);
        $approved = $this->process($customer, 'Lønnskjøring', ['Beregn lønn', 'Godkjenn utbetaling']);
        $this->approve($customer, $approved);
        $this->storeFlow($customer, $approved, ['Beregn lønn', 'Godkjenn utbetaling', 'Nytt steg ARBEIDSVERSJON']);
        $retired = $this->process($customer, 'Gammel prosess', ['Gammelt steg']);
        $this->approve($customer, $retired);
        $retired->forceFill(['status' => QualityItem::STATUS_RETIRED])->save();
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Feil lønn', 'status' => Risk::STATUS_IDENTIFIED]);
        RiskActivity::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $approved->id, 'activity_key' => 'beregn-lonn']);
        RiskActivity::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $approved->id, 'activity_key' => 'nytt-steg-arbeidsversjon']);
        RiskActivity::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $draft->id, 'activity_key' => 'forste-steg-utkast']);
        RiskProcess::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $retired->id]);
        $objective = Objective::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Riktig lønn']);
        $kpi = Kpi::query()->create(['customer_id' => $customer->id, 'objective_id' => $objective->id, 'title' => 'Feilutbetalinger', 'unit' => Kpi::UNIT_COUNT, 'target_max' => '0']);
        KpiActivity::query()->create(['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'quality_process_id' => $approved->id, 'activity_key' => 'godkjenn-utbetaling']);
        KpiActivity::query()->create(['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'quality_process_id' => $draft->id, 'activity_key' => 'forste-steg-utkast']);
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW]);
        $this->grantTo($client, $customer, $identity);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get("/api/v1/quality/items/{$draft->id}", $headers)->assertOk()
            ->assertJsonPath('data.publication', ['state' => 'not_approved', 'revision_number' => null, 'approved_at' => null])
            ->assertJsonPath('data.approved_flow', null)
            ->assertJsonPath('data.placed_controls', []);
        $retiredRead = $this->get("/api/v1/quality/items/{$retired->id}", $headers)->assertOk();
        $retiredRead->assertJsonPath('data.publication.state', 'retired')->assertJsonPath('data.approved_flow', null);

        // The risk: the approved activity named, the working-only one and the unapproved process left
        // out; the retired process linked as a whole, with no activities.
        $processes = collect($this->get("/api/v1/risks/{$risk->id}", $headers)->assertOk()->json('data.processes'))->keyBy('id');
        $this->assertSame([$approved->id, $retired->id], $processes->keys()->sort()->values()->all());
        $this->assertSame([
            'id' => $approved->id, 'title' => 'Lønnskjøring', 'code' => null, 'whole_process' => false, 'activity_count' => 1,
            'activities' => [['key' => 'beregn-lonn', 'label' => 'Beregn lønn', 'role' => 'Lønnsansvarlig']],
        ], $processes[$approved->id]);
        $this->assertSame([], $processes[$retired->id]['activities']);
        $this->assertTrue($processes[$retired->id]['whole_process']);

        $kpiProcesses = $this->get("/api/v1/kpis/{$kpi->id}", $headers)->assertOk()->json('data.processes');
        $this->assertSame([[
            'id' => $approved->id, 'title' => 'Lønnskjøring', 'code' => null, 'whole_process' => false, 'activity_count' => 1,
            'activities' => [['key' => 'godkjenn-utbetaling', 'label' => 'Godkjenn utbetaling', 'role' => 'Lønnsansvarlig']],
        ]], $kpiProcesses);

        $body = (string) $this->get("/api/v1/risks/{$risk->id}", $headers)->getContent().$this->get("/api/v1/kpis/{$kpi->id}", $headers)->getContent();

        foreach (['ARBEIDSVERSJON', 'UTKAST', 'Utkast', 'forste-steg', 'Gammelt steg'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_one_item_of_another_customer_or_missing_is_not_found_and_needs_quality_view(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $foreign = $this->item($this->apiCustomer('Annen kunde'), QualityItem::TYPE_POLICY, 'Annen kundes policy');
        $own = $this->item($customer, QualityItem::TYPE_POLICY, 'Hemmelig policy');
        $grant = $this->grantReader($client, $customer);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([$foreign->id, 999999999] as $id) {
            $this->get("/api/v1/quality/items/{$id}", $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        $this->get("/api/v1/quality/items/{$own->id}", $headers)->assertOk()->assertJsonPath('data.relations', []);
        $this->assertArrayNotHasKey('approved_flow', $this->get("/api/v1/quality/items/{$own->id}", $headers)->json('data'));

        $grant->forceFill(['revoked_at' => now()])->save();
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);
        $this->grantTo($client, $customer, $identity);
        $response = $this->get("/api/v1/quality/items/{$own->id}", $this->asCustomer($this->accessToken(), $customer));
        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
    }

    /** Approved through Kvalitet's own service, by a person who may approve: the revision in force. */
    private function approve(Customer $customer, QualityItem $process): void
    {
        app(QualityProcessBlueprintService::class)->approve((int) $customer->id, $process->fresh(), $this->qualitySystemOwner((int) $customer->id));
    }

    private function grantReader($client, Customer $customer): ApiClientCustomerGrant
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::QUALITY_VIEW]);

        return $this->grantTo($client, $customer, $identity);
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

    /** @param  list<string>  $steps */
    private function process(Customer $customer, string $title, array $steps): QualityItem
    {
        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_DRAFT,
        ]);

        return $this->storeFlow($customer, $process, $steps);
    }

    /**
     * Saves the working version of the flow, step keys from their labels — or from $keys when given,
     * so a step can be renamed while keeping its key.
     *
     * @param  list<string>  $steps
     * @param  list<string>|null  $keys
     */
    private function storeFlow(Customer $customer, QualityItem $process, array $steps, ?array $keys = null): QualityItem
    {
        $nodes = [['key' => 'start', 'lane' => 'lonn', 'type' => 'start', 'label' => 'Start']];
        foreach ($steps as $index => $step) {
            $nodes[] = ['key' => $keys[$index] ?? Str::slug($step), 'lane' => 'lonn', 'type' => 'step', 'label' => $step, 'description' => 'FRITEKST-NODE'];
        }
        $nodes[] = ['key' => 'slutt', 'lane' => 'lonn', 'type' => 'end', 'label' => 'Slutt'];
        $edges = [];
        for ($i = 1; $i < count($nodes); $i++) {
            $edges[] = ['from' => $nodes[$i - 1]['key'], 'to' => $nodes[$i]['key']];
        }

        app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            ['lanes' => [['key' => 'lonn', 'label' => 'Lønnsansvarlig']], 'nodes' => $nodes, 'edges' => $edges],
            QualityProcessBlueprint::SOURCE_MANUAL,
            $this->qualitySystemOwner((int) $customer->id),
            basedOn: QualityFlowVersion::of($process),
        );

        return $process->fresh();
    }

    private function tool(Customer $customer, string $title): QualityTool
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => Str::slug($title).'.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', $title.Str::random(8)),
            'extracted_text' => 'Innhold',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return QualityTool::query()->create(['customer_id' => $customer->id, 'enterprise_wiki_document_id' => $document->id, 'title' => $title]);
    }

    /** @return array<string, mixed> every Kvalitet row of the customer, and its risk and KPI links, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['quality_items', 'quality_item_documents', 'quality_process_blueprints', 'quality_activity_controls', 'risk_controls', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
