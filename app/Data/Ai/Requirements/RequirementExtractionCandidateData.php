<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use JsonSerializable;

final readonly class RequirementExtractionCandidateData implements JsonSerializable
{
    public const EXTRACTION_METHOD_PHASE_1 = 'phase_1_requirement_extraction';

    public const EXTRACTION_METHOD_FULL_DOCUMENT = self::EXTRACTION_METHOD_PHASE_1;

    public const OBLIGATION_TYPES = [
        'must',
        'shall',
        'should',
        'may',
        'must_not',
        'conditional',
        'unknown',
    ];

    public const INTERPRETATION_RISKS = [
        'low',
        'medium',
        'high',
        'ambiguous',
    ];

    public function __construct(
        public int $sourceDocumentId,
        public string $sourceBlockId,
        public int $sourceBlockIndex,
        public ?string $requirementIdentifier,
        public ?string $parentReference,
        public string $requirementType,
        public string $obligationType,
        public string $extractionMethod,
        public string $originalText,
        public string $normalizedText,
        public ?string $comment,
        public ?string $evaluationNotes,
        public ?string $responseExpectation,
        public array $expectedEvidence,
        public array $keywords,
        public array $domain,
        public array $relatedReferences,
        public array $sourceReference,
        public ?string $interpretationRisk,
        public bool $isRequirement,
        public float $confidence,
        public array $warnings,
        public ?string $sourceRowKey = null,
        public ?string $sourceElementKey = null,
    ) {}

    public static function fromPromptRow(array $row, SavedNoticeAiDocument $document, int $rowIndex): self
    {
        $requirementText = self::normalizeRequiredString((string) ($row['original_text'] ?? ''));
        $normalizedText = self::normalizeRequiredString((string) ($row['normalized_text'] ?? $requirementText));
        $requirementIdentifier = self::normalizeNullableString($row['requirement_identifier'] ?? null);
        $sourceReferenceText = self::normalizeNullableString($row['source_reference_text'] ?? null);
        $sourceBlockId = self::buildSourceBlockId($document, $requirementIdentifier, $requirementText, $sourceReferenceText);

        return new self(
            sourceDocumentId: $document->id,
            sourceBlockId: $sourceBlockId,
            sourceBlockIndex: $rowIndex,
            requirementIdentifier: $requirementIdentifier,
            parentReference: self::normalizeNullableString($row['parent_reference'] ?? null),
            requirementType: self::normalizeRequirementType(self::normalizePromptRequirementType((string) ($row['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED))),
            obligationType: self::normalizeObligationType(self::normalizePromptObligationType((string) ($row['obligation_type'] ?? 'unknown'))),
            extractionMethod: self::EXTRACTION_METHOD_PHASE_1,
            originalText: $requirementText,
            normalizedText: $normalizedText !== '' ? $normalizedText : $requirementText,
            comment: self::normalizeNullableString($row['comment'] ?? null),
            evaluationNotes: self::normalizeNullableString($row['evaluation_notes'] ?? null),
            responseExpectation: self::normalizeNullableString($row['response_expectation'] ?? null),
            expectedEvidence: self::normalizeStringList($row['expected_evidence'] ?? []),
            keywords: self::normalizeStringList($row['keywords'] ?? []),
            domain: self::normalizeStringList($row['domain'] ?? []),
            relatedReferences: self::normalizeStringList($row['related_references'] ?? []),
            sourceReference: self::normalizePromptSourceReference($document, $sourceBlockId, $rowIndex, $sourceReferenceText, $row),
            interpretationRisk: self::normalizeInterpretationRisk($row['interpretation_risk'] ?? null),
            isRequirement: (bool) ($row['is_requirement'] ?? true),
            confidence: self::normalizeConfidence($row['confidence'] ?? 0.0),
            warnings: self::normalizeStringList($row['warnings'] ?? []),
            sourceRowKey: self::normalizeNullableString($row['source_row_key'] ?? null),
        );
    }

    public static function fromArray(array $candidate, RequirementExtractionBlockData $block): self
    {
        $sourceReference = self::normalizeSourceReference(
            array_key_exists('source_reference', $candidate) && is_array($candidate['source_reference'])
                ? $candidate['source_reference']
                : [],
            $block,
        );

        return new self(
            sourceDocumentId: $block->savedNoticeAiDocumentId,
            sourceBlockId: (string) ($candidate['source_block_id'] ?? $block->sourceBlockId),
            sourceBlockIndex: (int) ($candidate['source_block_index'] ?? $block->sourceBlockIndex),
            requirementIdentifier: self::normalizeNullableString($candidate['requirement_identifier'] ?? null),
            parentReference: self::normalizeNullableString($candidate['parent_reference'] ?? null),
            requirementType: self::normalizeRequirementType((string) ($candidate['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED)),
            obligationType: self::normalizeObligationType((string) ($candidate['obligation_type'] ?? 'unknown')),
            extractionMethod: SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_STRUCTURED,
            originalText: self::normalizeRequiredString((string) ($candidate['original_text'] ?? '')),
            normalizedText: self::normalizeRequiredString((string) ($candidate['normalized_text'] ?? '')),
            comment: self::normalizeNullableString($candidate['comment'] ?? null),
            evaluationNotes: self::normalizeNullableString($candidate['evaluation_notes'] ?? null),
            responseExpectation: self::normalizeNullableString($candidate['response_expectation'] ?? null),
            expectedEvidence: self::normalizeStringList($candidate['expected_evidence'] ?? []),
            keywords: self::normalizeStringList($candidate['keywords'] ?? []),
            domain: self::normalizeStringList($candidate['domain'] ?? []),
            relatedReferences: self::normalizeStringList($candidate['related_references'] ?? []),
            sourceReference: $sourceReference,
            interpretationRisk: self::normalizeInterpretationRisk($candidate['interpretation_risk'] ?? null),
            isRequirement: (bool) ($candidate['is_requirement'] ?? true),
            confidence: self::normalizeConfidence($candidate['confidence'] ?? 0.0),
            warnings: self::normalizeStringList($candidate['warnings'] ?? []),
        );
    }

    /**
     * Purpose: Build a candidate DTO from a segmented segment-level extraction row.
     * Inputs: The extracted row, the source segment, and the row index within the segment.
     * Returns: A canonical AI extraction candidate with source-preserving segment metadata.
     * Side effects: None.
     */
    public static function fromSegmentRow(array $candidate, DocumentRequirementSegmentData $segment, int $rowIndex): self
    {
        $sourceReference = self::normalizeSegmentSourceReference(
            array_key_exists('source_reference', $candidate) && is_array($candidate['source_reference'])
                ? $candidate['source_reference']
                : [],
            $segment,
            $rowIndex,
            $candidate,
        );

        $requirementText = self::normalizeRequiredString((string) ($candidate['original_text'] ?? ''));
        $normalizedText = self::normalizeRequiredString((string) ($candidate['normalized_text'] ?? $requirementText));
        $requirementIdentifier = self::normalizeNullableString($candidate['requirement_identifier'] ?? null);

        return new self(
            sourceDocumentId: $segment->savedNoticeAiDocumentId,
            sourceBlockId: $segment->segmentId,
            sourceBlockIndex: $segment->segmentIndex,
            requirementIdentifier: $requirementIdentifier,
            parentReference: self::normalizeNullableString($candidate['parent_reference'] ?? null),
            requirementType: self::normalizeRequirementType(self::normalizePromptRequirementType((string) ($candidate['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED))),
            obligationType: self::normalizeObligationType(self::normalizePromptObligationType((string) ($candidate['obligation_type'] ?? 'unknown'))),
            extractionMethod: SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_SEGMENTED,
            originalText: $requirementText,
            normalizedText: $normalizedText !== '' ? $normalizedText : $requirementText,
            comment: self::normalizeNullableString($candidate['comment'] ?? null),
            evaluationNotes: self::normalizeNullableString($candidate['evaluation_notes'] ?? null),
            responseExpectation: self::normalizeNullableString($candidate['response_expectation'] ?? null),
            expectedEvidence: self::normalizeStringList($candidate['expected_evidence'] ?? []),
            keywords: self::normalizeStringList($candidate['keywords'] ?? []),
            domain: self::normalizeStringList($candidate['domain'] ?? []),
            relatedReferences: self::normalizeStringList($candidate['related_references'] ?? []),
            sourceReference: $sourceReference,
            interpretationRisk: self::normalizeInterpretationRisk($candidate['interpretation_risk'] ?? null),
            isRequirement: (bool) ($candidate['is_requirement'] ?? true),
            confidence: self::normalizeConfidence($candidate['confidence'] ?? 0.0),
            warnings: self::normalizeStringList($candidate['warnings'] ?? []),
        );
    }

    public static function fromLegacyArray(array $candidate, SavedNoticeAiDocument $document, int $rowIndex = 0): self
    {
        $requirementText = self::normalizeRequiredString((string) ($candidate['requirement_text'] ?? ''));
        $sourceBlockId = self::buildSourceBlockId(
            $document,
            self::normalizeNullableString($candidate['requirement_identifier'] ?? null),
            $requirementText,
            self::normalizeNullableString($candidate['source_reference_text'] ?? null),
        );

        return new self(
            sourceDocumentId: $document->id,
            sourceBlockId: $sourceBlockId,
            sourceBlockIndex: $rowIndex,
            requirementIdentifier: self::normalizeNullableString($candidate['requirement_identifier'] ?? null),
            parentReference: null,
            requirementType: self::normalizeRequirementType((string) ($candidate['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED)),
            obligationType: 'unknown',
            extractionMethod: SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            originalText: $requirementText,
            normalizedText: $requirementText,
            comment: null,
            evaluationNotes: null,
            responseExpectation: null,
            expectedEvidence: [],
            keywords: [],
            domain: [],
            relatedReferences: [],
            sourceReference: [
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => null,
                'source_block_id' => $sourceBlockId,
                'source_block_index' => $rowIndex,
                'source_segment_id' => $sourceBlockId,
                'source_segment_index' => $rowIndex,
                'document_title' => $document->original_filename,
                'document_filename' => $document->original_filename,
                'chunk_index' => null,
                'char_start' => null,
                'char_end' => null,
                'source_chunk_ids' => [],
                'source_reference_text' => self::normalizeNullableString($candidate['source_reference_text'] ?? null),
            ],
            interpretationRisk: null,
            isRequirement: true,
            confidence: 0.5,
            warnings: [],
        );
    }

    public function toPersistenceData(
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk = null,
        array $extractionMetadata = [],
    ): RequirementCandidateData {
        return RequirementCandidateData::fromAiExtractionCandidate(
            $document,
            $chunk,
            $this,
            $extractionMetadata,
        );
    }

    /**
     * Purpose: Attach a verified table-row match to this candidate — either the AI's own
     * source_row_key confirmed against a row actually sent to it ($origin = 'ai_verified'), or a
     * row recovered by the backend via exact normalized-text matching when the AI omitted or
     * mis-echoed the key ($origin = 'text_matched'). Overrides the candidate's requirement
     * identifier with the row's own identifier cell when the table has a recognizable ID column
     * (see DocxTableRowData::identifierCellValue()) so the persisted requirement number is
     * grounded in the source table, not the AI's restatement of it.
     * Inputs: The resolved DocxTableRowData and how it was resolved.
     * Returns: A new candidate with sourceRowKey set and source_reference enriched.
     * Side effects: None.
     */
    public function withResolvedTableRow(DocxTableRowData $row, string $origin): self
    {
        $identifierOverride = $row->identifierCellValue();

        return new self(
            sourceDocumentId: $this->sourceDocumentId,
            sourceBlockId: $this->sourceBlockId,
            sourceBlockIndex: $this->sourceBlockIndex,
            requirementIdentifier: $identifierOverride ?? $this->requirementIdentifier,
            parentReference: $this->parentReference,
            requirementType: $this->requirementType,
            obligationType: $this->obligationType,
            extractionMethod: $this->extractionMethod,
            originalText: $this->originalText,
            normalizedText: $this->normalizedText,
            comment: $this->comment,
            evaluationNotes: $this->evaluationNotes,
            responseExpectation: $this->responseExpectation,
            expectedEvidence: $this->expectedEvidence,
            keywords: $this->keywords,
            domain: $this->domain,
            relatedReferences: $this->relatedReferences,
            sourceReference: array_merge($this->sourceReference, array_filter([
                'source_row_key_origin' => $origin,
                'source_row_identifier' => $identifierOverride,
                'source_row_type_code' => $row->typeCellValue(),
                'source_section_number' => $row->sectionNumber,
                'source_section_title' => $row->sectionTitle,
                'source_element_type' => 'table_row',
            ], static fn (mixed $value): bool => $value !== null)),
            interpretationRisk: $this->interpretationRisk,
            isRequirement: $this->isRequirement,
            confidence: $this->confidence,
            warnings: $this->warnings,
            sourceRowKey: $row->sourceRowKey,
            // Kept in lockstep with the generalized source_element_key/source_element_type model
            // (see withResolvedTextElement()) so table_row is expressible through the same
            // unified field the frontend/API use for paragraph/list_item provenance — source_row_key
            // itself is untouched above, purely for backward compatibility with existing consumers.
            sourceElementKey: $row->sourceRowKey,
        );
    }

    /**
     * Purpose: Attach a verified paragraph/list-item match to this candidate — recovered by the
     * backend via exact/substring normalized-text matching against the document's parsed
     * text_elements (see DocumentTextExtractor::extractDocxTextAndTables()'s `text_elements` and
     * RequirementCandidateExtractor::reconcileCandidatesWithTextElements()). The AI is never given
     * these keys to echo back — unlike table rows, there is no 'ai_verified' origin here, only
     * 'text_matched' — so there is no hallucination risk to guard against for this source kind.
     * Overrides the candidate's requirement identifier with the element's own reconstructed Word
     * number (list items only — plain paragraphs have none) so the persisted requirement number is
     * grounded in the source document, not the AI's restatement of it.
     * Inputs: The resolved text element (see text_elements shape) and how it was resolved.
     * Returns: A new candidate with sourceElementKey set and source_reference enriched.
     * Side effects: None.
     */
    public function withResolvedTextElement(array $element, string $origin): self
    {
        $identifierOverride = isset($element['number']) && is_string($element['number']) && $element['number'] !== ''
            ? $element['number']
            : null;

        return new self(
            sourceDocumentId: $this->sourceDocumentId,
            sourceBlockId: $this->sourceBlockId,
            sourceBlockIndex: $this->sourceBlockIndex,
            requirementIdentifier: $identifierOverride ?? $this->requirementIdentifier,
            parentReference: $this->parentReference,
            requirementType: $this->requirementType,
            obligationType: $this->obligationType,
            extractionMethod: $this->extractionMethod,
            originalText: $this->originalText,
            normalizedText: $this->normalizedText,
            comment: $this->comment,
            evaluationNotes: $this->evaluationNotes,
            responseExpectation: $this->responseExpectation,
            expectedEvidence: $this->expectedEvidence,
            keywords: $this->keywords,
            domain: $this->domain,
            relatedReferences: $this->relatedReferences,
            sourceReference: array_merge($this->sourceReference, array_filter([
                'source_element_key_origin' => $origin,
                'source_element_type' => $element['element_type'] ?? null,
                'source_element_number' => $identifierOverride,
                'source_section_number' => $element['section_number'] ?? null,
                'source_section_title' => $element['section_title'] ?? null,
                // Extra provenance an element carries for its own source kind, kept nested under
                // one key rather than spread across the reference: a spreadsheet range needs its
                // sheet, its A1 reference and the human label a reader recognises, none of which a
                // paragraph has. DOCX elements simply never set it.
                'source_metadata' => is_array($element['source_metadata'] ?? null) ? $element['source_metadata'] : null,
            ], static fn (mixed $value): bool => $value !== null)),
            interpretationRisk: $this->interpretationRisk,
            isRequirement: $this->isRequirement,
            confidence: $this->confidence,
            warnings: $this->warnings,
            sourceRowKey: $this->sourceRowKey,
            sourceElementKey: is_string($element['element_key'] ?? null) ? $element['element_key'] : null,
        );
    }

    /**
     * Purpose: Reject a source_row_key the AI echoed that does not match any row actually sent to
     * it in this window — persisting an unverifiable key would be a validation error, not a model
     * quirk (a hallucinated/mismatched key must never be treated as valid provenance). The
     * rejected value is kept in source_reference for diagnostics only, never as source_row_key.
     * Inputs: The claimed source_row_key that failed verification.
     * Returns: A new candidate with sourceRowKey cleared and the rejection recorded.
     * Side effects: None.
     */
    public function withRejectedSourceRowKey(string $claimedSourceRowKey): self
    {
        return new self(
            sourceDocumentId: $this->sourceDocumentId,
            sourceBlockId: $this->sourceBlockId,
            sourceBlockIndex: $this->sourceBlockIndex,
            requirementIdentifier: $this->requirementIdentifier,
            parentReference: $this->parentReference,
            requirementType: $this->requirementType,
            obligationType: $this->obligationType,
            extractionMethod: $this->extractionMethod,
            originalText: $this->originalText,
            normalizedText: $this->normalizedText,
            comment: $this->comment,
            evaluationNotes: $this->evaluationNotes,
            responseExpectation: $this->responseExpectation,
            expectedEvidence: $this->expectedEvidence,
            keywords: $this->keywords,
            domain: $this->domain,
            relatedReferences: $this->relatedReferences,
            sourceReference: array_merge($this->sourceReference, [
                'source_row_key_origin' => 'ai_rejected_hallucinated',
                'source_row_key_rejected' => $claimedSourceRowKey,
            ]),
            interpretationRisk: $this->interpretationRisk,
            isRequirement: $this->isRequirement,
            confidence: $this->confidence,
            warnings: [...$this->warnings, sprintf('source_row_key_rejected:%s', $claimedSourceRowKey)],
            sourceRowKey: null,
            sourceElementKey: $this->sourceElementKey,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'source_document_id' => $this->sourceDocumentId,
            'source_block_id' => $this->sourceBlockId,
            'source_block_index' => $this->sourceBlockIndex,
            'requirement_identifier' => $this->requirementIdentifier,
            'parent_reference' => $this->parentReference,
            'requirement_type' => $this->requirementType,
            'obligation_type' => $this->obligationType,
            'extraction_method' => $this->extractionMethod,
            'original_text' => $this->originalText,
            'normalized_text' => $this->normalizedText,
            'comment' => $this->comment,
            'evaluation_notes' => $this->evaluationNotes,
            'response_expectation' => $this->responseExpectation,
            'expected_evidence' => $this->expectedEvidence,
            'keywords' => $this->keywords,
            'domain' => $this->domain,
            'related_references' => $this->relatedReferences,
            'source_reference' => $this->sourceReference,
            'interpretation_risk' => $this->interpretationRisk,
            'is_requirement' => $this->isRequirement,
            'confidence' => $this->confidence,
            'warnings' => $this->warnings,
            'source_row_key' => $this->sourceRowKey,
            'source_element_key' => $this->sourceElementKey,
        ];
    }

    private static function normalizeRequirementType(string $value): string
    {
        return in_array($value, SavedNoticeAiRequirement::REQUIREMENT_TYPES, true)
            ? $value
            : SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED;
    }

    private static function normalizeObligationType(string $value): string
    {
        return in_array($value, self::OBLIGATION_TYPES, true) ? $value : 'unknown';
    }

    private static function normalizeInterpretationRisk(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = match (true) {
            str_starts_with($normalized, 'lav') => 'low',
            str_starts_with($normalized, 'middels') => 'medium',
            str_starts_with($normalized, 'hoy'),
            str_starts_with($normalized, 'høy') => 'high',
            str_starts_with($normalized, 'ambiguous') => 'ambiguous',
            default => $normalized,
        };

        return in_array($normalized, self::INTERPRETATION_RISKS, true) ? $normalized : null;
    }

    private static function normalizePromptRequirementType(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            str_contains($normalized, 'dokument') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            str_contains($normalized, 'administr') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE,
            str_contains($normalized, 'informasj') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE,
            str_contains($normalized, 'evaluer') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            str_contains($normalized, 'kombinert') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            str_contains($normalized, 'skal') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            str_contains($normalized, 'må') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            str_contains($normalized, 'bor'),
            str_contains($normalized, 'bør') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED,
            str_contains($normalized, 'kan') => SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED,
            default => SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED,
        };
    }

    private static function normalizePromptObligationType(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            str_contains($normalized, 'obligatorisk') => 'must',
            str_contains($normalized, 'begge') => 'conditional',
            str_contains($normalized, 'evaluer') => 'conditional',
            str_contains($normalized, 'uavklart') => 'unknown',
            default => self::normalizeObligationType($normalized),
        };
    }

    private static function normalizeConfidence(mixed $value): float
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            $numeric = (float) $value;

            if (is_finite($numeric)) {
                return max(0.0, min(1.0, $numeric));
            }
        }

        return 0.0;
    }

    private static function normalizeRequiredString(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $normalized;
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $item = self::normalizeNullableString($value);

            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeSourceReference(array $sourceReference, RequirementExtractionBlockData $block): array
    {
        $sourceExcerpt = self::normalizeNullableString(
            $sourceReference['source_excerpt']
            ?? $sourceReference['source_reference_text']
            ?? $sourceReference['excerpt']
            ?? null,
        );
        $sourceReferenceText = self::normalizeNullableString(
            $sourceReference['source_reference_text']
            ?? $sourceReference['source_excerpt']
            ?? $sourceReference['excerpt']
            ?? null,
        );

        return array_merge([
            'saved_notice_ai_document_id' => $block->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $block->savedNoticeAiDocumentChunkId,
            'source_block_id' => $block->sourceBlockId,
            'source_block_index' => $block->sourceBlockIndex,
            'source_segment_id' => $sourceReference['source_segment_id'] ?? $block->sourceBlockId,
            'source_segment_index' => $sourceReference['source_segment_index'] ?? $block->sourceBlockIndex,
            'document_title' => $sourceReference['document_title'] ?? null,
            'document_filename' => $sourceReference['document_filename'] ?? null,
            'chunk_index' => $sourceReference['chunk_index'] ?? $block->sourceBlockIndex,
            'char_start' => $sourceReference['char_start'] ?? null,
            'char_end' => $sourceReference['char_end'] ?? null,
            'source_chunk_ids' => $sourceReference['source_chunk_ids'] ?? $block->sourceChunkIds,
            'source_excerpt' => $sourceExcerpt,
            'source_reference_text' => $sourceReferenceText,
        ], array_intersect_key($sourceReference, array_flip([
            'page',
            'section',
            'paragraph',
            'line_start',
            'line_end',
            'excerpt',
            'source_excerpt',
            'source_reference_text',
        ])));
    }

    private static function normalizePromptSourceReference(
        SavedNoticeAiDocument $document,
        string $sourceBlockId,
        int $rowIndex,
        ?string $sourceReferenceText,
        array $row,
    ): array {
        return array_merge([
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => null,
            'source_block_id' => $sourceBlockId,
            'source_block_index' => $rowIndex,
            'document_filename' => $document->original_filename,
            'chunk_index' => null,
            'char_start' => null,
            'char_end' => null,
            'source_chunk_ids' => [],
            'source_reference_text' => $sourceReferenceText,
            'row_index' => $rowIndex,
        ], array_filter([
            'page' => self::normalizeNullableString($row['source_page'] ?? null),
            'section' => self::normalizeNullableString($row['source_section'] ?? null),
            'paragraph' => self::normalizeNullableString($row['source_paragraph'] ?? null),
            'line_start' => self::normalizeNullableString($row['source_line_start'] ?? null),
            'line_end' => self::normalizeNullableString($row['source_line_end'] ?? null),
            'excerpt' => self::normalizeNullableString($row['source_excerpt'] ?? null),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Purpose: Normalize segment source reference metadata while preserving the source segment identity.
     * Inputs: The raw source reference array, the source segment, and the candidate row index.
     * Returns: A canonical source reference array for a segment-level extraction candidate.
     * Side effects: None.
     */
    private static function normalizeSegmentSourceReference(array $sourceReference, DocumentRequirementSegmentData $segment, int $rowIndex, array $candidate = []): array
    {
        $sourceExcerpt = self::normalizeNullableString(
            $sourceReference['source_excerpt']
            ?? $candidate['source_excerpt']
            ?? $sourceReference['source_reference_text']
            ?? $candidate['source_reference_text']
            ?? $segment->sourceExcerpt,
        );
        $sourcePageStart = self::normalizeNullableInteger($sourceReference['source_page_start'] ?? $candidate['source_page_start'] ?? $segment->pageStart);
        $sourcePageEnd = self::normalizeNullableInteger($sourceReference['source_page_end'] ?? $candidate['source_page_end'] ?? $segment->pageEnd);
        $sourceSectionTitle = self::normalizeNullableString($sourceReference['source_section_title'] ?? $candidate['source_section_title'] ?? $segment->sectionTitle);

        return array_merge([
            'saved_notice_id' => $segment->savedNoticeId,
            'saved_notice_ai_document_id' => $segment->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
            'source_block_id' => $segment->segmentId,
            'source_block_index' => $segment->segmentIndex,
            'source_segment_id' => $sourceReference['source_segment_id'] ?? $segment->segmentId,
            'source_segment_index' => $sourceReference['source_segment_index'] ?? $segment->segmentIndex,
            'document_title' => $sourceReference['document_title'] ?? $segment->documentTitle,
            'document_filename' => $sourceReference['document_filename'] ?? $segment->documentFilename,
            'source_page_start' => $sourcePageStart,
            'source_page_end' => $sourcePageEnd,
            'source_section_title' => $sourceSectionTitle,
            'source_excerpt' => $sourceExcerpt,
            'source_reference_text' => $sourceExcerpt,
            'char_start' => $sourceReference['char_start'] ?? $segment->charStart,
            'char_end' => $sourceReference['char_end'] ?? $segment->charEnd,
            'source_chunk_ids' => $sourceReference['source_chunk_ids'] ?? $segment->sourceChunkIds,
            'row_index' => $rowIndex,
        ], array_filter([
            'source_page' => self::normalizeNullableString($sourceReference['source_page'] ?? null),
            'page' => self::normalizeNullableString($sourceReference['page'] ?? null),
            'section' => self::normalizeNullableString($sourceReference['section'] ?? null),
            'paragraph' => self::normalizeNullableString($sourceReference['paragraph'] ?? null),
            'line_start' => self::normalizeNullableString($sourceReference['line_start'] ?? null),
            'line_end' => self::normalizeNullableString($sourceReference['line_end'] ?? null),
            'excerpt' => $sourceExcerpt,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private static function normalizeNullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value) || is_string($value)) {
            $numeric = (int) $value;

            return $numeric >= 0 ? $numeric : null;
        }

        return null;
    }

    private static function buildSourceBlockId(
        SavedNoticeAiDocument $document,
        ?string $requirementIdentifier,
        string $requirementText,
        ?string $sourceReferenceText,
    ): string {
        $fingerprintSource = implode('|', [
            $document->id,
            mb_strtolower(trim((string) $requirementIdentifier), 'UTF-8'),
            mb_strtolower(trim($requirementText), 'UTF-8'),
            mb_strtolower(trim((string) $sourceReferenceText), 'UTF-8'),
        ]);

        return sprintf(
            'saved-notice-ai-document-%d-phase-1-%s',
            $document->id,
            substr(sha1($fingerprintSource), 0, 16),
        );
    }
}
