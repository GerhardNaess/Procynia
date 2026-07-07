<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_ingest_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->cascadeOnDelete();
            $table->unsignedInteger('section_index');
            $table->string('heading')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['enterprise_wiki_ingest_run_id', 'section_index'], 'ewis_run_section_unique');
            $table->index(['enterprise_wiki_ingest_run_id', 'status'], 'ewis_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_ingest_sections');
    }
};
