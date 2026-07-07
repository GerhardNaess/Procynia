<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_hash_sha256', 64);
            $table->longText('extracted_text')->nullable();
            $table->string('document_status')->default('pending');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('document_status');
            $table->index(['customer_id', 'file_hash_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_documents');
    }
};
