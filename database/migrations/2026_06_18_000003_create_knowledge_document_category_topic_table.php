<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_category_topic', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_document_category_id')
                ->constrained('knowledge_document_categories')
                ->cascadeOnDelete();
            $table->foreignId('knowledge_document_topic_id')
                ->constrained('knowledge_document_topics')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['knowledge_document_category_id', 'knowledge_document_topic_id'],
                'knowledge_document_category_topic_unique',
            );
            $table->index('knowledge_document_category_id', 'knowledge_document_category_topic_category_index');
            $table->index('knowledge_document_topic_id', 'knowledge_document_category_topic_topic_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_category_topic');
    }
};
