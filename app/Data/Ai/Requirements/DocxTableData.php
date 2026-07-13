<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * One deterministically-parsed <w:tbl> from a DOCX document (see
 * DocumentTextExtractor::extractDocxTextAndTables()). `headerLabels` are the raw header row cell
 * texts (verbatim, for reference/debugging) — the header association per data cell already lives
 * on DocxTableCellData::$originalHeader, so consumers never need to re-join rows against
 * $headerLabels by column index.
 *
 * `sectionNumber`/`sectionTitle` are the nearest preceding H1/H2 heading (split into a leading
 * numbering prefix and the remaining title text, e.g. "2.1" / "Buying responsibility, not
 * activities") that was in effect when this table started — null when no heading precedes it.
 * The same values are also copied onto every row (see DocxTableRowData) since rows, not tables,
 * are the unit passed to the AI and used for requirement provenance.
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
        public ?string $sectionNumber = null,
        public ?string $sectionTitle = null,
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
            sectionNumber: isset($data['section_number']) ? (string) $data['section_number'] : null,
            sectionTitle: isset($data['section_title']) ? (string) $data['section_title'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'table_index' => $this->tableIndex,
            'header_labels' => $this->headerLabels,
            'rows' => array_map(static fn (DocxTableRowData $row): array => $row->toArray(), $this->rows),
            'section_number' => $this->sectionNumber,
            'section_title' => $this->sectionTitle,
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
            sectionNumber: $this->sectionNumber,
            sectionTitle: $this->sectionTitle,
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
