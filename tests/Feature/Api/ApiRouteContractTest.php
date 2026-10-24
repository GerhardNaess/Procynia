<?php

namespace Tests\Feature\Api;

use App\Support\Ai\ApiAiOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * The shape every external API route must have, checked over the real route table
 * (docs/external-api-phase-1-decisions.md §5 and §9).
 *
 *  - Read by default: GET (and the HEAD Laravel adds). The exceptions are the named write routes
 *    in WRITE_ROUTES, each a POST behind api.write that performs one catalogued operation
 *    (ApiOperationCatalog), and the AI routes in AI_ROUTES — «Spør Wiki» (D19) — a POST that writes
 *    nothing and so has no api.write, and the only routes ApiAiOperations lets spend AI. No route
 *    decides — which is also what keeps A46 («Flytt til historikk») and the Kvalitet half of A23
 *    unreachable.
 *  - The whole stack, in order, on every route: log, application, customer grant, module.
 *  - Only modules the API is meant to serve. Anbud is not among them.
 *  - No route model binding: a record is found through the access services, never by id alone.
 *  - API controllers do not touch the services behind A23 and A46, except «Spør Wiki» from the
 *    Wiki controller, whose service now holds the access rule itself.
 */
class ApiRouteContractTest extends TestCase
{
    private const STACK = ['api', 'api.log', 'api.client', 'api.customer', 'customer.module'];

    /** Modules the API may serve. Extend deliberately, with the module's own access tests. */
    private const SERVED_MODULES = ['risk', 'improvements', 'objectives', 'management_review', 'compliance', 'supplier', 'quality', 'wiki'];

    /** The write routes, by name, with the one method each accepts. Extend deliberately. */
    private const WRITE_ROUTES = [
        'api.v1.risks.treatment-actions.store' => 'POST',
        'api.v1.deviations.store' => 'POST',
        'api.v1.deviations.actions.store' => 'POST',
        'api.v1.improvements.store' => 'POST',
        'api.v1.improvements.actions.store' => 'POST',
    ];

    /** The routes that spend AI (ApiAiOperations), with the one method each accepts. Extend deliberately. */
    private const AI_ROUTES = [
        'api.v1.wiki.ask' => 'POST',
    ];

    /**
     * Routes that need no module: they describe the caller, or span the served modules and include
     * each only where the identity may read it (ApiAccessSummary).
     */
    private const MODULE_FREE = ['api.v1.me', 'api.v1.attention', 'api.v1.business-areas'];

    /** Classes behind A23 (Quality AI proposals, «Spør Wiki») and A46 (archive with outcome). */
    private const FORBIDDEN_REFERENCES = [
        'QualityProcessFlowInterpreter',
        'QualityProcessDescriptionClarifier',
        'QualityProcessFlowChangeProposer',
        'EnterpriseWikiQuestionAnswerService',
        'NoticeController',
        'SavedNotice',
        'OpenAiClient',
    ];

    /** The one exception (A23 resolved for the Wiki half): the service authorizes itself. */
    private const ALLOWED_REFERENCES = [
        'WikiController.php' => ['EnterpriseWikiQuestionAnswerService'],
    ];

    public function test_there_is_at_least_one_api_route(): void
    {
        $this->assertNotEmpty($this->apiRoutes());
    }

    public function test_every_api_route_reads_except_the_named_write_routes(): void
    {
        $names = [];

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();
            $names[] = $name;

            if (array_key_exists($name, self::WRITE_ROUTES)) {
                $this->assertSame([self::WRITE_ROUTES[$name]], $route->methods(), "Write route [{$name}] accepts another method.");
                $this->assertContains('api.write', $route->gatherMiddleware(), "Write route [{$name}] does not require the write app role.");

                continue;
            }

            if (array_key_exists($name, self::AI_ROUTES)) {
                $this->assertSame([self::AI_ROUTES[$name]], $route->methods(), "AI route [{$name}] accepts another method.");
                $this->assertNotContains('api.write', $route->gatherMiddleware(), "AI route [{$name}] writes nothing and needs no write role.");

                continue;
            }

            $this->assertSame(
                [],
                array_values(array_diff($route->methods(), ['GET', 'HEAD'])),
                "API route [{$route->uri()}] accepts more than GET.",
            );
            $this->assertNotContains('api.write', $route->gatherMiddleware());
        }

