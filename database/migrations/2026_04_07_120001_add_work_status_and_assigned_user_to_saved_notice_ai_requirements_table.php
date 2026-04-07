<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->string('work_status', 32)
                ->default('not_started')
                ->after('review_status');
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('work_status')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['saved_notice_id', 'work_status'], 'saved_notice_ai_requirements_work_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex('saved_notice_ai_requirements_work_status_index');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn('work_status');
        });
    }
};
