<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_page_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->nullable()
                ->constrained('enterprise_wiki_ingest_runs')
                ->nullOnDelete();
            $table->foreignId('from_page_id')
                ->constrained('enterprise_wiki_pages')
                ->cascadeOnDelete();
            $table->foreignId('to_page_id')
                ->constrained('enterprise_wiki_pages')
                ->cascadeOnDelete();
            $table->foreignId('from_page_version_id')
                ->nullable()
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();
            $table->foreignId('to_page_version_id')
                ->nullable()
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();
            $table->string('link_type');
            $table->string('source');
            $table->string('confidence')->default('certain');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['customer_id', 'from_page_id', 'to_page_id', 'link_type'],
                'ewpl_from_to_type_unique'
            );
            $table->index(['customer_id', 'from_page_id'], 'ewpl_customer_from_idx');
            $table->index(['customer_id', 'to_page_id'], 'ewpl_customer_to_idx');
            $table->index(['customer_id', 'link_type'], 'ewpl_customer_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_page_links');
    }
};
