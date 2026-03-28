<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('source_url');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['notice_id', 'source_url']);
            $table->index(['notice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_documents');
    }
};
