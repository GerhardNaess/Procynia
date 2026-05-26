<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_no_go_assessment_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')
                ->constrained('go_no_go_assessments')
                ->cascadeOnDelete();
            $table->foreignId('criterion_id')
                ->constrained('go_no_go_assessment_criteria')
                ->cascadeOnDelete();
            $table->string('selected_value', 10); // 'lav', 'middels', 'hoy'
            $table->unsignedSmallInteger('score');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'criterion_id'], 'go_no_go_answers_assessment_criterion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_no_go_assessment_answers');
    }
};
