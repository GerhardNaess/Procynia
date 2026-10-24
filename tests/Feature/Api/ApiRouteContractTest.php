<?php

namespace Tests\Feature\Api;

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
 *  - Read-only: GET (and the HEAD Laravel adds) only. No route writes, decides or starts work —
 *    which is also what keeps A23 (AI work) and A46 («Flytt til historikk») unreachable.
 *  - The whole stack, in order, on every route: log, application, customer grant, module.
 *  - Only modules the API is meant to serve. Kvalitet, Wiki and Anbud are not among them.
 *  - No route model binding: a record is found through the access services, never by id alone.
 *  - API controllers do not touch the services behind A23 and A46.
 */
class ApiRouteContractTest extends TestCase
{
    private const STACK = ['api', 'api.log', 'api.client', 'api.customer', 'customer.module'];

    /** Modules the API may serve. Extend deliberately, with the module's own access tests. */
    private const SERVED_MODULES = ['risk', 'improvements', 'objectives'];

    /** Routes that describe the caller and need no module. */
    private const MODULE_FREE = ['api.v1.me'];

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

    public function test_there_is_at_least_one_api_route(): void
    {
        $this->assertNotEmpty($this->apiRoutes());
    }

    public function test_every_api_route_is_read_only(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $this->assertSame(
                [],
                array_values(array_diff($route->methods(), ['GET', 'HEAD'])),
                "API route [{$route->uri()}] accepts more than GET.",
            );
        }
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

            foreach (self::FORBIDDEN_REFERENCES as $class) {
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
