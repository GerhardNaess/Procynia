<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's grants to client applications are access changes like any other (D13, point 5):
 * given, their roles changed, revoked — each a customer_access_changes row with its actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_access_changes DROP CONSTRAINT customer_access_changes_subject_type_check;
            ALTER TABLE customer_access_changes
                ADD CONSTRAINT customer_access_changes_subject_type_check CHECK (subject_type IN ('user', 'customer_role', 'permission_settings', 'saved_notice', 'api_client_grant'));
            SQL);
    }

    public function down(): void
    {
        // Rows about grants cannot be removed (the trail is append-only), so the wider check stays
        // while any exist — the same rule as 2026_10_23_000012.
        if (DB::getDriverName() !== 'pgsql' || DB::table('customer_access_changes')->where('subject_type', 'api_client_grant')->exists()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_access_changes DROP CONSTRAINT customer_access_changes_subject_type_check;
            ALTER TABLE customer_access_changes
                ADD CONSTRAINT customer_access_changes_subject_type_check CHECK (subject_type IN ('user', 'customer_role', 'permission_settings', 'saved_notice'));
            SQL);
    }
};
