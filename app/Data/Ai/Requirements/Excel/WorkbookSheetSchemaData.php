<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * How one worksheet expresses requirements, once validated against the parsed workbook.
 *
 * `logicalUnitStrategy` is the part that matters most downstream, and it exists because the naive
 * assumption — one row is one requirement — is wrong often enough to be dangerous. A workbook may
 * put a requirement on a single row, spread one requirement across several rows under a merged
 * band, or group rows under a section heading. Getting this wrong does not produce a slightly odd
 * import; it produces requirements that are silently split or merged.
 */
final readonly class WorkbookSheetSchemaData implements JsonSerializable
{
    /** One row of the data region is one requirement. */
    public const UNIT_ROW = 'row';

    /** Consecutive rows sharing a merged cell or a repeated key form one requirement. */
    public const UNIT_MERGED_GROUP = 'merged_group';

    /** Rows under a section heading belong to that section; each row is still a requirement. */
    public const UNIT_SECTION_GROUPED_ROW = 'section_grouped_row';

    public const LOGICAL_UNIT_STRATEGIES = [
        self::UNIT_ROW,
        self::UNIT_MERGED_GROUP,
        self::UNIT_SECTION_GROUPED_ROW,
    ];

    /**
     * @param  list<WorkbookFieldRoleData>  $fieldRoles
     * @param  list<int>  $sectionRowNumbers  rows that label a section rather than state a requirement
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $sheetIndex,
        public string $sheetName,
        public ?string $headerRange,
        public string $dataRange,
        public string $logicalUnitStrategy,
        public array $fieldRoles,
        public array $sectionRowNumbers = [],
        public ?string $groupingColumnLetter = null,
        public array $warnings = [],
        public ?float $confidence = null,
        public ?string $reason = null,
    ) {}

    /** @return list<WorkbookFieldRoleData> */
    public function rolesOf(string $role): array
    {
        return array_values(array_filter(
            $this->fieldRoles,
            static fn (WorkbookFieldRoleData $field): bool => $field->role === $role,
        ));
    }

    public function toArray(): array
    {
        return [
            'sheet_index' => $this->sheetIndex,
            'sheet_name' => $this->sheetName,
            'header_range' => $this->headerRange,
            'data_range' => $this->dataRange,
            'logical_unit_strategy' => $this->logicalUnitStrategy,
            'grouping_column_letter' => $this->groupingColumnLetter,
            'section_row_numbers' => $this->sectionRowNumbers,
            'field_roles' => array_map(
                static fn (WorkbookFieldRoleData $field): array => $field->toArray(),
                $this->fieldRoles,
            ),
            'warnings' => $this->warnings,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
