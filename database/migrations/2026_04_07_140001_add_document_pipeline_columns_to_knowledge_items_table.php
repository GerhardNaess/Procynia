<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('customer_id');
            $table->string('storage_path')->nullable()->after('original_filename');
            $table->string('mime_type', 120)->nullable()->after('storage_path');
            $table->unsignedBigInteger('file_size_bytes')->nullable()->after('mime_type');
            $table->string('document_type', 32)->default('other')->after('file_size_bytes');
            $table->longText('extracted_text')->nullable()->after('document_type');
            $table->string('extraction_status', 32)->default('completed')->after('extracted_text');
            $table->text('extraction_error')->nullable()->after('extraction_status');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('extraction_error');

            $table->index('document_type');
            $table->index('extraction_status');
            $table->index('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropIndex(['document_type']);
            $table->dropIndex(['extraction_status']);
            $table->dropIndex(['storage_path']);
            $table->dropConstrainedForeignId('uploaded_by_user_id');
            $table->dropColumn([
                'original_filename',
                'storage_path',
                'mime_type',
                'file_size_bytes',
                'document_type',
                'extracted_text',
                'extraction_status',
                'extraction_error',
            ]);
        });
    }
};
