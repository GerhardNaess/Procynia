<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Styring — the landing page of the arbeidsområde that groups Kvalitet, Risiko, Mål og KPI and
 * Avvik og forbedringer.
 *
 * Navigation and nothing else. It reads no domain data: no counts, no attention, no queries across
 * the four modules. Each card is a door into a module the person can already open, and the answer to
 * "can they" is exactly the one the module's own routes give — the customer holds the module
 * (EnsureModuleIsEnabled) and the person holds its view permission (the module's controller). There
 * is no Styring permission and no Styring package; a person with none of the four gets a 403 here,
 * just as the rail shows them no Styring.
 *
 * The same four, in the same order, are declared for the rail in resources/js/Support/appModules.js
 * (`workspace: 'governance'`). GovernanceControllerTest holds the two lists together.
 */
class GovernanceController extends Controller
{
    /**
     * key => [technical module, view permission, route name].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const MODULES = [
        'quality' => ['quality', CustomerPermissionCatalog::QUALITY_VIEW, 'app.quality.index'],
        'risk' => ['risk', CustomerPermissionCatalog::RISK_VIEW, 'app.risk.index'],
        'objectives' => ['objectives', CustomerPermissionCatalog::OBJECTIVE_VIEW, 'app.objectives.index'],
        'improvements' => ['improvements', CustomerPermissionCatalog::IMPROVEMENT_VIEW, 'app.improvements.index'],
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ModuleEntitlementService $moduleEntitlements,
        private readonly CustomerPermissionService $customerPermissions,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $customer = $user instanceof User ? $this->customerContext->currentCustomer($user) : null;

        abort_unless($user instanceof User && $user->canAccessCustomerFrontend() && $customer !== null, 403);

        $modules = [];

        foreach (self::MODULES as $key => [$module, $permission, $route]) {
            if ($this->moduleEntitlements->hasModule($customer, $module) && $this->customerPermissions->has($user, $permission)) {
                $modules[] = ['key' => $key, 'href' => route($route)];
            }
        }

        abort_if($modules === [], 403);

        return Inertia::render('App/Governance/Index', [
            'modules' => $modules,
        ]);
    }
}
