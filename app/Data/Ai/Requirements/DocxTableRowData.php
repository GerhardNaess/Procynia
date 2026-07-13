<?php

namespace App\Data\Ai\Requirements;

use JsonSerializable;

/**
 * One data row (never the header row — its cells' `original_header`/`normalized_column_key`
 * already carry the header association) of a parsed DOCX table (see DocxTableData).
 *
 * `sourceRowKey` is the canonical, stable provenance identifier for this row — deterministic
 * from (document, table, row) position, so re-parsing the same document produces the same key.
 * The AI extraction schema requires this key to be echoed back verbatim on any candidate derived
 * from this row (see FullDocumentRequirementExtractionPrompt); it is never regenerated or
 * rewritten downstream — this is the canonical source of truth for row provenance, not the
 * Markdown/plain-text rendering used only for human-readable logs.
 *
 * `charStart`/`charEnd` are positions within the document's flat `extracted_text` (see
 * DocumentTextExtractor::extractDocxTextAndTables()), used to attribute a row to the correct
 * requirement-extraction chunk/window.
 *
 * `sectionNumber`/`sectionTitle` are copied from the owning DocxTableData (see its doc comment) —
 * duplicated onto every row because rows, not tables, are the unit carried through extraction and
 * used for requirement provenance.
 */
final readonly class DocxTableRowData implements JsonSerializable
{
    /**
     * Column-key aliases used to identify which cell holds the row's own requirement
     * number/type, so a validated source_row_key can enrich a candidate with authoritative
     * values instead of trusting the AI's restatement of them. Deliberately conservative —
     * only exact normalized-column-key matches, no fuzzy header guessing.
     */
    private const IDENTIFIER_COLUMN_KEY_ALIASES = [
        'req_no', 'reqno', 'req_nr', 'requirement_no', 'requirement_nr',
        'krav_nr', 'kravnummer', 'krav_nummer', 'kravnr', 'id', 'no', 'nr', 'number',
    ];

    private const TYPE_COLUMN_KEY_ALIASES = [
        'type', 'krav_type', 'kravtype',
    ];

    /**
     * @param  list<DocxTableCellData>  $cells
     */
    public function __construct(
        public string $sourceRowKey,
        public int $tableIndex,
        public int $rowIndex,
        public int $charStart,
        public int $charEnd,
        public array $cells,
        public ?string $sectionNumber = null,
        public ?string $sectionTitle = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sourceRowKey: (string) ($data['source_row_key'] ?? ''),
            tableIndex: (int) ($data['table_index'] ?? 0),
            rowIndex: (int) ($data['row_index'] ?? 0),
            charStart: (int) ($data['char_start'] ?? 0),
            charEnd: (int) ($data['char_end'] ?? 0),
            cells: array_map(
                static fn (array $cell): DocxTableCellData => DocxTableCellData::fromArray($cell),
                is_array($data['cells'] ?? null) ? $data['cells'] : [],
            ),
            sectionNumber: isset($data['section_number']) ? (string) $data['section_number'] : null,
            sectionTitle: isset($data['section_title']) ? (string) $data['section_title'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'source_row_key' => $this->sourceRowKey,
            'table_index' => $this->tableIndex,
            'row_index' => $this->rowIndex,
            'char_start' => $this->charStart,
            'char_end' => $this->charEnd,
            'cells' => array_map(static fn (DocxTableCellData $cell): array => $cell->toArray(), $this->cells),
            'section_number' => $this->sectionNumber,
            'section_title' => $this->sectionTitle,
        ];
    }

    /**
     * The row's own requirement-number cell value (e.g. "2.1.1"), or null when no column is
     * recognized as an identifier column.
     */
    public function identifierCellValue(): ?string
    {
        return $this->cellValueByColumnKeyAliases(self::IDENTIFIER_COLUMN_KEY_ALIASES);
    }

    /**
     * The row's own requirement-type cell value (e.g. "M"), verbatim as written in the source
     * table — distinct from the app's internal requirement_type enum/taxonomy.
     */
    public function typeCellValue(): ?string
    {
        return $this->cellValueByColumnKeyAliases(self::TYPE_COLUMN_KEY_ALIASES);
    }

    private function cellValueByColumnKeyAliases(array $aliases): ?string
    {
        foreach ($this->cells as $cell) {
            if (in_array($cell->normalizedColumnKey, $aliases, true)) {
                $value = trim($cell->value);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The row translated to be relative to a $offset (e.g. a chunk's char_start within the full
     * document), for attributing this row to a requirement-extraction chunk/window.
     */
    public function withOffset(int $offset): self
    {
        return new self(
            sourceRowKey: $this->sourceRowKey,
            tableIndex: $this->tableIndex,
            rowIndex: $this->rowIndex,
            charStart: $this->charStart - $offset,
            charEnd: $this->charEnd - $offset,
            cells: $this->cells,
            sectionNumber: $this->sectionNumber,
            sectionTitle: $this->sectionTitle,
        );
    }

    /**
     * Stamps the final, document-scoped source_row_key once the owning document's DB id is
     * known (parsing happens before the SavedNoticeAiDocument row exists — see
     * DocumentTextExtractor::extractDocxTextAndTables()).
     */
    public function withSourceRowKey(string $sourceRowKey): self
    {
        return new self(
            sourceRowKey: $sourceRowKey,
            tableIndex: $this->tableIndex,
            rowIndex: $this->rowIndex,
            charStart: $this->charStart,
            charEnd: $this->charEnd,
            cells: $this->cells,
            sectionNumber: $this->sectionNumber,
            sectionTitle: $this->sectionTitle,
        );
    }

    /**
     * The canonical structured representation actually sent to the AI as input — deliberately
     * excludes char_start/char_end/table_index/row_index (internal bookkeeping the model has no
     * use for); only source_row_key and the cell header/value pairs it must reason about and
     * echo back verbatim.
     */
    public function toAiPayloadArray(): array
    {
        return [
            'source_row_key' => $this->sourceRowKey,
            'cells' => array_map(
                static fn (DocxTableCellData $cell): array => [
                    'header' => $cell->originalHeader,
                    'column_key' => $cell->normalizedColumnKey,
                    'value' => $cell->value,
                ],
                $this->cells,
            ),
        ];
    }

    /**
     * A compact, human-readable rendering for logs/debugging only — never used as the canonical
     * representation sent to the AI, persisted for provenance, or used for validation.
     */
    public function toDebugLine(): string
    {
        $pairs = array_map(
            static fn (DocxTableCellData $cell): string => sprintf('%s=%s', $cell->originalHeader ?? ('col'.$cell->columnIndex), $cell->value),
            $this->cells,
        );

        return sprintf('[%s] %s', $this->sourceRowKey, implode(' | ', $pairs));
    }
}
