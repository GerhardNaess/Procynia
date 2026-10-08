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
 * Commercial packages and the technical modules they switch on.
 *
 * The point these tests defend: activation is decided in the backend, derived from the package ->
 * module mapping, and a package is only ever held through an entitlement row. Basis is mandatory
 * because every customer is given its row and it cannot be cancelled — not because it is assumed
 * at read time. Ordering and cancelling options is PackageChangeTest's.
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

        $this->assertSame(['wiki', 'quality', 'improvements'], $service->modulesForPackage('basis'));

        // Each option carries its own module and nothing else.
        foreach (['risk', 'objectives', 'compliance', 'supplier'] as $option) {
            $this->assertSame([$option], $service->modulesForPackage($option), $option);
        }

        // Anbud also carries Wiki, because the bid engine answers from it.
        $this->assertSame(['wiki', 'tender'], $service->modulesForPackage('tender'));
    }

    public function test_the_catalog_is_basis_plus_five_independent_options(): void
    {
        $packages = app(ModuleEntitlementService::class)->packages();

        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'supplier', 'tender'], array_keys($packages));
        $this->assertSame('base', $packages['basis']['kind']);

        foreach (['risk', 'objectives', 'compliance', 'supplier', 'tender'] as $option) {
            $this->assertSame('option', $packages[$option]['kind'], $option);
            $this->assertTrue($packages[$option]['orderable'], $option);
        }
    }

    public function test_a_bundle_is_only_a_shortcut_for_basis_and_its_options(): void
    {
        $service = app(ModuleEntitlementService::class);

        $this->assertSame(['basis', 'risk', 'objectives'], $service->bundlePackages('governance'));
        $this->assertSame(['basis', 'risk', 'objectives', 'compliance'], $service->bundlePackages('iso'));
        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'supplier'], $service->bundlePackages('grc'));
        $this->assertSame([], $service->bundlePackages('tender'));

        $customer = $this->createCustomer();
        $service->activatePackage($customer, 'grc');

        // What is stored is Basis and four options — no `grc` row to tie them together.
        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'supplier'], $service->activePackageKeys($customer->fresh()));
        $this->assertSame(0, $customer->packageEntitlements()->where('package_key', 'grc')->count());
    }

    public function test_a_module_a_package_claims_but_the_catalog_does_not_know_grants_nothing(): void
    {
        config()->set('procynia_modules.packages.supplier.modules', ['supplier', 'typo_module']);

        $this->assertSame(
            ['supplier'],
            app(ModuleEntitlementService::class)->modulesForPackage('supplier'),
        );
    }

    public function test_extending_a_package_reaches_customers_who_already_hold_it(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'supplier');

        // The mapping is resolved at read time, so Leverandøroppfølging gaining contracts later needs
        // no change to anyone's stored entitlement.
        config()->set('procynia_modules.packages.supplier.modules', ['supplier', 'contracts']);

        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertContains('supplier', $modules);
        $this->assertContains('contracts', $modules);
    }

    // ---------------------------------------------------------------------
    // Customer entitlements
    // ---------------------------------------------------------------------

    public function test_a_customer_without_entitlements_has_no_module_at_all(): void
    {
        $customer = $this->createCustomer();

        $this->assertSame([], app(ModuleEntitlementService::class)->activePackageKeys($customer));
        $this->assertSame([], app(ModuleEntitlementService::class)->modulesFor($customer));
        $this->assertFalse($customer->hasModule('wiki'));
        $this->assertFalse($customer->hasModule('tender'));
        $this->assertFalse($customer->hasModule('quality'));
    }

    public function test_an_active_package_grants_every_module_it_maps_to(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'basis');

        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertSame(['wiki', 'quality', 'improvements'], $modules);
    }

    public function test_overlapping_packages_resolve_to_a_union_without_duplicates(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'basis');
        $this->grant($customer, 'compliance');
        $this->grant($customer, 'tender');

        // Basis and Anbud both carry Wiki; it is listed once.
        $modules = app(ModuleEntitlementService::class)->modulesFor($customer->fresh());

        $this->assertSame(['wiki', 'tender', 'quality', 'improvements', 'compliance'], $modules);
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

    public function test_rows_for_the_retired_packages_grant_nothing(): void
    {
        $customer = $this->createCustomer();

        // `quality` and `core` left the catalog with the move to the ladder.
        foreach (['quality', 'core'] as $retired) {
            $this->grant($customer, $retired);
        }

        $this->assertSame([], app(ModuleEntitlementService::class)->activePackageKeys($customer->fresh()));
        $this->assertSame([], app(ModuleEntitlementService::class)->modulesFor($customer->fresh()));
    }

    public function test_a_retired_package_cannot_be_requested(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ModuleEntitlementService::class)->requestPackage($this->createCustomer(), 'core');
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

    // ---------------------------------------------------------------------
    // Default package for a new customer
    // ---------------------------------------------------------------------

    public function test_a_new_customer_is_given_basis_and_exactly_its_modules(): void
    {
        $customer = $this->createCustomer();
        $service = app(ModuleEntitlementService::class);

        $entitlement = $service->grantDefaultPackage($customer);

        $this->assertSame('basis', $entitlement?->package_key);
        $this->assertSame(CustomerPackageEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertSame(['basis'], $service->activePackageKeys($customer->fresh()));
        $this->assertSame(['wiki', 'quality', 'improvements'], $service->modulesFor($customer->fresh()));

        foreach (['risk', 'objectives', 'compliance', 'tender', 'supplier'] as $module) {
            $this->assertFalse($service->hasModule($customer->fresh(), $module), $module);
        }
    }

    public function test_granting_the_default_twice_never_writes_a_second_basis_row(): void
    {
        $customer = $this->createCustomer();
        $service = app(ModuleEntitlementService::class);

        $service->grantDefaultPackage($customer);
        $service->grantDefaultPackage($customer->fresh());

        $this->assertSame(1, $customer->packageEntitlements()->count());
        $this->assertSame(1, $customer->packageEntitlements()->where('package_key', 'basis')->count());
    }

    public function test_an_option_does_not_stand_in_for_basis(): void
    {
        $customer = $this->createCustomer();
        $this->grant($customer, 'tender');
        $service = app(ModuleEntitlementService::class);

        $service->grantDefaultPackage($customer->fresh());

        $this->assertSame(['basis', 'tender'], $service->activePackageKeys($customer->fresh()));
    }

    public function test_activating_a_package_reuses_a_revoked_row_and_keeps_the_original_order(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $customer->packageEntitlements()->create([
            'package_key' => 'basis',
            'status' => CustomerPackageEntitlement::STATUS_REVOKED,
            'requested_by' => $context['owner']->id,
            'requested_at' => now()->subYear(),
            'deactivated_at' => now()->subMonth(),
        ]);

        $entitlement = app(ModuleEntitlementService::class)->activatePackage($customer->fresh(), 'basis');

        $this->assertSame(1, $customer->packageEntitlements()->where('package_key', 'basis')->count());
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
            ->post('/app/billing/packages/supplier/request');

        $response->assertRedirect(route('app.billing.index'));
        $response->assertSessionHas('success');

        $entitlement = $context['customer']->packageEntitlements()->firstWhere('package_key', 'supplier');

        $this->assertNotNull($entitlement);
        $this->assertSame(CustomerPackageEntitlement::STATUS_ACTIVE, $entitlement->status);
        $this->assertNotNull($entitlement->activated_at);
        $this->assertSame($context['owner']->id, $entitlement->requested_by);

        // The option switches on its own module and nothing it does not carry.
        $customer = $context['customer']->fresh();

        $this->assertTrue($customer->hasModule('supplier'));
        $this->assertFalse($customer->hasModule('compliance'), 'Leverandøroppfølging does not require Etterlevelse og revisjon.');
        $this->assertFalse($customer->hasModule('risk'));
    }

    public function test_ordering_basis_opens_the_module_on_the_next_request(): void
    {
        $context = $this->systemOwnerContext();

        // Before the order the guard sends the request to Hjem — that is the state being fixed.
        $this->actingAs($context['owner'])
            ->get('/app/quality')
            ->assertRedirect(route('app.dashboard'));

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/basis/request')
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

        $this->actingAs($context['owner'])->post('/app/billing/packages/basis/request');

        // The rail renders `entitlements.modules`; the redirect's GET is what has to carry the
        // new value, or the menu keeps saying "Ikke bestilt" while the route is already open.
        $after = $this->actingAs($context['owner'])->get('/app/billing')->viewData('page')['props'];

        $this->assertContains('quality', $after['entitlements']['modules']);
        $this->assertContains('quality', $after['active_modules']);
    }

    public function test_the_retired_packages_cannot_be_ordered(): void
    {
        $context = $this->systemOwnerContext();

        foreach (['core', 'quality'] as $retired) {
            $this->actingAs($context['owner'])
                ->post("/app/billing/packages/{$retired}/request")
                ->assertNotFound();
        }

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

        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'supplier', 'tender'], $packages->pluck('key')->all());
        $this->assertSame('active', $packages->firstWhere('key', 'tender')['status']);
        // A customer without its Basis row is shown as such, and can put it right.
        $this->assertSame(['available', 'order'], [$packages->firstWhere('key', 'basis')['status'], $packages->firstWhere('key', 'basis')['action']]);
        $this->assertSame(['available', 'order'], [$packages->firstWhere('key', 'supplier')['status'], $packages->firstWhere('key', 'supplier')['action']]);
        $this->assertSame('cancel', $packages->firstWhere('key', 'tender')['action']);
        $this->assertFalse($packages->firstWhere('key', 'tender')['can_order']);

        $this->assertSame(['wiki', 'tender'], $props['active_modules']);

        // The AI capacity block the page already had must survive the new section.
        $this->assertArrayHasKey('ai_quota', $props);
    }

    public function test_an_ordered_package_shows_as_active_and_cannot_be_ordered_again(): void
    {
        $context = $this->systemOwnerContext();

        $this->actingAs($context['owner'])->post('/app/billing/packages/basis/request');

        $props = $this->actingAs($context['owner'])->get('/app/billing')->viewData('page')['props'];
        $quality = collect($props['module_packages'])->firstWhere('key', 'basis');

        $this->assertSame('active', $quality['status']);
        $this->assertFalse($quality['can_order']);
        $this->assertNotNull($quality['requested_at']);
        $this->assertNotNull($quality['activated_at']);
    }

    public function test_ordering_an_already_active_package_is_refused_without_touching_it(): void
    {
        $context = $this->systemOwnerContext();
        $granted = $this->grant($context['customer'], 'basis');

        $this->actingAs($context['owner'])
            ->post('/app/billing/packages/basis/request')
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
