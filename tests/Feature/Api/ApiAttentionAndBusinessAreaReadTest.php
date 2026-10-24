<?php

namespace Tests\Feature\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\Risk;
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
 * GET /api/v1/attention (FEL-02, with RIS-03 and MOK-05) and GET /api/v1/business-areas (FEL-03):
 * across Risiko, Avvik og forbedringer and Mål og KPI, exactly what the identity may read there — its
 * customer, each module's view permission and its fagområder — and only in the modules it may read.
 */
class ApiAttentionAndBusinessAreaReadTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const ALL_VIEW = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
    }

    public function test_attention_lists_what_needs_following_up_in_every_readable_module_and_nothing_else(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $okonomi = $this->businessArea($customer, 'Økonomi');
        $owner = $this->person($customer, 'Eva Eier');

        $unassessed = $this->risk($customer, $drift, 'Ikke vurdert');
        $this->risk($customer, $okonomi, 'Utenfor fagområdet');
        $this->risk($customer, $drift, 'Lukket', Risk::STATUS_CLOSED);
        $overdueCase = $this->case($customer, $drift, ImprovementCase::TYPE_DEVIATION, 'Avvik over frist', [
            'owner_user_id' => $owner->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $this->case($customer, $drift, ImprovementCase::TYPE_DEVIATION, 'Avvik i orden', ['owner_user_id' => $owner->id]);
        $ownerless = $this->case($customer, $drift, ImprovementCase::TYPE_IMPROVEMENT, 'Forbedring uten ansvarlig');
        $passed = $this->objective($customer, $drift, 'Mål over måldato', [
            'owner_user_id' => $owner->id,
            'target_date' => now()->subDay()->toDateString(),
        ]);
        $offTarget = $this->kpi($passed, 'KPI under målverdi', ['target_min' => '10']);
        $this->measurement($offTarget, '5');
        $this->objective($customer, $okonomi, 'Skjult mål', ['target_date' => now()->subDay()->toDateString()]);

        $other = $this->apiCustomer('Annen kunde');
        $this->risk($other, $this->businessArea($other, 'Drift'), 'Annen kundes risiko');

        $this->grantReader($client, $customer, self::ALL_VIEW, [$drift]);

        $response = $this->get('/api/v1/attention', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => ['modules' => ['risk', 'deviations', 'objectives', 'compliance', 'suppliers'], 'items' => ['*' => ['type', 'id', 'title', 'objective_id', 'business_area', 'attention']]],
            'meta' => ['as_of', 'request_id'],
        ]);
        $area = ['id' => $drift->id, 'name' => 'Drift'];
        $response->assertJsonPath('data', [
            'modules' => ['risk' => true, 'deviations' => true, 'objectives' => true, 'compliance' => false, 'suppliers' => false],
            'items' => [
                ['type' => 'risk', 'id' => $unassessed->id, 'title' => 'Ikke vurdert', 'objective_id' => null, 'business_area' => $area, 'attention' => ['not_assessed']],
                ['type' => 'deviation', 'id' => $overdueCase->id, 'title' => 'Avvik over frist', 'objective_id' => null, 'business_area' => $area, 'attention' => ['case_overdue']],
                ['type' => 'improvement', 'id' => $ownerless->id, 'title' => 'Forbedring uten ansvarlig', 'objective_id' => null, 'business_area' => $area, 'attention' => ['case_owner_missing']],
                ['type' => 'objective', 'id' => $passed->id, 'title' => 'Mål over måldato', 'objective_id' => null, 'business_area' => $area, 'attention' => ['target_date_passed']],
                ['type' => 'kpi', 'id' => $offTarget->id, 'title' => 'KPI under målverdi', 'objective_id' => $passed->id, 'business_area' => $area, 'attention' => ['kpi_off_target']],
            ],
        ]);

        // The same codes as on each record's own rows.
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $this->get("/api/v1/risks/{$unassessed->id}", $headers)->assertJsonPath('data.attention', ['not_assessed']);
        $this->get("/api/v1/deviations/{$overdueCase->id}", $headers)->assertJsonPath('data.follow_up.attention', ['case_overdue']);
        $this->get("/api/v1/objectives/{$passed->id}", $headers)->assertJsonPath('data.attention', ['target_date_passed']);

        $body = (string) $response->getContent();

        foreach (['Utenfor fagområdet', 'Lukket', 'i orden', 'Skjult', 'Annen kunde', 'Økonomi', 'detail', '/app/'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    public function test_a_module_the_identity_cannot_read_is_reported_as_not_readable_and_contributes_nothing(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $risk = $this->risk($customer, $area, 'Ikke vurdert');
        $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Avvik uten ansvarlig');
        $this->objective($customer, $area, 'Mål uten ansvarlig');
        $this->grantReader($client, $customer, [CustomerPermissionCatalog::RISK_VIEW]);

        $this->get('/api/v1/attention', $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data.modules', ['risk' => true, 'deviations' => false, 'objectives' => false, 'compliance' => false, 'suppliers' => false])
            ->assertJsonPath('data.items.*.id', [$risk->id]);

        // A customer without the modules: nothing readable, still a 200 for the module-free route.
        $withoutModules = $this->apiCustomer('Uten moduler', withRisk: false);
        $this->risk($withoutModules, $this->businessArea($withoutModules, 'Drift'), 'Skjult');
        $this->grantReader($client, $withoutModules, self::ALL_VIEW);

        $this->get('/api/v1/attention', $this->asCustomer($this->accessToken(), $withoutModules))
            ->assertOk()
            ->assertJsonPath('data', ['modules' => ['risk' => false, 'deviations' => false, 'objectives' => false, 'compliance' => false, 'suppliers' => false], 'items' => []]);
        $this->get('/api/v1/business-areas', $this->asCustomer($this->accessToken(), $withoutModules))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_business_areas_lists_the_areas_the_identity_reads_in_with_their_modules(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $drift = $this->businessArea($customer, 'Drift');
        $salg = $this->businessArea($customer, 'Salg');
        $okonomi = $this->businessArea($customer, 'Økonomi');
        DB::table('business_areas')->where('id', $drift->id)->update(['description' => 'FRITEKST-BESKRIVELSE']);
        $other = $this->apiCustomer('Annen kunde');
        $this->businessArea($other, 'Annen kundes område');
        $identity = $this->integrationIdentity($customer);
        // Risiko in Drift and Salg; Mål og KPI in every area («Alle»); no Avvik og forbedringer.
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW], [$drift, $salg]);
        $this->giveRole($identity, [CustomerPermissionCatalog::OBJECTIVE_VIEW]);
        $this->grantTo($client, $customer, $identity);

        $response = $this->get('/api/v1/business-areas', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'modules']], 'meta' => ['as_of', 'request_id']]);
        $response->assertJsonPath('data', [
            ['id' => $drift->id, 'name' => 'Drift', 'modules' => ['risk', 'objectives']],
            ['id' => $salg->id, 'name' => 'Salg', 'modules' => ['risk', 'objectives']],
            ['id' => $okonomi->id, 'name' => 'Økonomi', 'modules' => ['objectives']],
        ]);

        // The same areas /me names per module.
        $me = $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer))->json('data.modules');
        $this->assertSame([$drift->id, $salg->id], array_column($me['risk']['business_areas'], 'id'));
        $this->assertSame('all', $me['objectives']['business_areas']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('FRITEKST', $body);
        $this->assertStringNotContainsString('Annen kunde', $body);
    }

    public function test_without_an_active_grant_or_an_enabled_client_both_are_refused(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->risk($customer, $this->businessArea($customer, 'Drift'), 'Hemmelig');
        $grant = $this->grantReader($client, $customer, self::ALL_VIEW);

        $grant->forceFill(['revoked_at' => now()])->save();

        foreach (['/api/v1/attention', '/api/v1/business-areas'] as $url) {
            $response = $this->get($url, $this->asCustomer($this->accessToken(), $customer));
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            $this->assertStringNotContainsString('Hemmelig', (string) $response->getContent());
        }

        $enabled = $this->apiCustomer('Aktiv');
        $this->grantReader($client, $enabled, self::ALL_VIEW);
        $client->forceFill(['is_enabled' => false])->save();

        foreach (['/api/v1/attention', '/api/v1/business-areas'] as $url) {
            $this->get($url, $this->asCustomer($this->accessToken(), $enabled))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
    }

    public function test_reading_attention_and_areas_writes_nothing_and_accepts_no_write(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $area = $this->businessArea($customer, 'Drift');
        $this->risk($customer, $area, 'Ikke vurdert');
        $this->case($customer, $area, ImprovementCase::TYPE_DEVIATION, 'Avvik', ['due_date' => now()->subDay()->toDateString()]);
        $this->objective($customer, $area, 'Mål', ['target_date' => now()->subDay()->toDateString()]);
        $this->grantReader($client, $customer, self::ALL_VIEW);
        $headers = $this->asCustomer($this->accessToken(), $customer);
        $before = $this->domainState($customer);

        $this->get('/api/v1/attention', $headers)->assertOk();
        $this->get('/api/v1/business-areas', $headers)->assertOk();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/api/v1/attention', '/api/v1/business-areas'] as $url) {
                $this->{$method.'Json'}($url, ['name' => 'Endret'], $headers)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
            }
        }

        $this->assertSame($before, $this->domainState($customer));
    }

    /** @param  list<string>  $permissions @param  list<BusinessArea>|null  $areas */
    private function grantReader($client, Customer $customer, array $permissions, ?array $areas = null)
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions, $areas);

        return $this->grantTo($client, $customer, $identity);
    }

    private function risk(Customer $customer, BusinessArea $area, string $title, string $status = Risk::STATUS_IDENTIFIED): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => $status,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function case(Customer $customer, BusinessArea $area, string $type, string $title, array $overrides = []): ImprovementCase
    {
        return ImprovementCase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => $type,
            'title' => $title,
            'description' => 'Beskrivelse',
        ], $overrides));
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

    private function measurement(Kpi $kpi, string $value): KpiMeasurement
    {
        $month = CarbonImmutable::now()->startOfMonth();

        return KpiMeasurement::query()->create([
            'customer_id' => $kpi->customer_id,
            'kpi_id' => $kpi->id,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'value' => $value,
            'target_min' => $kpi->target_min,
            'target_max' => $kpi->target_max,
            'tolerance' => $kpi->tolerance,
            'recorded_at' => now(),
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

    /** @return array<string, mixed> every row of the three modules and the areas of the customer, as stored */
    private function domainState(Customer $customer): array
    {
        $state = [];

        foreach (['business_areas', 'risks', 'risk_assessments', 'improvement_cases', 'improvement_actions', 'objectives', 'kpis', 'kpi_measurements', 'user_notifications'] as $table) {
            $state[$table] = DB::table($table)->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $state;
    }
}
