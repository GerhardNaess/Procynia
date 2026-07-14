<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Prove the test-suite database safety guarantees required after the incident where a
 * per-test-file connection helper fell back to the real "procynia" database and never restored
 * the previous default connection, silently letting a later test's migrations/writes run against
 * the development database. Every guarantee here must hold for ALL ~27 test files that use
 * Tests\Concerns\UsesProjectPostgresConnection, not just this file.
 * Inputs: None.
 * Returns: None.
 * Side effects: Temporarily mutates database config/env within each test; always restored.
 */
class UsesProjectPostgresConnectionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    public function test_assert_connection_is_safe_test_database_throws_for_the_real_procynia_database(): void
    {
        $originalPgsqlConfig = config('database.connections.pgsql');

        try {
            config(['database.connections.pgsql.database' => 'procynia']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Refusing to run tests/');

            TestCase::assertConnectionIsSafeTestDatabase('pgsql');
        } finally {
            config(['database.connections.pgsql' => $originalPgsqlConfig]);
        }
    }

    public function test_assert_connection_is_safe_test_database_throws_for_an_unknown_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not in the known-safe test connection list/');

        TestCase::assertConnectionIsSafeTestDatabase('some_unconfigured_connection');
    }

    public function test_it_resolves_the_configured_and_live_connection_to_the_test_database_only(): void
    {
        $this->useProjectPostgresConnection();

        $this->assertSame('procynia_test', config('database.connections.pgsql.database'));
        $this->assertSame('procynia_test', DB::connection('pgsql')->getDatabaseName());

        $liveDatabase = DB::connection('pgsql')->selectOne('select current_database() as db')->db;
        $this->assertSame('procynia_test', $liveDatabase);
        $this->assertNotSame('procynia', $liveDatabase);
    }

    /**
     * The root cause of the data-loss incident: env('DB_DATABASE') returning empty/unreadable in
     * some test's execution context, and the OLD per-file helper silently defaulting to the real
     * database name in that case. The new trait must never read env() for this at all.
     */
    public function test_it_never_falls_back_to_env_database_or_any_non_test_database(): void
    {
        $originalValue = getenv('DB_DATABASE');
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);

        try {
            $this->useProjectPostgresConnection();

            $this->assertSame('procynia_test', config('database.connections.pgsql.database'));
            $this->assertSame('procynia_test', DB::connection('pgsql')->getDatabaseName());
        } finally {
            if ($originalValue !== false) {
                putenv("DB_DATABASE={$originalValue}");
                $_ENV['DB_DATABASE'] = $originalValue;
                $_SERVER['DB_DATABASE'] = $originalValue;
            }
        }
    }

    /**
     * The other half of the root cause: DB::setDefaultConnection()/config('database.default')
     * mutations were never undone, so a single test could leave every LATER test in the same
     * process pointed at the wrong default connection. beforeApplicationDestroyed() must restore
     * both the default connection name and the full pgsql connection config exactly.
     */
    public function test_it_restores_the_previous_default_connection_after_the_application_is_destroyed(): void
    {
        $originalDefault = config('database.default');
        $originalPgsqlConfig = config('database.connections.pgsql');

        $this->useProjectPostgresConnection();

        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('procynia_test', config('database.connections.pgsql.database'));

        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertSame($originalDefault, config('database.default'));
        $this->assertEquals($originalPgsqlConfig, config('database.connections.pgsql'));
    }
}
