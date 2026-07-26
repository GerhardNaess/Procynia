<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;

/**
 * Builds genuine, deterministic "table" content blocks (and the deterministic per-cell claim
 * payloads derived from them) for simple, rectangular Word tables — never sent through the page-
 * generation AI for paraphrasing, so a table's rows/columns/cell values reach the Wiki page and
 * its claims byte-for-byte as written in the source document (see docs/chunking-strategy.md and
 * CLAUDE.md: "AI supports structure, it does not replace it").
 *
 * A table is attached to a generated page only when the AI-authored blocks for that page actually
 * cited at least one of its rows (via source_element_type=table_row, source_row_key matching
 * "tbl{n}-row{m}" — the existing, unmodified convention from
 * EnterpriseWikiDocumentSourceElementService) — this ties table rendering to the same relevance
 * signal already driving ordinary prose generation, instead of dumping every table from a
 * multi-page document onto every page.
 *
 * Scope: Fase 1 — simple rectangular tables only (first row = header, one data row per row,
 * plain-text cells). Merged cells, nested tables, and vertical headers are out of scope here (see
 * KnowledgeDocumentStructureParser for a separate, Knowledge-Base-only parser that already handles
 * those for a different pipeline).
 */
class EnterpriseWikiTableBlockBuilder
{
    private const TABLE_ROW_KEY_PATTERN = '/^tbl(\d+)-row\d+$/';

