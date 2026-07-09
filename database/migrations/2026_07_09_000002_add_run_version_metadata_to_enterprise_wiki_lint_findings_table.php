<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_lint_findings', function (Blueprint $table): void {
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->nullOnDelete();

            $table->foreignId('enterprise_wiki_page_version_id')
                ->nullable()
                ->after('enterprise_wiki_page_id')
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();

            $table->json('metadata')
                ->nullable()
                ->after('message');

            $table->index('enterprise_wiki_ingest_run_id', 'ewlf_ingest_run_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_lint_findings', function (Blueprint $table): void {
            $table->dropForeign(['enterprise_wiki_ingest_run_id']);
            $table->dropForeign(['enterprise_wiki_page_version_id']);
            $table->dropIndex('ewlf_ingest_run_idx');
            $table->dropColumn([
                'enterprise_wiki_ingest_run_id',
                'enterprise_wiki_page_version_id',
                'metadata',
            ]);
        });
    }
};
