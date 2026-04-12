<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use JsonSerializable;

final readonly class DocumentRequirementSegmentData implements JsonSerializable
{
    /**
     * Purpose: Define a source-preserving extraction segment for one AI document chunk.
     * Inputs: The owning saved notice, AI document, chunk metadata, and segment text.
     * Returns: None.
     * Side effects: None.
     */
    public function __construct(
        public int $savedNoticeId,
        public int $savedNoticeAiDocumentId,
        public int $savedNoticeAiDocumentChunkId,
        public string $documentTitle,
        public string $documentFilename,
        public string $segmentId,
        public int $segmentIndex,
        public ?int $pageStart,
        public ?int $pageEnd,
        public ?string $sectionTitle,
        public string $text,
        public string $sourceExcerpt,
        public int $charStart,
        public int $charEnd,
        public int $wordCount,
        public array $sourceChunkIds,
        public array $sourceReference,
    ) {
    }

    /**
     * Purpose: Build one deterministic segment DTO from a persisted AI document chunk.
     * Inputs: The source AI document and one ordered text chunk.
     * Returns: A source-preserving segment DTO.
     * Side effects: None.
     */
    public static function fromChunk(SavedNoticeAiDocument $document, SavedNoticeAiDocumentChunk $chunk): self
    {
        $text = self::normalizeText((string) $chunk->content);
        $sectionTitle = self::detectSectionTitle($text);
        $segmentId = sprintf('saved-notice-ai-document-%d-segment-%d', $document->id, (int) $chunk->chunk_index);

        return new self(
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            savedNoticeAiDocumentChunkId: $chunk->id,
            documentTitle: (string) $document->original_filename,
            documentFilename: (string) $document->original_filename,
            segmentId: $segmentId,
            segmentIndex: (int) $chunk->chunk_index,
            pageStart: null,
            pageEnd: null,
            sectionTitle: $sectionTitle,
            text: $text,
            sourceExcerpt: $text,
            charStart: (int) ($chunk->char_start ?? 0),
            charEnd: (int) ($chunk->char_end ?? 0),
            wordCount: (int) ($chunk->word_count ?? 0),
            sourceChunkIds: [$chunk->id],
            sourceReference: [
                'saved_notice_id' => (int) $document->saved_notice_id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $chunk->id,
                'document_title' => (string) $document->original_filename,
                'document_filename' => (string) $document->original_filename,
                'source_segment_id' => $segmentId,
                'source_segment_index' => (int) $chunk->chunk_index,
                'source_page_start' => null,
                'source_page_end' => null,
                'source_section_title' => $sectionTitle,
                'source_excerpt' => $text,
                'source_reference_text' => $text,
                'char_start' => (int) ($chunk->char_start ?? 0),
                'char_end' => (int) ($chunk->char_end ?? 0),
                'source_chunk_ids' => [$chunk->id],
            ],
        );
    }

    /**
     * Purpose: Export the segment in a compact prompt-friendly structure.
     * Inputs: None.
     * Returns: A deterministic array suitable for OpenAI prompts.
     * Side effects: None.
     */
    public function toPromptArray(): array
    {
        return [
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'document_title' => $this->documentTitle,
            'document_filename' => $this->documentFilename,
            'segment_id' => $this->segmentId,
            'segment_index' => $this->segmentIndex,
            'page_start' => $this->pageStart,
            'page_end' => $this->pageEnd,
            'section_title' => $this->sectionTitle,
            'text' => $this->text,
            'source_excerpt' => $this->sourceExcerpt,
            'char_start' => $this->charStart,
            'char_end' => $this->charEnd,
            'word_count' => $this->wordCount,
            'source_chunk_ids' => $this->sourceChunkIds,
            'source_reference' => $this->sourceReference,
        ];
    }

    /**
     * Purpose: Export the segment contract for persistence and logging.
     * Inputs: None.
     * Returns: A serialisable segment structure with stable source identifiers.
     * Side effects: None.
     */
    public function toArray(): array
    {
        return $this->toPromptArray() + [
            'saved_notice_id' => $this->savedNoticeId,
        ];
    }

    /**
     * Purpose: Serialise the segment DTO for JSON transport.
     * Inputs: None.
     * Returns: The stable segment contract as an array.
     * Side effects: None.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Purpose: Normalize a chunk of extracted text before it becomes a segment payload.
     * Inputs: Raw chunk text.
     * Returns: A lightly normalized string that preserves the original content.
     * Side effects: None.
     */
    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Derive a deterministic section title from a segment when the text makes it obvious.
     * Inputs: Normalized segment text.
     * Returns: A section title when the first line looks like a heading, otherwise null.
     * Side effects: None.
     */
    private static function detectSectionTitle(string $text): ?string
    {
        $firstLine = trim((string) explode("\n", $text)[0]);

        if ($firstLine === '') {
            return null;
        }

        if (preg_match('/^\d+(?:\.\d+)*\s+.+/u', $firstLine) === 1) {
            return $firstLine;
        }

        if (mb_strlen($firstLine, 'UTF-8') <= 120 && preg_match('/^[A-ZÆØÅ0-9][A-ZÆØÅ0-9\s,:;()\/\-]+$/u', $firstLine) === 1) {
            return $firstLine;
        }

        return null;
    }
}
