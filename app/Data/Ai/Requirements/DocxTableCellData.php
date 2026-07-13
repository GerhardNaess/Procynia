<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * One cell within a data row of a parsed DOCX table (see DocxTableRowData). `originalHeader`
 * and `value` are preserved verbatim from the source document; `normalizedColumnKey` is a
 * separate, machine-friendly derivation (see DocxTableParser::normalizeColumnKey()) — the two
 * are never conflated, so downstream consumers can always recover the exact original header
 * text a customer used, independent of how it's addressed programmatically.
 */
final readonly class DocxTableCellData implements JsonSerializable
{
    public function __construct(
        public int $columnIndex,
        public ?string $originalHeader,
        public string $normalizedColumnKey,
        public string $value,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            columnIndex: (int) ($data['column_index'] ?? 0),
            originalHeader: isset($data['original_header']) && is_string($data['original_header']) ? $data['original_header'] : null,
            normalizedColumnKey: (string) ($data['normalized_column_key'] ?? ''),
            value: (string) ($data['value'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'column_index' => $this->columnIndex,
            'original_header' => $this->originalHeader,
            'normalized_column_key' => $this->normalizedColumnKey,
            'value' => $this->value,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