    /**
     * @param  list<array<string, mixed>>  $contentBlocks  AI-generated blocks already built by
     *                                                     EnterpriseWikiPageContentBlockService.
     * @return list<int> distinct table indexes referenced anywhere in $contentBlocks
     */
    public function referencedTableIndexes(array $contentBlocks): array
    {
        $indexes = [];

        foreach ($contentBlocks as $block) {
            foreach ((array) ($block['source_elements'] ?? []) as $element) {
                if (! is_array($element) || ($element['source_element_type'] ?? null) !== EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW) {
                    continue;
                }

                $rowKey = (string) ($element['source_row_key'] ?? '');

                if (preg_match(self::TABLE_ROW_KEY_PATTERN, $rowKey, $matches) === 1) {
                    $tableIndex = (int) $matches[1];

                    if (! in_array($tableIndex, $indexes, true)) {
                        $indexes[] = $tableIndex;
                    }
                }
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * @param  list<DocxTableData>  $tables  full parse result, see
     *                                       EnterpriseWikiDocumentSourceElementService::tablesForDocument()
     * @param  list<int>  $tableIndexes  which tables (by DocxTableData::$tableIndex) to build blocks for
     * @return list<array<string, mixed>> one content block per requested table, positioned
     *                                    starting at $startingPosition, in table order
     */
    public function buildTableBlocks(EnterpriseWikiDocument $document, array $tables, array $tableIndexes, int $startingPosition): array
    {
        if ($tableIndexes === []) {
            return [];
        }

        $tablesByIndex = [];

        foreach ($tables as $table) {
            $tablesByIndex[$table->tableIndex] = $table;
        }

        $blocks = [];
        $position = $startingPosition;

        foreach ($tableIndexes as $tableIndex) {
            $table = $tablesByIndex[$tableIndex] ?? null;

            if ($table === null || $table->rows === []) {
                continue;
            }

            $blocks[] = $this->buildTableBlock($document, $table, $position);
            $position++;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTableBlock(EnterpriseWikiDocument $document, DocxTableData $table, int $position): array
    {
        $tableData = $this->buildTableData($table);
        $markdown = $this->renderMarkdownTable($tableData);
        $pageReference = $this->tablePageReference($table);

        $sourceElements = array_map(
            fn (DocxTableRowData $row): array => [
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_hash' => $document->file_hash_sha256 ?? '',
                'document_version_hash' => $document->file_hash_sha256 ?? '',
                'source_element_key' => $row->sourceRowKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
                'source_row_key' => $row->sourceRowKey,
                'source_excerpt' => $this->renderRowText($row->cells),
                'page_reference' => $this->tablePageReference($table),
            ],
            $table->rows,
        );

        return [
            'block_key' => sprintf('table-block-%04d', $table->tableIndex + 1),
            'position' => $position,
            'block_type' => 'table',
            'markdown' => $markdown,
            'table_data' => $tableData,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256 ?? '',
            'document_version_hash' => $document->file_hash_sha256 ?? '',
            'source_element_key' => $table->rows[0]->sourceRowKey,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
            'source_row_key' => $table->rows[0]->sourceRowKey,
            'source_excerpt' => $this->renderRowText($table->rows[0]->cells),
            'page_reference' => $pageReference,
            'source_elements' => $sourceElements,
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTableData(DocxTableData $table): array
    {
        return [
            'table_index' => $table->tableIndex,
            'section_number' => $table->sectionNumber,
            'section_title' => $table->sectionTitle,
            'headers' => $table->headerLabels,
            'rows' => array_map(
                fn (DocxTableRowData $row): array => [
                    'row_key' => $row->sourceRowKey,
                    'label' => $this->rowLabel($row),
                    'cells' => array_map(
                        static fn (DocxTableCellData $cell): array => [
                            'column_index' => $cell->columnIndex,
                            'header' => $cell->originalHeader,
                            'column_key' => $cell->normalizedColumnKey,
                            'value' => $cell->value,
                        ],
                        $row->cells,
                    ),
                ],
                $table->rows,
            ),
        ];
    }

    /**
     * The row's own identifying label, used both as the "row" component of a precise citation
     * (e.g. "Rad «Administrert klient»") and as the subject of each deterministic per-cell claim.
     * Prefers a recognized identifier column (see DocxTableRowData::identifierCellValue() —
     * req-number-style columns), then falls back to the first cell's value, matching how a human
     * reader would naturally identify a row in a simple table with no explicit id column.
     */
    private function rowLabel(DocxTableRowData $row): string
    {
        $identifier = $row->identifierCellValue();

        if ($identifier !== null) {
            return $identifier;
        }

        $firstCellValue = trim((string) ($row->cells[0]->value ?? ''));

        return $firstCellValue !== '' ? $firstCellValue : sprintf('Rad %d', $row->rowIndex + 1);
    }

    /**
     * One deterministic claim per (row, non-label column) cell — never AI-authored, so provenance
     * is exact by construction rather than by the AI faithfully restating it. Cells with an empty
     * value are skipped (nothing to claim), as is the row's own label column (the label is the
     * claim's subject, not a fact about itself).
     *
     * @param  array<string, mixed>  $tableBlock  a block built by buildTableBlocks() above
     * @return list<array{claim_text: string, source_row_key: string, source_cell_key: string, source_column_key: ?string, page_reference: string, excerpt: string}>
     */
    public function tableClaimPayloads(EnterpriseWikiDocument $document, array $tableBlock): array
    {
        $tableData = (array) ($tableBlock['table_data'] ?? []);
        $tableIndex = (int) ($tableData['table_index'] ?? 0);
        $payloads = [];

        foreach ((array) ($tableData['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowKey = (string) ($row['row_key'] ?? '');
            $label = (string) ($row['label'] ?? '');
            $cells = (array) ($row['cells'] ?? []);

            if ($rowKey === '' || $label === '') {
                continue;
            }

            // The label's own source column never generates a claim about itself.
            $labelColumnIndex = $this->labelColumnIndex($cells, $label);

            foreach ($cells as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $columnIndex = (int) ($cell['column_index'] ?? -1);

                if ($columnIndex === $labelColumnIndex) {
                    continue;
                }

                $header = trim((string) ($cell['header'] ?? ''));
                $value = trim((string) ($cell['value'] ?? ''));

                if ($header === '' || $value === '') {
                    continue;
                }

                $cellKey = sprintf('%s-col%d', $rowKey, $columnIndex);

                $payloads[] = [
                    'claim_text' => sprintf('%s: %s er %s.', $label, $header, $value),
                    'source_row_key' => $rowKey,
                    'source_cell_key' => $cellKey,
                    'source_column_key' => is_string($cell['column_key'] ?? null) ? $cell['column_key'] : null,
                    'page_reference' => sprintf(
                        '%s → Tabell %d → Rad «%s» → Kolonne «%s»',
                        $document->original_filename,
                        $tableIndex + 1,
                        $label,
                        $header,
                    ),
                    'excerpt' => sprintf('%s: %s', $header, $value),
                ];
            }
        }

        return $payloads;
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     */
    private function labelColumnIndex(array $cells, string $label): ?int
    {
        foreach ($cells as $cell) {
            if (is_array($cell) && trim((string) ($cell['value'] ?? '')) === $label) {
                return (int) ($cell['column_index'] ?? -1);
            }
        }

        return null;
    }

    /**
     * @param  array<int, DocxTableCellData>  $cells
     */
    private function renderRowText(array $cells): string
    {
        $parts = [];

        foreach ($cells as $cell) {
            $header = trim((string) ($cell->originalHeader ?? ''));
            $value = trim((string) ($cell->value ?? ''));

            if ($header === '' && $value === '') {
                continue;
            }

            $parts[] = $header !== '' ? sprintf('%s: %s', $header, $value) : $value;
        }

        return trim(implode(' | ', $parts));
    }

    private function tablePageReference(DocxTableData $table): string
    {
        $parts = [];

        if ($table->sectionNumber !== null && $table->sectionNumber !== '') {
            $parts[] = 'Avsnitt '.$table->sectionNumber;
        } elseif ($table->sectionTitle !== null && $table->sectionTitle !== '') {
            $parts[] = $table->sectionTitle;
        }

        $parts[] = 'Tabell '.($table->tableIndex + 1);

        return implode(' · ', $parts);
    }

    /**
     * GFM-style Markdown table — the fallback plain-text representation consumed by
     * markdown-only readers (RequirementWikiPageReader, react-markdown without remark-gfm's table
     * plugin still renders this legibly as literal text, and remark-gfm-aware consumers render it
     * as a real table). Pipe characters and newlines inside cell values are escaped/collapsed so
     * they can never break the table's row structure.
     *
     * @param  array<string, mixed>  $tableData
     */
    private function renderMarkdownTable(array $tableData): string
    {
        $headers = array_map([$this, 'escapeMarkdownCell'], (array) ($tableData['headers'] ?? []));
        $rows = (array) ($tableData['rows'] ?? []);

        if ($headers === []) {
            return '';
        }

        $lines = [
            '| '.implode(' | ', $headers).' |',
            '| '.implode(' | ', array_fill(0, count($headers), '---')).' |',
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $valuesByColumn = [];

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                if (is_array($cell)) {
                    $valuesByColumn[(int) ($cell['column_index'] ?? count($valuesByColumn))] = (string) ($cell['value'] ?? '');
                }
            }

            $cellValues = [];

            for ($i = 0; $i < count($headers); $i++) {
                $cellValues[] = $this->escapeMarkdownCell($valuesByColumn[$i] ?? '');
            }

            $lines[] = '| '.implode(' | ', $cellValues).' |';
        }

        return implode("\n", $lines);
    }

    private function escapeMarkdownCell(string $value): string
    {
        $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);

        return str_replace('|', '\\|', trim($value));
    }
}
