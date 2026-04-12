<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

final readonly class RequirementExtractionResultData implements JsonSerializable
{
    /**
     * Purpose: Represent the complete document-level extraction run for one AI document.
     * Inputs: The run id, document identity, stage outputs, candidates, and run metadata.
     * Returns: None.
     * Side effects: None.
     */
    public function __construct(
        public bool $ok,
        public bool $partial,
        public int $savedNoticeId,
        public int $savedNoticeAiDocumentId,
        public string $runId,
        public string $documentTitle,
        public string $documentFilename,
        public string $model,
        public string $relevanceModel,
        public string $extractionModel,
        public int $segmentCount,
        public int $relevantSegmentCount,
        public int $relevanceCallCount,
        public int $extractionCallCount,
        public int $openAiCallCount,
        /** @var array<int, DocumentRequirementSegmentData> */
        public array $segments,
        /** @var array<int, DocumentRequirementSegmentRelevanceData> */
        public array $relevanceResults,
        /** @var array<int, RequirementSegmentExtractionResultData> */
        public array $extractionResults,
        /** @var array<int, RequirementExtractionCandidateData> */
        public array $candidates,
        public array $metadata = [],
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public ?string $failureStage = null,
        public ?string $failureType = null,
    ) {
    }

    /**
     * Purpose: Convert the document-level extraction result into a serialisable array.
     * Inputs: None.
     * Returns: A deterministic payload containing the run summary and all stage outputs.
     * Side effects: None.
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'partial' => $this->partial,
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'run_id' => $this->runId,
            'document_title' => $this->documentTitle,
            'document_filename' => $this->documentFilename,
            'model' => $this->model,
            'relevance_model' => $this->relevanceModel,
            'extraction_model' => $this->extractionModel,
            'segment_count' => $this->segmentCount,
            'relevant_segment_count' => $this->relevantSegmentCount,
            'relevance_call_count' => $this->relevanceCallCount,
            'extraction_call_count' => $this->extractionCallCount,
            'openai_call_count' => $this->openAiCallCount,
            'segments' => array_map(static fn (DocumentRequirementSegmentData $segment): array => $segment->toArray(), $this->segments),
            'relevance_results' => array_map(static fn (DocumentRequirementSegmentRelevanceData $result): array => $result->toArray(), $this->relevanceResults),
            'extraction_results' => array_map(static fn (RequirementSegmentExtractionResultData $result): array => $result->toArray(), $this->extractionResults),
            'candidates' => array_map(static fn (RequirementExtractionCandidateData $candidate): array => $candidate->jsonSerialize(), $this->candidates),
            'metadata' => $this->metadata,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'failure_stage' => $this->failureStage,
            'failure_type' => $this->failureType,
        ];
    }

    /**
     * Purpose: Serialise the document-level result for JSON transport.
     * Inputs: None.
     * Returns: The full run payload ready for transport or logging.
     * Side effects: None.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
