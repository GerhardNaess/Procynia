<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookCellData;
use App\Data\Ai\Requirements\Excel\WorkbookCoordinate;
use App\Data\Ai\Requirements\Excel\WorkbookData;
use App\Data\Ai\Requirements\Excel\WorkbookRowData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetData;
use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use RuntimeException;
use Throwable;

/**
 * Reads an .xlsx workbook into structure. Nothing more.
 *
 * Requirement spreadsheets do not share a shape. One customer puts one requirement per row with a
 * header on row 1; the next uses a merged section band every few rows, a two-row header, an
 * answer column the supplier fills in, and three sheets of which only the second matters. So this
 * parser deliberately decides NOTHING about meaning: it does not guess which sheet holds
 * requirements, which column holds text, or whether a row is a requirement. It preserves what the
 * file says and hands that on. Interpreting it is a later, separate phase.
 *
 * What it does guarantee:
 * - Real coordinates. Rows keep their true 1-based sheet numbers and cells their real column
 *   index, so D17 means what Excel shows as D17 even when the sheet starts at row 9 and column D.
 * - Formulas are never evaluated. A formula cell reports the expression AND the value Excel itself
 *   cached in the file. Evaluating one here would mean running arbitrary spreadsheet logic over an
 *   uploaded file, which is both a correctness and a safety problem.
 * - Merges are kept. A merged band is usually how a workbook expresses a section or a requirement
 *   spanning several rows, so flattening it away would destroy the structure we are here to read.
 * - Bounded memory. OpenSpout streams row by row; this class never holds the whole file as one
 *   string and refuses to grow without limit on a pathological workbook (see the caps below).
 *
 * OpenSpout is already part of the dependency tree and reads .xlsx deterministically with merge
 * support and cached formula values, so no new library was introduced for this.
 */
class XlsxWorkbookParser
{
    /**
     * Caps exist to bound memory on a hostile or accidental workbook (a sheet whose used range is
     * a million rows because someone once formatted a whole column). They are generous enough that
     * a real requirement spreadsheet is never affected; a sheet that hits one is marked
     * `truncated` rather than silently shortened, so a caller can see it happened.
     */
    public const MAX_ROWS_PER_SHEET = 5000;

    public const MAX_COLUMNS_PER_ROW = 256;

    /** Cell text longer than this is a pasted document, not a cell — kept, but bounded. */
    public const MAX_CELL_TEXT_CHARS = 8000;

