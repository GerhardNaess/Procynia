<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * One worksheet, with the structure a later structure-discovery phase needs in order to reason
 * about where requirements live: how far the content actually extends, which ranges are merged
 * (the usual way sections and grouped requirements are expressed), and whether the sheet is
 * visible at all.
 *
 * A hidden sheet is parsed and kept, flagged rather than dropped: hiding a sheet is a formatting
 * decision by whoever built the workbook, not a statement that its content is irrelevant, and
 * silently discarding it would lose requirements with no trace. Consumers decide what to do with
 * it; this layer only reports it.
 */
final readonly class WorkbookSheetData implements JsonSerializable
{
    /**
     * @param  list<WorkbookRowData>  $rows
     * @param  list<string>  $mergedRanges  e.g. ['B17:G18']
     */
    public function __construct(
        public int $index,
        public string $name,
        public bool $isVisible,
        public bool $isActive,
        public array $rows,
        public array $mergedRanges = [],
        public ?int $firstRow = null,
        public ?int $lastRow = null,
        public ?int $firstColumnIndex = null,
        public ?int $lastColumnIndex = null,
        public bool $truncated = false,
    ) {}

    /** The A1-style used range, or null for an entirely empty sheet. */
    public function dimensionRef(): ?string
    {
        if ($this->firstRow === null || $this->lastRow === null
            || $this->firstColumnIndex === null || $this->lastColumnIndex === null) {
            return null;
        }

        return sprintf(
            '%s%d:%s%d',
            WorkbookCoordinate::columnLetter($this->firstColumnIndex),
            $this->firstRow,
            WorkbookCoordinate::columnLetter($this->lastColumnIndex),
            $this->lastRow,
        );
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'is_visible' => $this->isVisible,
            'is_active' => $this->isActive,
            'dimensions' => [
                'ref' => $this->dimensionRef(),
                'first_row' => $this->firstRow,
                'last_row' => $this->lastRow,
                'first_column_index' => $this->firstColumnIndex,
                'last_column_index' => $this->lastColumnIndex,
            ],
            'merged_ranges' => $this->mergedRanges,
            'truncated' => $this->truncated,
            'rows' => array_map(static fn (WorkbookRowData $row): array => $row->toArray(), $this->rows),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
