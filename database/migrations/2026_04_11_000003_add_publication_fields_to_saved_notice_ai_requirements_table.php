<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->foreignId('extraction_run_id')
                ->nullable()
                ->after('saved_notice_ai_document_chunk_id')
                ->constrained('requirement_extraction_runs')
                ->nullOnDelete();
            $table->string('publication_status', 32)
                ->nullable()
                ->after('extraction_run_id');
            $table->timestampTz('published_at')
                ->nullable()
                ->after('publication_status');
            $table->timestampTz('superseded_at')
                ->nullable()
                ->after('published_at');

            $table->index(['saved_notice_id', 'publication_status'], 'saved_notice_ai_requirements_notice_publication_index');
            $table->index(['saved_notice_ai_document_id', 'publication_status'], 'saved_notice_ai_requirements_document_publication_index');
            $table->index('extraction_run_id', 'saved_notice_ai_requirements_extraction_run_index');
        });

        DB::table('saved_notice_ai_requirements')
            ->orderBy('id')
            ->chunkById(500, function ($requirements): void {
                foreach ($requirements as $requirement) {
                    DB::table('saved_notice_ai_requirements')
                        ->where('id', $requirement->id)
                        ->update([
                            'extraction_run_id' => null,
                            'publication_status' => 'published',
                            'published_at' => optional($requirement->created_at)?->toDateTimeString() ?? now()->toDateTimeString(),
                            'superseded_at' => null,
                        ]);
                }
            });

        DB::statement('ALTER TABLE saved_notice_ai_requirements ALTER COLUMN publication_status SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex('saved_notice_ai_requirements_notice_publication_index');
            $table->dropIndex('saved_notice_ai_requirements_document_publication_index');
            $table->dropIndex('saved_notice_ai_requirements_extraction_run_index');
            $table->dropConstrainedForeignId('extraction_run_id');
            $table->dropColumn([
                'publication_status',
                'published_at',
                'superseded_at',
            ]);
        });
    }
};
