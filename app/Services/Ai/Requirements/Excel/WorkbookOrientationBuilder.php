<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookCellData;
use App\Data\Ai\Requirements\Excel\WorkbookCoordinate;
use App\Data\Ai\Requirements\Excel\WorkbookData;
use App\Data\Ai\Requirements\Excel\WorkbookRowData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetData;

/**
 * Turns a parsed workbook into a compact description a model can reason about.
 *
 * A requirement spreadsheet can be thousands of rows; sending it whole would be expensive, slow,
 * and — worse — would bury the structure under the content. What actually reveals how a workbook
 * is organised is the top of each sheet (headings, decorative rows, the header band), a look at
 * what the body rows are shaped like, and per-column statistics. So the orientation carries those
 * and says explicitly when it left something out.
 *
 * This layer DESCRIBES, it never INTERPRETS. There is deliberately nothing here that says a column
 * called "Krav" holds requirement text, that "M" means mandatory, or that one row is one
 * requirement — those are exactly the customer-specific assumptions the discovery phase exists to
 * avoid. The model gets facts about shape and is asked to draw the conclusions.
 *
 * Sampling is positional and fixed, never random: the same workbook always produces the same
 * orientation, so a discovery result can be reproduced and compared.
 */
class WorkbookOrientationBuilder
{
    /** Rows from the top of a sheet, where headings, decorative rows and the header band live. */
    public const HEAD_ROWS = 12;

    /** Body rows sampled at an even stride, to show what a typical data row looks like. */
    public const SAMPLE_ROWS = 8;

    /** Trailing rows, where totals and appended notes tend to sit. */
    public const TAIL_ROWS = 3;

    /** Cell text is truncated for orientation only — the parsed workbook keeps the full value. */
    public const MAX_CELL_PREVIEW_CHARS = 120;

    /** Columns beyond this are summarized by statistics only, never cell by cell. */
    public const MAX_COLUMNS = 40;

