<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\GovernanceController;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Styring is navigation: it groups Kvalitet, Risiko, Mål og KPI and Avvik og forbedringer and is
 * derived entirely from them.
 *
 * What these tests defend:
 *
 *  - A card is shown exactly when the module's own routes would let the person in: the customer
 *    holds the module and the person holds its view permission. Neither alone is enough.
 *  - Someone with none of the four gets a 403 — there is no Styring permission to hold. System
 *    Owner sees the modules they could already open, and still no data without a role.
 *  - The page carries no domain data, only which modules to show and where they are.
 *  - The modules' own URLs are untouched by the grouping.
 */
class GovernanceControllerTest extends TestCase
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleModuleProvider(): array
    {
        return [
            'Kvalitet' => [CustomerPermissionCatalog::QUALITY_VIEW, 'quality'],
            'Risiko' => [CustomerPermissionCatalog::RISK_VIEW, 'risk'],
            'Mål og KPI' => [CustomerPermissionCatalog::OBJECTIVE_VIEW, 'objectives'],
            'Avvik og forbedringer' => [CustomerPermissionCatalog::IMPROVEMENT_VIEW, 'improvements'],
            'Etterlevelse og revisjon' => [CustomerPermissionCatalog::COMPLIANCE_VIEW, 'compliance'],
            'Leverandøroppfølging' => [CustomerPermissionCatalog::SUPPLIER_VIEW, 'suppliers'],
        ];
    }

    #[DataProvider('singleModuleProvider')]
    public function test_one_view_permission_shows_exactly_that_module(string $permission, string $key): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [$permission]);

        $page = $this->governancePage($user);

        $this->assertSame('App/Governance/Index', $page['component']);
        $this->assertSame([$key], array_column($page['props']['modules'], 'key'));
    }

    public function test_several_permissions_show_every_permitted_module_in_rail_order(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [
            CustomerPermissionCatalog::SUPPLIER_VIEW,
            CustomerPermissionCatalog::COMPLIANCE_VIEW,
            CustomerPermissionCatalog::IMPROVEMENT_VIEW,
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::OBJECTIVE_VIEW,
        ]);

        $modules = $this->governancePage($user)['props']['modules'];

        $this->assertSame(['quality', 'risk', 'objectives', 'improvements', 'compliance', 'suppliers'], array_column($modules, 'key'));
        $this->assertSame(
            [route('app.quality.index'), route('app.risk.index'), route('app.objectives.index'), route('app.improvements.index'), route('app.compliance.requirements.index'), route('app.supplier-management.index')],
            array_column($modules, 'href'),
        );
    }

    public function test_the_page_carries_no_domain_data(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW]);

        foreach ($this->governancePage($user)['props']['modules'] as $module) {
            $this->assertSame(['key', 'href'], array_keys($module));
        }
    }

    public function test_none_of_the_view_permissions_is_a_403(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_EDIT]);

        $this->actingAs($user)->get('/app/governance')->assertForbidden();
    }

    public function test_a_permission_in_a_module_the_customer_has_not_ordered_is_not_shown(): void
    {
        // Basis carries Kvalitet and Avvik og forbedringer, but not Risiko or Mål og KPI.
        ['customer' => $customer] = $this->context('basis');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::IMPROVEMENT_VIEW]);

        $this->assertSame(['improvements'], array_column($this->governancePage($user)['props']['modules'], 'key'));

        $riskOnly = $this->member($customer);
        $this->grantAll($customer, $riskOnly, [CustomerPermissionCatalog::RISK_VIEW]);
        $this->actingAs($riskOnly)->get('/app/governance')->assertForbidden();
    }

    public function test_system_owner_gets_no_more_through_styring_than_the_modules_already_give(): void
    {
        // System Owner opens every module (CustomerPermissionService) except Etterlevelse og
        // revisjon, an explicit-grant domain, and reads no data in one without a role in its
        // fagområder. Styring must change neither half of that: its cards are exactly the modules
        // System Owner's own routes already let in, and it shows no data at all.
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $case = $this->improvementCase($customer, $this->area($customer, 'HR'), 'Avvik ingen rolle');

        $modules = $this->governancePage($owner)['props']['modules'];
        $this->assertSame(['quality', 'risk', 'objectives', 'improvements', 'management_review'], array_column($modules, 'key'));

        foreach ($modules as $module) {
            $this->assertSame(['key', 'href'], array_keys($module));
            $this->actingAs($owner)->get($module['href'])->assertOk();
        }

        $register = $this->actingAs($owner)->get('/app/improvements')->viewData('page')['props'];
        $this->assertSame([], $register['cases']);
        $this->actingAs($owner)->get("/app/improvements/{$case->id}")->assertNotFound();
        $this->actingAs($owner)->get('/app/compliance/requirements')->assertForbidden();

        // With a role of their own that grants compliance.view, the card appears like anyone's.
        $this->grantAll($customer, $owner, [CustomerPermissionCatalog::COMPLIANCE_VIEW]);
        $this->assertSame(
            ['quality', 'risk', 'objectives', 'improvements', 'compliance', 'management_review'],
            array_column($this->governancePage($owner->fresh())['props']['modules'], 'key'),
        );

        // A customer that holds only Basis: the System Owner's permissions do not conjure Risiko.
        ['owner' => $qualityOwner] = $this->context('basis');
        $this->assertSame(
            ['quality', 'improvements', 'management_review'],
            array_column($this->governancePage($qualityOwner)['props']['modules'], 'key'),
        );
    }

    public function test_the_modules_keep_their_own_urls(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::OBJECTIVE_VIEW,
            CustomerPermissionCatalog::IMPROVEMENT_VIEW,
            CustomerPermissionCatalog::COMPLIANCE_VIEW,
        ]);

        foreach (['/app/quality', '/app/risk', '/app/objectives', '/app/improvements', '/app/compliance/requirements'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_the_controller_and_the_rail_name_the_same_modules_in_the_same_order(): void
    {
        $rail = file_get_contents(resource_path('js/Support/appModules.js'));
        preg_match_all("/key: '([a-z_]+)',\\n(?:(?!\\n    \\{).)*?workspace: 'governance'/s", $rail, $matches);

        $this->assertSame(array_keys(GovernanceController::MODULES), $matches[1]);

        foreach (GovernanceController::MODULES as $key => [$module, $permission]) {
            $this->assertMatchesRegularExpression(
                "/key: '{$key}',.*?module: '{$module}',.*?permission: '".preg_quote($permission, '/')."'/s",
                $rail,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function governancePage(User $user): array
    {
        $response = $this->actingAs($user)->get('/app/governance')->assertOk();

        return $response->viewData('page');
    }
}
