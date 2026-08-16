<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * A workbook schema that has been checked against the real workbook.
 *
 * The distinction from the raw AI discovery result is the whole point of this type: a model's
 * answer is a proposal about structure, and a proposal can name a sheet that does not exist or a
 * range that runs off the end of the data. Only what WorkbookSchemaValidator has confirmed against
 * WorkbookData reaches this class, so anything downstream can treat every coordinate here as real
 * without re-checking.
 *
 * It describes SHAPE, not content: which sheets hold requirements, where the header and data live,
 * what each column appears to be for, and how several cells combine into one logical requirement.
 * No requirement text is interpreted, rewritten or classified at this stage.
 */
final readonly class WorkbookSchemaData implements JsonSerializable
{
    /**
     * @param  list<WorkbookSheetSchemaData>  $requirementSheets
     * @param  list<array{sheet_index: int, sheet_name: string, reason: string}>  $supportingSheets
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $requirementSheets,
        public array $supportingSheets = [],
        public array $warnings = [],
        public ?float $confidence = null,
    ) {}

    public function hasRequirementSheets(): bool
    {
        return $this->requirementSheets !== [];
    }

    public function toArray(): array
    {
        return [
            'requirement_sheets' => array_map(
                static fn (WorkbookSheetSchemaData $sheet): array => $sheet->toArray(),
                $this->requirementSheets,
            ),
            'supporting_sheets' => $this->supportingSheets,
            'warnings' => $this->warnings,
            'confidence' => $this->confidence,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
