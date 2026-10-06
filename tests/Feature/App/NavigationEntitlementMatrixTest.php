<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\GovernanceController;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Bundle → module → permission → navigation, end to end on the server.
 *
 * The package catalog is the real one in config/procynia_modules.php — Basis, Styring
 * (`governance`), ISO, GRC, with Anbud (`tender`) as a separate add-on. Nothing here overrides it:
 * packages are configuration, the rail and the Styring landing page only ever see modules.
 *
 * What a person sees is read from the two places it is decided:
 *
 *  - the shared props the rail is built from (`entitlements.modules`, `access.permissions`), put
 *    through the rail's rule — module held AND view permission held — for the modules
 *    GovernanceController declares (GovernanceControllerTest holds that list to appModules.js);
 *  - the Styring landing page itself.
 *
 * Both must agree for every package, and both must drop a module when either the package or the
 * permission is missing.
 */
class NavigationEntitlementMatrixTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    /** Anbud is chosen per customer below, never handed out by default. */
    protected bool $customersHoldTenderPackage = false;

    private const VIEW_ALL = [
        CustomerPermissionCatalog::WIKI_VIEW,
        CustomerPermissionCatalog::QUALITY_VIEW,
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::COMPLIANCE_VIEW,
    ];

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

    /**
     * @return array<string, array{0: list<string>, 1: list<string>, 2: list<string>, 3: bool}>
     */
    public static function packageProvider(): array
    {
        $styring = ['quality', 'risk', 'objectives', 'improvements'];
        $iso = [...$styring, 'compliance'];

        return [
            'Basis' => [['basis'], ['wiki', 'quality', 'improvements'], ['quality', 'improvements'], false],
            'Basis + Anbud' => [['basis', 'tender'], ['wiki', 'tender', 'quality', 'improvements'], ['quality', 'improvements'], true],
            'Styring' => [['governance'], ['wiki', 'quality', 'risk', 'objectives', 'improvements'], $styring, false],
            'Styring + Anbud' => [['governance', 'tender'], ['wiki', 'tender', 'quality', 'risk', 'objectives', 'improvements'], $styring, true],
            'ISO' => [['iso'], ['wiki', 'quality', 'risk', 'objectives', 'improvements', 'compliance'], $iso, false],
            'ISO + Anbud' => [['iso', 'tender'], ['wiki', 'tender', 'quality', 'risk', 'objectives', 'improvements', 'compliance'], $iso, true],
            // GRC already carries Leverandøroppfølging; the module is not built, so Styring shows ISO's.
            'GRC' => [['grc'], ['wiki', 'quality', 'risk', 'objectives', 'improvements', 'compliance', 'supplier'], $iso, false],
            'GRC + Anbud' => [['grc', 'tender'], ['wiki', 'tender', 'quality', 'risk', 'objectives', 'improvements', 'compliance', 'supplier'], $iso, true],
            'Anbud alone' => [['tender'], ['wiki', 'tender'], [], true],
        ];
    }

    /**
     * @param  list<string>  $packages
     * @param  list<string>  $modules
     * @param  list<string>  $governance
     */
    #[DataProvider('packageProvider')]
    public function test_each_package_resolves_to_its_modules_and_the_rail_and_styring_agree(array $packages, array $modules, array $governance, bool $tender): void
    {
        $customer = $this->customerWith($packages);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::VIEW_ALL);

        $props = $this->sharedProps($user);

        $this->assertSame($modules, $props['entitlements']['modules']);
        $this->assertSame($governance, $this->railGovernance($props));
        $this->assertSame($governance, $this->governanceKeys($user));
        $this->assertSame($tender, in_array('tender', $props['entitlements']['modules'], true), 'Anbud is an add-on of its own');

        // Every package carries Wiki; the view permission gates it.
        $this->assertContains('wiki', $props['entitlements']['modules']);
        $this->actingAs($user)->get('/app/wiki')->assertOk();

        // The routes say the same as the navigation.
        $this->actingAs($user)->get('/app/notices')->assertStatus($tender ? 200 : 302);

        foreach (['/app/compliance/requirements', '/app/compliance/audits'] as $url) {
            $response = $this->actingAs($user)->get($url);
            in_array('compliance', $governance, true) ? $response->assertOk() : $response->assertRedirect();
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function viewPermissionProvider(): array
    {
        return [
            'Kvalitet' => [CustomerPermissionCatalog::QUALITY_VIEW, 'quality'],
            'Risiko' => [CustomerPermissionCatalog::RISK_VIEW, 'risk'],
            'Mål og KPI' => [CustomerPermissionCatalog::OBJECTIVE_VIEW, 'objectives'],
            'Avvik og forbedringer' => [CustomerPermissionCatalog::IMPROVEMENT_VIEW, 'improvements'],
            'Etterlevelse og revisjon' => [CustomerPermissionCatalog::COMPLIANCE_VIEW, 'compliance'],
        ];
    }

    #[DataProvider('viewPermissionProvider')]
    public function test_a_module_the_customer_holds_is_hidden_in_both_places_without_its_view_permission(string $permission, string $key): void
    {
        $customer = $this->customerWith(['iso', 'tender']);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, array_values(array_diff(self::VIEW_ALL, [$permission])));

        $props = $this->sharedProps($user);

        $this->assertContains(GovernanceController::MODULES[$key][0], $props['entitlements']['modules'], 'the customer holds it');
        $this->assertNotContains($permission, $props['access']['permissions']);
        $this->assertNotContains($key, $this->railGovernance($props));
        $this->assertNotContains($key, $this->governanceKeys($user));
        $this->assertSame($this->railGovernance($props), $this->governanceKeys($user));
    }

    #[DataProvider('viewPermissionProvider')]
    public function test_a_permission_without_the_module_is_hidden_in_both_places(string $permission, string $key): void
    {
        // Basis carries Kvalitet and Avvik og forbedringer only.
        $customer = $this->customerWith(['basis']);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::VIEW_ALL);

        $props = $this->sharedProps($user);
        $held = in_array($key, ['quality', 'improvements'], true);

        $this->assertContains($permission, $props['access']['permissions']);
        $this->assertSame($held, in_array($key, $this->railGovernance($props), true));
        $this->assertSame($held, in_array($key, $this->governanceKeys($user), true));
    }

    public function test_wiki_follows_its_view_permission_on_a_customer_that_holds_it(): void
    {
        $customer = $this->customerWith(['basis']);
        $without = $this->member($customer);
        $with = $this->member($customer);
        $this->grantAll($customer, $with, [CustomerPermissionCatalog::WIKI_VIEW]);

        $this->assertContains('wiki', $this->sharedProps($without)['entitlements']['modules']);
        $this->assertNotContains(CustomerPermissionCatalog::WIKI_VIEW, $this->sharedProps($without)['access']['permissions']);
        $this->assertContains(CustomerPermissionCatalog::WIKI_VIEW, $this->sharedProps($with)['access']['permissions']);
    }

    public function test_a_customer_with_no_package_holds_no_module_and_not_even_wiki(): void
    {
        // There is no mandatory package any more: Wiki comes with a package like everything else.
        $customer = $this->customerWith([]);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::VIEW_ALL);

        $this->assertSame([], $this->sharedProps($user)['entitlements']['modules']);
        $this->actingAs($user)->get('/app/wiki')->assertRedirect(route('app.dashboard'));
        $this->actingAs($user)->get('/app/governance')->assertForbidden();
    }

    public function test_only_the_new_package_keys_are_in_the_catalog(): void
    {
        $this->assertSame(
            ['basis', 'governance', 'iso', 'grc', 'tender'],
            array_keys(app(ModuleEntitlementService::class)->packages()),
        );

        // A row left over from the old catalog grants nothing.
        $customer = $this->customerWith(['quality', 'core']);
        $this->assertSame([], app(ModuleEntitlementService::class)->modulesFor($customer));
    }

    public function test_anbud_is_decided_by_the_add_on_alone_and_has_no_view_permission_of_its_own(): void
    {
        // Today no Anbud permission exists in CustomerPermissionCatalog: anyone in the customer
        // frontend reaches Anbud when the customer holds it. Recorded here so that introducing one
        // is a deliberate change, not a silent one.
        $this->assertSame([], array_filter(
            CustomerPermissionCatalog::all(),
            fn (string $key): bool => str_starts_with($key, 'tender.') || str_starts_with($key, 'bid.'),
        ));

        $withAddOn = $this->member($this->customerWith(['basis', 'tender']));
        $withoutAddOn = $this->member($this->customerWith(['iso']));

        $this->assertContains('tender', $this->sharedProps($withAddOn)['entitlements']['modules']);
        $this->assertNotContains('tender', $this->sharedProps($withoutAddOn)['entitlements']['modules']);
    }

    public function test_system_owner_follows_each_domain_with_no_bypass_into_compliance(): void
    {
        $customer = $this->customerWith(['iso', 'tender']);
        $owner = User::query()->where('customer_id', $customer->id)->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)->firstOrFail();

        $props = $this->sharedProps($owner);

        $this->assertSame(['quality', 'risk', 'objectives', 'improvements'], $this->railGovernance($props));
        $this->assertSame(['quality', 'risk', 'objectives', 'improvements'], $this->governanceKeys($owner));
        $this->assertNotContains(CustomerPermissionCatalog::COMPLIANCE_VIEW, $props['access']['permissions']);
    }

    public function test_the_order_is_the_module_sort_order_in_config_for_both_the_rail_and_styring(): void
    {
        $customer = $this->customerWith(['iso']);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::VIEW_ALL);

        $this->assertSame(['quality', 'risk', 'objectives', 'improvements', 'compliance'], $this->governanceKeys($user));

        // Move Etterlevelse og revisjon first and Kvalitet last: both lists follow, neither keeps
        // an order of its own.
        config([
            'procynia_modules.modules.compliance.sort_order' => 1,
            'procynia_modules.modules.quality.sort_order' => 99,
        ]);

        $expected = ['compliance', 'risk', 'objectives', 'improvements', 'quality'];
        $this->assertSame($expected, $this->governanceKeys($user));
        $this->assertSame($expected, $this->railGovernance($this->sharedProps($user)));
    }

    public function test_with_no_governance_module_there_is_no_styring_anywhere(): void
    {
        $customer = $this->customerWith(['tender']);
        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::VIEW_ALL);

        $this->assertSame([], $this->railGovernance($this->sharedProps($user)));
        $this->actingAs($user)->get('/app/governance')->assertForbidden();
    }

    /**
     * @param  list<string>  $packages
     */
    private function customerWith(array $packages): Customer
    {
        ['customer' => $customer] = $this->context(null);

        foreach ($packages as $package) {
            CustomerPackageEntitlement::query()->create([
                'customer_id' => $customer->id,
                'package_key' => $package,
                'status' => CustomerPackageEntitlement::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedProps(User $user): array
    {
        return $this->actingAs($user)->get('/app/dashboard')->assertOk()->viewData('page')['props'];
    }

    /**
     * The rail's rule (appModules.js moduleAvailability), applied to what the rail is handed.
     *
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private function railGovernance(array $props): array
    {
        $modules = $props['entitlements']['modules'];
        $permissions = $props['access']['permissions'];
        $byModule = [];

        foreach (GovernanceController::MODULES as $key => [$module, $permission]) {
            if (in_array($module, $modules, true) && in_array($permission, $permissions, true)) {
                $byModule[$module] = $key;
            }
        }

        // In the order the rail lists them: the order of `entitlements.modules`.
        return array_values(array_filter(array_map(fn (string $module): ?string => $byModule[$module] ?? null, $modules)));
    }

    /**
     * @return list<string>
     */
    private function governanceKeys(User $user): array
    {
        $response = $this->actingAs($user)->get('/app/governance');

        if ($response->status() !== 200) {
            return [];
        }

        return array_column($response->viewData('page')['props']['modules'], 'key');
    }
}
