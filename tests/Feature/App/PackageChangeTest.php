<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\Objective;
use App\Models\Risk;
use App\Models\SavedNotice;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierDocument;
use App\Models\SupplierRisk;
use App\Models\SupplierStatusChange;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Ordering and cancelling options, one at a time, on top of the mandatory Basis.
 *
 * What is defended: every option is ordered and cancelled on its own, Basis never is, cancelling
 * only takes access away — never data, links, roles or permissions — and ordering again finds
 * everything as it was. Existing ladder customers are moved to exactly the options they had. Which
 * module each package carries, and that the rail follows module and permission together, is
 * CustomerModuleEntitlementTest's and NavigationEntitlementMatrixTest's.
 */
class PackageChangeTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const OPTIONS = ['risk', 'objectives', 'compliance', 'supplier', 'tender'];

    /** Each test decides for itself which options the customer holds. */
    protected bool $customersHoldTenderPackage = false;

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

    public function test_each_option_is_ordered_on_its_own_and_adds_only_its_module(): void
    {
        foreach (self::OPTIONS as $option) {
            ['customer' => $customer, 'owner' => $owner] = $this->context('basis');

            $this->actingAs($owner)->post("/app/billing/packages/{$option}/request")
                ->assertRedirect(route('app.billing.index'))
                ->assertSessionHas('success');

            $this->assertSame(['basis', $option], $this->activeKeys($customer), $option);
            $this->assertSame(
                [$option],
                array_values(array_diff(app(ModuleEntitlementService::class)->modulesFor($customer), ['wiki', 'quality', 'improvements', 'management_review'])),
                "{$option} adds its own module and nothing else",
            );
        }
    }

    public function test_each_option_is_cancelled_on_its_own_and_basis_never_is(): void
    {
        foreach (self::OPTIONS as $option) {
            ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
            $this->activate($customer, self::OPTIONS);

            $this->actingAs($owner)->post("/app/billing/packages/{$option}/cancel")
                ->assertRedirect(route('app.billing.index'))
                ->assertSessionHas('success');

            $this->assertSame(['basis', ...array_values(array_diff(self::OPTIONS, [$option]))], $this->activeKeys($customer), $option);
            $this->assertSame(CustomerPackageEntitlement::STATUS_REVOKED, $this->row($customer, $option)->status);
            $this->assertNotNull($this->row($customer, $option)->deactivated_at);
        }

        // Basis is not an option: there is no cancelling it, and the overview offers no action.
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
        $this->actingAs($owner)->post('/app/billing/packages/basis/cancel')->assertNotFound();
        $this->assertSame(['basis'], $this->activeKeys($customer));

        $basis = collect($this->actingAs($owner)->get('/app/billing')->assertOk()->viewData('page')['props']['module_packages'])->firstWhere('key', 'basis');
        $this->assertSame(['base', 'active', null], [$basis['kind'], $basis['status'], $basis['action']]);
    }

    /**
     * The central requirement: Leverandøroppfølging cancelled while Risiko stays, every supplier row
     * kept, permissions alone opening nothing, and ordering it again bringing it all back.
     */
    public function test_cancelling_suppliers_hides_them_without_deleting_anything_and_ordering_again_brings_them_back(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
        $this->activate($customer, ['risk', 'supplier']);
        $manager = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $this->grantAll($customer, $manager, [CustomerPermissionCatalog::RISK_VIEW]);

        $this->actingAs($manager)->post('/app/supplier-management', $this->supplierPayload($manager, ['name' => 'Drift AS']))->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('customer_id', $customer->id)->sole();
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/assessments", [
            'quality_rating' => 'good', 'delivery_rating' => 'good', 'security_rating' => 'good', 'compliance_rating' => 'good',
            'overall_result' => 'satisfactory', 'rationale' => 'Stabile leveranser.', 'assessed_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/documents", [
            'document_type' => 'data_processing_agreement', 'title' => 'Databehandleravtale',
            'location' => '', 'valid_from' => '', 'valid_until' => '', 'comment' => '',
        ])->assertSessionHasNoErrors();
        $before = $this->supplierRows($customer);
        $rolesBefore = $manager->customerRoles()->pluck('customer_roles.id')->all();

        $this->actingAs($owner)->post('/app/billing/packages/supplier/cancel')->assertSessionHas('success');

        // Only Leverandøroppfølging went; Risiko is untouched.
        $this->assertSame(['basis', 'risk'], $this->activeKeys($customer));
        $this->assertFalse($customer->fresh()->hasModule('supplier'));
        $this->assertTrue($customer->fresh()->hasModule('risk'));
        $this->actingAs($manager)->get('/app/risk')->assertOk();

        // The permission is still there; without the module it opens nothing.
        $this->assertSame($rolesBefore, $manager->customerRoles()->pluck('customer_roles.id')->all());
        $this->actingAs($manager)->get('/app/supplier-management')->assertRedirect(route('app.dashboard'));
        $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertRedirect(route('app.dashboard'));
        $this->assertSame($before, $this->supplierRows($customer), 'Cancelling deletes no supplier data.');

        $this->actingAs($owner)->post('/app/billing/packages/supplier/request')->assertSessionHas('success');

        $this->assertSame(['basis', 'risk', 'supplier'], $this->activeKeys($customer));
        $this->assertSame(1, $customer->packageEntitlements()->where('package_key', 'supplier')->count(), 'the row is reactivated, never duplicated');
        $this->assertSame($before, $this->supplierRows($customer));
        $page = $this->actingAs($manager)->get('/app/supplier-management')->assertOk()->viewData('page');
        $this->assertStringContainsString('Drift AS', json_encode($page['props'], JSON_UNESCAPED_UNICODE));
        $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertOk();

        // The module alone grants no access: a colleague without supplier.view is still refused.
        $this->actingAs($this->member($customer))->get('/app/supplier-management')->assertForbidden();
    }

    /** A supplier's link to a risk outlives Risiko being cancelled, shows nothing meanwhile, and works again after. */
    public function test_a_supplier_risk_link_survives_cancelling_risk_and_is_hidden_until_risk_is_back(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
        $this->activate($customer, ['risk', 'supplier']);
        $manager = $this->supplierUser($customer, []);
        $this->grantAll($customer, $manager, [CustomerPermissionCatalog::RISK_VIEW]);
        $supplier = $this->supplier($customer, $manager, 'Drift AS');
        $risk = Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $this->area($customer, 'Drift')->id, 'title' => 'Tap av driftsleverandør',
            'cause' => 'Én leverandør', 'event' => 'Konkurs', 'consequence' => 'Stans', 'status' => Risk::STATUS_IDENTIFIED,
        ]);
        SupplierRisk::query()->create(['customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'risk_id' => $risk->id, 'origin' => SupplierRisk::ORIGIN_LINKED]);
        $risksOnSupplier = fn () => $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props']['risks'];

        $this->assertSame(['Tap av driftsleverandør'], array_column($risksOnSupplier(), 'title'));

        $this->actingAs($owner)->post('/app/billing/packages/risk/cancel')->assertSessionHas('success');

        $this->assertNull($risksOnSupplier(), 'no risk data leaks through the supplier page');
        $this->assertSame([1, 1], [Risk::query()->where('customer_id', $customer->id)->count(), SupplierRisk::query()->where('supplier_id', $supplier->id)->count()]);

        $this->actingAs($owner)->post('/app/billing/packages/risk/request')->assertSessionHas('success');

        $this->assertSame(['Tap av driftsleverandør'], array_column($risksOnSupplier(), 'title'));
    }

    public function test_cancelling_risk_objectives_compliance_and_tender_keeps_their_data_for_when_they_return(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
        $this->activate($customer, ['risk', 'objectives', 'compliance', 'tender']);
        $area = $this->area($customer, 'Drift');
        Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Tap av leverandør',
            'cause' => 'Én leverandør', 'event' => 'Konkurs', 'consequence' => 'Stans', 'status' => Risk::STATUS_IDENTIFIED,
        ]);
        Objective::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Færre avvik']);
        $source = ComplianceSource::query()->create(['customer_id' => $customer->id, 'name' => 'ISO 27001', 'kind' => ComplianceSource::KIND_CONTRACT]);
        ComplianceRequirement::query()->create([
            'customer_id' => $customer->id, 'source_id' => $source->id, 'reference' => '5.1', 'title' => 'Ledelsens ansvar', 'requirement_text' => 'Ledelsen skal ...',
        ]);
        SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'PKG-'.Str::random(8), 'title' => 'Anbud om drift', 'buyer_name' => 'Etaten', 'status' => 'ACTIVE',
        ]);
        $counts = fn (): array => [
            Risk::query()->where('customer_id', $customer->id)->count(),
            Objective::query()->where('customer_id', $customer->id)->count(),
            ComplianceRequirement::query()->where('customer_id', $customer->id)->count(),
            SavedNotice::query()->where('customer_id', $customer->id)->count(),
        ];

        foreach (['risk', 'objectives', 'compliance', 'tender'] as $option) {
            $this->actingAs($owner)->post("/app/billing/packages/{$option}/cancel")->assertSessionHas('success');
        }

        $this->assertSame(['basis'], $this->activeKeys($customer));
        $this->assertSame([1, 1, 1, 1], $counts());
        // Anbud's Wiki stays, carried by Basis.
        $this->assertTrue($customer->fresh()->hasModule('wiki'));
        $this->actingAs($owner)->get('/app/bid-status')->assertRedirect(route('app.dashboard'));

        foreach (['risk', 'objectives', 'compliance', 'tender'] as $option) {
            $this->actingAs($owner)->post("/app/billing/packages/{$option}/request")->assertSessionHas('success');
        }

        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'tender'], $this->activeKeys($customer));
        $this->assertSame([1, 1, 1, 1], $counts());
        $this->actingAs($owner)->get('/app/bid-status')->assertOk();
    }

    public function test_ordering_or_cancelling_only_ever_reaches_the_signed_in_users_own_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');
        ['customer' => $other] = $this->context('basis');
        $this->activate($other, ['supplier', 'tender']);

        $this->actingAs($owner)->post('/app/billing/packages/risk/request');
        $this->actingAs($owner)->post('/app/billing/packages/supplier/cancel');
        $this->actingAs($owner)->post('/app/billing/packages/tender/cancel');

        $this->assertSame(['basis', 'risk'], $this->activeKeys($customer));
        $this->assertSame(['basis', 'supplier', 'tender'], $this->activeKeys($other));

        // Someone without billing access cannot change their own customer's options either.
        $member = $this->member($other);
        $this->actingAs($member)->post('/app/billing/packages/risk/request')->assertForbidden();
        $this->actingAs($member)->post('/app/billing/packages/supplier/cancel')->assertForbidden();
        $this->assertSame(['basis', 'supplier', 'tender'], $this->activeKeys($other));

        // A bundle is not orderable from the page, and an inactive option cannot be cancelled.
        $this->actingAs($owner)->post('/app/billing/packages/grc/request')->assertNotFound();
        $this->actingAs($owner)->post('/app/billing/packages/supplier/cancel')->assertSessionHas('error');
    }

    public function test_ordering_suppliers_and_compliance_gives_system_owner_no_access_of_its_own(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('basis');

        $this->actingAs($owner)->post('/app/billing/packages/supplier/request');
        $this->actingAs($owner)->post('/app/billing/packages/compliance/request');

        $this->assertTrue($customer->fresh()->hasModule('supplier'));
        $this->assertTrue($customer->fresh()->hasModule('compliance'));
        $this->actingAs($owner->fresh())->get('/app/supplier-management')->assertForbidden();
        $this->actingAs($owner->fresh())->get('/app/compliance/requirements')->assertForbidden();
    }

    /** Existing customers keep exactly what they had: each ladder step becomes Basis and its options. */
    public function test_the_migration_moves_ladder_customers_to_basis_and_the_options_they_had(): void
    {
        $customers = [];

        foreach (['grc' => ['grc', 'tender'], 'iso' => ['iso'], 'governance' => ['governance'], 'tender' => ['tender'], 'basis' => ['basis']] as $label => $rows) {
            ['customer' => $customer] = $this->context(null);

            foreach ($rows as $key) {
                CustomerPackageEntitlement::query()->create([
                    'customer_id' => $customer->id, 'package_key' => $key, 'status' => 'active', 'activated_at' => '2026-01-15 09:00:00',
                ]);
            }

            $customers[$label] = $customer;
        }

        $before = array_map(fn (Customer $customer): array => $this->legacyModules($customer), $customers);
        $migration = require database_path('migrations/2026_10_08_000001_split_governance_packages_into_options.php');
        $migration->up();

        $this->assertSame(['basis', 'risk', 'objectives', 'compliance', 'supplier', 'tender'], $this->activeKeys($customers['grc']));
        $this->assertSame(['basis', 'risk', 'objectives', 'compliance'], $this->activeKeys($customers['iso']));
        $this->assertSame(['basis', 'risk', 'objectives'], $this->activeKeys($customers['governance']));
        // Basis is mandatory now: an Anbud-only customer gains it.
        $this->assertSame(['basis', 'tender'], $this->activeKeys($customers['tender']));
        $this->assertSame(['basis'], $this->activeKeys($customers['basis']));

        foreach ($customers as $label => $customer) {
            $this->assertSame([], array_values(array_diff($before[$label], app(ModuleEntitlementService::class)->modulesFor($customer))), "{$label} loses no module");
        }

        // An option dates from when the customer actually got that access.
        $this->assertSame('2026-01-15', $this->row($customers['grc'], 'supplier')->activated_at->toDateString());
        $this->assertSame(CustomerPackageEntitlement::STATUS_REVOKED, $this->row($customers['grc'], 'grc')->status);

        // Running it again changes nothing, and down() puts every row back as it was.
        $migration->up();
        $this->assertSame(['basis', 'risk', 'objectives', 'compliance'], $this->activeKeys($customers['iso']));

        $migration->down();
        $this->assertSame(['grc', 'tender'], $customers['grc']->packageEntitlements()->active()->orderBy('package_key')->pluck('package_key')->all());
        $this->assertSame(['tender'], $customers['tender']->packageEntitlements()->pluck('package_key')->all());
    }

    // ---------------------------------------------------------------------

    /** @param  list<string>  $packages */
    private function activate(Customer $customer, array $packages): void
    {
        foreach ($packages as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }
    }

    /** @return list<string> */
    private function activeKeys(Customer $customer): array
    {
        return app(ModuleEntitlementService::class)->activePackageKeys($customer->fresh());
    }

    private function row(Customer $customer, string $packageKey): CustomerPackageEntitlement
    {
        return $customer->packageEntitlements()->where('package_key', $packageKey)->sole();
    }

    /**
     * What a customer's rows gave under the ladder, before the migration.
     *
     * @return list<string>
     */
    private function legacyModules(Customer $customer): array
    {
        $ladder = [
            'basis' => ['wiki', 'quality', 'improvements'],
            'governance' => ['wiki', 'quality', 'improvements', 'risk', 'objectives'],
            'iso' => ['wiki', 'quality', 'improvements', 'risk', 'objectives', 'compliance'],
            'grc' => ['wiki', 'quality', 'improvements', 'risk', 'objectives', 'compliance', 'supplier'],
            'tender' => ['wiki', 'tender'],
        ];

        return array_values(array_unique(array_merge(...array_map(
            fn (string $key): array => $ladder[$key],
            $customer->packageEntitlements()->active()->pluck('package_key')->all(),
        ))));
    }

    /** @return array<string, int> */
    private function supplierRows(Customer $customer): array
    {
        return [
            'suppliers' => Supplier::query()->where('customer_id', $customer->id)->count(),
            'status_changes' => SupplierStatusChange::query()->where('customer_id', $customer->id)->count(),
            'assessments' => SupplierAssessment::query()->where('customer_id', $customer->id)->count(),
            'documents' => SupplierDocument::query()->where('customer_id', $customer->id)->count(),
        ];
    }
}
