<?php

namespace Tests\Feature\App;

use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging's access contract, settled before any supplier exists.
 *
 * Which packages carry the module, and that the rail and Styring agree with the route, is
 * NavigationEntitlementMatrixTest's. What is defended here:
 *
 *  - On a customer that holds the module, the register opens with supplier.view and only with it.
 *  - System Owner is fail-closed: no access by virtue of the administrator role, exactly the
 *    access of a role of their own once they give themselves one.
 *  - edit, assess and delete are separate keys, and none of them works without view.
 */
class SupplierAccessTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

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

    public function test_the_register_opens_with_supplier_view_and_is_forbidden_without_it(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $viewer = $this->member($customer);
        $this->grantAll($customer, $viewer, [CustomerPermissionCatalog::SUPPLIER_VIEW]);

        $page = $this->actingAs($viewer)->get('/app/supplier-management')->assertOk()->viewData('page');
        $this->assertSame('App/SupplierManagement/Index', $page['component']);
        $this->assertSame('Leverandører', $page['props']['translations']['supplier_management']['index_heading']);

        // Every other right in the product, but not this one.
        $other = $this->member($customer);
        $this->grantAll($customer, $other, array_values(array_filter(
            CustomerPermissionCatalog::all(),
            fn (string $key): bool => $key !== CustomerPermissionCatalog::SUPPLIER_VIEW,
        )));
        $this->actingAs($other)->get('/app/supplier-management')->assertForbidden();
    }

    public function test_system_owner_is_fail_closed_until_a_role_of_their_own_grants_supplier_view(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $access = app(SupplierAccessService::class);

        $this->actingAs($owner)->get('/app/supplier-management')->assertForbidden();
        $this->assertFalse($access->canOpenModule($owner));
        $this->assertFalse($access->canEdit($owner));
        $this->assertFalse($access->canAssess($owner));
        $this->assertFalse($access->canDelete($owner));

        $this->grantAll($customer, $owner, [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $owner = $owner->fresh();

        $this->actingAs($owner)->get('/app/supplier-management')->assertOk();
        $this->assertTrue($access->canEdit($owner));
        $this->assertFalse($access->canAssess($owner));
        $this->assertFalse($access->canDelete($owner));
    }

    public function test_edit_assess_and_delete_are_separate_and_each_needs_view(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $access = app(SupplierAccessService::class);

        $cases = [
            CustomerPermissionCatalog::SUPPLIER_EDIT => 'canEdit',
            CustomerPermissionCatalog::SUPPLIER_ASSESS => 'canAssess',
            CustomerPermissionCatalog::SUPPLIER_DELETE => 'canDelete',
        ];

        foreach ($cases as $key => $granted) {
            $withView = $this->member($customer);
            $this->grantAll($customer, $withView, [CustomerPermissionCatalog::SUPPLIER_VIEW, $key]);

            foreach ($cases as $method) {
                $this->assertSame($method === $granted, $access->{$method}($withView), "{$key}: {$method}");
            }

            $withoutView = $this->member($customer);
            $this->grantAll($customer, $withoutView, [$key]);
            $this->assertFalse($access->{$granted}($withoutView), "{$key} without view");
        }
    }
}
