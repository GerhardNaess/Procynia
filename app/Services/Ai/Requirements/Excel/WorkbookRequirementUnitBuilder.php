<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookCellData;
use App\Data\Ai\Requirements\Excel\WorkbookCoordinate;
use App\Data\Ai\Requirements\Excel\WorkbookData;
use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;
use App\Data\Ai\Requirements\Excel\WorkbookRowData;
use App\Data\Ai\Requirements\Excel\WorkbookSchemaData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;

/**
 * Turns a validated schema plus the real workbook into logical requirements. Entirely
 * deterministic — the AI already did its one job in the discovery phase, and re-guessing here
 * would mean two systems deciding the same thing differently.
 *
 * It reads the FULL data region, not the sampled orientation. That distinction matters: discovery
 * only ever saw a sample, enough to work out the shape; extraction must see every row the schema
 * points at, or requirements go missing silently. The one bound is the parser's own row cap, and
 * when a sheet hit it the result says so rather than presenting a partial workbook as complete.
 *
 * Everything it does is driven by the schema. There is no rule here that a "Skal" means mandatory,
 * no scan for rows that look requirement-shaped, no fuzzy grouping of similar identifiers. Where
 * the schema is not specific enough to form a unit, the sheet fails closed instead of being
 * guessed at.
 */
