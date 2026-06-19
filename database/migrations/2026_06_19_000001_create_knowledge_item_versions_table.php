<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_item_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_item_id')
                ->constrained('knowledge_items')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->boolean('is_current')->default(false);
            $table->string('original_filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->string('extraction_status')->nullable();
            $table->text('extraction_error')->nullable();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_item_id', 'version_no'], 'kiv_item_version_unique');
            $table->index(['knowledge_item_id', 'is_current'], 'kiv_item_current_index');
            $table->index('customer_id', 'kiv_customer_index');
            $table->index('storage_path', 'kiv_storage_path_index');
            $table->index('extraction_status', 'kiv_extraction_status_index');
        });

        // Backfill one version row per existing KnowledgeItem that has a storage_path.
        DB::statement("
            INSERT INTO knowledge_item_versions (
                knowledge_item_id,
                customer_id,
                version_no,
                is_current,
                original_filename,
                storage_path,
                mime_type,
                file_size_bytes,
                extracted_text,
                extraction_status,
                extraction_error,
                uploaded_by_user_id,
                uploaded_at,
                created_at,
                updated_at
            )
            SELECT
                ki.id,
                ki.customer_id,
                1,
                true,
                ki.original_filename,
                ki.storage_path,
                ki.mime_type,
                ki.file_size_bytes,
                ki.extracted_text,
                ki.extraction_status,
                ki.extraction_error,
                ki.uploaded_by_user_id,
                COALESCE(ki.created_at, ki.updated_at),
                NOW(),
                NOW()
            FROM knowledge_items ki
            WHERE ki.storage_path IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM knowledge_item_versions kiv
                  WHERE kiv.knowledge_item_id = ki.id
                    AND kiv.version_no = 1
              )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_item_versions');
    }
};
