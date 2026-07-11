<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->foreignId('generated_page_version_id')
                ->nullable()
                ->after('action')
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();
            $table->string('generation_status')->default('pending')->after('generated_page_version_id');
            $table->timestamp('generation_started_at')->nullable()->after('generation_status');
            $table->timestamp('generation_completed_at')->nullable()->after('generation_started_at');
            $table->text('generation_error')->nullable()->after('generation_completed_at');

            $table->index(['enterprise_wiki_ingest_run_id', 'generation_status'], 'ewirp_run_generation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->dropIndex('ewirp_run_generation_status_idx');
            $table->dropConstrainedForeignId('generated_page_version_id');
            $table->dropColumn(['generation_status', 'generation_started_at', 'generation_completed_at', 'generation_error']);
        });
    }
};
