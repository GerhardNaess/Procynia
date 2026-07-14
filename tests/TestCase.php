<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database targets allowed during automated tests. This is the single source of
     * truth for test-database safety — Tests\Concerns\UsesProjectPostgresConnection reuses it
     * (via assertConnectionIsSafeTestDatabase()) rather than keeping its own copy, so there is
     * exactly one place that decides what counts as "a test database".
     *
     * @var array<string, list<string>>
     */
    private const SAFE_TEST_DATABASES = [
        'pgsql' => ['procynia_test'],
        'sqlite' => [':memory:'],
    ];

    /**
     * Create the application and stop immediately if tests resolve to an unsafe database.
     */
    public function createApplication()
    {
        $this->primeTestingEnvironment();

        /** @var Application $app */
        $app = parent::createApplication();

        $this->guardAgainstUnsafeTestingDatabase($app);

        return $app;
    }

    /**
     * Set explicit testing defaults before Laravel resolves cached config paths.
     */
    protected function primeTestingEnvironment(): void
    {
        $defaults = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/testing-config.php',
            'APP_SERVICES_CACHE' => 'bootstrap/cache/testing-services.php',
            'APP_PACKAGES_CACHE' => 'bootstrap/cache/testing-packages.php',
            'APP_ROUTES_CACHE' => 'bootstrap/cache/testing-routes.php',
            'APP_EVENTS_CACHE' => 'bootstrap/cache/testing-events.php',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'procynia_test',
            'DB_USERNAME' => 'gehard',
            'DB_PASSWORD' => 'Opaque01',
            'QUEUE_CONNECTION' => 'sync',
            'STRIPE_KEY' => 'pk_test_procynia',
            'STRIPE_SECRET' => 'sk_test_procynia',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_test_procynia',
            'STRIPE_PLAN_MONTHLY' => 'price_test_plan_monthly',
            'STRIPE_PLAN_YEARLY' => 'price_test_plan_yearly',
            'STRIPE_PRICE_PRO_MONTHLY' => 'price_test_pro_monthly',
            'STRIPE_PRICE_PRO_YEARLY' => 'price_test_pro_yearly',
            'STRIPE_PRICE_MAX_MONTHLY' => 'price_test_max_monthly',
            'STRIPE_PRICE_MAX_YEARLY' => 'price_test_max_yearly',
            'STRIPE_PRICE_ULTRA_MONTHLY' => 'price_test_ultra_monthly',
            'STRIPE_PRICE_ULTRA_YEARLY' => 'price_test_ultra_yearly',
        ];

        foreach ($defaults as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Refuse to run the test suite unless it uses an explicit safe test database.
     */
    protected function guardAgainstUnsafeTestingDatabase(Application $app): void
    {
        if ($app->environment() !== 'testing') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests outside the testing environment. Current environment: [%s].',
                $app->environment(),
            ));
        }

        if ($app->configurationIsCached()) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests with cached config. Current config cache path: [%s].',
                $app->getCachedConfigPath(),
            ));
        }

        $defaultConnection = (string) $app['config']->get('database.default');
        $connection = $app['config']->get("database.connections.{$defaultConnection}", []);
        $databaseName = trim((string) ($connection['database'] ?? ''));
        $host = trim((string) ($connection['host'] ?? ''));
        $allowedDatabases = self::SAFE_TEST_DATABASES[$defaultConnection] ?? [];

        if (! in_array($databaseName, $allowedDatabases, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against unsafe database configuration. Connection [%s] on host [%s] resolves to database [%s]. Allowed test targets are: %s.',
                $defaultConnection,
                $host !== '' ? $host : 'n/a',
                $databaseName !== '' ? $databaseName : 'n/a',
                json_encode(self::SAFE_TEST_DATABASES, JSON_UNESCAPED_SLASHES),
            ));
        }

        if ($defaultConnection === 'pgsql' && $databaseName === 'procynia') {
            throw new RuntimeException('Refusing to run tests against the real procynia database.');
        }
    }

    /**
     * Purpose: Fail fast if a live database connection does not resolve to an explicitly allowed
     * test database — checked against the ACTIVE connection itself (via a live `current_database()`
     * query), not just config or environment values, so a stale PDO handle or a misconfigured
     * fallback can never silently let a test run — let alone migrate or write — against the real
     * development database. This is the check Tests\Concerns\UsesProjectPostgresConnection calls
     * immediately after establishing/reconnecting a connection, before any other test code runs.
     * Inputs: The connection name to verify (e.g. "pgsql").
     * Returns: None.
     * Side effects: Opens the connection if not already open (a lightweight `select` query).
     */
    public static function assertConnectionIsSafeTestDatabase(string $connectionName): void
    {
        $driver = (string) config("database.connections.{$connectionName}.driver", $connectionName);
        $allowedDatabases = self::SAFE_TEST_DATABASES[$connectionName] ?? self::SAFE_TEST_DATABASES[$driver] ?? [];

        if ($allowedDatabases === []) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests on connection [%s] — it is not in the known-safe test connection list.',
                $connectionName,
            ));
        }

        $configuredDatabase = trim((string) config("database.connections.{$connectionName}.database", ''));

        if (! in_array($configuredDatabase, $allowedDatabases, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against connection [%s] configured for database [%s]. Allowed: %s.',
                $connectionName,
                $configuredDatabase !== '' ? $configuredDatabase : 'n/a',
                implode(', ', $allowedDatabases),
            ));
        }

        if ($driver === 'sqlite') {
            // :memory: has no live "current database" concept to query — the config check above
            // is the whole check for this driver.
            return;
        }

        $liveDatabase = trim((string) DB::connection($connectionName)->selectOne('select current_database() as db')->db);

        if (! in_array($liveDatabase, $allowedDatabases, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests — live connection [%s] reports current_database() = [%s], not an allowed test database (%s). A config value can lie about which database is actually connected; this check cannot.',
                $connectionName,
                $liveDatabase !== '' ? $liveDatabase : 'n/a',
                implode(', ', $allowedDatabases),
            ));
        }
    }
}
