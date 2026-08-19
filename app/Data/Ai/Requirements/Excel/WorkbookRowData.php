<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * One worksheet row, keyed by its real 1-based sheet row number — not by its position in the
 * parsed output. A sheet whose first data starts at row 9 yields rows numbered from 9, so a
 * coordinate like D17 always means what Excel shows as D17.
 */
final readonly class WorkbookRowData implements JsonSerializable
{
    /** @param  list<WorkbookCellData>  $cells */
    public function __construct(
        public int $rowNumber,
        public array $cells,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->cells as $cell) {
            if (! $cell->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<WorkbookCellData> */
    public function nonEmptyCells(): array
    {
        return array_values(array_filter($this->cells, static fn (WorkbookCellData $cell): bool => ! $cell->isEmpty()));
    }

    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'cells' => array_map(static fn (WorkbookCellData $cell): array => $cell->toArray(), $this->cells),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
