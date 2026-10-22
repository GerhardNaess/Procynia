<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page owner withdrawing a page from review is a review event too — docs/external-api-phase-0-plan.md
 * §4.9 (B5).
 *
 * Withdrawing ends the handover: the version's submitted_by / submitted_at / reviewer_user_id are
 * cleared, exactly as when a returned page is reopened. Who the version had been handed to must not
 * be lost with them, so the event records it. A withdrawal needs no reason — the reviewer is not
 * being asked to act on one — so `reason` becomes optional; a return still requires one, which is
 * WikiController's validation and not this column's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_review_events', function (Blueprint $table): void {
            $table->foreignId('assigned_reviewer_user_id')
                ->nullable()
                ->after('actor_role')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('enterprise_wiki_page_review_events')->whereNull('reason')->update(['reason' => '']);

        Schema::table('enterprise_wiki_page_review_events', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
            $table->dropConstrainedForeignId('assigned_reviewer_user_id');
        });
    }
};