    /**
     * Purpose: Build the compact, deterministic orientation for one workbook.
     * Inputs: The parsed workbook.
     * Returns: {sheets: [...], totals: {...}} — safe to send to a model.
     * Side effects: None.
     *
     * @return array<string, mixed>
     */
    public function build(WorkbookData $workbook): array
    {
        $sheets = array_map(fn (WorkbookSheetData $sheet): array => $this->buildSheet($sheet), $workbook->sheets);

        return [
            'sheets' => $sheets,
            'totals' => [
                'sheet_count' => count($sheets),
                'sampled_sheet_count' => count(array_filter($sheets, static fn (array $sheet): bool => $sheet['sampling']['is_sampled'])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function buildSheet(WorkbookSheetData $sheet): array
    {
        $rows = $sheet->rows;
        $contentRows = array_values(array_filter($rows, static fn (WorkbookRowData $row): bool => ! $row->isEmpty()));
        $sampledRowNumbers = $this->sampleRowNumbers($rows);

        return [
            'sheet_index' => $sheet->index,
            'sheet_name' => $sheet->name,
            // Reported rather than acted on: whether a hidden sheet counts is a decision for the
            // model and, ultimately, for the product — not something to silently drop here.
            'is_visible' => $sheet->isVisible,
            'is_active' => $sheet->isActive,
            'dimensions' => [
                'ref' => $sheet->dimensionRef(),
                'first_row' => $sheet->firstRow,
                'last_row' => $sheet->lastRow,
                'first_column_index' => $sheet->firstColumnIndex,
                'last_column_index' => $sheet->lastColumnIndex,
                'used_row_count' => count($rows),
                'non_empty_row_count' => count($contentRows),
            ],
            // Merged ranges are how a workbook usually draws a section band or a header spanning
            // several columns, so they are given in full — they are few, and they carry structure.
            'merged_ranges' => $sheet->mergedRanges,
            'columns' => $this->columnProfiles($sheet),
            'row_fill_pattern' => $this->rowFillPattern($rows),
            'sampling' => [
                'is_sampled' => count($sampledRowNumbers) < count($rows),
                'parser_truncated_sheet' => $sheet->truncated,
                'included_row_numbers' => $sampledRowNumbers,
                'strategy' => sprintf('first %d, every-nth up to %d, last %d', self::HEAD_ROWS, self::SAMPLE_ROWS, self::TAIL_ROWS),
            ],
            'rows' => $this->rowPreviews($rows, $sampledRowNumbers),
        ];
    }

    /**
     * Deterministic row selection: the head (structure), an evenly-strided sample of the body
     * (what a typical row looks like), and the tail (totals/notes). Always the same rows for the
     * same sheet.
     *
     * @param  list<WorkbookRowData>  $rows
     * @return list<int>
     */
    private function sampleRowNumbers(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $rowNumbers = array_map(static fn (WorkbookRowData $row): int => $row->rowNumber, $rows);
        $total = count($rowNumbers);
        $selected = array_slice($rowNumbers, 0, self::HEAD_ROWS);
        $tail = array_slice($rowNumbers, max(self::HEAD_ROWS, $total - self::TAIL_ROWS));

        $bodyStart = self::HEAD_ROWS;
        $bodyEnd = $total - count($tail);

        if ($bodyEnd > $bodyStart) {
            $bodyLength = $bodyEnd - $bodyStart;
            $stride = max(1, (int) ceil($bodyLength / self::SAMPLE_ROWS));

            for ($offset = $bodyStart; $offset < $bodyEnd; $offset += $stride) {
                $selected[] = $rowNumbers[$offset];
            }
        }

        $selected = array_values(array_unique([...$selected, ...$tail]));
        sort($selected);

        return $selected;
    }

    /**
     * @param  list<WorkbookRowData>  $rows
     * @param  list<int>  $rowNumbers
     * @return list<array<string, mixed>>
     */
    private function rowPreviews(array $rows, array $rowNumbers): array
    {
        $wanted = array_flip($rowNumbers);
        $previews = [];

        foreach ($rows as $row) {
            if (! isset($wanted[$row->rowNumber])) {
                continue;
            }

            $cells = [];

            foreach ($row->cells as $cell) {
                if ($cell->columnIndex >= self::MAX_COLUMNS || $cell->isEmpty()) {
                    continue;
                }

                $cells[] = [
                    'coordinate' => $cell->coordinate,
                    'column_letter' => $cell->columnLetter,
                    'column_index' => $cell->columnIndex,
                    'data_type' => $cell->dataType,
                    'value' => $this->preview($cell),
                    // A cell that only continues a merge holds no value of its own; saying so keeps
                    // the model from reading a blank as missing data.
                    'merge_range' => $cell->mergeRange,
                    'is_merge_continuation' => $cell->isMergeContinuation(),
                ];
            }

            $previews[] = [
                'row_number' => $row->rowNumber,
                'is_empty' => $row->isEmpty(),
                'non_empty_cell_count' => count($row->nonEmptyCells()),
                'cells' => $cells,
            ];
        }

        return $previews;
    }

    /**
     * Per-column shape: how often it is filled, which types occur, and a couple of sample values.
     * This is what lets a model tell a requirement-text column (long strings, nearly always
     * filled) from a code column (short, repeating) or an answer column (mostly empty) without
     * anyone hardcoding what those columns are called.
     *
     * @return list<array<string, mixed>>
     */
    private function columnProfiles(WorkbookSheetData $sheet): array
    {
        $lastColumn = min($sheet->lastColumnIndex ?? -1, self::MAX_COLUMNS - 1);

        if ($lastColumn < 0) {
            return [];
        }

        $profiles = [];

        for ($columnIndex = 0; $columnIndex <= $lastColumn; $columnIndex++) {
            $filled = 0;
            $types = [];
            $lengths = [];
            $samples = [];

            foreach ($sheet->rows as $row) {
                $cell = $this->cellInColumn($row, $columnIndex);

                if ($cell === null || $cell->isEmpty()) {
                    continue;
                }

                $filled++;
                $types[$cell->dataType] = ($types[$cell->dataType] ?? 0) + 1;
                $text = (string) $cell->value;
                $lengths[] = mb_strlen($text, 'UTF-8');

                if (count($samples) < 3) {
                    $samples[] = $this->preview($cell);
                }
            }

            $usedRowCount = count($sheet->rows);

            $profiles[] = [
                'column_index' => $columnIndex,
                'column_letter' => WorkbookCoordinate::columnLetter($columnIndex),
                'filled_cell_count' => $filled,
                'fill_ratio' => $usedRowCount > 0 ? round($filled / $usedRowCount, 3) : 0.0,
                'data_types' => $types,
                'max_text_length' => $lengths === [] ? 0 : max($lengths),
                'median_text_length' => $this->median($lengths),
                'sample_values' => $samples,
            ];
        }

        return $profiles;
    }

    /**
     * A compact map of which rows carry content, so the model can see rhythm — a header band, a
     * blank line between sections, a block of data — without receiving every row.
     *
     * @param  list<WorkbookRowData>  $rows
     * @return array<string, mixed>
     */
    private function rowFillPattern(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[] = ['row' => $row->rowNumber, 'cells' => count($row->nonEmptyCells())];
        }

        return [
            'legend' => 'non-empty cell count per row, in row order',
            'rows' => $counts,
        ];
    }

    private function cellInColumn(WorkbookRowData $row, int $columnIndex): ?WorkbookCellData
    {
        foreach ($row->cells as $cell) {
            if ($cell->columnIndex === $columnIndex) {
                return $cell;
            }
        }

        return null;
    }

    private function preview(WorkbookCellData $cell): string|int|float|bool|null
    {
        if (! is_string($cell->value)) {
            return $cell->value;
        }

        return mb_strlen($cell->value, 'UTF-8') > self::MAX_CELL_PREVIEW_CHARS
            ? mb_substr($cell->value, 0, self::MAX_CELL_PREVIEW_CHARS, 'UTF-8').'…'
            : $cell->value;
    }

    /** @param  list<int>  $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? (int) round(($values[$middle - 1] + $values[$middle]) / 2)
            : $values[$middle];
    }
}
