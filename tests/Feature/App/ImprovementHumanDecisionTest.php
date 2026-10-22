<?php

namespace Tests\Feature\App;

use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The human decisions in Avvik og forbedringer — Lukk, Avbryt and Gjenåpne a case, Effektverifisering
 * of a tiltak, and deleting a case — are refused in the services themselves, phase 0 step 10
 * (docs/external-api-phase-0-plan.md §4.4).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the case's fagområde are turned away there, whatever the entry point, and nothing
 * is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class ImprovementHumanDecisionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_EDIT,
        CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        CustomerPermissionCatalog::IMPROVEMENT_DELETE,
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
     * The (permission, area) pairs are read from the roles directly (BusinessAreaGrants), so the rule
     * that an integration never holds a human-only key has to hold in the access service too.
     */
    public function test_an_integration_holds_no_human_only_improvement_key_through_its_roles(): void
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'Drift');
        $case = $this->improvementCase($customer, $area, 'Feil i rutine');
        $decider = $this->member($customer);
        $integration = $this->member($customer);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $role = $this->grantAll($customer, $decider, self::EVERYTHING);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $access = app(ImprovementCaseAccessService::class);

        foreach ([CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
            $this->assertTrue($access->can($decider, $key, $case), $key);
        }

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case), $key);
        }
    }
}
