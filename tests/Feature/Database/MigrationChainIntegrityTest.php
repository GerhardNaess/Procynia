<?php

namespace Tests\Feature\Database;

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the property that made a whole class of test failures unreadable: a migration that skips
 * its own body and is still recorded as Ran.
 *
 * Eighteen migrations used to open with `if (! Schema::hasTable('x')) { return; }`. Laravel marks a
 * migration Ran whenever up() returns without throwing, so one guard firing left a database whose
 * migrations table claimed to be complete while the columns were absent — and re-running the
 * migrator could never repair it, because the row was already there. The damage surfaced far away
 * as "column ownership_type of relation knowledge_items does not exist" in tests that had nothing
 * to do with migrations.
 *
 * These tests are deliberately about the CHAIN, not about any one migration: the point is that
 * "migrations table says everything ran" and "the schema is actually complete" cannot drift apart.
 *
 * No isolation trait: the one test that rebuilds the schema must see committed DDL, so it cannot
 * sit inside a transaction. It ends on a completed migrate:fresh, so it hands the next test a
 * fully migrated database. The remaining tests are pure source and helper checks that touch no
 * data at all.
 */
class MigrationChainIntegrityTest extends TestCase
{
    /**
     * Columns whose absence was the observable symptom. Each is added by a migration that used to
     * carry a silent guard, so together they prove the guarded bodies actually executed.
     *
     * @var array<string, list<string>>
     */
    private const FORMERLY_GUARDED_COLUMNS = [
        'knowledge_items' => [
            'ownership_type',
            'owner_user_id',
            'owning_saved_notice_id',
            'document_theme_term_id',
            'document_category_id',
            'ai_usage_enabled',
            'document_status',
        ],
        'saved_notices' => [
            'bid_status',
            'bid_closure_reason',
            'bid_manager_user_id',
        ],
        'operational_runbooks' => [
            'operational_runbook_category_id',
        ],
    ];

    /**
     * Every assertion that needs a real migrate:fresh lives in this one test, deliberately.
     *
     * Rebuilding the schema is not free and it is not local: the suite shares one database, so each
     * extra drop-and-recreate is another window in which an unrelated test can observe a database
     * mid-rebuild. Splitting this into one assertion per test method would read better and would
     * have cost four more full rebuilds in every run, for no additional coverage.
     */
    public function test_the_migration_chain_builds_a_complete_schema_and_stays_complete(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertCompleteSchema();

        $recorded = DB::table('migrations')->pluck('migration')->sort()->values()->all();

        $onDisk = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => basename($path, '.php'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $onDisk,
            $recorded,
            'The migrations table and database/migrations must describe the same set — a difference '
            .'means either a migration silently did not run, or a recorded one no longer exists.',
        );

        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', '')->count(),
            'A blank migration name indicates a corrupted migrations table.',
        );

        // The reported corruption appeared on a SECOND migrate:fresh inside one process, which is
        // exactly what RefreshDatabase does when a test breaks its wrapping transaction. The
        // schema must survive that, and the run must leave a complete database behind for whatever
        // test comes next.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertCompleteSchema();
    }

    public function test_no_migration_can_silently_skip_its_body_again(): void
    {
        // A source-level guard: the pattern this whole test class exists to prevent must not come
        // back. Legitimate idempotence checks ("the column is already there") are unaffected —
        // they do not test for a MISSING table before doing the work.
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $source = (string) file_get_contents($path);
            $up = $this->upMethodBody($source);

            if ($up === '') {
                continue;
            }

            // Conditions are single-line throughout this codebase, so anchoring on the line keeps
            // the pattern readable and still catches multi-table guards like
            // `if (! Schema::hasTable('a') || ! Schema::hasTable('b')) { return; }`.
            if (preg_match('/if \([^\n]*!\s*Schema::hasTable\([^\n]*\)\s*\{\s*\n\s*return;/', $up) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These migrations return early when a table is missing, which records them as Ran while\n"
            .'doing nothing. Use '.MigrationSchemaPrecondition::class."::requireTables() instead.\n"
            .implode("\n", $offenders),
        );
    }

    public function test_the_precondition_helper_refuses_a_missing_table(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a_table_that_does_not_exist');

        MigrationSchemaPrecondition::requireTables('test_migration', 'a_table_that_does_not_exist');
    }

    public function test_the_precondition_helper_names_the_database_it_inspected(): void
    {
        try {
            MigrationSchemaPrecondition::requireTables('test_migration', 'a_table_that_does_not_exist');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            // Without this, a guard firing against the wrong connection is indistinguishable from
            // a genuinely broken chain — which is the ambiguity that made the original bug hard.
            $this->assertStringContainsString('procynia_test', $exception->getMessage());
            $this->assertStringContainsString('test_migration', $exception->getMessage());
        }
    }

    public function test_the_precondition_helper_accepts_a_table_that_exists(): void
    {
        MigrationSchemaPrecondition::requireTables('test_migration', 'users');
        MigrationSchemaPrecondition::requireColumns('test_migration', 'users', 'id', 'email');

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_the_precondition_helper_refuses_a_missing_column(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a_column_that_does_not_exist');

        MigrationSchemaPrecondition::requireColumns('test_migration', 'users', 'a_column_that_does_not_exist');
    }

    private function assertCompleteSchema(): void
    {
        foreach (self::FORMERLY_GUARDED_COLUMNS as $table => $columns) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Table {$table} is missing after migrate:fresh.",
            );

            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Column {$table}.{$column} is missing while the migrations table claims its "
                    .'migration ran — this is exactly the silent-skip failure.',
                );
            }
        }
    }

    private function upMethodBody(string $source): string
    {
        $start = strpos($source, 'function up()');

        if ($start === false) {
            return '';
        }

        $end = strpos($source, 'function down()', $start);

        return $end === false
            ? substr($source, $start)
            : substr($source, $start, $end - $start);
    }
}
