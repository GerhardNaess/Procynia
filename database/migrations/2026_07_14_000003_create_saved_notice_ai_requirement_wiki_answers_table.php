<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_requirement_id')
                ->constrained('saved_notice_ai_requirements')
                ->cascadeOnDelete();
            $table->string('coverage_status');
            $table->longText('answer_text')->nullable();
            $table->text('missing_summary')->nullable();
            $table->json('sources')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestamps();

            // Explicit short name — the default auto-generated name collides (after Postgres's
            // 63-char identifier truncation) with the index constrained() already added above.
            $table->unique('saved_notice_ai_requirement_id', 'swa_wiki_answers_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_requirement_wiki_answers');
    }
};
