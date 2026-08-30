<?php

namespace Tests\Feature\Security;

use App\Services\Operations\RuntimePreflightService;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Redis authentication (security finding F-08).
 *
 * Redis holds Procynia's sessions, all nine queues and the cache. Before this it ran without
 * requirepass: anything that could reach the port got PONG, and with it readable session ids and
 * writable queue payloads.
 *
 * Two layers are pinned here. The Compose layer makes an unauthenticated Redis impossible to start.
 * The runtime layer makes a deployment pointing at an external Redis without a credential fail
 * loudly instead of connecting anyway.
 *
 * No test contains a real credential.
 */
class RedisSecurityConfigTest extends TestCase
{
    private function composeFile(string $name): string
    {
        return (string) file_get_contents(base_path($name));
    }

    /** Invoke the private preflight check without exposing it on the public API. */
    private function redisAuthCheck(): array
    {
        $service = app(RuntimePreflightService::class);
        $method = new ReflectionMethod($service, 'checkRedisAuthentication');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    // -----------------------------------------------------------------------
    // Laravel configuration
    // -----------------------------------------------------------------------

    public function test_both_redis_connections_read_the_password_from_the_environment(): void
    {
        // Both connections point at the same instance, so both must authenticate. A connection that
        // silently omitted the credential would simply fail to connect once Redis requires one.
        foreach (['default', 'cache'] as $connection) {
            $this->assertArrayHasKey(
                'password',
                config("database.redis.{$connection}"),
                "The {$connection} Redis connection must carry a password.",
            );
        }

        $config = (string) file_get_contents(config_path('database.php'));

        $this->assertSame(
            2,
            substr_count($config, "'password' => env('REDIS_PASSWORD')"),
            'Both Redis connections should take the credential from REDIS_PASSWORD.',
        );
    }

    public function test_the_session_store_uses_a_redis_connection_that_carries_the_credential(): void
    {
        // F-06 hardened the session cookie; this makes sure the session *store* behind it is not
        // sitting on an unauthenticated Redis.
        Config::set('session.driver', 'redis');
        Config::set('session.connection', 'default');

        $this->assertArrayHasKey('password', config('database.redis.default'));
    }

    public function test_no_redis_credential_is_hardcoded_in_committed_configuration(): void
    {
        foreach (['config/database.php', 'docker-compose.yml', 'docker-compose.prod.yml', '.env.example'] as $file) {
            $contents = (string) file_get_contents(base_path($file));

            // Must stay on one line: a \s* here would swallow the newline and match the *next*
            // key, which is exactly how this assertion first produced a false positive on
            // "REDIS_PASSWORD=" followed by "REDIS_PORT".
            // ${...} interpolation and env('...') lookups are references, not literals.
            $this->assertDoesNotMatchRegularExpression(
                '/REDIS_PASSWORD[ \t]*[:=][ \t]*["\']?(?!\$\{)(?!env\()[A-Za-z0-9!@#$%^&*_+-]{6,}/',
                $contents,
                "{$file} must not contain a literal Redis password.",
            );
        }
    }

    // -----------------------------------------------------------------------
    // Runtime preflight (ops:runtime-check)
    // -----------------------------------------------------------------------

    public function test_production_without_a_redis_password_is_a_critical_failure(): void
    {
        // The point of F-08: a deployment that reaches production without a Redis credential must
        // stop, not connect to an open Redis.
        $this->app->detectEnvironment(fn () => 'production');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.password', null);
        Config::set('database.redis.cache.password', null);

        $check = $this->redisAuthCheck();

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);
    }

    public function test_a_placeholder_password_is_rejected_in_production(): void
    {
        // "null" is what a copied .env line leaves behind, and Redis would happily accept the literal
        // string as the password — which looks configured while being guessable.
        $this->app->detectEnvironment(fn () => 'production');
        Config::set('database.redis.default.url', null);

        foreach (['null', 'NULL', 'none', 'false', ' '] as $placeholder) {
            Config::set('database.redis.default.password', $placeholder);
            Config::set('database.redis.cache.password', $placeholder);

            $check = $this->redisAuthCheck();

            $this->assertSame(
                RuntimePreflightService::STATUS_FAIL,
                $check['status'],
                "'{$placeholder}' must not count as a configured password.",
            );
        }
    }

