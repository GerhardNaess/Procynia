<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * GET /api/v1/objectives/{objectiveId} (MOK-03) and GET /api/v1/kpis/{kpiId}/measurements (MOK-04):
 * one mål with its KPIs, and one KPI's whole målehistorikk — through ObjectiveAccessService, in
 * structured fields only, and without writing anything.
 */
class ApiObjectiveKpiDetailReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const OBJECTIVE_KEYS = [
        'id', 'title', 'status', 'business_area', 'owner', 'target_date', 'closed_at', 'kpis', 'attention',
        'created_at', 'updated_at',
    ];

    private const KPI_KEYS = [
        'id', 'objective_id', 'business_area', 'title', 'status', 'owner', 'unit', 'unit_label', 'currency_code',
        'target', 'baseline', 'frequency', 'reporting_grace_days', 'latest_measurement', 'result', 'measurements',
        'attention', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_one_mal_is_presented_with_its_kpis_in_structured_fields_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Salg');
        $owner = $this->person($customer, 'Eva Eier');
        $objective = $this->objective($customer, $area, 'Økt kundetilfredshet', [
            'owner_user_id' => $owner->id,
            'description' => 'FRITEKST-BESKRIVELSE',
            'target_date' => now()->addYear()->toDateString(),
        ]);
        $first = $this->kpi($objective, 'NPS');
        $second = $this->kpi($objective, 'Svartid', ['unit' => Kpi::UNIT_HOURS, 'target_min' => null, 'target_max' => '24', 'description' => 'FRITEKST-KPI']);
        $this->kpi($this->objective($customer, $area, 'Annet mål'), 'Annen KPI');
        $this->grantReader($client, $customer, [$area]);

        $response = $this->get("/api/v1/objectives/{$objective->id}", $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => [...self::OBJECTIVE_KEYS, 'kpi_list' => ['*' => self::KPI_KEYS]],
            'meta' => ['as_of', 'request_id'],
        ]);
        $response->assertJsonPath('data.id', $objective->id);
        $response->assertJsonPath('data.owner', ['id' => $owner->id, 'name' => 'Eva Eier']);
        $response->assertJsonPath('data.kpis', ['total' => 2, 'active' => 2, 'on_target' => 0, 'needing_attention' => 0]);
        $response->assertJsonPath('data.attention', []);
        $this->assertSame([$first->id, $second->id], array_column($response->json('data.kpi_list'), 'id'));

        // The KPIs read exactly as GET /kpis presents them.
        $list = $this->get('/api/v1/kpis?objective_id='.$objective->id, $this->asCustomer($this->accessToken(), $customer))->json('data');
        $this->assertSame($list, $response->json('data.kpi_list'));

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Annen KPI', 'description', 'status_history', 'quality', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_the_whole_malehistorikk_counts_current_measurements_only(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $recorder = $this->person($customer, 'Rita Registrerer');
        $objective = $this->objective($customer, $area, 'Lang historikk');
        $kpi = $this->kpi($objective, 'Oppetid', ['unit' => Kpi::UNIT_PERCENT, 'target_min' => '95', 'tolerance' => '2', 'frequency' => Kpi::FREQUENCY_MONTHLY]);
        $start = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(30);

        foreach (range(0, 29) as $offset) {
            $this->measurement($kpi, $start->addMonthsNoOverflow($offset), '99', $recorder, $start->addMonthsNoOverflow($offset + 1));
        }

        // The first period corrected later: only the correction counts.
        $corrected = $this->measurement($kpi, $start, '94', $recorder, $start->addMonthsNoOverflow(2));
        // The last period withdrawn: it does not count at all.
        $last = KpiMeasurement::query()->where('kpi_id', $kpi->id)->where('period_start', $start->addMonthsNoOverflow(29)->toDateString())->sole();
        $last->forceFill(['withdrawn_at' => now(), 'withdrawn_by_user_id' => $recorder->id, 'withdrawal_reason' => 'FRITEKST-TILBAKETREKKING'])->save();
        // The målverdi changed after the old measurements were registered.
        $kpi->forceFill(['target_min' => '99.5'])->save();
        $this->grantReader($client, $customer);

        $response = $this->get("/api/v1/kpis/{$kpi->id}/measurements", $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => ['*' => ['period_start', 'period_end', 'value', 'recorded_at', 'result']],
            'meta' => ['as_of', 'request_id'],
        ]);
        // Beyond the 24 periods of GET /kpis: 29 periods, the withdrawn one gone, oldest first.
        $response->assertJsonCount(29, 'data');
        $response->assertJsonPath('data.0', [
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'value' => '94',
            'recorded_at' => $corrected->recorded_at->utc()->toIso8601ZuluString(),
            // Against the målverdi it was registered with (95, tolerance 2), not today's.
            'result' => 'attention',
        ]);
        $response->assertJsonPath('data.28.period_start', $start->addMonthsNoOverflow(28)->toDateString());
        $response->assertJsonPath('data.28.result', 'on_target');

        $body = (string) $response->getContent();

        foreach (['FRITEKST', 'Rita', 'comment', 'withdraw', 'recorded_by'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_an_objective_or_kpi_of_another_customer_outside_the_fagomrader_or_not_there_is_not_found(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $salg = $this->businessArea($customer, 'Salg');
        $outside = $this->objective($customer, $this->businessArea($customer, 'Økonomi'), 'Utenfor fagområdet');
        $outsideKpi = $this->kpi($outside, 'Skjult KPI');
        $other = $this->apiCustomer('Annen kunde');
        $foreign = $this->objective($other, $this->businessArea($other, 'Salg'), 'Annen kundes mål');
        $foreignKpi = $this->kpi($foreign, 'Annen kundes KPI');
        $this->grantReader($client, $customer, [$salg]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        foreach ([
            "objectives/{$outside->id}", "objectives/{$foreign->id}", 'objectives/999999999',
            "kpis/{$outsideKpi->id}/measurements", "kpis/{$foreignKpi->id}/measurements", 'kpis/999999999/measurements',
        ] as $path) {
            $response = $this->get("/api/v1/{$path}", $headers);
            $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->assertStringNotContainsString('KPI', (string) $response->getContent());
        }

        $this->get("/api/v1/objectives/{$foreign->id}", $this->asCustomer($this->accessToken(), $other))->assertForbidden();
    }

    public function test_without_objective_view_the_module_an_active_grant_or_an_enabled_client_it_is_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $objective = $this->objective($customer, $this->businessArea($customer, 'Salg'), 'Hemmelig');
        $kpi = $this->kpi($objective, 'Hemmelig KPI');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::OBJECTIVE_MEASURE]);
        $grant = $this->grantTo($client, $customer, $identity);
        $urls = ["/api/v1/objectives/{$objective->id}", "/api/v1/kpis/{$kpi->id}/measurements"];

        foreach ($urls as $url) {
            $response = $this->get($url, $this->asCustomer($this->accessToken(), $customer));
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        $grant->forceFill(['revoked_at' => now()])->save();

        foreach ($urls as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $customer))->assertForbidden();
        }

        $withoutModule = $this->apiCustomer('Uten moduler', withRisk: false);
        $hidden = $this->objective($withoutModule, $this->businessArea($withoutModule, 'Salg'), 'Hemmelig');
        $hiddenKpi = $this->kpi($hidden, 'Hemmelig KPI');
        $this->grantReader($client, $withoutModule);

        foreach (["/api/v1/objectives/{$hidden->id}", "/api/v1/kpis/{$hiddenKpi->id}/measurements"] as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $withoutModule))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        $enabled = $this->apiCustomer('Aktiv');
        $readable = $this->objective($enabled, $this->businessArea($enabled, 'Salg'), 'Mål');
        $this->grantReader($client, $enabled);
        $client->forceFill(['is_enabled' => false])->save();
        $this->get("/api/v1/objectives/{$readable->id}", $this->asCustomer($this->accessToken(), $enabled))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_reading_an_objective_and_its_history_writes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $objective = $this->objective($customer, $this->businessArea($customer, 'Salg'), 'Uendret', ['target_date' => now()->subDay()->toDateString()]);
        $kpi = $this->kpi($objective, 'Uendret KPI', ['frequency' => Kpi::FREQUENCY_MONTHLY]);
        $this->measurement($kpi, CarbonImmutable::now()->subMonthsNoOverflow(3), '5', null, CarbonImmutable::now()->subMonthsNoOverflow(2));
        $this->grantReader($client, $customer, permissions: [
            CustomerPermissionCatalog::OBJECTIVE_VIEW,
            CustomerPermissionCatalog::OBJECTIVE_EDIT,
            CustomerPermissionCatalog::OBJECTIVE_MEASURE,
        ]);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $this->get("/api/v1/objectives/{$objective->id}", $headers)->assertOk()->assertJsonPath('data.attention', ['target_date_passed', 'owner_missing']);
        $this->get("/api/v1/kpis/{$kpi->id}/measurements", $headers)->assertOk();

        $this->assertSame($before, $this->domainState($customer));
    }

    /**
     * @param  list<BusinessArea>|null  $areas
     * @param  list<string>  $permissions
     */
    private function grantReader($client, Customer $customer, ?array $areas = null, array $permissions = [CustomerPermissionCatalog::OBJECTIVE_VIEW]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions, $areas);
        $this->grantTo($client, $customer, $identity);

        return $identity;
    }

    /** @param  array<string, mixed>  $overrides */
    private function objective(Customer $customer, BusinessArea $area, string $title, array $overrides = []): Objective
    {
        return Objective::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function kpi(Objective $objective, string $title, array $overrides = []): Kpi
    {
        return Kpi::query()->create(array_merge([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_NUMBER,
            'target_min' => '1',
        ], $overrides));
    }

    private function measurement(Kpi $kpi, CarbonImmutable $month, string $value, ?User $recorder, CarbonImmutable $recordedAt): KpiMeasurement
    {
        return KpiMeasurement::query()->create([
            'customer_id' => $kpi->customer_id,
            'kpi_id' => $kpi->id,
            'period_start' => $month->startOfMonth()->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'value' => $value,
            'comment' => 'FRITEKST-KOMMENTAR',
            'target_min' => $kpi->target_min,
            'target_max' => $kpi->target_max,
            'tolerance' => $kpi->tolerance,
            'recorded_by_user_id' => $recorder?->id,
            'recorded_at' => $recordedAt,
        ]);
    }

    private function person(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> every Mål og KPI row of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['objectives', 'kpis', 'kpi_measurements', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
