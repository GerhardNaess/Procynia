<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Commercial packages, the technical modules they switch on, and the one package nobody buys.
 *
 * The point these tests defend: activation is decided in the backend, derived from the package ->
 * module mapping, and Wiki/Core is not a purchase decision at all.
 */
class CustomerModuleEntitlementTest extends TestCase
{
    use UsesProjectPostgresConnection;

    /** This file is about which packages a customer holds, so it starts from none. */
    protected bool $customersHoldTenderPackage = false;

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

    // ---------------------------------------------------------------------
    // Package -> technical module mapping
    // ---------------------------------------------------------------------

    public function test_each_commercial_package_maps_to_its_technical_modules(): void
    {
        $service = app(ModuleEntitlementService::class);

        $this->assertSame(['tender'], $service->modulesForPackage('tender'));
        // Mål og KPI is a general management area, and Avvik og forbedringer is quality management;
        // both are reached through Kvalitet and GRC.
        $this->assertSame(['quality', 'objectives', 'improvements'], $service->modulesForPackage('quality'));
        $this->assertSame(
            ['quality', 'risk', 'objectives', 'improvements', 'compliance'],
            $service->modulesForPackage('grc'),
            'GRC is the compound package and must carry all five modules.'
        );
        $this->assertSame([ModuleEntitlementService::MODULE_WIKI_CORE], $service->modulesForPackage('core'));
    }

    public function test_a_module_a_package_claims_but_the_catalog_does_not_know_grants_nothing(): void
    {
        config()->set('procynia_modules.packages.grc.modules', ['quality', 'typo_module']);

        $this->assertSame(
            ['quality'],
            app(ModuleEntitlementService::class)->modulesForPackage('grc'),
        );
    }

    public function test_extending_a_package_reaches_customers_who_already_hold_it(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'grc');