        foreach ([...array_keys(self::WRITE_ROUTES), ...array_keys(self::AI_ROUTES)] as $name) {
            $this->assertContains($name, $names, "Route [{$name}] is not registered.");
        }
    }

    public function test_only_the_ai_routes_may_spend_ai(): void
    {
        $this->assertSame(array_keys(self::AI_ROUTES), array_keys(ApiAiOperations::byRoute()));
        $this->assertSame(['wiki.navigation_plan', 'wiki.ask.retrieval_plan', 'wiki.ask.answer'], ApiAiOperations::byRoute()['api.v1.wiki.ask']);
    }

    public function test_every_api_route_is_named_under_v1(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $this->assertStringStartsWith('api/v1/', $route->uri());
            $this->assertStringStartsWith('api.v1.', (string) $route->getName(), "API route [{$route->uri()}] has no api.v1 name.");
        }
    }

    public function test_every_api_route_runs_the_whole_stack_in_order(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $middleware = array_values(array_filter(
                $route->gatherMiddleware(),
                fn (mixed $name): bool => is_string($name) && in_array($name, self::STACK, true),
            ));

            $this->assertSame(self::STACK, $middleware, "API route [{$route->uri()}] does not run the API stack in order.");
        }
    }

    public function test_every_api_route_belongs_to_a_served_module(): void
    {
        $map = (array) config('procynia_modules.route_modules', []);
        $names = array_map(fn (Route $route): string => (string) $route->getName(), $this->apiRoutes());

        foreach (self::MODULE_FREE as $name) {
            $this->assertContains($name, $names);
        }

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();

            if (in_array($name, self::MODULE_FREE, true)) {
                continue;
            }

            $module = $this->moduleFor($name, $map);

            $this->assertNotNull($module, "API route [{$name}] maps to no module in procynia_modules.route_modules.");
            $this->assertContains($module, self::SERVED_MODULES, "API route [{$name}] serves module [{$module}].");
        }

        foreach ($map as $prefix => $module) {
            if (str_starts_with((string) $prefix, 'api.')) {
                $this->assertContains($module, self::SERVED_MODULES, "route_modules maps [{$prefix}] to [{$module}].");
            }
        }
    }

    public function test_no_api_action_binds_a_model(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $action = $route->getActionName();
            [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $this->assertFalse(
                        is_subclass_of($type->getName(), Model::class),
                        "{$class}::{$method} binds the model {$type->getName()}.",
                    );
                }
            }
        }
    }

    public function test_api_controllers_do_not_reach_the_blocked_operations(): void
    {
        $files = glob(app_path('Http/Controllers/Api/V1/*.php')) ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $allowed = self::ALLOWED_REFERENCES[basename($path)] ?? [];

            foreach (array_diff(self::FORBIDDEN_REFERENCES, $allowed) as $class) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.$class.'\b/',
                    $source,
                    basename($path)." references {$class}.",
                );
            }
        }
    }

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    /** @param  array<string, string>  $map */
    private function moduleFor(string $name, array $map): ?string
    {
        if (isset($map[$name])) {
            return $map[$name];
        }

        $best = null;
        $length = 0;

        foreach ($map as $prefix => $module) {
            if (str_ends_with((string) $prefix, '.') && str_starts_with($name, (string) $prefix) && strlen((string) $prefix) > $length) {
                $best = $module;
                $length = strlen((string) $prefix);
            }
        }

        return $best;
    }
}
