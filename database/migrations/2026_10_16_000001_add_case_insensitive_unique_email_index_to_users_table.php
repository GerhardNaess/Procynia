<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email addresses are unique regardless of letter case. The existing users_email_unique index
 * compares the column as stored, so "Ola@Firma.no" and "ola@firma.no" could both exist. This adds a
 * unique index on lower(email) beside it. No row is changed: if case-variant duplicates already
 * exist the migration stops and names them, because choosing which account survives is a human
 * decision, not something a deploy may do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('lower(email) as normalized_email, count(*) as total')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('normalized_email')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add users_email_lower_unique: case-variant duplicate email addresses exist for '
                .implode(', ', $duplicates).'. Resolve them manually and run the migration again.'
            );
        }

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
