<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_page_version_document_owner_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained(indexName: 'ewpvoa_customer_fk')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_id')->constrained('enterprise_wiki_pages', indexName: 'ewpvoa_page_fk')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_version_id')->constrained('enterprise_wiki_page_versions', indexName: 'ewpvoa_page_version_fk')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_ingest_run_id')->nullable()->constrained('enterprise_wiki_ingest_runs', indexName: 'ewpvoa_run_fk')->nullOnDelete();
            $table->foreignId('document_owner_user_id')->nullable()->constrained('users', indexName: 'ewpvoa_document_owner_fk')->nullOnDelete();
            $table->json('source_document_ids');
            $table->string('source_documents_hash', 64);
            $table->string('approval_status', 32)->default('pending');
            $table->text('approval_comment')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users', indexName: 'ewpvoa_decided_by_fk')->nullOnDelete();
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by_user_id')->nullable()->constrained('users', indexName: 'ewpvoa_overridden_by_fk')->nullOnDelete();
            $table->timestampTz('overridden_at')->nullable();
            $table->timestamps();

            $table->unique([
                'enterprise_wiki_page_version_id',
                'document_owner_user_id',
                'source_documents_hash',
            ], 'ewpvoa_version_owner_hash_unique');
            $table->index([
                'enterprise_wiki_page_version_id',
                'approval_status',
            ], 'ewpvoa_version_status_index');
            $table->index([
                'enterprise_wiki_page_id',
                'approval_status',
            ], 'ewpvoa_page_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_page_version_document_owner_approvals');
    }
};
