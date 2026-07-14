<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Centralizes the one safe way a test switches to a real PostgreSQL connection instead of the
 * default sqlite/testing connection. This replaces ~27 previously copy-pasted, per-test-file
 * implementations of "useProjectPostgresConnection()" — several of which fell back to the real
 * development database name ("procynia") whenever env('DB_DATABASE') was unreadable, and none of
 * which restored the previous default connection afterward, so a single misconfigured test could
 * leave every later test in the same process pointed at the wrong database.
 *
 * This trait never reads env() and never has a fallback to anything other than the one allowed
 * test database. It reconfigures the existing "pgsql" connection only (never introduces a second,
 * separately-named connection as the new application default), verifies the LIVE connection
 * immediately via TestCase::assertConnectionIsSafeTestDatabase() — not just the config value —
 * and always restores the previous connection state via beforeApplicationDestroyed(), which
 * Laravel guarantees to run after every test regardless of whether the test class defines its
 * own tearDown() (as long as that tearDown() calls parent::tearDown(), which every test class
 * using this trait already does).
 */
trait UsesProjectPostgresConnection
{
    /**
     * Purpose: Point the default "pgsql" connection at the test database and verify it live.
     * Inputs: None.
     * Returns: None.
     * Side effects: Mutates config('database.default')/config('database.connections.pgsql'),
     * reconnects the "pgsql" connection, and registers a beforeApplicationDestroyed() callback
     * that restores both to their pre-call values.
     */
    protected function useProjectPostgresConnection(): void
    {
        $previousDefault = config('database.default');
        $previousPgsqlConfig = config('database.connections.pgsql');

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => 'Opaque01',
            'database.connections.pgsql.search_path' => 'public',
            'database.connections.pgsql.url' => null,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        TestCase::assertConnectionIsSafeTestDatabase('pgsql');

        $this->beforeApplicationDestroyed(function () use ($previousDefault, $previousPgsqlConfig): void {
            config([
                'database.default' => $previousDefault,
                'database.connections.pgsql' => $previousPgsqlConfig,
            ]);

            DB::purge('pgsql');
        });
    }
}
