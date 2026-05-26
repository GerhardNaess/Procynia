<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_no_go_assessment_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('go_no_go_assessment_templates')
                ->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('short_description')->nullable();
            $table->text('help_what_is_assessed')->nullable();
            $table->text('help_why_it_matters')->nullable();
            $table->text('help_what_to_investigate')->nullable();
            $table->text('help_positive_indicators')->nullable();
            $table->text('help_warning_signs')->nullable();
            $table->text('help_example_assessment')->nullable();
            $table->unsignedTinyInteger('weight')->default(2); // 1–5
            $table->boolean('is_score_reversed')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_no_go_assessment_criteria');
    }
};
