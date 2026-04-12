<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use JsonSerializable;

final readonly class RequirementExtractionBlockData implements JsonSerializable
{
    public function __construct(
        public int $savedNoticeId,
        public int $savedNoticeAiDocumentId,
        public int $savedNoticeAiDocumentChunkId,
        public int $sourceBlockIndex,
        public string $sourceBlockId,
        public string $content,
        public ?string $contextBefore,
        public ?string $contextAfter,
        public string $blockType,
        public array $sourceChunkIds,
        public array $sourceReference,
    ) {
    }

    public static function fromChunk(
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
        ?SavedNoticeAiDocumentChunk $previousChunk = null,
        ?SavedNoticeAiDocumentChunk $nextChunk = null,
    ): self {
        $content = self::normalizeText((string) $chunk->content);
        $contextBefore = $previousChunk !== null ? self::normalizeText((string) $previousChunk->content) : null;
        $contextAfter = $nextChunk !== null ? self::normalizeText((string) $nextChunk->content) : null;

        return new self(
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: $document->id,
            savedNoticeAiDocumentChunkId: $chunk->id,
            sourceBlockIndex: (int) $chunk->chunk_index,
            sourceBlockId: sprintf('saved-notice-ai-document-%d-chunk-%d', $document->id, $chunk->id),
            content: $content,
            contextBefore: $contextBefore,
            contextAfter: $contextAfter,
            blockType: self::detectBlockType($content),
            sourceChunkIds: array_values(array_filter([
                $previousChunk?->id,
                $chunk->id,
                $nextChunk?->id,
            ], static fn (mixed $value): bool => $value !== null)),
            sourceReference: [
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $chunk->id,
                'source_block_id' => sprintf('saved-notice-ai-document-%d-chunk-%d', $document->id, $chunk->id),
                'source_block_index' => (int) $chunk->chunk_index,
                'document_filename' => $document->original_filename,
                'chunk_index' => (int) $chunk->chunk_index,
                'char_start' => $chunk->char_start !== null ? (int) $chunk->char_start : null,
                'char_end' => $chunk->char_end !== null ? (int) $chunk->char_end : null,
                'source_chunk_ids' => array_values(array_filter([
                    $previousChunk?->id,
                    $chunk->id,
                    $nextChunk?->id,
                ], static fn (mixed $value): bool => $value !== null)),
            ],
        );
    }

    public function toPromptArray(): array
    {
        return [
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'source_block_id' => $this->sourceBlockId,
            'source_block_index' => $this->sourceBlockIndex,
            'source_chunk_ids' => $this->sourceChunkIds,
            'block_type' => $this->blockType,
            'content' => $this->content,
            'context_before' => $this->contextBefore,
            'context_after' => $this->contextAfter,
            'source_reference' => $this->sourceReference,
        ];
    }

    public function toArray(): array
    {
        return $this->toPromptArray() + [
            'saved_notice_id' => $this->savedNoticeId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function detectBlockType(string $text): string
    {
        $firstLine = trim((string) explode("\n", $text)[0]);

        if ($firstLine === '') {
            return 'empty';
        }

        if (preg_match('/^(\d+(?:\.\d+)*[.)]|\-|\*|•|·)\s+/u', $firstLine) === 1) {
            return 'list';
        }

        if (mb_strlen($firstLine, 'UTF-8') <= 80 && preg_match('/^[A-ZÆØÅ0-9\s,:;()\-]+$/u', $firstLine) === 1) {
            return 'heading';
        }

        return 'prose';
    }
}