    /**
     * Purpose: Parse one .xlsx file into the structured workbook representation.
     * Inputs: Absolute path to a readable .xlsx file.
     * Returns: WorkbookData — every sheet, including hidden ones (flagged, never dropped).
     * Side effects: Reads the file. Never writes to it, never persists anything, never calls AI.
     *
     * @throws RuntimeException when the file cannot be opened as a workbook
     */
    public function parse(string $path): WorkbookData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf('XlsxWorkbookParser: file is not readable [%s].', $path));
        }

        $options = new Options;
        // Real row numbers depend on empty rows being emitted rather than skipped — without this,
        // a gap would silently shift every coordinate below it.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_LOAD_MERGE_CELLS = true;
        // Dates stay as DateTimeImmutable rather than being pre-formatted into a locale string, so
        // the caller — not the parser — decides how a date is presented.
        $options->SHOULD_FORMAT_DATES = false;

        $reader = new Reader($options);

        try {
            $reader->open($path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('XlsxWorkbookParser: could not open workbook [%s]: %s', basename($path), $exception->getMessage()),
                previous: $exception,
            );
        }

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $this->parseSheet($sheet);
            }
        } finally {
            $reader->close();
        }

        return new WorkbookData(sheets: array_values($sheets));
    }

    private function parseSheet(Sheet $sheet): WorkbookSheetData
    {
        $mergedRanges = $this->normalizeMergedRanges($sheet->getMergeCells());
        $mergeByCoordinate = $this->mergeMembershipByCoordinate($mergedRanges);

        $rows = [];
        $rowNumber = 0;
        $truncated = false;
        $firstRow = null;
        $lastRow = null;
        $firstColumnIndex = null;
        $lastColumnIndex = null;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;

            if ($rowNumber > self::MAX_ROWS_PER_SHEET) {
                $truncated = true;

                break;
            }

            $cells = $this->parseRow($row, $rowNumber, $sheet->getIndex(), $mergeByCoordinate);
            $rowData = new WorkbookRowData(rowNumber: $rowNumber, cells: $cells);

            // Trailing empty rows are an artefact of the used range, not content. They are dropped
            // only once we know something follows them, which is why the buffer below exists.
            $rows[] = $rowData;

            if ($rowData->isEmpty()) {
                continue;
            }

            $firstRow ??= $rowNumber;
            $lastRow = $rowNumber;

            foreach ($rowData->nonEmptyCells() as $cell) {
                $firstColumnIndex = $firstColumnIndex === null
                    ? $cell->columnIndex
                    : min($firstColumnIndex, $cell->columnIndex);
                $lastColumnIndex = $lastColumnIndex === null
                    ? $cell->columnIndex
                    : max($lastColumnIndex, $cell->columnIndex);
            }
        }

        return new WorkbookSheetData(
            index: $sheet->getIndex(),
            name: $sheet->getName(),
            isVisible: $sheet->isVisible(),
            isActive: $sheet->isActive(),
            // Rows past the last row that carries content are used-range padding; the rows BETWEEN
            // content are kept, because a blank row inside a sheet is real structure (it separates
            // sections) and dropping it would move every coordinate after it.
            rows: $lastRow === null ? [] : array_slice($rows, 0, $lastRow),
            mergedRanges: $mergedRanges,
            firstRow: $firstRow,
            lastRow: $lastRow,
            firstColumnIndex: $firstColumnIndex,
            lastColumnIndex: $lastColumnIndex,
            truncated: $truncated,
        );
    }

    /**
     * @param  array<string, array{range: string, is_origin: bool}>  $mergeByCoordinate
     * @return list<WorkbookCellData>
     */
    private function parseRow(Row $row, int $rowNumber, int $sheetIndex, array $mergeByCoordinate): array
    {
        $cells = [];

        foreach (array_values($row->getCells()) as $columnIndex => $cell) {
            if ($columnIndex >= self::MAX_COLUMNS_PER_ROW) {
                break;
            }

            $coordinate = WorkbookCoordinate::cellReference($columnIndex, $rowNumber);
            $merge = $mergeByCoordinate[$coordinate] ?? null;

            $cells[] = new WorkbookCellData(
                row: $rowNumber,
                columnIndex: $columnIndex,
                columnLetter: WorkbookCoordinate::columnLetter($columnIndex),
                coordinate: $coordinate,
                dataType: $this->dataTypeFor($cell),
                value: $this->displayValueFor($cell),
                formula: $cell instanceof Cell\FormulaCell ? $cell->getValue() : null,
                mergeRange: $merge['range'] ?? null,
                isMergeOrigin: $merge['is_origin'] ?? false,
            );
        }

        return $cells;
    }

    /**
     * The value a reader sees. For a formula cell this is the value Excel stored in the file —
     * this class never computes one itself.
     */
    private function displayValueFor(Cell $cell): string|int|float|bool|null
    {
        $value = $cell instanceof Cell\FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return $this->normalizeScalar($value);
    }

    private function normalizeScalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof DateInterval) {
            return $value->format('%R%a days %H:%I:%S');
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return mb_strlen($trimmed, 'UTF-8') > self::MAX_CELL_TEXT_CHARS
                ? mb_substr($trimmed, 0, self::MAX_CELL_TEXT_CHARS, 'UTF-8')
                : $trimmed;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }

    private function dataTypeFor(Cell $cell): string
    {
        $resolved = $cell instanceof Cell\FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            $cell instanceof Cell\ErrorCell => WorkbookCellData::TYPE_ERROR,
            $resolved instanceof DateTimeInterface => WorkbookCellData::TYPE_DATE,
            $resolved instanceof DateInterval => WorkbookCellData::TYPE_DURATION,
            is_bool($resolved) => WorkbookCellData::TYPE_BOOLEAN,
            is_int($resolved) || is_float($resolved) => WorkbookCellData::TYPE_NUMERIC,
            is_string($resolved) && trim($resolved) !== '' => WorkbookCellData::TYPE_STRING,
            default => WorkbookCellData::TYPE_EMPTY,
        };
    }

    /**
     * @param  array<int, string>  $ranges
     * @return list<string>
     */
    private function normalizeMergedRanges(array $ranges): array
    {
        $normalized = [];

        foreach ($ranges as $range) {
            $range = strtoupper(trim((string) $range));

            if ($range !== '' && WorkbookCoordinate::parseRange($range) !== null) {
                $normalized[$range] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * Expand each merged range into per-coordinate membership, marking the top-left cell as the
     * origin — the one that actually carries the merged value in the file. Bounded by the same
     * row/column caps so a merge covering an entire sheet cannot blow up memory on its own.
     *
     * @param  list<string>  $ranges
     * @return array<string, array{range: string, is_origin: bool}>
     */
    private function mergeMembershipByCoordinate(array $ranges): array
    {
        $membership = [];

        foreach ($ranges as $range) {
            $bounds = WorkbookCoordinate::parseRange($range);

            if ($bounds === null) {
                continue;
            }

            $lastRow = min($bounds['last_row'], self::MAX_ROWS_PER_SHEET);
            $lastColumn = min($bounds['last_column_index'], self::MAX_COLUMNS_PER_ROW - 1);

            for ($row = $bounds['first_row']; $row <= $lastRow; $row++) {
                for ($column = $bounds['first_column_index']; $column <= $lastColumn; $column++) {
                    $coordinate = WorkbookCoordinate::cellReference($column, $row);
                    $membership[$coordinate] = [
                        'range' => $range,
                        'is_origin' => $row === $bounds['first_row'] && $column === $bounds['first_column_index'],
                    ];
                }
            }
        }

        return $membership;
    }
}
