<?php

use App\Models\SavedNoticeAiRequirementAssessment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saved_notice_ai_requirement_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_requirement_id');
            $table->foreign('saved_notice_ai_requirement_id', 'sar_assessment_requirement_fk')
                ->references('id')
                ->on('saved_notice_ai_requirements')
                ->cascadeOnDelete();
            $table->string('assessment_status');
            $table->string('coverage_status')->nullable();
            $table->string('risk_level')->nullable();
            $table->text('requirement_summary')->nullable();
            $table->text('coverage_rationale')->nullable();
            $table->text('missing_information')->nullable();
            $table->text('recommended_next_step')->nullable();
            $table->json('source_evidence_snapshot')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('assessed_by_user_id')->nullable();
            $table->foreign('assessed_by_user_id', 'sar_assessment_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('saved_notice_ai_requirement_id', 'sar_assessment_requirement_unique');
            $table->index('assessment_status', 'sar_assessment_status_index');
            $table->index('coverage_status', 'sar_assessment_coverage_status_index');
            $table->index('risk_level', 'sar_assessment_risk_level_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_requirement_assessments');
    }
};
