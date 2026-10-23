<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud: who closed an aksjon — docs/external-api-phase-0-plan.md §2m.
 *
 * Closing an aksjon («Lukk») recorded when and why, never who. The same fact as
 * saved_notices.archived_by_user_id (step 18). Aksjoner closed before this have no recorded closer,
 * and none is filled in: created_by_user_id and owner_user_id are not the person who closed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by_user_id');
        });
    }
};
