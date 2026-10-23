<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud, case level — phase 0 step 18 (docs/external-api-phase-0-plan.md §2l).
 *
 * - customer_access_changes may now name a case (subject_type saved_notice): granting and revoking
 *   per-case access and changing the commercial owner or Bid Manager change who can open and work on
 *   a case, and were written without the person who did it. The same trail as F4.
 * - saved_notices.archived_by_user_id: who moved the case to history. Null for cases archived before
 *   this — that was never recorded and is not filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE customer_access_changes DROP CONSTRAINT customer_access_changes_subject_type_check;
                ALTER TABLE customer_access_changes
                    ADD CONSTRAINT customer_access_changes_subject_type_check CHECK (subject_type IN ('user', 'customer_role', 'permission_settings', 'saved_notice'));
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Rows about cases cannot be removed (the trail is append-only), so the wider check stays
            // while any exist.
            if (! DB::table('customer_access_changes')->where('subject_type', 'saved_notice')->exists()) {
                DB::unprepared(<<<'SQL'
                    ALTER TABLE customer_access_changes DROP CONSTRAINT customer_access_changes_subject_type_check;
                    ALTER TABLE customer_access_changes
                        ADD CONSTRAINT customer_access_changes_subject_type_check CHECK (subject_type IN ('user', 'customer_role', 'permission_settings'));
                    SQL);
            }
        }

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by_user_id');
        });
    }
};
