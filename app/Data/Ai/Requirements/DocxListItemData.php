<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * A canonical list-item source element from a parsed DOCX document (see
 * DocumentTextExtractor::extractDocxTextAndTables()) — a body-level (not inside a table),
 * auto-numbered paragraph that is NOT classified as a document heading (see DocxHeadingData).
 * Typical example: a numbered/lettered list paragraph inside body text ("(a) ...", "1. ...").
 *
 * `number` is Word's own reconstructed auto-number, resolved the same way as for headings and
 * table rows — never a hardcoded pattern.
 */
final readonly class DocxListItemData implements JsonSerializable
{
    public function __construct(
        public string $sourceKey,
        public int $documentOrder,
        public int $ilvl,
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
            ilvl: (int) ($data['ilvl'] ?? 0),
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
            'ilvl' => $this->ilvl,
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
     * @param  list<array<string, mixed>>  $listItems
     * @return list<DocxListItemData>
     */
    public static function manyFromArray(array $listItems): array
    {
        return array_map(static fn (array $item): self => self::fromArray($item), $listItems);
    }

    public function withCharOffsets(int $charStart, int $charEnd): self
    {
        return new self(
            sourceKey: $this->sourceKey,
            documentOrder: $this->documentOrder,
            ilvl: $this->ilvl,
            text: $this->text,
            number: $this->number,
            charStart: $charStart,
            charEnd: $charEnd,
        );
    }
}
