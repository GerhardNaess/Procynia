<?php

namespace App\Services\Suppliers\Import;

use App\Support\PrivateFiles\PrivateFileType;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Throwable;
use ZipArchive;

/**
 * Reads an uploaded Excel file for «Importer leverandører» into the cell values of the columns it
 * recognises. The file is untrusted input:
 *
 *  - it must be a real .xlsx — the content decides, not the name or the browser (PrivateFileType),
 *    and a macro-enabled workbook is refused;
 *  - the package is measured before it is opened: too many parts, or more uncompressed data than a
 *    supplier register can hold, is refused (zip-bomb guard);
 *  - formulas are never evaluated: a formula cell is read as the value Excel cached in the file;
 *  - at most MAX_ROWS suppliers, MAX_COLUMNS columns and MAX_CELL_CHARS characters a cell are read;
 *  - a library error is logged and reported as «the file could not be read», never shown.
 *
 * The sheet read is the one named like the template's («Leverandører» / «Suppliers»), or else the
 * first. The header is the first row, among the first HEADER_SCAN_ROWS, that names the supplier.
 * Row numbers are the sheet's own, as Excel shows them. Rows with nothing in a recognised column are
 * skipped. Nothing is written anywhere.
 */
class SupplierImportReader
{
    public const MAX_KILOBYTES = 5120;

    public const MAX_ROWS = 1000;

    public const MAX_COLUMNS = 80;

    /** One more than the longest field (note, 10 000) so an overlong cell fails its own rule. */
    public const MAX_CELL_CHARS = 10001;

    private const MAX_PACKAGE_ENTRIES = 1000;

    private const MAX_UNCOMPRESSED_BYTES = 52_428_800;

    private const HEADER_SCAN_ROWS = 10;

    /** The template's data sheet, in both languages (normalized). */
    private const DATA_SHEET_NAMES = ['leverandører', 'leverandorer', 'suppliers'];

    /**
     * @return array{rows: list<array{row: int, cells: array<string, string>}>, ignored_columns: list<string>}
     *
     * @throws SupplierImportFileException
     */
    public function read(string $path, string $originalName): array
    {
        if (PrivateFileType::detect($path, $originalName) !== 'xlsx') {
            throw new SupplierImportFileException('not_xlsx');
        }

        $this->guardPackage($path);

        $options = new Options;
        // Real row numbers need the empty rows too.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new Reader($options);

        try {
            $reader->open($path);

            return $this->readSheet($this->dataSheet($reader));
        } catch (SupplierImportFileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Supplier import: the workbook could not be read.', ['exception' => $exception->getMessage()]);

            throw new SupplierImportFileException('unreadable');
        } finally {
            $reader->close();
        }
    }

    private function guardPackage(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SupplierImportFileException('unreadable');
        }

        try {
            if ($zip->numFiles > self::MAX_PACKAGE_ENTRIES) {
                throw new SupplierImportFileException('unreadable');
            }

            $total = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $total += is_array($stat) ? (int) $stat['size'] : 0;

                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new SupplierImportFileException('too_large_content');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function dataSheet(Reader $reader): Sheet
    {
        $first = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $first ??= $sheet;

            if (in_array(mb_strtolower(trim($sheet->getName())), self::DATA_SHEET_NAMES, true)) {
                return $sheet;
            }
        }

        if ($first === null) {
            throw new SupplierImportFileException('no_rows');
        }

        return $first;
    }

    /**
     * @return array{rows: list<array{row: int, cells: array<string, string>}>, ignored_columns: list<string>}
     */
    private function readSheet(Sheet $sheet): array
    {
        $headerMap = SupplierImportColumns::headerMap();
        $columns = null;
        $ignored = [];
        $rows = [];
        $rowNumber = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            $texts = [];

            foreach (array_slice($row->getCells(), 0, self::MAX_COLUMNS, true) as $index => $cell) {
                $texts[(int) $index] = $this->text($cell);
            }

            if ($columns === null) {
                if ($rowNumber > self::HEADER_SCAN_ROWS) {
                    throw new SupplierImportFileException('missing_columns', ['columns' => $this->headerList(SupplierImportColumns::REQUIRED)]);
                }

                [$columns, $ignored] = $this->header($texts, $headerMap);

                continue;
            }

            $cells = [];

            foreach ($columns as $index => $field) {
                $value = $texts[$index] ?? '';

                if ($value !== '') {
                    $cells[$field] = $value;
                }
            }

            if ($cells === []) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new SupplierImportFileException('too_many_rows', ['max' => self::MAX_ROWS]);
            }

            $rows[] = ['row' => $rowNumber, 'cells' => $cells];
        }

        if ($columns === null) {
            throw new SupplierImportFileException('missing_columns', ['columns' => $this->headerList(SupplierImportColumns::REQUIRED)]);
        }

        if ($rows === []) {
            throw new SupplierImportFileException('no_rows');
        }

        return ['rows' => $rows, 'ignored_columns' => $ignored];
    }

    /**
     * The row as the header, when it names the supplier column; null columns otherwise, so the next
     * row is tried.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, string>  $headerMap
     * @return array{0: array<int, string>|null, 1: list<string>}
     */
    private function header(array $texts, array $headerMap): array
    {
        $columns = [];
        $ignored = [];

        foreach ($texts as $index => $text) {
            if ($text === '') {
                continue;
            }

            $field = $headerMap[SupplierImportColumns::normalize($text)] ?? null;

            if ($field === null) {
                $ignored[] = mb_substr($text, 0, 100);

                continue;
            }

            if (in_array($field, $columns, true)) {
                throw new SupplierImportFileException('duplicate_column', ['column' => SupplierImportColumns::header($field)]);
            }

            $columns[$index] = $field;
        }

        if (! in_array('name', $columns, true)) {
            return [null, []];
        }

        $missing = array_values(array_diff(SupplierImportColumns::REQUIRED, $columns));

        if ($missing !== []) {
            throw new SupplierImportFileException('missing_columns', ['columns' => $this->headerList($missing)]);
        }

        return [$columns, $ignored];
    }

    /** @param  list<string>  $fields */
    private function headerList(array $fields): string
    {
        return implode(', ', array_map(fn (string $field): string => '«'.SupplierImportColumns::header($field).'»', $fields));
    }

    /** The cell as text: trimmed, one line of ordinary spaces, never a formula. */
    private function text(Cell $cell): string
    {
        $value = match (true) {
            $cell instanceof FormulaCell => $cell->getComputedValue(),
            $cell instanceof ErrorCell => null,
            default => $cell->getValue(),
        };

        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            // A number typed into Excel — an organisation or phone number — is read without «.0».
            is_float($value) => floor($value) === $value && abs($value) < 1e15
                ? sprintf('%.0f', $value)
                : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof DateInterval => '',
            default => (string) $value,
        };

        // Control characters out, non-breaking and other spaces as one ordinary space, at most
        // MAX_CELL_CHARS. Line breaks are kept for the long texts.
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $text));
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);

        return mb_substr(trim($text), 0, self::MAX_CELL_CHARS);
    }
}
