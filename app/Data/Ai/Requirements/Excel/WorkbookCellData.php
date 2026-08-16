<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * One cell of a parsed .xlsx worksheet, positioned by coordinate rather than by reading order.
 *
 * Identity is positional and never derived from the value: two cells holding the same text get
 * different keys, and editing a cell's text does not change its key. That is what lets a later
 * phase say "this requirement came from Kravspesifikasjon!B17" and still mean the same cell after
 * the wording changes.
 *
 * `value` is what a reader sees — for a formula cell it is the value Excel itself cached in the
 * file, never a value this code computed. `formula` keeps the expression alongside it, so the
 * distinction between "the sheet says 42" and "the sheet says =SUM(A1:A9)" survives.
 */
final readonly class WorkbookCellData implements JsonSerializable
{
    public const TYPE_STRING = 'string';

    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATE = 'date';

    public const TYPE_DURATION = 'duration';

    public const TYPE_ERROR = 'error';

    public const TYPE_EMPTY = 'empty';

    public function __construct(
        public int $row,
        public int $columnIndex,
        public string $columnLetter,
        public string $coordinate,
        public string $dataType,
        public string|int|float|bool|null $value,
        public ?string $formula = null,
        public ?string $mergeRange = null,
        public bool $isMergeOrigin = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->dataType === self::TYPE_EMPTY
            || $this->value === null
            || (is_string($this->value) && trim($this->value) === '');
    }

    /** True when this cell is covered by a merge but is not the cell that carries its value. */
    public function isMergeContinuation(): bool
    {
        return $this->mergeRange !== null && ! $this->isMergeOrigin;
    }

    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'column_index' => $this->columnIndex,
            'column_letter' => $this->columnLetter,
            'coordinate' => $this->coordinate,
            'data_type' => $this->dataType,
            'value' => $this->value,
            'formula' => $this->formula,
            'merge_range' => $this->mergeRange,
            'is_merge_origin' => $this->isMergeOrigin,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
