<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use JsonSerializable;

final readonly class RequirementCandidateData implements JsonSerializable
{
    public function __construct(
        public int $savedNoticeId,
        public ?int $savedNoticeAiDocumentId,
        public ?int $savedNoticeAiDocumentChunkId,
        public ?string $requirementIdentifier,
        public ?string $originalRequirementIdentifier,
        public string $requirementText,
        public ?string $originalRequirementText,
        public string $requirementType,
        public string $extractionMethod,
        public string $sourceType,
        public string $reviewStatus,
        public string $approvalStatus,
        public array $sourceReference = [],
        public array $extractionMetadata = [],
        public ?string $sourceRowKey = null,
        public ?string $sourceElementKey = null,
        public ?string $sourceElementType = null,
    ) {}

    /**
     * Purpose: Build a canonical persistence DTO from a structured AI extraction candidate.
     * Inputs: The source AI document, the source chunk, the extracted candidate, and optional trace metadata.
     * Returns: A deterministic data contract ready for persistence.
     * Side effects: None.
     */
    public static function fromAiExtractionCandidate(
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk,
        RequirementExtractionCandidateData $candidate,
        array $extractionMetadata = [],
    ): self {
        $normalizedText = self::normalizeText($candidate->normalizedText);
        $originalText = self::normalizeText($candidate->originalText);
        $requirementIdentifier = self::normalizeNullableString($candidate->requirementIdentifier);
        $sourceReference = self::buildSourceReference($document, $chunk, $candidate);

        return new self(
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            savedNoticeAiDocumentChunkId: $chunk?->id,
            requirementIdentifier: $requirementIdentifier,
            originalRequirementIdentifier: $requirementIdentifier,
            requirementText: $originalText !== '' ? $originalText : $normalizedText,
            originalRequirementText: $originalText !== '' ? $originalText : $normalizedText,
            requirementType: $candidate->requirementType,
            extractionMethod: $candidate->extractionMethod,
            sourceType: SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            reviewStatus: SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            approvalStatus: SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            sourceReference: $sourceReference,
            extractionMetadata: self::buildExtractionMetadata($candidate, $extractionMetadata),
            sourceRowKey: self::normalizeNullableString($candidate->sourceRowKey),
            sourceElementKey: self::normalizeNullableString($candidate->sourceElementKey),
            sourceElementType: self::normalizeNullableString($sourceReference['source_element_type'] ?? null),
        );
    }

    /**
     * Purpose: Build a canonical persistence DTO from the legacy rule-based extractor output.
     * Inputs: The source AI document and chunk plus the extracted candidate payload.
     * Returns: A deterministic data contract ready for persistence.
     * Side effects: None.
     */
    public static function fromLegacyExtraction(
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
        array $candidate,
        array $extractionMetadata = [],
    ): self {
        $requirementText = self::normalizeText((string) ($candidate['requirement_text'] ?? ''));
        $requirementIdentifier = array_key_exists('requirement_identifier', $candidate)
            ? self::normalizeNullableString($candidate['requirement_identifier'])
            : null;
        $sourceReference = [
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'source_block_id' => sprintf('saved-notice-ai-document-%d-chunk-%d', $document->id, $chunk->id),
            'source_block_index' => (int) $chunk->chunk_index,
            'document_filename' => $document->original_filename,
            'chunk_index' => (int) $chunk->chunk_index,
        ];

        return new self(
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            savedNoticeAiDocumentChunkId: $chunk->id,
            requirementIdentifier: $requirementIdentifier,
            originalRequirementIdentifier: $requirementIdentifier,
            requirementText: $requirementText,
            originalRequirementText: $requirementText,
            requirementType: (string) ($candidate['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED),
            extractionMethod: (string) ($candidate['extraction_method'] ?? SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED),
            sourceType: SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            reviewStatus: (string) ($candidate['review_status'] ?? SavedNoticeAiRequirement::REVIEW_STATUS_PENDING),
            approvalStatus: SavedNoticeAiRequirement::approvalStatusForReviewStatus(
                (string) ($candidate['review_status'] ?? SavedNoticeAiRequirement::REVIEW_STATUS_PENDING),
            ),
            sourceReference: $sourceReference,
            extractionMetadata: self::buildLegacyExtractionMetadata($candidate, $extractionMetadata),
        );
    }

    /**
     * Purpose: Build the persistence attributes for a canonical requirement insert.
     * Inputs: None.
     * Returns: A deterministic attribute array for the requirement model.
     * Side effects: None.
     */
    public function toCreationAttributes(): array
    {
        return [
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'source_type' => $this->sourceType,
            'approval_status' => $this->approvalStatus,
            'requirement_identifier' => $this->requirementIdentifier,
            'original_requirement_identifier' => $this->originalRequirementIdentifier,
            'requirement_text' => $this->requirementText,
            'original_requirement_text' => $this->originalRequirementText,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'assigned_user_id' => null,
            'source_reference' => $this->sourceReference,
            'extraction_metadata' => $this->extractionMetadata,
            'source_row_key' => $this->sourceRowKey,
            'source_element_key' => $this->sourceElementKey,
            'source_element_type' => $this->sourceElementType,
            'original_candidate_snapshot' => $this->toSnapshotArray(),
            'current_requirement_snapshot' => $this->toSnapshotArray(),
            'requirement_type' => $this->requirementType,
            'extraction_method' => $this->extractionMethod,
            'review_status' => $this->reviewStatus,
        ];
    }

    /**
     * Purpose: Build a serialisable snapshot of the original AI candidate state.
     * Inputs: None.
     * Returns: A traceable array snapshot.
     * Side effects: None.
     */
    public function toSnapshotArray(): array
    {
        return [
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'source_type' => $this->sourceType,
            'approval_status' => $this->approvalStatus,
            'review_status' => $this->reviewStatus,
            'requirement_identifier' => $this->requirementIdentifier,
            'original_requirement_identifier' => $this->originalRequirementIdentifier,
            'requirement_text' => $this->requirementText,
            'original_requirement_text' => $this->originalRequirementText,
            'requirement_type' => $this->requirementType,
            'extraction_method' => $this->extractionMethod,
            'source_reference' => $this->sourceReference,
            'extraction_metadata' => $this->extractionMetadata,
            'source_row_key' => $this->sourceRowKey,
            'source_element_key' => $this->sourceElementKey,
            'source_element_type' => $this->sourceElementType,
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'requirement_identifier' => $this->requirementIdentifier,
            'original_requirement_identifier' => $this->originalRequirementIdentifier,
            'requirement_text' => $this->requirementText,
            'original_requirement_text' => $this->originalRequirementText,
            'requirement_type' => $this->requirementType,
            'extraction_method' => $this->extractionMethod,
            'source_type' => $this->sourceType,
            'review_status' => $this->reviewStatus,
            'approval_status' => $this->approvalStatus,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'source_reference' => $this->sourceReference,
            'extraction_metadata' => $this->extractionMetadata,
            'source_row_key' => $this->sourceRowKey,
            'source_element_key' => $this->sourceElementKey,
            'source_element_type' => $this->sourceElementType,
        ];
    }

    private static function buildSourceReference(
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk,
        RequirementExtractionCandidateData $candidate,
    ): array {
        $sourceReference = is_array($candidate->sourceReference) ? $candidate->sourceReference : [];

        return [
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk?->id,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'source_block_id' => $candidate->sourceBlockId,
            'source_block_index' => $candidate->sourceBlockIndex,
            'source_segment_id' => $sourceReference['source_segment_id'] ?? $candidate->sourceBlockId,
            'source_segment_index' => $sourceReference['source_segment_index'] ?? $candidate->sourceBlockIndex,
            'document_title' => $sourceReference['document_title'] ?? $document->original_filename,
            'document_filename' => $sourceReference['document_filename'] ?? $document->original_filename,
            'source_page_start' => $sourceReference['source_page_start'] ?? null,
            'source_page_end' => $sourceReference['source_page_end'] ?? null,
            'source_section_title' => $sourceReference['source_section_title'] ?? null,
            'source_section_number' => $sourceReference['source_section_number'] ?? null,
            'source_row_key_origin' => $sourceReference['source_row_key_origin'] ?? null,
            'source_row_identifier' => $sourceReference['source_row_identifier'] ?? null,
            'source_row_type_code' => $sourceReference['source_row_type_code'] ?? null,
            'source_row_key_rejected' => $sourceReference['source_row_key_rejected'] ?? null,
            'source_element_key_origin' => $sourceReference['source_element_key_origin'] ?? null,
            'source_element_type' => $sourceReference['source_element_type'] ?? null,
            'source_element_number' => $sourceReference['source_element_number'] ?? null,
            'source_excerpt' => $sourceReference['source_excerpt'] ?? $sourceReference['source_reference_text'] ?? null,
            'source_reference_text' => $sourceReference['source_reference_text'] ?? $sourceReference['source_excerpt'] ?? null,
            'chunk_index' => $chunk?->chunk_index !== null ? (int) $chunk->chunk_index : null,
            'char_start' => $sourceReference['char_start'] ?? ($chunk?->char_start !== null ? (int) $chunk->char_start : null),
            'char_end' => $sourceReference['char_end'] ?? ($chunk?->char_end !== null ? (int) $chunk->char_end : null),
            'source_chunk_ids' => $sourceReference['source_chunk_ids'] ?? ($chunk !== null ? [$chunk->id] : []),
            'row_index' => $sourceReference['row_index'] ?? null,
        ] + array_filter([
            'page' => $sourceReference['page'] ?? null,
            'section' => $sourceReference['section'] ?? null,
            'paragraph' => $sourceReference['paragraph'] ?? null,
            'line_start' => $sourceReference['line_start'] ?? null,
            'line_end' => $sourceReference['line_end'] ?? null,
            'excerpt' => $sourceReference['excerpt'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function buildExtractionMetadata(
        RequirementExtractionCandidateData $candidate,
        array $extractionMetadata,
    ): array {
        return array_merge($extractionMetadata, [
            'extraction_method' => $candidate->extractionMethod,
            'original_text' => $candidate->originalText,
            'normalized_text' => $candidate->normalizedText,
            'parent_reference' => $candidate->parentReference,
            'obligation_type' => $candidate->obligationType,
            'comment' => $candidate->comment,
            'evaluation_notes' => $candidate->evaluationNotes,
            'response_expectation' => $candidate->responseExpectation,
            'expected_evidence' => $candidate->expectedEvidence,
            'keywords' => $candidate->keywords,
            'domain' => $candidate->domain,
            'related_references' => $candidate->relatedReferences,
            'interpretation_risk' => $candidate->interpretationRisk,
            'is_requirement' => $candidate->isRequirement,
            'confidence' => $candidate->confidence,
            'warnings' => $candidate->warnings,
        ]);
    }

    private static function buildLegacyExtractionMetadata(array $candidate, array $extractionMetadata): array
    {
        $normalizedRequirementText = self::normalizeText((string) ($candidate['requirement_text'] ?? ''));

        return array_merge($extractionMetadata, [
            'extraction_method' => $candidate['extraction_method'] ?? SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'original_text' => $normalizedRequirementText,
            'normalized_text' => $normalizedRequirementText,
            'comment' => null,
            'evaluation_notes' => null,
            'response_expectation' => null,
            'expected_evidence' => [],
            'keywords' => [],
            'domain' => [],
            'related_references' => [],
            'interpretation_risk' => null,
            'is_requirement' => true,
            'confidence' => 0.5,
            'warnings' => [],
        ]);
    }

    private static function normalizeText(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $normalized;
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
