<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * A parsed .xlsx workbook, kept as structure rather than flattened into prose.
 *
 * This is deliberately a representation, not an interpretation: nothing here decides what a
 * requirement is, which column holds its text, or whether one row equals one requirement. Those
 * questions belong to the structure-discovery phase that runs after this one, and answering them
 * here would bake one customer's spreadsheet layout into the parser.
 */
final readonly class WorkbookData implements JsonSerializable
{
    /** @param  list<WorkbookSheetData>  $sheets */
    public function __construct(
        public array $sheets,
    ) {}

    /** @return list<WorkbookSheetData> */
    public function visibleSheets(): array
    {
        return array_values(array_filter($this->sheets, static fn (WorkbookSheetData $sheet): bool => $sheet->isVisible));
    }

    public function sheetByIndex(int $index): ?WorkbookSheetData
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->index === $index) {
                return $sheet;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'sheets' => array_map(static fn (WorkbookSheetData $sheet): array => $sheet->toArray(), $this->sheets),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
