<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->string('source_type', 32)
                ->default('ai_candidate')
                ->after('saved_notice_id');
            $table->string('approval_status', 32)
                ->default('draft')
                ->after('review_status');
            $table->string('requirement_identifier', 255)
                ->nullable()
                ->after('extraction_method');
            $table->string('original_requirement_identifier', 255)
                ->nullable()
                ->after('requirement_identifier');
            $table->longText('original_requirement_text')
                ->nullable()
                ->after('requirement_text');
            $table->json('original_candidate_snapshot')
                ->nullable()
                ->after('original_requirement_text');
            $table->json('current_requirement_snapshot')
                ->nullable()
                ->after('original_candidate_snapshot');
            $table->timestampTz('approved_at')
                ->nullable()
                ->after('current_requirement_snapshot');
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('rejected_at')
                ->nullable()
                ->after('approved_by_user_id');
            $table->foreignId('rejected_by_user_id')
                ->nullable()
                ->after('rejected_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['saved_notice_id', 'approval_status'], 'saved_notice_ai_requirements_approval_status_index');
            $table->index(['saved_notice_id', 'source_type'], 'saved_notice_ai_requirements_source_type_index');
        });

        DB::statement('ALTER TABLE saved_notice_ai_requirements ALTER COLUMN saved_notice_ai_document_id DROP NOT NULL');
        DB::statement('ALTER TABLE saved_notice_ai_requirements ALTER COLUMN saved_notice_ai_document_chunk_id DROP NOT NULL');

        DB::table('saved_notice_ai_requirements')
            ->orderBy('id')
            ->chunkById(500, function ($requirements): void {
                foreach ($requirements as $requirement) {
                    $approvalStatus = $this->approvalStatusForReviewStatus((string) $requirement->review_status);
                    $snapshot = $this->buildSnapshot($requirement, $approvalStatus);

                    DB::table('saved_notice_ai_requirements')
                        ->where('id', $requirement->id)
                        ->update([
                            'source_type' => 'ai_candidate',
                            'approval_status' => $approvalStatus,
                            'requirement_identifier' => null,
                            'original_requirement_identifier' => null,
                            'original_requirement_text' => $requirement->requirement_text,
                            'original_candidate_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'current_requirement_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'approved_at' => $approvalStatus === 'approved' ? $requirement->updated_at : null,
                            'approved_by_user_id' => null,
                            'rejected_at' => $approvalStatus === 'rejected' ? $requirement->updated_at : null,
                            'rejected_by_user_id' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE saved_notice_ai_requirements ALTER COLUMN saved_notice_ai_document_id SET NOT NULL');
        DB::statement('ALTER TABLE saved_notice_ai_requirements ALTER COLUMN saved_notice_ai_document_chunk_id SET NOT NULL');

        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex('saved_notice_ai_requirements_approval_status_index');
            $table->dropIndex('saved_notice_ai_requirements_source_type_index');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn([
                'source_type',
                'approval_status',
                'requirement_identifier',
                'original_requirement_identifier',
                'original_requirement_text',
                'original_candidate_snapshot',
                'current_requirement_snapshot',
                'approved_at',
                'approved_by_user_id',
                'rejected_at',
                'rejected_by_user_id',
            ]);
        });
    }

    private function approvalStatusForReviewStatus(string $reviewStatus): string
    {
        return match ($reviewStatus) {
            'confirmed' => 'approved',
            'rejected' => 'rejected',
            default => 'draft',
        };
    }

    private function buildSnapshot(object $requirement, string $approvalStatus): array
    {
        return [
            'id' => (int) $requirement->id,
            'saved_notice_id' => (int) $requirement->saved_notice_id,
            'saved_notice_ai_document_id' => $requirement->saved_notice_ai_document_id !== null
                ? (int) $requirement->saved_notice_ai_document_id
                : null,
            'saved_notice_ai_document_chunk_id' => $requirement->saved_notice_ai_document_chunk_id !== null
                ? (int) $requirement->saved_notice_ai_document_chunk_id
                : null,
            'source_type' => 'ai_candidate',
            'approval_status' => $approvalStatus,
            'review_status' => (string) $requirement->review_status,
            'work_status' => (string) $requirement->work_status,
            'assigned_user_id' => $requirement->assigned_user_id !== null
                ? (int) $requirement->assigned_user_id
                : null,
            'requirement_identifier' => null,
            'original_requirement_identifier' => null,
            'requirement_text' => (string) $requirement->requirement_text,
            'original_requirement_text' => (string) $requirement->requirement_text,
            'requirement_type' => (string) $requirement->requirement_type,
            'extraction_method' => (string) $requirement->extraction_method,
            'approved_at' => $approvalStatus === 'approved' ? optional($requirement->updated_at)?->toIso8601String() : null,
            'approved_by_user_id' => null,
            'rejected_at' => $approvalStatus === 'rejected' ? optional($requirement->updated_at)?->toIso8601String() : null,
            'rejected_by_user_id' => null,
        ];
    }
};
