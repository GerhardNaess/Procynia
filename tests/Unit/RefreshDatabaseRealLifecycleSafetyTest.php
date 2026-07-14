<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Prove the database-safety guard through the REAL Laravel/PHPUnit test lifecycle —
 * not just by calling TestCase::assertConnectionIsSafeTestDatabase() in isolation (see
 * UsesProjectPostgresConnectionTest for that). Two things must be true for the whole suite, not
 * just the ~27 files that use Tests\Concerns\UsesProjectPostgresConnection:
 *
 * 1. RefreshDatabase — used directly by ~110 other test files, with no involvement of that
 *    trait at all — actually migrates and operates against procynia_test.
 * 2. If a connection's LIVE database ever disagreed with its config (the exact failure mode
 *    behind the incident this fix responds to: config said the test database, the actual PDO
 *    was talking to the real one), RefreshDatabase's migrate:fresh step — which is gated by a
 *    process-wide static flag (RefreshDatabaseState::$migrated) and would DROP EVERY TABLE if it
 *    ever ran against the wrong database — must never be reached.
 *
 * Inputs: None.
 * Returns: None.
 * Side effects: None on any real database — the negative-case test never opens a connection to
 * 'procynia' (or anywhere else). It substitutes a connection DOUBLE (a minimal
 * Illuminate\Database\Connection subclass whose selectOne() returns a canned, deliberately
 * disallowed database name) for the "pgsql" connection before the guard runs, so the LIVE check
 * sees exactly the disagreement it exists to catch, entirely in-process. The migration hook is
 * additionally overridden to be inert (it never calls the real migrator), so even if the guard
 * had a bug, this test cannot itself trigger a migrate:fresh against any database.
 */
class RefreshDatabaseRealLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bullet 1: prove RefreshDatabase, used the ordinary way with no special helper, ends up
     * migrated against and querying procynia_test — both by config and by a live query.
     */
    public function test_refresh_database_migrates_and_operates_against_the_test_database(): void
    {
        $this->assertSame('procynia_test', DB::connection()->getDatabaseName());

        $liveDatabase = DB::connection()->selectOne('select current_database() as db')->db;
        $this->assertSame('procynia_test', $liveDatabase);
        $this->assertNotSame('procynia', $liveDatabase);

        $this->assertTrue(
            Schema::hasTable('users'),
            'RefreshDatabase must have actually migrated real application tables into procynia_test for this assertion to pass.',
        );
    }

    /**
     * Bullet 2: a connection whose config claims the test database but whose LIVE connection is
     * actually the real one must be rejected before RefreshDatabase's migration step — proven by
     * running the actual setUp()/createApplication()/setUpTraits() lifecycle on an isolated inner
     * test case, not by calling the validation method directly.
     */
    public function test_a_live_mismatched_connection_is_rejected_before_refresh_database_can_migrate(): void
    {
        $victim = new class('bootForTest') extends TestCase
        {
            use RefreshDatabase;

            public bool $refreshTestDatabaseWasReached = false;

            /**
             * Injects the failure mode behind the incident — config still correctly says
             * procynia_test, but the LIVE connection disagrees (e.g. a stale PDO handle
             * established before a config change) — immediately before the real guard runs, so
             * the guard sees precisely what a genuinely corrupted boot would produce. Uses a
             * connection DOUBLE (never a real PDO/network connection, to any database) whose
             * selectOne() returns a canned, deliberately-disallowed database name — this is
             * sufficient to exercise the exact code path assertConnectionIsSafeTestDatabase()
             * uses (`DB::connection($name)->selectOne('select current_database() as db')->db`)
             * without ever opening a socket anywhere.
             */
            protected function guardAgainstUnsafeTestingDatabase(Application $app): void
            {
                $defaultConnection = (string) $app['config']->get('database.default');
                $database = $app->make('db');

                $database->purge($defaultConnection);
                $database->extend($defaultConnection, fn (): Connection => new class(fn () => throw new RuntimeException('This connection double must never actually connect anywhere.'), 'procynia_test', '', ['driver' => 'pgsql']) extends Connection
                {
                    public function selectOne($query, $bindings = [], $useReadPdo = true)
                    {
                        return (object) ['db' => 'not_an_allowed_test_database'];
                    }
                });

                parent::guardAgainstUnsafeTestingDatabase($app);
            }

            public function bootForTest(): void
            {
                $this->setUp();
            }

            /**
             * Deliberately never calls the real migrator (which would run `migrate:fresh` — a
             * full drop-and-recreate of every table). This override exists purely so the test
             * can observe whether RefreshDatabase's migration step was ever reached, without
             * risking a real migration against any database even if the guard had a bug.
             */
            protected function refreshTestDatabase()
            {
                $this->refreshTestDatabaseWasReached = true;
            }
        };

        try {
            $victim->bootForTest();
            $this->fail('Expected a RuntimeException to be thrown before RefreshDatabase could run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('live connection', $exception->getMessage());
            $this->assertStringContainsString('not_an_allowed_test_database', $exception->getMessage());
        }

        $this->assertFalse(
            $victim->refreshTestDatabaseWasReached,
            'RefreshDatabase reached its migration step despite a live-mismatched connection — the guard ran too late or not at all.',
        );
    }
}
