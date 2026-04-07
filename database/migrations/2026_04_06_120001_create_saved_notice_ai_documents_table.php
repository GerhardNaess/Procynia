<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->text('stored_path');
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('processing_status', 32)->default('uploaded');
            $table->timestamps();

            $table->index(['saved_notice_id', 'created_at']);
            $table->index(['saved_notice_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_documents');
    }
};
