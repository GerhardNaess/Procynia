<?php

namespace Tests\Concerns;

use RuntimeException;
use Tests\TestCase;

/**
 * Asserts that a test really is running against the project's PostgreSQL test database, on the
 * restricted test role, before it does anything else. This replaces ~27 previously copy-pasted,
 * per-test-file implementations of "useProjectPostgresConnection()" — several of which fell back
 * to the real development database name ("procynia") whenever env('DB_DATABASE') was unreadable,
 * and none of which restored the previous default connection afterward, so a single misconfigured
 * test could leave every later test in the same process pointed at the wrong database.
 *
 * WHY THIS ONLY VERIFIES, AND NO LONGER RECONFIGURES.
 *
 * The trait used to coerce the connection: it overwrote config('database.default') and the whole
 * of config('database.connections.pgsql'), then called DB::purge('pgsql') + DB::reconnect('pgsql')
 * and registered a restore callback that purged again. Since the test-database hardening, every
 * one of those values is already in place before any test body runs — phpunit.xml forces them, and
 * Tests\TestCase::primeTestingEnvironment() forces the same values again for every test regardless
 * of how PHPUnit was invoked. The coercion could therefore only ever rewrite each value to what it
 * already was. (The single nominal difference, url => null instead of the forced empty string, is
 * not a difference at all: Illuminate\Support\ConfigurationUrlParser::parseConfiguration() drops
 * any falsy url.)
 *
 * The purge, on the other hand, was not harmless. DatabaseManager::purge() unsets the connection
 * object, so RefreshDatabase's wrapping transaction — opened moments earlier in parent::setUp() —
 * was thrown away, and the next DatabaseManager::connection('pgsql') built a fresh connection with
 * a fresh PDO. RefreshDatabase's teardown hook then saw a connection that was no longer
 * "inTransaction()" and set RefreshDatabaseState::$migrated = false, making the NEXT test in the
 * process pay for a full migrate:fresh. In practice that never fired, but only because all 13
 * test files combining this trait with RefreshDatabase also called DB::disconnect() in their own
 * tearDown() before parent::tearDown(), which nulls the PDO so the hook's getPdo() check
 * short-circuits. That is accidental, order-dependent masking, not a design — and it has been
 * removed along with the purge that made it necessary.
 *
 * What remains is the part that was always the real value: a live check, at the moment the test
 * asks for it, that this connection is the test database and nothing else. It never reads env(),
 * never has a fallback, and reuses Tests\TestCase's single source of truth rather than keeping its
 * own copy. If a test ever runs somewhere else, this now FAILS instead of silently steering the
 * process — which is the correct behaviour, and the same stance Tests\TestCase already takes when
 * it refuses to boot at all against an unsafe database.
 */
trait UsesProjectPostgresConnection
{
    /**
     * Purpose: Verify — live, not just from config — that the default connection is the project's
     * PostgreSQL test database on the restricted test role.
     * Inputs: None.
     * Returns: None.
     * Side effects: Runs two lightweight `select` queries on the existing connection. Mutates no
     * configuration, opens no new connection, and leaves any transaction the framework owns
     * completely untouched.
     */
    protected function useProjectPostgresConnection(): void
    {
        $default = (string) config('database.default');

        if ($default !== 'pgsql') {
            throw new RuntimeException(sprintf(
                'This test requires the project PostgreSQL test connection, but the default connection is [%s]. '.
                'The connection is established by phpunit.xml and Tests\TestCase::primeTestingEnvironment(); '.
                'a test must not have to switch it.',
                $default !== '' ? $default : 'n/a',
            ));
        }

        TestCase::assertConnectionIsSafeTestDatabase('pgsql');

        static::assertConnectionUsesRestrictedTestRole('pgsql');
    }
}
