<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give quality assurance a recipient.
 *
 * The QA capability has existed for a while: a user with it can approve and reject claims on any
 * page they can open. What has never existed is a way to say WHO SHOULD — so a QA user was someone
 * who could help if they happened to look, and nothing ever told them to. These columns are that
 * missing sentence, and the notification it makes possible.
 *
 * They mirror the review assignment three rows above them, and for the same reason: QA is work on
 * the knowledge in a specific version. A page outlives many versions, and an assignment that
 * survived a regeneration would say v2 had been checked when only v1 was. Version scope is what
 * makes "already quality assured" impossible to claim by accident.
 *
 * Deliberately NOT a qa_status column. Whether the work is done is already written in the claims —
 * pending, approved, rejected — and a second status field would be a copy of that truth, free to
 * drift from it. Progress is derived.
 *
 * All nullable: QA is support, not a gate. A version nobody was asked to check is an ordinary
 * version, and every version that exists today is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->foreignId('qa_user_id')->nullable()->after('reviewer_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('qa_assigned_at')->nullable()->after('qa_user_id');
            $table->foreignId('qa_assigned_by_user_id')->nullable()->after('qa_assigned_at')
                ->constrained('users')->nullOnDelete();

            // "What has someone asked me to quality assure?" — the query these exist to answer.
            $table->index(['qa_user_id', 'qa_assigned_at'], 'ewpv_qa_assigned_index');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropIndex('ewpv_qa_assigned_index');
            $table->dropConstrainedForeignId('qa_assigned_by_user_id');
            $table->dropColumn('qa_assigned_at');
            $table->dropConstrainedForeignId('qa_user_id');
        });
    }
};
