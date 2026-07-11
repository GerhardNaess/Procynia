<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_page_relink_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->cascadeOnDelete();
            $table->foreignId('trigger_page_id')
                ->constrained('enterprise_wiki_pages')
                ->cascadeOnDelete();
            $table->foreignId('candidate_page_id')
                ->constrained('enterprise_wiki_pages')
                ->cascadeOnDelete();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->foreignId('created_page_version_id')
                ->nullable()
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['enterprise_wiki_ingest_run_id', 'trigger_page_id', 'candidate_page_id'],
                'ewpra_run_trigger_candidate_unique',
            );
            $table->index('candidate_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_page_relink_attempts');
    }
};
