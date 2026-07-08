<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_id')
                ->constrained('enterprise_wiki_pages')
                ->cascadeOnDelete();
            $table->string('action')->default('created');
            $table->timestamps();

            $table->unique(['enterprise_wiki_ingest_run_id', 'enterprise_wiki_page_id'], 'ewirp_run_page_unique');
            $table->index('enterprise_wiki_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_ingest_run_pages');
    }
};
