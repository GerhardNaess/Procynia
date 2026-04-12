<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

final readonly class RequirementSegmentExtractionResultData implements JsonSerializable
{
    /**
     * Purpose: Represent the AI extraction output for one relevant document segment.
     * Inputs: Segment identity, model metadata, extracted candidates, and trace information.
     * Returns: None.
     * Side effects: None.
     */
    public function __construct(
        public bool $ok,
        public int $savedNoticeId,
        public int $savedNoticeAiDocumentId,
        public int $savedNoticeAiDocumentChunkId,
        public string $documentTitle,
        public string $documentFilename,
        public string $segmentId,
        public int $segmentIndex,
        public string $model,
        public ?string $requestId,
        public ?string $responseId,
        public ?int $upstreamStatus,
        public bool $fallbackUsed,
        public ?string $errorType,
        public ?string $errorMessage,
        public int $openAiCallCount,
        public ?int $elapsedMs,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
        public ?string $parseStrategy,
        public ?string $rawOutput,
        public int $candidateCount,
        public array $candidates,
        public array $metadata = [],
    ) {
    }

    /**
     * Purpose: Serialise the extraction result for logs and document-level aggregation.
     * Inputs: None.
     * Returns: A deterministic array representation.
     * Side effects: None.
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'document_title' => $this->documentTitle,
            'document_filename' => $this->documentFilename,
            'segment_id' => $this->segmentId,
            'segment_index' => $this->segmentIndex,
            'model' => $this->model,
            'request_id' => $this->requestId,
            'response_id' => $this->responseId,
            'upstream_status' => $this->upstreamStatus,
            'fallback_used' => $this->fallbackUsed,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'openai_call_count' => $this->openAiCallCount,
            'elapsed_ms' => $this->elapsedMs,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'parse_strategy' => $this->parseStrategy,
            'raw_output' => $this->rawOutput,
            'candidate_count' => $this->candidateCount,
            'candidates' => array_map(
                static fn (RequirementExtractionCandidateData $candidate): array => $candidate->jsonSerialize(),
                $this->candidates,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Purpose: Serialise the extraction DTO for JSON transport.
     * Inputs: None.
     * Returns: The deterministic segment extraction payload.
     * Side effects: None.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
