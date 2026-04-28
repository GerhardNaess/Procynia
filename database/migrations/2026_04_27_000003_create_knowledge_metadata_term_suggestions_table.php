<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_metadata_term_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_chunk_id')->constrained('knowledge_item_chunks')->cascadeOnDelete();
            $table->string('suggested_term', 191);
            $table->string('suggested_type', 64);
            $table->string('suggested_canonical_parent', 191)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'suggested_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_metadata_term_suggestions');
    }
};
