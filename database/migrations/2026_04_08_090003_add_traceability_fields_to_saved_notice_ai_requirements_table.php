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
            $table->json('source_reference')
                ->nullable()
                ->after('current_requirement_snapshot');
            $table->json('extraction_metadata')
                ->nullable()
                ->after('source_reference');

            $table->index(['saved_notice_id', 'saved_notice_ai_document_id'], 'saved_notice_ai_requirements_document_trace_index');
        });

        DB::table('saved_notice_ai_requirements')
            ->orderBy('id')
            ->chunkById(500, function ($requirements): void {
                foreach ($requirements as $requirement) {
                    $originalCandidateSnapshot = json_decode((string) ($requirement->original_candidate_snapshot ?? ''), true);
                    $currentRequirementSnapshot = json_decode((string) ($requirement->current_requirement_snapshot ?? ''), true);
                    $sourceReference = null;

                    if (is_array($currentRequirementSnapshot) && isset($currentRequirementSnapshot['source_reference']) && is_array($currentRequirementSnapshot['source_reference'])) {
                        $sourceReference = $currentRequirementSnapshot['source_reference'];
                    } elseif (is_array($originalCandidateSnapshot) && isset($originalCandidateSnapshot['source_reference']) && is_array($originalCandidateSnapshot['source_reference'])) {
                        $sourceReference = $originalCandidateSnapshot['source_reference'];
                    }

                    DB::table('saved_notice_ai_requirements')
                        ->where('id', $requirement->id)
                        ->update([
                            'source_reference' => $sourceReference !== null
                                ? json_encode($sourceReference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : null,
                            'extraction_metadata' => json_encode([
                                'extraction_method' => $requirement->extraction_method,
                                'source_type' => $requirement->source_type,
                                'backfilled_at' => now()->toIso8601String(),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex('saved_notice_ai_requirements_document_trace_index');
            $table->dropColumn([
                'source_reference',
                'extraction_metadata',
            ]);
        });
    }
};
