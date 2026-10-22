<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko's human decisions — assessing a risk, accepting and revoking residual risk, deleting a
 * risk — are refused in the service itself, phase 0 step 9 (docs/external-api-phase-0-plan.md §4.4).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the risk's fagområde are turned away there, whatever the entry point, and nothing
 * is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class RiskHumanDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_CREATE,
        CustomerPermissionCatalog::RISK_EDIT,
        CustomerPermissionCatalog::RISK_ASSESS,
        CustomerPermissionCatalog::RISK_ACCEPT,
        CustomerPermissionCatalog::RISK_DELETE,
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
     * never holds a human-only key has to hold in RiskAccessService too — not only in
     * CustomerPermissionService::has().
     */
    public function test_an_integration_holds_no_human_only_risk_key_through_its_roles(): void
    {
        $case = $this->case();
        $integration = $case['integration'];
        $access = app(RiskAccessService::class);

        foreach ([CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT, CustomerPermissionCatalog::RISK_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case['risk']), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
        }

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE, CustomerPermissionCatalog::RISK_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case['risk']), $key);
            $this->assertTrue($access->reachesAllAreas($integration, $key), $key);
        }

        // The same role makes a person a full decider.
        $this->assertTrue($access->can($case['decider'], CustomerPermissionCatalog::RISK_ACCEPT, $case['risk']));

        // An integration does not reach the customer frontend at all.
        $this->actingAs($integration)->get("/app/risk/risks/{$case['risk']->id}")->assertForbidden();
    }

    /** @return array{customer: Customer, area: BusinessArea, risk: Risk, decider: User, integration: User, role: CustomerRole} */
    private function case(): array
    {
        $customer = $this->customer('Risikobeslutning AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);
        $risk = $this->risk($customer, $area, 'Strømbrudd');

        // «Alle» in one role holding every risk key: the strongest grant a role can give.
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Alt '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EVERYTHING);
        $role->syncBusinessAreas(true, []);

        $decider = $this->member($customer);
        $decider->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return compact('customer', 'area', 'risk', 'decider', 'integration', 'role');
    }

    private function customer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'risiko-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        return $customer;
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    private function member(Customer $customer, string $role = User::ROLE_USER): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risikobeslutning-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
