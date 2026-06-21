<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_versions', function (Blueprint $table): void {
            $table->string('approval_status')->default('approved')->after('file_hash_sha256');
            $table->timestamp('submitted_for_review_at')->nullable()->after('approval_status');
            $table->foreignId('submitted_for_review_by_user_id')->nullable()->after('submitted_for_review_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('submitted_for_review_by_user_id');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('rejected_by_user_id')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('rejected_by_user_id');

            $table->index('approval_status', 'kiv_approval_status_index');
            $table->index(['knowledge_item_id', 'approval_status'], 'kiv_ki_approval_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_versions', function (Blueprint $table): void {
            $table->dropIndex('kiv_ki_approval_status_index');
            $table->dropIndex('kiv_approval_status_index');

            $table->dropConstrainedForeignId('submitted_for_review_by_user_id');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');

            $table->dropColumn([
                'approval_status',
                'submitted_for_review_at',
                'approved_at',
                'approved_by_user_id',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
