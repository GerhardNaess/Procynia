<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_answer_basis_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('answer_basis_type', 32);
            $table->string('title', 255);
            $table->string('original_filename', 255)->nullable();
            $table->longText('body_text');
            $table->text('stored_path')->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->timestamps();

            $table->index(['saved_notice_id', 'created_at'], 'saved_notice_ai_answer_basis_items_saved_notice_created_index');
            $table->index(['saved_notice_id', 'answer_basis_type'], 'saved_notice_ai_answer_basis_items_saved_notice_type_index');
        });

        Schema::create('saved_notice_ai_requirement_answer_basis_selections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('saved_notice_ai_requirement_id');
            $table->unsignedBigInteger('saved_notice_ai_answer_basis_item_id');
            $table->timestamps();

            $table->foreign('saved_notice_ai_requirement_id', 'fk_sna_req_answer_basis_requirement')
                ->references('id')
                ->on('saved_notice_ai_requirements')
                ->cascadeOnDelete();
            $table->foreign('saved_notice_ai_answer_basis_item_id', 'fk_sna_req_answer_basis_item')
                ->references('id')
                ->on('saved_notice_ai_answer_basis_items')
                ->cascadeOnDelete();
            $table->unique([
                'saved_notice_ai_requirement_id',
                'saved_notice_ai_answer_basis_item_id',
            ], 'saved_notice_ai_requirement_answer_basis_selection_unique');
            $table->index(['saved_notice_ai_requirement_id', 'created_at'], 'saved_notice_ai_requirement_answer_basis_selection_requirement_created_index');
            $table->index(['saved_notice_ai_answer_basis_item_id', 'created_at'], 'saved_notice_ai_requirement_answer_basis_selection_item_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_requirement_answer_basis_selections');
        Schema::dropIfExists('saved_notice_ai_answer_basis_items');
    }
};
