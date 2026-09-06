<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give "Send til gjennomgang" a real recipient.
 *
 * Until now it only moved a status field: nobody was named as submitter, nobody as reviewer, and the
 * same person could submit and approve. These three columns record who handed the work over, when,
 * and who is expected to act on it.
 *
 * They live on the VERSION, not the page, because that is what is under review. A page outlives many
 * versions, and an assignment that survived a regeneration would point at a review of content that
 * no longer exists. A separate assignment table would only earn its keep once one version can carry
 * several review rounds — reassignment is not built yet, so the smaller model is still true.
 *
 * All nullable: every version that exists today was never submitted through this flow, and there is
 * no honest way to infer a reviewer for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->foreignId('submitted_by_user_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by_user_id');
            $table->foreignId('reviewer_user_id')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();

            // "What is waiting for me to review?" is the query this exists to answer.
            $table->index(['reviewer_user_id', 'submitted_at'], 'ewpv_reviewer_submitted_index');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropIndex('ewpv_reviewer_submitted_index');
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropColumn('submitted_at');
            $table->dropConstrainedForeignId('submitted_by_user_id');
        });
    }
};