        // The mapping is resolved at read time, so GRC gaining supplier/contracts later needs no
        // change to anyone's stored entitlement.
        config()->set('procynia_modules.packages.grc.modules', ['quality', 'risk', 'compliance', 'supplier', 'contracts']);

        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertContains('supplier', $modules);
        $this->assertContains('contracts', $modules);
    }

    // ---------------------------------------------------------------------
    // Customer entitlements
    // ---------------------------------------------------------------------

    public function test_a_customer_without_entitlements_has_only_the_mandatory_module(): void
    {
        $customer = $this->createCustomer();

        $this->assertSame(
            [ModuleEntitlementService::MODULE_WIKI_CORE],
            app(ModuleEntitlementService::class)->modulesFor($customer),
        );
        $this->assertFalse($customer->hasModule('tender'));
        $this->assertFalse($customer->hasModule('quality'));
    }

    public function test_an_active_package_grants_every_module_it_maps_to(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'grc');

        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertSame(
            [ModuleEntitlementService::MODULE_WIKI_CORE, 'quality', 'risk', 'objectives', 'improvements', 'compliance'],
            $modules,
        );
    }

    public function test_overlapping_packages_resolve_to_a_union_without_duplicates(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'quality');
        $this->grant($customer, 'grc');

        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertSame($modules, array_values(array_unique($modules)));
        $this->assertContains('quality', $modules);
        $this->assertContains('risk', $modules);
        $this->assertContains('objectives', $modules);
        $this->assertSame(1, count(array_keys($modules, 'objectives', true)));
        $this->assertSame(1, count(array_keys($modules, 'improvements', true)));
    }

    public function test_a_package_that_is_not_active_grants_nothing(): void
    {
        $customer = $this->createCustomer();

        foreach ([
            CustomerPackageEntitlement::STATUS_REQUESTED,
            CustomerPackageEntitlement::STATUS_DECLINED,
            CustomerPackageEntitlement::STATUS_REVOKED,
        ] as $status) {
            $customer->packageEntitlements()->updateOrCreate(
                ['package_key' => 'tender'],
                ['status' => $status],
            );

            $this->assertFalse(
                $customer->fresh()->hasModule('tender'),
                "Status [{$status}] must not grant the tender module."
            );
        }
    }

    public function test_an_entitlement_for_a_package_no_longer_in_the_catalog_grants_nothing(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'tender');

        config()->set('procynia_modules.packages', array_diff_key(
            config('procynia_modules.packages'),
            ['tender' => null],
        ));

        $this->assertNotContains('tender', app(ModuleEntitlementService::class)->activePackageKeys($customer->fresh()));
    }

    public function test_entitlements_do_not_leak_between_customers(): void
    {
        $owner = $this->createCustomer('Kunde A');
        $other = $this->createCustomer('Kunde B');
        $this->grant($owner, 'tender');

        $this->assertTrue($owner->fresh()->hasModule('tender'));
        $this->assertFalse($other->fresh()->hasModule('tender'));
    }

    // ---------------------------------------------------------------------
    // Wiki/Core is mandatory
    // ---------------------------------------------------------------------

    public function test_wiki_core_is_active_for_every_customer_with_no_row_of_its_own(): void
    {
        $customer = $this->createCustomer();

        $this->assertTrue($customer->hasModule(ModuleEntitlementService::MODULE_WIKI_CORE));
        $this->assertTrue(app(ModuleEntitlementService::class)->hasPackage($customer, ModuleEntitlementService::CORE_PACKAGE));
        $this->assertSame(0, $customer->packageEntitlements()->count());
    }

    public function test_wiki_core_cannot_be_ordered(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(InvalidArgumentException::class);

        app(ModuleEntitlementService::class)->requestPackage($customer, ModuleEntitlementService::CORE_PACKAGE);
    }

    public function test_wiki_core_survives_a_revoked_row_being_forced_into_the_table(): void
    {
        $customer = $this->createCustomer();

        // Nothing should create this row, but if one ever appears it must not switch Core off.
        $customer->packageEntitlements()->create([
            'package_key' => ModuleEntitlementService::CORE_PACKAGE,
            'status' => CustomerPackageEntitlement::STATUS_REVOKED,
        ]);

        $this->assertTrue($customer->fresh()->hasModule(ModuleEntitlementService::MODULE_WIKI_CORE));
    }

    public function test_the_overview_reports_wiki_core_as_included_and_not_orderable(): void
    {
        $customer = $this->createCustomer();

        $core = collect(app(ModuleEntitlementService::class)->overviewFor($customer))
            ->firstWhere('key', ModuleEntitlementService::CORE_PACKAGE);

        $this->assertSame('included', $core['status']);
        $this->assertTrue($core['mandatory']);
        $this->assertFalse($core['orderable']);
        $this->assertFalse($core['can_order']);
    }

    // ---------------------------------------------------------------------
    // Ordering
    // ---------------------------------------------------------------------

    public function test_requesting_a_package_records_an_order_without_granting_access(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $entitlement = app(ModuleEntitlementService::class)
            ->requestPackage($customer, 'tender', $context['owner']);

        $this->assertSame(CustomerPackageEntitlement::STATUS_REQUESTED, $entitlement->status);
        $this->assertSame($context['owner']->id, $entitlement->requested_by);
        $this->assertNotNull($entitlement->requested_at);
        $this->assertFalse($customer->fresh()->hasModule('tender'));
    }

    public function test_activating_a_package_reuses_a_revoked_row_and_keeps_the_original_order(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $customer->packageEntitlements()->create([
            'package_key' => 'quality',
            'status' => CustomerPackageEntitlement::STATUS_REVOKED,
            'requested_by' => $context['owner']->id,
            'requested_at' => now()->subYear(),
            'deactivated_at' => now()->subMonth(),
        ]);

        $entitlement = app(ModuleEntitlementService::class)->activatePackage($customer->fresh(), 'quality');

        $this->assertSame(1, $customer->packageEntitlements()->where('package_key', 'quality')->count());
        $this->assertSame(CustomerPackageEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertNull($entitlement->deactivated_at);
        $this->assertSame($context['owner']->id, $entitlement->requested_by);
        $this->assertTrue($entitlement->requested_at->isBefore(now()->subMonths(6)));
        $this->assertTrue($customer->fresh()->hasModule('quality'));
    }

    public function test_requesting_an_active_package_leaves_it_active(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'tender');

        $entitlement = app(ModuleEntitlementService::class)->requestPackage($customer->fresh(), 'tender');

        $this->assertSame(CustomerPackageEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertTrue($customer->fresh()->hasModule('tender'));
    }

    public function test_system_owner_can_order_a_package_from_the_subscription_page(): void
    {
        $context = $this->systemOwnerContext();

        $response = $this->actingAs($context['owner'])
            ->post('/app/billing/packages/grc/request');

        $response->assertRedirect(route('app.billing.index'));
        $response->assertSessionHas('success');

        $entitlement = $context['customer']->packageEntitlements()->firstWhere('package_key', 'grc');

        $this->assertNotNull($entitlement);
        $this->assertSame(CustomerPackageEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertNotNull($entitlement->activated_at);
        $this->assertSame($context['owner']->id, $entitlement->requested_by);

        // GRC is the compound package: ordering it must switch on every module it maps to, not
        // just the one with pages.
        $customer = $context['customer']->fresh();

        $this->assertTrue($customer->hasModule('quality'));
        $this->assertTrue($customer->hasModule('risk'));
        $this->assertTrue($customer->hasModule('compliance'));
    }

    public function test_ordering_quality_opens_the_module_on_the_next_request(): void
    {
        $context = $this->systemOwnerContext();

        // Before the order the guard sends the request to Hjem — that is the state being fixed.
        $this->actingAs($context['owner'])
            ->get('/app/quality')
            ->assertRedirect(route('app.dashboard'));

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/quality/request')
            ->assertRedirect(route('app.billing.index'));

        $this->assertTrue($context['customer']->fresh()->hasModule('quality'));

        // No logout, no cache clearing: the very next request reaches the page.
        $this->actingAs($context['owner'])
            ->get('/app/quality')
            ->assertOk();
    }

    public function test_ordering_refreshes_the_shared_entitlements_the_left_rail_reads(): void
    {
        $context = $this->systemOwnerContext();

        $before = $this->actingAs($context['owner'])->get('/app/billing')->viewData('page')['props'];

        $this->assertNotContains('quality', $before['entitlements']['modules']);

        $this->actingAs($context['owner'])->post('/app/billing/packages/quality/request');

        // The rail renders `entitlements.modules`; the redirect's GET is what has to carry the
        // new value, or the menu keeps saying "Ikke bestilt" while the route is already open.
        $after = $this->actingAs($context['owner'])->get('/app/billing')->viewData('page')['props'];

        $this->assertContains('quality', $after['entitlements']['modules']);
        $this->assertContains('quality', $after['active_modules']);
    }

    public function test_the_mandatory_package_has_no_order_endpoint(): void
    {
        $context = $this->systemOwnerContext();

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/core/request')
            ->assertNotFound();

        $this->assertSame(0, $context['customer']->packageEntitlements()->count());
    }

    public function test_an_unknown_package_cannot_be_ordered(): void
    {
        $context = $this->systemOwnerContext();

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/does-not-exist/request')
            ->assertNotFound();
    }

    public function test_a_user_without_billing_access_cannot_order(): void
    {
        $context = $this->viewerContext();

        $this->actingAs($context['user'])
            ->post('/app/billing/packages/tender/request')
            ->assertForbidden();

        $this->assertSame(0, $context['customer']->packageEntitlements()->count());
    }

    // ---------------------------------------------------------------------
    // The page renders the backend verdict
    // ---------------------------------------------------------------------

    public function test_the_subscription_page_exposes_resolved_packages_and_modules(): void
    {
        $context = $this->systemOwnerContext();
        $this->grant($context['customer'], 'tender');

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $packages = collect($props['module_packages']);

        $this->assertSame('included', $packages->firstWhere('key', 'core')['status']);
        $this->assertSame('active', $packages->firstWhere('key', 'tender')['status']);
        $this->assertSame('available', $packages->firstWhere('key', 'quality')['status']);
        $this->assertSame('available', $packages->firstWhere('key', 'grc')['status']);
        $this->assertTrue($packages->firstWhere('key', 'grc')['can_order']);
        $this->assertFalse($packages->firstWhere('key', 'tender')['can_order']);

        $this->assertSame(
            [ModuleEntitlementService::MODULE_WIKI_CORE, 'tender'],
            $props['active_modules'],
        );

        // The AI capacity block the page already had must survive the new section.
        $this->assertArrayHasKey('ai_quota', $props);
    }

    public function test_an_ordered_package_shows_as_active_and_cannot_be_ordered_again(): void
    {
        $context = $this->systemOwnerContext();

        $this->actingAs($context['owner'])->post('/app/billing/packages/quality/request');

        $props = $this->actingAs($context['owner'])->get('/app/billing')->viewData('page')['props'];
        $quality = collect($props['module_packages'])->firstWhere('key', 'quality');

        $this->assertSame('active', $quality['status']);
        $this->assertFalse($quality['can_order']);
        $this->assertNotNull($quality['requested_at']);
        $this->assertNotNull($quality['activated_at']);
    }

    public function test_ordering_an_already_active_package_is_refused_without_touching_it(): void
    {
        $context = $this->systemOwnerContext();
        $granted = $this->grant($context['customer'], 'quality');

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/quality/request')
            ->assertSessionHas('error');

        $this->assertSame(
            $granted->activated_at->toDateTimeString(),
            $granted->fresh()->activated_at->toDateTimeString(),
        );
        $this->assertTrue($context['customer']->fresh()->hasModule('quality'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function grant(Customer $customer, string $packageKey): CustomerPackageEntitlement
    {
        return $customer->packageEntitlements()->updateOrCreate(
            ['package_key' => $packageKey],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );
    }

    private function systemOwnerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => Str::slug($customerName).'.module.owner@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function viewerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::query()->create([
            'name' => 'Viewer',
            'email' => Str::slug($customerName).'.module.viewer@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user];
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
