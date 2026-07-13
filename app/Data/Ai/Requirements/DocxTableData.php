<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * One deterministically-parsed <w:tbl> from a DOCX document (see
 * DocumentTextExtractor::extractDocxTextAndTables()). `headerLabels` are the raw header row cell
 * texts (verbatim, for reference/debugging) — the header association per data cell already lives
 * on DocxTableCellData::$originalHeader, so consumers never need to re-join rows against
 * $headerLabels by column index.
 */
final readonly class DocxTableData implements JsonSerializable
{
    /**
     * @param  list<string>  $headerLabels
     * @param  list<DocxTableRowData>  $rows
     */
    public function __construct(
        public int $tableIndex,
        public array $headerLabels,
        public array $rows,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            tableIndex: (int) ($data['table_index'] ?? 0),
            headerLabels: array_values(array_map('strval', is_array($data['header_labels'] ?? null) ? $data['header_labels'] : [])),
            rows: array_map(
                static fn (array $row): DocxTableRowData => DocxTableRowData::fromArray($row),
                is_array($data['rows'] ?? null) ? $data['rows'] : [],
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'table_index' => $this->tableIndex,
            'header_labels' => $this->headerLabels,
            'rows' => array_map(static fn (DocxTableRowData $row): array => $row->toArray(), $this->rows),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<DocxTableData>
     */
    public static function manyFromArray(array $tables): array
    {
        return array_map(static fn (array $table): self => self::fromArray($table), $tables);
    }

    /**
     * Stamps every row's canonical, document-scoped source_row_key (see
     * DocxTableRowData::$sourceRowKey doc comment) — called once, right after the owning
     * SavedNoticeAiDocument row is created and its id is known.
     */
    public function withDocumentId(int $documentId): self
    {
        return new self(
            tableIndex: $this->tableIndex,
            headerLabels: $this->headerLabels,
            rows: array_map(
                fn (DocxTableRowData $row): DocxTableRowData => $row->withSourceRowKey(
                    sprintf('doc%d-tbl%d-row%d', $documentId, $this->tableIndex, $row->rowIndex),
                ),
                $this->rows,
            ),
        );
    }

    /**
     * @param  list<DocxTableData>  $tables
     * @return list<DocxTableData>
     */
    public static function manyWithDocumentId(array $tables, int $documentId): array
    {
        return array_map(static fn (self $table): self => $table->withDocumentId($documentId), $tables);
    }
}
