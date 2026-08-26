<?php

namespace Tests\Feature\Azure;

use App\Jobs\OpsQueueHeartbeatJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Azure migration readiness — Redis runtime contract.
 *
 * Procynia runs queue, cache and session on Redis, so Redis is mandatory from the first Azure
 * deployment. Two things change when the local redis:7-alpine container is replaced by Azure
 * Managed Redis:
 *
 *   1. The connection becomes TLS with access-key auth. The application receives it as a single
 *      REDIS_URL secret (tls://default:<key>@<host>:10000/0) rather than REDIS_HOST/REDIS_PORT.
 *
 *   2. Azure Managed Redis exposes a single logical database. docker-compose.yml currently splits
 *      cache onto REDIS_CACHE_DB=1 while queue and session use database 0. That split cannot
 *      survive the move, so both must share database 0.
 *
 * These tests run against the real Redis in the local stack. Nothing here is mocked: a passing
 * result means the framework really does resolve the URL that way, and cache/queue really do
 * coexist on one database. What cannot be proven locally is the TLS handshake itself and Azure's
 * server-side behaviour — see docs/azure-migration-test-readiness.md.
 */
class RedisRuntimeContractTest extends TestCase
{
    private string $testPrefix;

    protected function setUp(): void
    {
        parent::setUp();

        // Every key this test writes is namespaced, so it can never collide with — or outlive —
        // anything in the shared development Redis.
        $this->testPrefix = 'azure-readiness-test-'.Str::lower(Str::random(10)).'-';
    }

    protected function tearDown(): void
    {
        $this->flushTestKeys();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // REDIS_URL contract
    // -----------------------------------------------------------------------

    /**
     * config/database.php must read REDIS_URL for both connections the application uses, otherwise
     * the Key Vault secret would only reach one of them.
     */
    public function test_both_redis_connections_read_the_url_from_env(): void
    {
        $source = file_get_contents(config_path('database.php'));

        $this->assertSame(
            2,
            substr_count($source, "'url' => env('REDIS_URL')"),
            "config/database.php must read REDIS_URL for both the 'default' and the 'cache' redis connection. "
            .'In Azure the whole connection arrives as one REDIS_URL secret from Key Vault.',
        );
    }

    /**
     * The real framework code path: RedisManager must turn a tls:// URL into a phpredis TLS
     * connection, and must carry the credentials and the database index across.
     */
    public function test_redis_manager_promotes_a_tls_url_to_a_tls_scheme(): void
    {
        $resolved = $this->resolveRedisConfiguration([
            'url' => 'tls://default:examplekey@procynia.norwayeast.redis.azure.net:10000/0',
            'host' => '127.0.0.1',
            'port' => '6379',
            'database' => '5',
        ]);

        $this->assertSame(
            'tls',
            $resolved['scheme'] ?? null,
            'Laravel must promote a tls:// REDIS_URL to the phpredis TLS scheme. Without this, the '
            .'connection to Azure Managed Redis would be attempted in cleartext and rejected.',
        );
        $this->assertSame('procynia.norwayeast.redis.azure.net', $resolved['host']);
        $this->assertSame(10000, (int) $resolved['port']);
        $this->assertSame('default', $resolved['username']);
        $this->assertSame('examplekey', $resolved['password']);
        $this->assertSame(
            '0',
            (string) $resolved['database'],
            'The /0 path in REDIS_URL must override the configured database index, because Azure '
            .'Managed Redis only exposes database 0.',
        );
    }

    /**
     * A plain tcp:// URL must NOT be promoted to TLS — that would break the local stack.
     */
    public function test_a_non_tls_url_does_not_gain_a_tls_scheme(): void
    {
        $resolved = $this->resolveRedisConfiguration([
            'url' => 'tcp://:secret@redis:6379/0',
            'host' => '127.0.0.1',
            'port' => '6379',
        ]);

        $this->assertSame('tcp', $resolved['scheme'] ?? null);
        $this->assertSame('redis', $resolved['host']);
    }

    /**
     * A URL without a database path must leave the configured index alone. This is what makes the
     * explicit /0 in the Azure REDIS_URL load-bearing rather than cosmetic.
     */
    public function test_a_url_without_a_database_path_keeps_the_configured_database(): void
    {
        $resolved = $this->resolveRedisConfiguration([
            'url' => 'tls://default:examplekey@procynia.norwayeast.redis.azure.net:10000',
            'host' => '127.0.0.1',
            'port' => '6379',
            'database' => '1',
        ]);

        $this->assertSame(
            '1',
            (string) $resolved['database'],
            'Without an explicit /0 the cache connection would keep REDIS_CACHE_DB=1, which Azure '
            .'Managed Redis does not have. The Azure REDIS_URL must therefore end with /0.',
        );
    }

    // -----------------------------------------------------------------------
    // Single-database coexistence, against the real Redis
    // -----------------------------------------------------------------------

    /**
     * The core Azure question: with cache and queue forced onto the same Redis database, do they
     * still stay apart? This runs against the real Redis server, using the real cache repository
     * and the real Redis queue driver.
     */
    public function test_cache_and_queue_coexist_on_database_zero_without_colliding(): void
    {
        $this->useRealRedisOnDatabaseZero();

        $cacheKey = $this->testPrefix.'cache-key';
        $queueName = $this->testPrefix.'queue';

        Cache::store('redis')->put($cacheKey, 'cached-value', 60);

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue, 'Expected the real Redis queue driver.');
        $queue->push(new OpsQueueHeartbeatJob($queueName), '', $queueName);

        $this->assertSame(
            'cached-value',
            Cache::store('redis')->get($cacheKey),
            'A cache read must survive sharing database 0 with the queue.',
        );

        $this->assertSame(
            1,
            $queue->size($queueName),
            'The queued job must still be on the queue after a cache write to the same database.',
        );

        // And the actual key names must not overlap. This is what makes the shared database safe:
        // the connection prefix and the cache prefix keep the namespaces disjoint.
        $rawKeys = array_map(
            static fn ($key) => (string) $key,
            Redis::connection('default')->keys('*'.$this->testPrefix.'*'),
        );

        $cacheKeys = array_values(array_filter($rawKeys, static fn ($k) => str_contains($k, 'cache-key')));
        $queueKeys = array_values(array_filter($rawKeys, static fn ($k) => str_contains($k, 'queues:')));

        $this->assertNotEmpty($cacheKeys, 'The cache entry was not found in Redis: '.implode(', ', $rawKeys));
        $this->assertNotEmpty($queueKeys, 'The queue entry was not found in Redis: '.implode(', ', $rawKeys));
        $this->assertEmpty(
            array_intersect($cacheKeys, $queueKeys),
            'Cache keys and queue keys must not overlap when they share one Redis database.',
        );

        $queue->clear($queueName);
    }

    /**
     * A job pushed to Redis must be readable back as the same job — the queue is not just a
     * write-only sink. This is the local stand-in for "a worker picks the job up from Managed
     * Redis", using a real application job and the real serializer.
     */
    public function test_a_real_job_pushed_to_redis_can_be_popped_back(): void
    {
        $this->useRealRedisOnDatabaseZero();

        $queueName = $this->testPrefix.'roundtrip';

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->push(new OpsQueueHeartbeatJob($queueName), '', $queueName);

        $job = $queue->pop($queueName);

        $this->assertNotNull($job, 'A job written to Redis must be poppable by a worker.');
        $this->assertStringContainsString(
            'OpsQueueHeartbeatJob',
            $job->getRawBody(),
            'The popped payload must be the job that was pushed.',
        );

        // Deleted without ever being handled: this test proves transport, not job behaviour.
        $job->delete();
        $queue->clear($queueName);
    }

    /**
     * The cache and connection prefixes are what keep the namespaces apart on a single database.
     * If either were emptied, the previous test's guarantee would quietly disappear.
     */
    public function test_cache_and_connection_prefixes_are_non_empty_and_distinct(): void
    {
        $connectionPrefix = (string) config('database.redis.options.prefix');
        $cachePrefix = (string) config('cache.prefix');

        $this->assertNotSame('', $connectionPrefix, 'database.redis.options.prefix must not be empty.');
        $this->assertNotSame('', $cachePrefix, 'cache.prefix must not be empty.');
        $this->assertNotSame(
            $connectionPrefix,
            $cachePrefix,
            'The Redis connection prefix and the cache prefix must differ, so cache entries cannot '
            .'collide with queue keys once both share database 0 in Azure.',
        );
    }

    // -----------------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------------

    /**
     * The Azure env contract sets SESSION_DRIVER=redis and SESSION_CONNECTION=default. Both must be
     * values the application actually understands.
     */
    public function test_session_can_be_driven_by_redis(): void
    {
        $this->useRealRedisOnDatabaseZero();

        Config::set('session.driver', 'redis');
        Config::set('session.connection', 'default');

        $handler = app('session')->driver('redis')->getHandler();

        $sessionId = $this->testPrefix.'session';
        $this->assertTrue($handler->write($sessionId, 'azure-session-payload'));
        $this->assertStringContainsString('azure-session-payload', (string) $handler->read($sessionId));

        $handler->destroy($sessionId);
    }

    /**
     * Sessions must not depend on the container filesystem, or a Container Apps restart or a second
     * web replica would log every user out.
     */
    public function test_no_runtime_component_pins_sessions_to_the_local_filesystem(): void
    {
        foreach ([
            'docker-compose.yml' => 'SESSION_DRIVER: redis',
            'infra/main.bicep' => "name: 'SESSION_DRIVER'",
        ] as $file => $needle) {
            $this->assertStringContainsString(
                $needle,
                file_get_contents(base_path($file)),
                sprintf('%s must configure the session driver explicitly.', $file),
            );
        }

        $bicep = file_get_contents(base_path('infra/main.bicep'));
        $this->assertMatchesRegularExpression(
            "/name: 'SESSION_DRIVER'\s*\n\s*value: 'redis'/",
            $bicep,
            'The Azure environment contract must set SESSION_DRIVER=redis, not file.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Invoke the framework's own connection-configuration parser, so the assertions above are about
     * real Laravel behaviour rather than a re-implementation of it.
     *
     * @param  array<string, mixed>  $connectionConfig
     * @return array<string, mixed>
     */
    private function resolveRedisConfiguration(array $connectionConfig): array
    {
        $manager = new RedisManager(app(), 'phpredis', ['default' => $connectionConfig]);

        $method = new ReflectionMethod($manager, 'parseConnectionConfiguration');
        $method->setAccessible(true);

        return $method->invoke($manager, $connectionConfig);
    }

    /**
     * Point both redis connections at the real local Redis, both on database 0 — the Azure layout.
     *
     * RedisManager snapshots database.redis when the "redis" singleton is constructed, so a plain
     * Config::set() here would be silently ignored and the cache connection would stay on database
     * 1 — the exact split this test exists to prove Procynia can live without. The singleton is
     * therefore rebuilt, and the resulting live connection is asserted to really be on database 0.
     */
    private function useRealRedisOnDatabaseZero(): void
    {
        foreach (['default', 'cache'] as $connection) {
            Config::set("database.redis.{$connection}", [
                'url' => null,
                'host' => 'redis',
                'port' => 6379,
                'database' => 0,
                'username' => null,
                'password' => null,
            ]);
        }

        Config::set('database.redis.client', 'phpredis');
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.connection', 'cache');
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.connection', 'default');

        foreach (['redis', 'redis.connection', 'cache', 'queue'] as $abstract) {
            app()->forgetInstance($abstract);
        }

        Redis::clearResolvedInstances();
        Cache::clearResolvedInstances();
        Queue::clearResolvedInstances();

        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Local Redis is not reachable from the test container: '.$e->getMessage());
        }

        // Without this guard the test could pass while the cache connection was still on database
        // 1, proving nothing about the Azure single-database layout.
        foreach (['default', 'cache'] as $connection) {
            $this->assertSame(
                0,
                (int) Redis::connection($connection)->client()->getDbNum(),
                sprintf('The [%s] redis connection must really be on database 0 for this test to mean anything.', $connection),
            );
        }
    }

    /**
     * Remove every key this test created. Scoped to the random per-test prefix, so it can never
     * touch application data in the shared development Redis.
     */
    private function flushTestKeys(): void
    {
        try {
            $connection = Redis::connection('default');
            $keys = $connection->keys('*'.$this->testPrefix.'*');

            foreach ($keys as $key) {
                // keys() returns prefixed names; del() re-applies the prefix, so strip it first.
                $prefix = (string) config('database.redis.options.prefix');
                $bare = $prefix !== '' && str_starts_with((string) $key, $prefix)
                    ? substr((string) $key, strlen($prefix))
                    : (string) $key;

                $connection->del($bare);
            }
        } catch (\Throwable) {
            // Redis unreachable — nothing was written, so nothing to clean.
        }
    }
}
