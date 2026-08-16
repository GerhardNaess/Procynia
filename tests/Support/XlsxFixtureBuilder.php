<?php

namespace Tests\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Builds small, throwaway .xlsx files for the workbook-parser tests.
 *
 * Written rather than committed as binaries: a fixture whose content is visible in the test that
 * uses it is far easier to trust than an opaque blob, and each test can shape exactly the edge it
 * cares about (a merge band, a hidden sheet, a gap in the rows) without a pile of near-identical
 * files. No production data is involved.
 */
class XlsxFixtureBuilder
{
    /** @var list<string> */
    private array $createdFiles = [];

    /**
     * @param  array<string, list<list<Cell|string|int|float|bool|null>>>  $sheets  sheet name => rows of cell values
     * @param  list<array{sheet: string, range: array{int, int, int, int}}>  $merges  [topColumnIndex, topRow, bottomColumnIndex, bottomRow] — columns 0-based, rows 1-based, matching OpenSpout's own mergeCells() signature
     * @param  list<string>  $hiddenSheets
     */
    public function build(array $sheets, array $merges = [], array $hiddenSheets = []): string
    {
        $path = sprintf('%s/procynia-xlsx-%s.xlsx', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        $this->createdFiles[] = $path;

        $options = new Options;

        foreach ($merges as $merge) {
            [$topColumn, $topRow, $bottomColumn, $bottomRow] = $merge['range'];
            $options->mergeCells($topColumn, $topRow, $bottomColumn, $bottomRow);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        $first = true;

        foreach ($sheets as $sheetName => $rows) {
            if ($first) {
                $writer->getCurrentSheet()->setName($sheetName);
                $first = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent()->setName($sheetName);
            }

            if (in_array($sheetName, $hiddenSheets, true)) {
                $writer->getCurrentSheet()->setIsVisible(false);
            }

            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(
                    static fn ($value): Cell => $value instanceof Cell ? $value : Cell::fromValue($value),
                    $row,
                )));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Inject cached formula results into a written workbook.
     *
     * OpenSpout's writer emits <f>SUM(A1:B1)</f> with no <v>, but Excel always stores the last
     * computed value alongside the formula — and reading that cached value, rather than evaluating
     * anything, is precisely the behaviour under test. So the fixture is patched to look like a
     * real Excel file.
     *
     * @param  array<string, string>  $valuesByCoordinate  e.g. ['C1' => '5']
     */
    public function withCachedFormulaValues(string $path, array $valuesByCoordinate, string $sheetEntry = 'xl/worksheets/sheet1.xml'): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('XlsxFixtureBuilder: could not reopen [%s].', $path));
        }

        $xml = (string) $zip->getFromName($sheetEntry);

        foreach ($valuesByCoordinate as $coordinate => $value) {
            $xml = preg_replace(
                sprintf('/(<c r="%s"[^>]*>)(<f>[^<]*<\/f>)(<\/c>)/', preg_quote($coordinate, '/')),
                sprintf('$1$2<v>%s</v>$3', $value),
                $xml,
                1,
            );
        }

        $zip->addFromString($sheetEntry, $xml);
        $zip->close();

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->createdFiles = [];
    }
}