    public function test_mismatched_credentials_between_connections_fail(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.password', 'one-value');
        Config::set('database.redis.cache.password', 'another-value');

        $check = $this->redisAuthCheck();

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);
    }

    public function test_a_configured_password_passes_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.password', 'a-configured-value');
        Config::set('database.redis.cache.password', 'a-configured-value');

        $check = $this->redisAuthCheck();

        $this->assertSame(RuntimePreflightService::STATUS_PASS, $check['status']);
    }

    public function test_local_development_without_a_password_warns_but_does_not_fail(): void
    {
        // Local convenience must not be a critical failure, or every developer's runtime check is red.
        $this->app->detectEnvironment(fn () => 'local');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.password', null);
        Config::set('database.redis.cache.password', null);

        $check = $this->redisAuthCheck();

        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertFalse($check['critical']);
    }

    public function test_the_check_never_returns_the_credential(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Config::set('database.redis.default.url', null);
        Config::set('database.redis.default.password', 'super-secret-value');
        Config::set('database.redis.cache.password', 'super-secret-value');

        $this->assertStringNotContainsString(
            'super-secret-value',
            json_encode($this->redisAuthCheck()),
        );
    }

    // -----------------------------------------------------------------------
    // Compose topology
    // -----------------------------------------------------------------------

    public function test_redis_requires_a_password_and_refuses_to_start_without_one(): void
    {
        $compose = $this->composeFile('docker-compose.yml');

        $this->assertStringContainsString('--requirepass', $compose);

        // ${REDIS_PASSWORD:?...} is what makes this fail-closed: compose aborts rather than starting
        // an open Redis when the variable is missing or empty.
        $this->assertMatchesRegularExpression(
            '/REDIS_PASSWORD:\s*"\$\{REDIS_PASSWORD:\?/',
            $compose,
            'Redis must refuse to start without REDIS_PASSWORD.',
        );
    }

    public function test_the_redis_healthcheck_authenticates(): void
    {
        // A ping without credentials would report the container unhealthy even though Redis is fine.
        $compose = $this->composeFile('docker-compose.yml');

        $this->assertStringContainsString('redis-cli --no-auth-warning -a "$$REDIS_PASSWORD" ping', $compose);
    }

    public function test_production_does_not_publish_the_redis_port(): void
    {
        // Authentication is not a substitute for not exposing the port.
        $this->assertMatchesRegularExpression(
            '/redis:\s*\n\s*ports:\s*!override\s*\[\]/',
            $this->composeFile('docker-compose.prod.yml'),
        );
    }

    public function test_every_service_that_talks_to_redis_inherits_the_credential(): void
    {
        // The credential reaches containers through `env_file: .env`, so every Redis-using service
        // must declare it. A worker missing it would fail to connect once Redis requires auth.
        $compose = $this->composeFile('docker-compose.yml');
        $services = [];
        $current = null;

        foreach (explode("\n", $compose) as $line) {
            if (preg_match('/^    ([a-z][a-z0-9_-]*):/', $line, $m)) {
                $current = $m[1];
                $services[$current] = ['redis' => false, 'env_file' => false];
            }

            if ($current === null) {
                continue;
            }

            if (str_contains($line, 'REDIS_HOST')) {
                $services[$current]['redis'] = true;
            }

            if (str_contains($line, 'env_file')) {
                $services[$current]['env_file'] = true;
            }
        }

        $usingRedis = array_keys(array_filter($services, fn (array $s) => $s['redis']));

        $this->assertNotEmpty($usingRedis);

        foreach ($usingRedis as $service) {
            $this->assertTrue(
                $services[$service]['env_file'],
                "Service {$service} talks to Redis but does not inherit .env, so it would get no credential.",
            );
        }
    }
}
