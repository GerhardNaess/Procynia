<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_requirement_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_requirement_id')
                ->constrained('saved_notice_ai_requirements')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_id')
                ->constrained('saved_notices')
                ->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('change_type', 32);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('changed_fields')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['saved_notice_id', 'saved_notice_ai_requirement_id'], 'saved_notice_ai_requirement_revisions_case_requirement_index');
            $table->index(['saved_notice_ai_requirement_id', 'change_type'], 'saved_notice_ai_requirement_revisions_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_requirement_revisions');
    }
};
