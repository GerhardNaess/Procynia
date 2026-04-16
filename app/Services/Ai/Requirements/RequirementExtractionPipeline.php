<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Data\Ai\Requirements\RequirementExtractionResultData;
use App\Data\Ai\Requirements\RequirementSegmentExtractionResultData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RequirementExtractionPipeline
{
    public function __construct(
        private readonly RequirementCandidateExtractor $candidateExtractor,
        private readonly DocumentRequirementSegmentBuilder $segmentBuilder,
        private readonly RequirementEditorService $requirementEditorService,
    ) {
    }

    /**
     * Purpose: Rebuild AI requirement candidates for one persisted AI document using a single full-document AI call.
     * Inputs: The source AI document and the actor who triggered the extraction.
     * Returns: A document-level extraction result with explicit full-document provenance metadata.
     * Side effects: Calls OpenAI, deletes stale document-scoped requirement rows, and persists new candidates.
     */
    public function syncDocumentRequirements(SavedNoticeAiDocument $document, ?User $changedBy = null, ?string $runId = null): RequirementExtractionResultData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);

        $splitPlanner = app(DocumentSplitPlanner::class);

        $splitResult = $splitPlanner->plan($document, $runId);

        Log::info('[TT][SPLIT] Split executed.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'ok' => $splitResult['ok'] ?? null,
            'split_plan_count' => count($splitResult['split_plan'] ?? []),
        ]);
        $document->requirements()->delete();

        $documentTitle = (string) $document->original_filename;
        $documentFilename = $documentTitle;
        $documentText = trim((string) $document->extracted_text);
        $documentTextLength = mb_strlen($documentText, 'UTF-8');

        Log::info('[PROCYNIA][REQ_PIPELINE] Document received for full-document extraction.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'document_text_length' => $documentTextLength,
            'full_document_mode' => true,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Extraction run started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'document_text_length' => $documentTextLength,
            'full_document_mode' => true,
            'candidate_count' => null,
        ]);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][START] Full-document extraction run started.', $this->documentAiCostContext(
            $document,
            $runId,
            [
                'openai_call_count' => 0,
                'built_segment_count' => 0,
                'segments_sent_to_extraction_count' => 0,
                'extracted_candidate_count' => 0,
                'extraction_model' => $this->extractionModel(),
                'fallback_used' => false,
                'full_document_mode' => true,
                'document_text_length' => $documentTextLength,
            ],
        ));

        if ($documentTextLength === 0) {
            Log::info('[PROCYNIA][REQ_PIPELINE] Document extraction ended with no extracted text available.', [
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $documentTitle,
                'document_filename' => $documentFilename,
                'full_document_mode' => true,
                'document_text_length' => 0,
                'raw_requirement_count_total' => 0,
                'normalized_requirement_count_total' => 0,
                'deduped_requirement_count_total' => 0,
                'validated_requirement_count_total' => 0,
                'persisted_requirement_count_total' => 0,
                'zero_result' => true,
            ]);

            Log::info('[PROCYNIA][AI_HANG] Extraction run finished.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'document_title' => $documentTitle,
                'document_filename' => $documentFilename,
                'document_text_length' => 0,
                'candidate_count' => 0,
                'raw_requirement_count_total' => 0,
                'normalized_requirement_count_total' => 0,
                'deduped_requirement_count_total' => 0,
                'validated_requirement_count_total' => 0,
                'persisted_requirement_count_total' => 0,
                'full_document_mode' => true,
            ]);

            Log::info('[PROCYNIA][AI_COST][EXTRACTION][END] Full-document extraction run finished.', $this->documentAiCostContext(
                $document,
                $runId,
                [
                    'openai_call_count' => 0,
                    'built_segment_count' => 0,
                    'segments_sent_to_extraction_count' => 0,
                    'extracted_candidate_count' => 0,
                    'extraction_call_count' => 0,
                    'model' => $this->extractionModel(),
                    'extraction_model' => $this->extractionModel(),
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'fallback_used' => false,
                    'partial' => false,
                    'failure_count' => 0,
                    'candidate_count' => 0,
                    'full_document_mode' => true,
                    'document_text_length' => 0,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => null,
                    'error_message' => null,
                ],
            ));

            return new RequirementExtractionResultData(
                ok: true,
                partial: false,
                savedNoticeId: (int) $document->saved_notice_id,
                savedNoticeAiDocumentId: $document->id,
                runId: $runId,
                documentTitle: $documentTitle,
                documentFilename: $documentFilename,
                model: $this->extractionModel(),
                relevanceModel: $this->relevanceModel(),
                extractionModel: $this->extractionModel(),
                segmentCount: 0,
                relevantSegmentCount: 0,
                relevanceCallCount: 0,
                extractionCallCount: 0,
                openAiCallCount: 0,
                segments: [],
                relevanceResults: [],
                extractionResults: [],
                candidates: [],
                metadata: [
                    'prompt_versions' => [
                        'relevance' => $this->relevancePromptVersion(),
                        'extraction' => FullDocumentRequirementExtractionPrompt::promptVersion(),
                    ],
                    'full_document_mode' => true,
                    'document_text_length' => 0,
                    'input_text_length' => 0,
                    'raw_candidate_count' => 0,
                    'mapped_candidate_count' => 0,
                    'deduped_candidate_count' => 0,
                    'relevant_segment_count' => 0,
                    'relevance_call_count' => 0,
                    'extraction_call_count' => 0,
                    'openai_call_count' => 0,
                    'input_tokens_total' => 0,
                    'output_tokens_total' => 0,
                    'total_tokens_total' => 0,
                    'candidate_count' => 0,
                    'failure_count' => 0,
                    'partial' => false,
                    'fallback_used' => false,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'parse_strategy' => 'empty',
                ],
                errorType: null,
                errorMessage: null,
            );
        }

        $extractionResult = $this->candidateExtractor->extractFullDocument($document, $runId);
        $documentCandidates = [];
        $resultCandidateCount = count($extractionResult->candidates);
        $rawRequirementCountTotal = (int) data_get($extractionResult->metadata, 'raw_candidate_count', $resultCandidateCount);
        $normalizedRequirementCountTotal = (int) data_get($extractionResult->metadata, 'mapped_candidate_count', $resultCandidateCount);
        $dedupedRequirementCountTotal = (int) data_get($extractionResult->metadata, 'deduped_candidate_count', $resultCandidateCount);
        $validatedRequirementCountTotal = 0;
        $persistedRequirementCountTotal = 0;
        $fullDocumentRejectionReasons = [];

        Log::info('[PROCYNIA][AI_HANG] Validation started.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'candidate_count' => $resultCandidateCount,
            'full_document_mode' => true,
        ]);

        foreach ($extractionResult->candidates as $candidateIndex => $candidate) {
            Log::info('[PROCYNIA][AI_HANG] Validation candidate started.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'candidate_index' => $candidateIndex,
                'candidate_count' => $resultCandidateCount,
                'validated_requirement_count_total' => $validatedRequirementCountTotal,
                'persisted_requirement_count_total' => $persistedRequirementCountTotal,
                'full_document_mode' => true,
            ]);

            if (! $candidate instanceof RequirementExtractionCandidateData || ! $candidate->isRequirement) {
                $fullDocumentRejectionReasons[] = ! $candidate instanceof RequirementExtractionCandidateData
                    ? 'invalid_candidate_type'
                    : 'is_requirement_false';

                Log::warning('[PROCYNIA][REQ_PIPELINE] Requirement candidate rejected before persistence.', [
                    'run_id' => $runId,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'candidate_index' => $candidateIndex,
                    'rejection_reason_category' => ! $candidate instanceof RequirementExtractionCandidateData
                        ? 'invalid_candidate_type'
                        : 'is_requirement_false',
                    'full_document_mode' => true,
                ]);

                Log::info('[PROCYNIA][AI_HANG] Validation candidate rejected.', [
                    'timestamp' => now()->toIso8601String(),
                    'run_id' => $runId,
                    'document_id' => $document->id,
                    'saved_notice_ai_document_id' => $document->id,
                    'saved_notice_id' => $document->saved_notice_id,
                    'candidate_index' => $candidateIndex,
                    'candidate_count' => $resultCandidateCount,
                    'rejection_reason_category' => ! $candidate instanceof RequirementExtractionCandidateData
                        ? 'invalid_candidate_type'
                        : 'is_requirement_false',
                    'validated_requirement_count_total' => $validatedRequirementCountTotal,
                    'persisted_requirement_count_total' => $persistedRequirementCountTotal,
                    'full_document_mode' => true,
                ]);

                continue;
            }

            $validatedRequirementCountTotal++;

            Log::info('[PROCYNIA][AI_HANG] Validation candidate accepted.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'candidate_index' => $candidateIndex,
                'candidate_count' => $resultCandidateCount,
                'validated_requirement_count_total' => $validatedRequirementCountTotal,
                'persisted_requirement_count_total' => $persistedRequirementCountTotal,
                'full_document_mode' => true,
            ]);

            Log::info('[PROCYNIA][AI_HANG] Persistence started.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'candidate_index' => $candidateIndex,
                'candidate_count' => $resultCandidateCount,
                'validated_requirement_count_total' => $validatedRequirementCountTotal,
                'persisted_requirement_count_total' => $persistedRequirementCountTotal,
                'full_document_mode' => true,
            ]);

            $extractionMetadata = array_merge($candidate->jsonSerialize(), [
                'run_id' => $runId,
                'document_title' => $documentTitle,
                'document_filename' => $documentFilename,
                'full_document_mode' => true,
                'full_document_extraction' => $extractionResult->toArray(),
                'extraction_prompt_version' => data_get($extractionResult->metadata, 'prompt_versions.extraction', $this->extractionPromptVersion()),
                'extraction_model' => $extractionResult->model,
                'extraction_request_id' => data_get($extractionResult->metadata, 'request_id'),
                'extraction_response_id' => data_get($extractionResult->metadata, 'response_id'),
                'extraction_elapsed_ms' => data_get($extractionResult->metadata, 'elapsed_ms'),
            ]);

            $persistenceData = $candidate->toPersistenceData(
                $document,
                null,
                $extractionMetadata,
            );

            $this->requirementEditorService->createAiCandidate(
                $document,
                null,
                $persistenceData,
                $changedBy,
            );

            $documentCandidates[] = $candidate;
            $persistedRequirementCountTotal++;

            Log::info('[PROCYNIA][AI_HANG] Persistence finished.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $runId,
                'document_id' => $document->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_id' => $document->saved_notice_id,
                'candidate_index' => $candidateIndex,
                'candidate_count' => $resultCandidateCount,
                'validated_requirement_count_total' => $validatedRequirementCountTotal,
                'persisted_requirement_count_total' => $persistedRequirementCountTotal,
                'full_document_mode' => true,
            ]);
        }

        Log::info('[PROCYNIA][AI_HANG] Validation finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'candidate_count' => $resultCandidateCount,
            'validated_requirement_count_total' => $validatedRequirementCountTotal,
            'persisted_requirement_count_total' => $persistedRequirementCountTotal,
            'full_document_mode' => true,
        ]);

        $candidateCount = count($documentCandidates);
        $openAiCallCount = (int) $extractionResult->openAiCallCount;
        $failureCount = $extractionResult->ok ? 0 : 1;
        $partial = $failureCount > 0;
        $ok = $failureCount === 0;
        $elapsedMs = $this->elapsedMs($startedAt);
        $errorType = $extractionResult->errorType;
        $errorMessage = $extractionResult->errorMessage;

        Log::info('[PROCYNIA][REQ_PIPELINE] Full-document extraction run summary.', [
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'full_document_mode' => true,
            'document_text_length' => $documentTextLength,
            'segment_count' => 0,
            'segments_passed_to_relevance_stage_count' => 0,
            'relevance_stage_status' => 'bypassed',
            'segments_passed_to_extraction_stage_count' => 0,
            'raw_requirement_count_total' => $rawRequirementCountTotal,
            'normalized_requirement_count_total' => $normalizedRequirementCountTotal,
            'deduped_requirement_count_total' => $dedupedRequirementCountTotal,
            'validated_requirement_count_total' => $validatedRequirementCountTotal,
            'persisted_requirement_count_total' => $persistedRequirementCountTotal,
            'rejection_reason_categories' => array_values(array_unique($fullDocumentRejectionReasons)),
            'candidate_count' => $candidateCount,
            'failure_count' => $failureCount,
            'zero_result' => $persistedRequirementCountTotal === 0,
            'partial' => $partial,
            'elapsed_ms' => $elapsedMs,
        ]);

        Log::info('[PROCYNIA][AI_HANG] Extraction run finished.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $documentTitle,
            'document_filename' => $documentFilename,
            'document_text_length' => $documentTextLength,
            'candidate_count' => $candidateCount,
            'raw_requirement_count_total' => $rawRequirementCountTotal,
            'normalized_requirement_count_total' => $normalizedRequirementCountTotal,
            'deduped_requirement_count_total' => $dedupedRequirementCountTotal,
            'validated_requirement_count_total' => $validatedRequirementCountTotal,
            'persisted_requirement_count_total' => $persistedRequirementCountTotal,
            'full_document_mode' => true,
            'elapsed_ms' => $elapsedMs,
            'failure_count' => $failureCount,
            'partial' => $partial,
        ]);

        Log::info('[PROCYNIA][AI_COST][EXTRACTION][END] Full-document extraction run finished.', $this->documentAiCostContext(
            $document,
            $runId,
            [
                'openai_call_count' => $openAiCallCount,
                'built_segment_count' => 0,
                'segments_sent_to_extraction_count' => 0,
                'extracted_candidate_count' => $candidateCount,
                'extraction_call_count' => $extractionResult->extractionCallCount,
                'model' => $extractionResult->model,
                'extraction_model' => $extractionResult->model,
                'input_tokens' => data_get($extractionResult->metadata, 'input_tokens_total', 0),
                'output_tokens' => data_get($extractionResult->metadata, 'output_tokens_total', 0),
                'total_tokens' => data_get($extractionResult->metadata, 'total_tokens_total', 0),
                'fallback_used' => false,
                'partial' => $partial,
                'failure_count' => $failureCount,
                'candidate_count' => $candidateCount,
                'full_document_mode' => true,
                'document_text_length' => $documentTextLength,
                'elapsed_ms' => $elapsedMs,
                'error_type' => $errorType,
                'error_message' => $errorMessage,
            ],
        ));

        return new RequirementExtractionResultData(
            ok: $ok,
            partial: $partial,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            runId: $runId,
            documentTitle: $documentTitle,
            documentFilename: $documentFilename,
            model: $extractionResult->model,
            relevanceModel: $this->relevanceModel(),
            extractionModel: $extractionResult->model,
            segmentCount: 0,
            relevantSegmentCount: 0,
            relevanceCallCount: 0,
            extractionCallCount: $extractionResult->extractionCallCount,
            openAiCallCount: $openAiCallCount,
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: $documentCandidates,
            metadata: [
                'prompt_versions' => [
                    'relevance' => $this->relevancePromptVersion(),
                    'extraction' => data_get($extractionResult->metadata, 'prompt_versions.extraction', FullDocumentRequirementExtractionPrompt::promptVersion()),
                ],
                'full_document_mode' => true,
                'built_segment_count' => 0,
                'segments_sent_to_extraction_count' => 0,
                'extracted_candidate_count' => $candidateCount,
                'relevant_segment_count' => 0,
                'relevance_call_count' => 0,
                'extraction_call_count' => $extractionResult->extractionCallCount,
                'openai_call_count' => $openAiCallCount,
                'extraction_input_tokens' => data_get($extractionResult->metadata, 'input_tokens_total', 0),
                'extraction_output_tokens' => data_get($extractionResult->metadata, 'output_tokens_total', 0),
                'extraction_total_tokens' => data_get($extractionResult->metadata, 'total_tokens_total', 0),
                'input_tokens_total' => data_get($extractionResult->metadata, 'input_tokens_total', 0),
                'output_tokens_total' => data_get($extractionResult->metadata, 'output_tokens_total', 0),
                'total_tokens_total' => data_get($extractionResult->metadata, 'total_tokens_total', 0),
                'candidate_count' => $candidateCount,
                'failure_count' => $failureCount,
                'partial' => $partial,
                'fallback_used' => false,
                'full_document_mode' => true,
                'document_text_length' => $documentTextLength,
                'elapsed_ms' => $elapsedMs,
            ],
            errorType: $errorType,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Purpose: Resolve the configured model for relevance classification.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function relevanceModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_relevance_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        return $model !== '' ? $model : 'gpt-4.1-mini';
    }

    /**
     * Purpose: Resolve the configured model for segment extraction.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function extractionModel(): string
    {
        $model = trim((string) config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')));

        return $model !== '' ? $model : 'gpt-4.1-mini';
    }

    /**
     * Purpose: Return the prompt version used by the relevance classifier.
     * Inputs: None.
     * Returns: The configured prompt version string.
     * Side effects: None.
     */
    private function relevancePromptVersion(): string
    {
        return RequirementSegmentRelevancePromptBuilder::PROMPT_VERSION;
    }

    /**
     * Purpose: Return the prompt version used by the extraction stage.
     * Inputs: None.
     * Returns: The configured prompt version string.
     * Side effects: None.
     */
    private function extractionPromptVersion(): string
    {
        return RequirementSegmentExtractionPromptBuilder::PROMPT_VERSION;
    }

    /**
     * Purpose: Build a consistent log context for document-level extraction tracing.
     * Inputs: The AI document, the extraction run id, and any additional context fields.
     * Returns: A structured log context with stable document and notice identifiers.
     * Side effects: None.
     */
    private function documentAiCostContext(SavedNoticeAiDocument $document, string $runId, array $extra = []): array
    {
        return array_merge([
            'run_id' => $runId,
            'document_id' => $document->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_id' => $document->saved_notice_id,
            'document_title' => $document->original_filename,
            'document_filename' => $document->original_filename,
        ], $extra);
    }

    /**
     * Purpose: Derive the elapsed time in milliseconds from a captured start time.
     * Inputs: The floating-point timestamp captured before processing began.
     * Returns: The elapsed time rounded to the nearest millisecond.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
