<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_metadata_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('canonical_name', 191);
            $table->json('synonyms')->nullable();
            $table->text('description')->nullable();
            $table->boolean('approved')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'type', 'canonical_name']);
            $table->index(['customer_id', 'type', 'approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_metadata_terms');
    }
};
