<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * A canonical heading source element from a parsed DOCX document (see
 * DocumentTextExtractor::extractDocxTextAndTables()) — one of the generic source-element kinds
 * the server is authoritative for (heading, paragraph/list item, table, table row, table cell).
 *
 * `number` is Word's own reconstructed auto-number (e.g. "2.1", "A.3", "3.2 (a)") resolved from
 * the document's real numbering.xml/styles.xml definitions — never a hardcoded pattern, never
 * guessed from the heading text. Null when the heading has no associated numbering (or uses a
 * non-numeric list format such as bullet/none) — in that case the raw text may still start with
 * a literally-typed number (see DocumentTextExtractor::splitDocxSectionHeading()) as a fallback.
 *
 * `sourceKey` is stable across re-parses of the same document: it is derived purely from
 * document order, not from content, so it never changes unless the document's own heading
 * structure changes.
 */
final readonly class DocxHeadingData implements JsonSerializable
{
    public function __construct(
        public string $sourceKey,
        public int $documentOrder,
        public int $level,
        public string $text,
        public ?string $number,
        public int $charStart,
        public int $charEnd,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: (string) ($data['source_key'] ?? ''),
            documentOrder: (int) ($data['document_order'] ?? 0),
            level: (int) ($data['level'] ?? 0),
            text: (string) ($data['text'] ?? ''),
            number: isset($data['number']) ? (string) $data['number'] : null,
            charStart: (int) ($data['char_start'] ?? 0),
            charEnd: (int) ($data['char_end'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'document_order' => $this->documentOrder,
            'level' => $this->level,
            'text' => $this->text,
            'number' => $this->number,
            'char_start' => $this->charStart,
            'char_end' => $this->charEnd,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $headings
     * @return list<DocxHeadingData>
     */
    public static function manyFromArray(array $headings): array
    {
        return array_map(static fn (array $heading): self => self::fromArray($heading), $headings);
    }

    public function withCharOffsets(int $charStart, int $charEnd): self
    {
        return new self(
            sourceKey: $this->sourceKey,
            documentOrder: $this->documentOrder,
            level: $this->level,
            text: $this->text,
            number: $this->number,
            charStart: $charStart,
            charEnd: $charEnd,
        );
    }
}
