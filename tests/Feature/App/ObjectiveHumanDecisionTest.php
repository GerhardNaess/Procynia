<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Mål og KPI's human decisions — registering and withdrawing a measurement, deleting an objective or
 * a KPI — are refused in the service itself, phase 0 step 15 (docs/external-api-phase-0-plan.md §2k).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the objective's fagområde are turned away there, whatever the entry point, and
 * nothing is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class ObjectiveHumanDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_EDIT,
        CustomerPermissionCatalog::OBJECTIVE_MEASURE,
        CustomerPermissionCatalog::OBJECTIVE_DELETE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    /**
     * The (permission, area) pairs are read from the roles directly, so the rule that an integration
     * never holds a human-only key has to hold in ObjectiveAccessService too (A6, as in Risiko).
     */
    public function test_an_integration_holds_no_human_only_objective_key_through_its_roles(): void
    {
        $case = $this->case();
        $integration = $case['integration'];
        $access = app(ObjectiveAccessService::class);

        foreach ([CustomerPermissionCatalog::OBJECTIVE_MEASURE, CustomerPermissionCatalog::OBJECTIVE_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case['objective']), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
        }

        $this->assertFalse($access->canMeasure($integration, $case['objective']));
        $this->assertFalse($access->canDelete($integration, $case['objective']));

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case['objective']), $key);
            $this->assertTrue($access->reachesAllAreas($integration, $key), $key);
        }

        // The same role makes a person a full decider.
        $this->assertTrue($access->canMeasure($case['decider'], $case['objective']));
        $this->assertTrue($access->canDelete($case['decider'], $case['objective']));

        // An integration does not reach the customer frontend at all.
        $this->actingAs($integration)->get("/app/objectives/{$case['objective']->id}")->assertForbidden();
    }

    /**
     * @param  list<string>  $keys
     * @param  list<BusinessArea>  $areas
     */
    private function person(Customer $customer, array $keys, array $areas, bool $allAreas = false): User
    {
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions($keys);
        $role->syncBusinessAreas($allAreas, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        $person = $this->member($customer);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $person;
    }

    /**
     * One objective with two KPIs: «Oppetid» with a measurement (history), «Tom» without.
     *
     * @return array{customer: Customer, area: BusinessArea, objective: Objective, kpi: Kpi, empty: Kpi, measurement: KpiMeasurement, decider: User, integration: User, role: CustomerRole}
     */
    private function case(bool $allAreas = true): array
    {
        $customer = $this->customer('Målbeslutning AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);

        // By default «Alle» in one role holding every objective key: the strongest grant a role can give.
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Alt '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EVERYTHING);
        $role->syncBusinessAreas($allAreas, $allAreas ? [] : [(int) $area->id]);

        $decider = $this->member($customer);
        $decider->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $objective = Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => 'Stabil drift',
            'owner_user_id' => $decider->id,
        ]);
        $kpi = $this->kpi($objective, 'Oppetid');
        $empty = $this->kpi($objective, 'Tom');

        $measurement = KpiMeasurement::query()->create([
            'customer_id' => $customer->id,
            'kpi_id' => $kpi->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'value' => '99',
            'target_min' => '98',
            'recorded_by_user_id' => $decider->id,
            'recorded_at' => now()->subDay(),
        ]);

        return compact('customer', 'area', 'objective', 'kpi', 'empty', 'measurement', 'decider', 'integration', 'role');
    }

    private function kpi(Objective $objective, string $title): Kpi
    {
        return Kpi::query()->create([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '98',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    private function customer(string $name, bool $module = true): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'maal-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($module) {
            app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');
        }

        return $customer;
    }

    private function member(Customer $customer, string $role = User::ROLE_USER, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'maalbeslutning-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
