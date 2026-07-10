<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->string('qa_status', 32)
                ->nullable()
                ->after('maintainer_decision_status');

            $table->timestamp('qa_started_at')
                ->nullable()
                ->after('qa_status');

            $table->timestamp('qa_completed_at')
                ->nullable()
                ->after('qa_started_at');

            $table->unsignedSmallInteger('qa_attempt_count')
                ->default(0)
                ->after('qa_completed_at');

            $table->text('qa_last_error')
                ->nullable()
                ->after('qa_attempt_count');

            $table->json('qa_result')
                ->nullable()
                ->after('qa_last_error');

            $table->index('qa_status', 'ewir_qa_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropIndex('ewir_qa_status_idx');
            $table->dropColumn([
                'qa_status',
                'qa_started_at',
                'qa_completed_at',
                'qa_attempt_count',
                'qa_last_error',
                'qa_result',
            ]);
        });
    }
};
