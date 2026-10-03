<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request for a technical module the customer is not entitled to.
 *
 * The left rail hides what a customer has not bought, but hiding is presentation: a bookmark, a
 * stale tab or a typed URL all reach the route directly. This is the guard that actually holds,
 * and it resolves entitlements through the same service the rail and the Abonnement page read, so
 * the three can never disagree about what is active.
 *
 * WHY IT READS A MAP INSTEAD OF TAKING AN ARGUMENT.
 *
 * Anbud alone spans roughly sixty routes across four controllers. Threading `module:tender`
 * through each of them would have meant restructuring the route file and would have left the real
 * question — which parts of the product are gated — spread across sixty lines instead of stated
 * in one place. The map in config/procynia_modules.php says it once, by route-name prefix, so new
 * routes under a gated namespace are covered the moment they are added.
 *
 * A blocked request is redirected to Hjem with an explanation rather than refused with a bare 403.
 * This is a commercial boundary, not an authorisation failure: the person is allowed to be here,
 * their customer has simply not ordered the module, and the message that says so is more use than
 * an error page. Hjem is deliberately ungated, so the redirect always has somewhere to land.
 */
class EnsureModuleIsEnabled
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $module = $this->requiredModule($request->route()?->getName());

        if ($module === null) {
            return $next($request);
        }

        $user = $request->user();
        $customer = $this->customerContext->currentCustomer($user instanceof User ? $user : null);

        // No customer means an internal account browsing the frontend; tenancy, not entitlements,
        // is what governs that, and EnsureCustomerFrontendAccess has already had its say.
        if ($customer === null || $this->entitlements->hasModule($customer, $module)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403);
        }

        return redirect()
            ->route('app.dashboard')
            ->with('error', __('procynia.navigation.modules.not_enabled', [
                'module' => __("procynia.billing.modules.module_labels.{$module}"),
            ]));
    }

    /**
     * The module a route name requires: an exact match first, then the longest matching prefix, so
     * a specific entry can override a broader one if that is ever needed.
     */
    private function requiredModule(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        $map = config('procynia_modules.route_modules', []);

        if (isset($map[$routeName])) {
            return $map[$routeName];
        }

        $best = null;
        $bestLength = 0;

        foreach ($map as $prefix => $module) {
            if (! str_ends_with((string) $prefix, '.') || ! str_starts_with($routeName, (string) $prefix)) {
                continue;
            }

            if (strlen((string) $prefix) > $bestLength) {
                $best = $module;
                $bestLength = strlen((string) $prefix);
            }
        }

        return $best;
    }
}
