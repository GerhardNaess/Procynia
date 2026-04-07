<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_item_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->timestamps();

            $table->unique(['knowledge_item_id', 'chunk_index']);
            $table->index('knowledge_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_item_chunks');
    }
};
