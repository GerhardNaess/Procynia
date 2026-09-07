<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Asserts that a migration's structural preconditions actually hold, instead of letting the
 * migration quietly do nothing.
 *
 * Several migrations used to open with:
 *
 *     if (! Schema::hasTable('knowledge_items')) {
 *         return;
 *     }
 *
 * The intent was defensive, but the effect is the opposite of defensive. Laravel records a
 * migration as Ran whenever up() returns without throwing — it has no idea the body was skipped.
 * A single guard firing therefore produces a database whose migrations table claims to be fully
 * migrated while the columns are missing, and nothing will ever add them: re-running the migrator
 * is a no-op because the row is already there. The failure surfaces much later and somewhere else,
 * as "column X of relation Y does not exist" in unrelated application code.
 *
 * Every remaining caller is a case where the table is created by an earlier migration and never
 * dropped, so a missing table means the migration chain is already broken. Saying so immediately,
 * naming the migration and the database, is strictly better than recording a lie and continuing.
 *
 * This is deliberately NOT used for genuine idempotence checks ("the column is already there",
 * "this table was already created"). Those stay as ordinary early returns, because there the
 * skipped body really has nothing left to do.
 */
final class MigrationSchemaPrecondition
{
    /**
     * Purpose: Require that every named table exists before a migration touches it.
     * Inputs: $migration — the migration file's basename, for the error message; $tables.
     * Returns: None.
     * Side effects: None. Throws RuntimeException if any table is missing.
     */
    public static function requireTables(string $migration, string ...$tables): void
    {
        $missing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(self::message(
            $migration,
            count($missing) === 1
                ? "table \"{$missing[0]}\" does not exist"
                : 'tables "'.implode('", "', $missing).'" do not exist',
        ));
    }

    /**
     * Purpose: Require that a table exists and carries every named column.
     * Inputs: $migration, $table, $columns.
     * Returns: None.
     * Side effects: None. Throws RuntimeException if the table or any column is missing.
     */
    public static function requireColumns(string $migration, string $table, string ...$columns): void
    {
        self::requireTables($migration, $table);

        $missing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(self::message(
            $migration,
            'table "'.$table.'" is missing column(s) "'.implode('", "', $missing).'"',
        ));
    }

    /**
     * Names the connection and database as well as the migration: when this fires during a test
     * run the most useful question is usually "which database was the schema builder even looking
     * at?", and that is exactly what a bare "table does not exist" refuses to answer.
     */
    private static function message(string $migration, string $problem): string
    {
        $connection = Schema::getConnection();

        return sprintf(
            'Migration %s requires schema that does not exist: %s (connection "%s", database "%s"). '
            .'The migration chain is inconsistent — this migration must not be recorded as run.',
            $migration,
            $problem,
            $connection->getName() ?? (string) config('database.default'),
            $connection->getDatabaseName(),
        );
    }
}