class WorkbookRequirementUnitBuilder
{
    /**
     * Purpose: Build the logical requirement units a validated schema describes.
     * Inputs: The parsed workbook and a schema already validated against it.
     * Returns: {units, warnings, is_complete, skipped}. is_complete is false when any contributing
     *          sheet was truncated by the parser — the units are real, but the set may not be whole.
     * Side effects: None. No AI, no persistence.
     *
     * @return array{units: list<WorkbookRequirementUnitData>, warnings: list<string>, is_complete: bool, skipped: list<array<string, mixed>>}
     */
    public function build(WorkbookData $workbook, WorkbookSchemaData $schema): array
    {
        $units = [];
        $warnings = [];
        $skipped = [];
        $isComplete = true;

        foreach ($schema->requirementSheets as $sheetSchema) {
            $sheet = $workbook->sheetByIndex($sheetSchema->sheetIndex);

            if ($sheet === null) {
                // The validator already guarantees this cannot happen; if it somehow does, the
                // schema and the workbook are not the pair they claim to be.
                $warnings[] = sprintf('Sheet %d in the schema is not in this workbook.', $sheetSchema->sheetIndex);
                $isComplete = false;

                continue;
            }

            if ($sheet->truncated) {
                // Never quietly. A truncated sheet means the parser stopped at its row cap, so
                // requirements below that point were never read at all.
                $warnings[] = sprintf(
                    'Sheet %d (%s) was truncated by the parser at %d rows — requirements below that point are missing.',
                    $sheet->index,
                    $sheet->name,
                    XlsxWorkbookParser::MAX_ROWS_PER_SHEET,
                );
                $isComplete = false;
            }

            $sheetUnits = $this->buildSheetUnits($sheet, $sheetSchema, $warnings, $skipped);

            if ($sheetUnits === null) {
                $isComplete = false;

                continue;
            }

            $units = [...$units, ...$sheetUnits];
        }

        return [
            'units' => $units,
            'warnings' => $warnings,
            'is_complete' => $isComplete,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $skipped
     * @return list<WorkbookRequirementUnitData>|null null when the sheet cannot be formed at all
     */
    private function buildSheetUnits(WorkbookSheetData $sheet, WorkbookSheetSchemaData $sheetSchema, array &$warnings, array &$skipped): ?array
    {
        $bounds = WorkbookCoordinate::parseRange($sheetSchema->dataRange);

        if ($bounds === null) {
            $warnings[] = sprintf('Sheet %d has an unusable data range.', $sheet->index);

            return null;
        }

        $rows = $this->rowsInRange($sheet, $bounds['first_row'], $bounds['last_row']);

        return match ($sheetSchema->logicalUnitStrategy) {
            WorkbookSheetSchemaData::UNIT_MERGED_GROUP => $this->buildMergedGroupUnits($sheet, $sheetSchema, $bounds, $rows, $warnings, $skipped),
            WorkbookSheetSchemaData::UNIT_SECTION_GROUPED_ROW => $this->buildSectionGroupedUnits($sheet, $sheetSchema, $bounds, $rows, $skipped),
            default => $this->buildRowUnits($sheet, $sheetSchema, $bounds, $rows, $skipped),
        };
    }

    /**
     * One data row, one requirement.
     *
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}  $bounds
     * @param  list<WorkbookRowData>  $rows
     * @param  list<array<string, mixed>>  $skipped
     * @return list<WorkbookRequirementUnitData>
     */
    private function buildRowUnits(WorkbookSheetData $sheet, WorkbookSheetSchemaData $sheetSchema, array $bounds, array $rows, array &$skipped): array
    {
        $units = [];

        foreach ($rows as $row) {
            $unit = $this->assembleUnit($sheet, $sheetSchema, $bounds, [$row], null, null, $skipped);

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Each row is still its own requirement, but it inherits the nearest section heading above it.
     *
     * The section rows come from the validated schema, never from looking for rows that seem
     * heading-like. A section row is context, not a requirement of its own — turning it into one
     * would add a requirement the tender never stated.
     *
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}  $bounds
     * @param  list<WorkbookRowData>  $rows
     * @param  list<array<string, mixed>>  $skipped
     * @return list<WorkbookRequirementUnitData>
     */
    private function buildSectionGroupedUnits(WorkbookSheetData $sheet, WorkbookSheetSchemaData $sheetSchema, array $bounds, array $rows, array &$skipped): array
    {
        $sectionRows = array_flip($sheetSchema->sectionRowNumbers);
        $units = [];
        $currentSection = null;
        $currentSectionRow = null;

        foreach ($rows as $row) {
            if (isset($sectionRows[$row->rowNumber])) {
                $currentSection = $this->sectionText($row, $sheetSchema, $bounds);
                $currentSectionRow = $row->rowNumber;

                continue;
            }

            $unit = $this->assembleUnit($sheet, $sheetSchema, $bounds, [$row], $currentSection, $currentSectionRow, $skipped);

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Several consecutive rows form one requirement.
     *
     * Grouping is read from the file, not inferred from content. A group starts where the
     * schema's grouping column carries its own value, and continues while that column is either
     * blank or a continuation of the same merged cell — which is exactly the two ways a
     * spreadsheet expresses "these rows belong together". Nothing here compares values for
     * similarity or guesses at repeated identifiers.
     *
     * If the data region does not begin with a group start, the schema and the sheet disagree
     * about where groups begin, and the sheet fails closed rather than producing a first unit
     * assembled from whatever happened to come first.
     *
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}  $bounds
     * @param  list<WorkbookRowData>  $rows
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $skipped
     * @return list<WorkbookRequirementUnitData>|null
     */
    private function buildMergedGroupUnits(WorkbookSheetData $sheet, WorkbookSheetSchemaData $sheetSchema, array $bounds, array $rows, array &$warnings, array &$skipped): ?array
    {
        if ($sheetSchema->groupingColumnLetter === null) {
            $warnings[] = sprintf('Sheet %d groups rows but names no grouping column.', $sheet->index);

            return null;
        }

        $groupingIndex = WorkbookCoordinate::columnIndex($sheetSchema->groupingColumnLetter);
        $groups = [];

        foreach ($rows as $row) {
            $cell = $this->cellAt($row, $groupingIndex);
            $startsGroup = $cell !== null && ! $cell->isEmpty() && ! $cell->isMergeContinuation();

            if ($startsGroup) {
                $groups[] = [$row];

                continue;
            }

            if ($groups === []) {
                $warnings[] = sprintf(
                    'Sheet %d starts its data region mid-group at row %d — the grouping column does not begin a group there.',
                    $sheet->index,
                    $row->rowNumber,
                );

                return null;
            }

            $groups[count($groups) - 1][] = $row;
        }

        $units = [];

        foreach ($groups as $group) {
            $unit = $this->assembleUnit($sheet, $sheetSchema, $bounds, $group, null, null, $skipped);

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Assemble one unit from one or more rows, or refuse it.
     *
     * A unit exists only when it carries requirement text. A blank row, a decorative row, a row
     * where only the supplier's answer column is filled — none of those state a requirement, and
     * producing an empty requirement from them would put noise into the extracted list that a
     * human then has to clean out.
     *
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}  $bounds
     * @param  list<WorkbookRowData>  $rows
     * @param  list<array<string, mixed>>  $skipped
     */
    private function assembleUnit(
        WorkbookSheetData $sheet,
        WorkbookSheetSchemaData $sheetSchema,
        array $bounds,
        array $rows,
        ?string $sectionContext,
        ?int $sectionRowNumber,
        array &$skipped,
    ): ?WorkbookRequirementUnitData {
        if ($rows === []) {
            return null;
        }

        $textParts = $this->collectTextParts($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT);

        if ($textParts === []) {
            $skipped[] = [
                'sheet_index' => $sheet->index,
                'start_row' => $rows[0]->rowNumber,
                'end_row' => $rows[count($rows) - 1]->rowNumber,
                'reason' => 'no requirement text in the columns the schema named',
            ];

            return null;
        }

        $startRow = $rows[0]->rowNumber;
        $endRow = $rows[count($rows) - 1]->rowNumber;
        $range = sprintf(
            '%s%d:%s%d',
            WorkbookCoordinate::columnLetter($bounds['first_column_index']),
            $startRow,
            WorkbookCoordinate::columnLetter($bounds['last_column_index']),
            $endRow,
        );

        return new WorkbookRequirementUnitData(
            sheetIndex: $sheet->index,
            sheetName: $sheet->name,
            startRow: $startRow,
            endRow: $endRow,
            sourceRange: $range,
            humanSourceRef: WorkbookCoordinate::label($sheet->name, $range),
            sourceElementType: WorkbookCoordinate::ELEMENT_TYPE_RANGE,
            sourceElementKey: WorkbookCoordinate::rangeKey($sheet->index, $range),
            requirementText: implode("\n\n", $textParts),
            requirementTextParts: $textParts,
            requirementId: $this->firstValue($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            qualification: $this->firstValue($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_QUALIFICATION),
            weighting: $this->firstValue($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_WEIGHTING),
            responseField: $this->joinValues($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_RESPONSE),
            comment: $this->joinValues($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_COMMENT),
            sectionContext: $sectionContext ?? $this->firstValue($rows, $sheetSchema, WorkbookFieldRoleData::ROLE_SECTION),
            sectionRowNumber: $sectionRowNumber,
            otherFields: $this->otherFields($rows, $sheetSchema),
        );
    }

    /**
     * Requirement text may legitimately live in more than one column — a short title plus a longer
     * description is a common layout. All of them contribute, in the order the schema lists them
     * and then in row order, so the same workbook always produces the same text. Exact repeats are
     * dropped: a title echoed verbatim in the detail column is one statement, not two.
     *
     * @param  list<WorkbookRowData>  $rows
     * @return list<string>
     */
    private function collectTextParts(array $rows, WorkbookSheetSchemaData $sheetSchema, string $role): array
    {
        $parts = [];
        $seen = [];

        foreach ($sheetSchema->rolesOf($role) as $field) {
            foreach ($rows as $row) {
                $value = $this->stringValue($this->cellAt($row, $field->columnIndex));

                if ($value === null) {
                    continue;
                }

                $fingerprint = mb_strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value, 'UTF-8');

                if (isset($seen[$fingerprint])) {
                    continue;
                }

                $seen[$fingerprint] = true;
                $parts[] = $value;
            }
        }

        return $parts;
    }

    /** @param  list<WorkbookRowData>  $rows */
    private function firstValue(array $rows, WorkbookSheetSchemaData $sheetSchema, string $role): ?string
    {
        $parts = $this->collectTextParts($rows, $sheetSchema, $role);

        return $parts === [] ? null : $parts[0];
    }

    /** @param  list<WorkbookRowData>  $rows */
    private function joinValues(array $rows, WorkbookSheetSchemaData $sheetSchema, string $role): ?string
    {
        $parts = $this->collectTextParts($rows, $sheetSchema, $role);

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Columns the schema marked `other` are kept verbatim under their column letter rather than
     * discarded: the schema said they exist but not what they mean, and dropping them would lose
     * information the next phase might need.
     *
     * @param  list<WorkbookRowData>  $rows
     * @return array<string, string>
     */
    private function otherFields(array $rows, WorkbookSheetSchemaData $sheetSchema): array
    {
        $fields = [];

        foreach ($sheetSchema->rolesOf(WorkbookFieldRoleData::ROLE_OTHER) as $field) {
            foreach ($rows as $row) {
                $value = $this->stringValue($this->cellAt($row, $field->columnIndex));

                if ($value !== null && ! isset($fields[$field->columnLetter])) {
                    $fields[$field->columnLetter] = $value;
                }
            }
        }

        return $fields;
    }

    /**
     * A section row's own text: the section column when the schema names one, otherwise every
     * non-empty cell on that row joined in column order.
     *
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}  $bounds
     */
    private function sectionText(WorkbookRowData $row, WorkbookSheetSchemaData $sheetSchema, array $bounds): ?string
    {
        $sectionColumns = $sheetSchema->rolesOf(WorkbookFieldRoleData::ROLE_SECTION);

        if ($sectionColumns !== []) {
            $value = $this->stringValue($this->cellAt($row, $sectionColumns[0]->columnIndex));

            if ($value !== null) {
                return $value;
            }
        }

        $values = [];

        foreach ($row->cells as $cell) {
            if ($cell->columnIndex < $bounds['first_column_index'] || $cell->columnIndex > $bounds['last_column_index']) {
                continue;
            }

            $value = $this->stringValue($cell);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : implode(' ', $values);
    }

    /**
     * @param  list<WorkbookRowData>  $rows  the sheet's rows
     * @return list<WorkbookRowData>
     */
    private function rowsInRange(WorkbookSheetData $sheet, int $firstRow, int $lastRow): array
    {
        return array_values(array_filter(
            $sheet->rows,
            static fn (WorkbookRowData $row): bool => $row->rowNumber >= $firstRow && $row->rowNumber <= $lastRow,
        ));
    }

    private function cellAt(WorkbookRowData $row, int $columnIndex): ?WorkbookCellData
    {
        foreach ($row->cells as $cell) {
            if ($cell->columnIndex === $columnIndex) {
                return $cell;
            }
        }

        return null;
    }

    private function stringValue(?WorkbookCellData $cell): ?string
    {
        if ($cell === null || $cell->isEmpty()) {
            return null;
        }

        $value = is_bool($cell->value) ? ($cell->value ? 'true' : 'false') : (string) $cell->value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
