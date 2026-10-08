<?php

namespace Tests\Feature\App;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\ImprovementCase;
use App\Models\Risk;
use App\Models\SavedNotice;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierDocument;
use App\Models\SupplierStatusChange;
use App\Models\User;
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
 * Moving between steps of the ladder and cancelling Anbud.
 *
 * What is defended: the customer is on one main package, the steps below it read as included in
 * it, moving down or cancelling only takes access away — never data, roles or permissions — and
 * moving back up finds everything as it was. Which modules each package carries is
 * CustomerModuleEntitlementTest's.
 */
class PackageChangeTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    /** Each test decides for itself whether the customer holds Anbud. */
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

    public function test_grc_is_the_one_active_main_package_and_the_steps_below_are_included_in_it(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        // A row left standing by an order from before package changes replaced the main package.
        $this->grant($customer, 'basis');

        $this->assertSame('grc', app(ModuleEntitlementService::class)->effectiveMainPackage($customer));

        $packages = $this->overview($owner);

        $this->assertSame(
            ['basis' => 'included', 'governance' => 'included', 'iso' => 'included', 'grc' => 'active', 'tender' => 'available'],
            $packages->mapWithKeys(fn (array $row): array => [$row['key'] => $row['status']])->all(),
        );
        $this->assertSame(['grc', 'grc', 'grc'], $packages->take(3)->pluck('included_in')->all());
        $this->assertSame([null, null, null, 'change', 'order'], $packages->pluck('action')->all());
        // Only the row that holds the access carries a date; Basis's own stale row does not.
        $this->assertNull($packages->firstWhere('key', 'basis')['activated_at']);
        $this->assertNotNull($packages->firstWhere('key', 'grc')['activated_at']);
        $this->assertSame(['supplier'], $packages->firstWhere('key', 'iso')['modules_lost']);
        $this->assertSame(['compliance', 'supplier'], $packages->firstWhere('key', 'governance')['modules_lost']);
    }

    public function test_iso_as_the_main_package_includes_the_steps_below_and_offers_grc_as_an_upgrade(): void
    {
        ['owner' => $owner] = $this->context('iso');

        $packages = $this->overview($owner);

        $this->assertSame(['included', 'included', 'active', 'available'], $packages->take(4)->pluck('status')->all());
        $this->assertSame(['iso', 'iso', null, null], $packages->take(4)->pluck('included_in')->all());
        $grc = $packages->firstWhere('key', 'grc');
        $this->assertSame(['upgrade', 'upgrade', ['supplier']], [$grc['action'], $grc['direction'], $grc['modules_gained']]);
    }

    /**
     * The central requirement: GRC → ISO takes Leverandøroppfølging away, keeps every supplier row,
     * and GRC again brings it all back.
     */
    public function test_moving_down_to_iso_hides_suppliers_without_deleting_them_and_grc_brings_them_back(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $manager = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS]);

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

        $this->actingAs($owner)->post('/app/billing/packages/iso/request')
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('success');

        $this->assertSame(['iso'], app(ModuleEntitlementService::class)->activePackageKeys($customer));
        $this->assertSame(CustomerPackageEntitlement::STATUS_REVOKED, $this->row($customer, 'grc')->status);
        $this->assertNotNull($this->row($customer, 'grc')->deactivated_at);
        $this->assertFalse($customer->fresh()->hasModule('supplier'));
        $this->assertTrue($customer->fresh()->hasModule('compliance'));

        // The permission is still there; without the entitlement it opens nothing.
        $this->assertSame($rolesBefore, $manager->customerRoles()->pluck('customer_roles.id')->all());
        $this->actingAs($manager)->get('/app/supplier-management')->assertRedirect(route('app.dashboard'));
        $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertRedirect(route('app.dashboard'));
        $this->assertSame($before, $this->supplierRows($customer), 'Moving down deletes no supplier data.');

        $this->actingAs($owner)->post('/app/billing/packages/grc/request')->assertSessionHas('success');

        $this->assertSame(['grc'], app(ModuleEntitlementService::class)->activePackageKeys($customer));
        $this->assertSame($before, $this->supplierRows($customer));
        $page = $this->actingAs($manager)->get('/app/supplier-management')->assertOk()->viewData('page');
        $this->assertStringContainsString('Drift AS', json_encode($page['props'], JSON_UNESCAPED_UNICODE));
        $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertOk();
    }

    public function test_moving_down_to_basis_keeps_risks_requirements_and_cases(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $area = $this->area($customer, 'Drift');
        Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Tap av leverandør',
            'cause' => 'Én leverandør', 'event' => 'Konkurs', 'consequence' => 'Stans', 'status' => Risk::STATUS_IDENTIFIED,
        ]);
        $source = ComplianceSource::query()->create(['customer_id' => $customer->id, 'name' => 'ISO 27001', 'kind' => ComplianceSource::KIND_CONTRACT]);
        ComplianceRequirement::query()->create([
            'customer_id' => $customer->id, 'source_id' => $source->id, 'reference' => '5.1', 'title' => 'Ledelsens ansvar', 'requirement_text' => 'Ledelsen skal ...',
        ]);
        $before = [Risk::query()->where('customer_id', $customer->id)->count(), ComplianceRequirement::query()->where('customer_id', $customer->id)->count(), ImprovementCase::query()->where('customer_id', $customer->id)->count()];

        $this->actingAs($owner)->post('/app/billing/packages/basis/request')->assertSessionHas('success');

        $this->assertSame(['basis'], app(ModuleEntitlementService::class)->activePackageKeys($customer));
        $this->assertSame(['wiki', 'quality', 'improvements'], app(ModuleEntitlementService::class)->modulesFor($customer));
        $this->assertSame($before, [Risk::query()->where('customer_id', $customer->id)->count(), ComplianceRequirement::query()->where('customer_id', $customer->id)->count(), ImprovementCase::query()->where('customer_id', $customer->id)->count()]);
    }

    public function test_anbud_is_cancelled_on_its_own_keeps_its_data_and_can_be_ordered_again(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('iso');
        $this->grant($customer, 'tender');
        SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'PKG-'.Str::random(8), 'title' => 'Anbud om drift', 'buyer_name' => 'Etaten', 'status' => 'ACTIVE',
        ]);

        $cancel = collect(app(ModuleEntitlementService::class)->overviewFor($customer))->firstWhere('key', 'tender');
        // Wiki stays with ISO, so Anbud alone is what goes.
        $this->assertSame(['cancel', ['tender']], [$cancel['action'], $cancel['modules_lost']]);

        $this->actingAs($owner)->post('/app/billing/packages/tender/cancel')
            ->assertRedirect(route('app.billing.index'))
            ->assertSessionHas('success');

        $this->assertFalse($customer->fresh()->hasModule('tender'));
        $this->assertTrue($customer->fresh()->hasModule('wiki'));
        $this->assertSame('iso', app(ModuleEntitlementService::class)->effectiveMainPackage($customer), 'Cancelling Anbud leaves the main package alone.');
        $this->assertSame(1, SavedNotice::query()->where('customer_id', $customer->id)->count());
        $this->actingAs($owner)->get('/app/bid-status')->assertRedirect(route('app.dashboard'));

        $this->actingAs($owner)->post('/app/billing/packages/tender/request')->assertSessionHas('success');

        $this->assertTrue($customer->fresh()->hasModule('tender'));
        $this->assertSame(1, SavedNotice::query()->where('customer_id', $customer->id)->count());
    }

    public function test_a_main_package_cannot_be_cancelled_and_cancelling_an_inactive_add_on_changes_nothing(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');

        $this->actingAs($owner)->post('/app/billing/packages/grc/cancel')->assertNotFound();
        $this->actingAs($owner)->post('/app/billing/packages/tender/cancel')->assertSessionHas('error');
        $this->actingAs($owner)->post('/app/billing/packages/grc/request')->assertSessionHas('error');

        $this->assertSame(['grc'], app(ModuleEntitlementService::class)->activePackageKeys($customer));
    }

    public function test_a_package_change_only_ever_reaches_the_signed_in_users_own_customer(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $this->grant($other, 'tender');

        $this->actingAs($owner)->post('/app/billing/packages/basis/request');
        $this->actingAs($owner)->post('/app/billing/packages/tender/cancel');

        $this->assertSame(['basis'], app(ModuleEntitlementService::class)->activePackageKeys($customer));
        $this->assertSame(['grc', 'tender'], app(ModuleEntitlementService::class)->activePackageKeys($other));

        // Someone without billing access cannot change their own customer's package either.
        $member = $this->member($other);
        $this->actingAs($member)->post('/app/billing/packages/basis/request')->assertForbidden();
        $this->actingAs($member)->post('/app/billing/packages/tender/cancel')->assertForbidden();
        $this->assertSame(['grc', 'tender'], app(ModuleEntitlementService::class)->activePackageKeys($other));
    }

    public function test_moving_back_up_to_grc_gives_system_owner_no_supplier_access_of_its_own(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');

        $this->actingAs($owner)->post('/app/billing/packages/iso/request');
        $this->actingAs($owner)->post('/app/billing/packages/grc/request');

        $this->assertTrue($customer->fresh()->hasModule('supplier'));
        $this->actingAs($owner->fresh())->get('/app/supplier-management')->assertForbidden();
        $this->actingAs($owner->fresh())->get('/app/compliance/requirements')->assertForbidden();
    }

    // ---------------------------------------------------------------------

    private function overview(User $owner)
    {

        return collect($this->actingAs($owner)->get('/app/billing')->assertOk()->viewData('page')['props']['module_packages']);
    }

    private function grant(Customer $customer, string $packageKey): void
    {
        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => $packageKey],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()->subYear()],
        );
    }

    private function row(Customer $customer, string $packageKey): CustomerPackageEntitlement
    {
        return $customer->packageEntitlements()->where('package_key', $packageKey)->sole();
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
