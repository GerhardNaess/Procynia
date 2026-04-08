<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purpose: Create the persisted requirement-to-knowledge evidence table.
     * Inputs: None.
     * Returns: None.
     * Side effects: Creates the saved_notice_ai_evidence table.
     */
    public function up(): void
    {
        Schema::create('saved_notice_ai_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_requirement_id')->constrained('saved_notice_ai_requirements')->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('knowledge_item_chunk_id')->constrained('knowledge_item_chunks')->cascadeOnDelete();
            $table->string('match_type', 32)->default('auto_match');
            $table->unsignedInteger('match_score')->default(0);
            $table->unsignedInteger('match_rank')->default(0);
            $table->string('selection_status', 32)->default('suggested');
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('saved_notice_ai_requirement_id');
            $table->index('knowledge_item_id');
            $table->index('knowledge_item_chunk_id');
            $table->index(['saved_notice_ai_requirement_id', 'selection_status'], 'saved_notice_ai_evidence_requirement_status_index');
            $table->index(['saved_notice_ai_requirement_id', 'match_rank'], 'saved_notice_ai_evidence_requirement_rank_index');
            $table->unique(['saved_notice_ai_requirement_id', 'knowledge_item_chunk_id'], 'saved_notice_ai_evidence_requirement_chunk_unique');
        });
    }

    /**
     * Purpose: Drop the persisted requirement-to-knowledge evidence table.
     * Inputs: None.
     * Returns: None.
     * Side effects: Drops the saved_notice_ai_evidence table.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_evidence');
    }
};
