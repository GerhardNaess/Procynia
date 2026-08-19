<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookCoordinate;
use App\Data\Ai\Requirements\Excel\WorkbookData;
use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookSchemaData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;

/**
 * Checks a proposed workbook schema against the workbook it claims to describe.
 *
 * The rule this enforces is simple and absolute: the model may say what a region MEANS, but the
 * backend decides what EXISTS. A discovery result naming sheet 7 of a three-sheet workbook, a data
 * range ending at row 900 in a 40-row sheet, or a column K on a sheet that stops at F is not a
 * near-miss to be nudged into place — it is evidence that the model was reasoning about something
 * other than this file, and acting on it would attach requirements to coordinates nobody has seen.
 *
 * So there is deliberately no repair here. Nothing is clamped to the nearest valid row, no column
 * is matched to a similar-looking one, no range is trimmed to fit. An invalid reference is
 * rejected and reported by name. A sheet that loses its data range or its requirement-text column
 * is dropped entirely, and if that leaves no usable sheet the whole schema is invalid — fail
 * closed, with the reasons kept for the caller to log.
 */
class WorkbookSchemaValidator
{
    /**
     * Purpose: Validate a raw discovery result against the parsed workbook.
     * Inputs: The raw AI discovery payload and the workbook it was produced from.
     * Returns: {schema: ?WorkbookSchemaData, is_valid: bool, errors: [...], rejected_references: [...]}
     *          schema is null whenever is_valid is false.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $discovery
     * @return array{schema: ?WorkbookSchemaData, is_valid: bool, errors: list<string>, rejected_references: list<array<string, mixed>>}
     */
    public function validate(array $discovery, WorkbookData $workbook): array
    {
        $errors = [];
        $rejected = [];
        $sheets = [];

        $rawSheets = is_array($discovery['requirement_sheets'] ?? null) ? $discovery['requirement_sheets'] : [];

        if ($rawSheets === []) {
            $errors[] = 'Discovery named no requirement sheets.';
        }

        foreach ($rawSheets as $position => $rawSheet) {
            if (! is_array($rawSheet)) {
                $errors[] = sprintf('requirement_sheets[%s] was not an object.', (string) $position);

                continue;
            }

            $validated = $this->validateSheet($rawSheet, $workbook, $errors, $rejected);

            if ($validated !== null) {
                $sheets[] = $validated;
            }
        }

        // Two sheets claiming the same index cannot both be acted on, and picking one would be a
        // guess about which the model meant.
        $indexes = array_map(static fn (WorkbookSheetSchemaData $sheet): int => $sheet->sheetIndex, $sheets);

        if (count($indexes) !== count(array_unique($indexes))) {
            $errors[] = 'The same sheet was described more than once.';
            $sheets = [];
        }

        if ($sheets === []) {
            $errors[] = 'No requirement sheet survived validation.';

            return ['schema' => null, 'is_valid' => false, 'errors' => array_values(array_unique($errors)), 'rejected_references' => $rejected];
        }

        $schema = new WorkbookSchemaData(
            requirementSheets: $sheets,
            supportingSheets: $this->validateSupportingSheets($discovery['supporting_sheets'] ?? null, $workbook, $rejected),
            warnings: $this->stringList($discovery['warnings'] ?? null),
            confidence: $this->confidence($discovery['confidence'] ?? null),
        );

        return ['schema' => $schema, 'is_valid' => true, 'errors' => array_values(array_unique($errors)), 'rejected_references' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $errors
     * @param  list<array<string, mixed>>  $rejected
     */
    private function validateSheet(array $raw, WorkbookData $workbook, array &$errors, array &$rejected): ?WorkbookSheetSchemaData
    {
        $sheetIndex = is_int($raw['sheet_index'] ?? null) ? $raw['sheet_index'] : null;
        $sheet = $sheetIndex === null ? null : $workbook->sheetByIndex($sheetIndex);

        if ($sheet === null) {
            $rejected[] = ['kind' => 'sheet_index', 'value' => $raw['sheet_index'] ?? null, 'reason' => 'no such sheet in the workbook'];
            $errors[] = sprintf('Sheet index [%s] does not exist.', var_export($raw['sheet_index'] ?? null, true));

            return null;
        }

        // The name is cross-checked rather than trusted: a mismatch means the model was tracking a
        // different sheet than the index it gave, and we cannot tell which one it meant.
        $claimedName = is_string($raw['sheet_name'] ?? null) ? $raw['sheet_name'] : null;

        if ($claimedName !== null && $claimedName !== $sheet->name) {
            $rejected[] = ['kind' => 'sheet_name', 'value' => $claimedName, 'reason' => sprintf('sheet %d is named "%s"', $sheet->index, $sheet->name)];
            $errors[] = sprintf('Sheet %d was called "%s" but is "%s".', $sheet->index, $claimedName, $sheet->name);

            return null;
        }

        $dataRange = $this->validateRange($raw['data_range'] ?? null, $sheet, 'data_range', $errors, $rejected);

        if ($dataRange === null) {
            return null;
        }

        $headerRange = null;

        if (($raw['header_range'] ?? null) !== null) {
            $headerRange = $this->validateRange($raw['header_range'], $sheet, 'header_range', $errors, $rejected);

            if ($headerRange === null) {
                return null;
            }
        }

        $strategy = is_string($raw['logical_unit_strategy'] ?? null) ? $raw['logical_unit_strategy'] : '';

        if (! in_array($strategy, WorkbookSheetSchemaData::LOGICAL_UNIT_STRATEGIES, true)) {
            $rejected[] = ['kind' => 'logical_unit_strategy', 'value' => $raw['logical_unit_strategy'] ?? null, 'reason' => 'not a supported strategy'];
            $errors[] = sprintf('Sheet %d used an unsupported logical unit strategy.', $sheet->index);

            return null;
        }

        $dataBounds = WorkbookCoordinate::parseRange($dataRange);
        $fieldRoles = $this->validateFieldRoles($raw['field_roles'] ?? null, $sheet, $dataBounds, $errors, $rejected);

        if ($fieldRoles === []) {
            $errors[] = sprintf('Sheet %d has no usable field roles.', $sheet->index);

            return null;
        }

        // Without a requirement-text column there is nothing to extract, and a schema that claims
        // requirements but cannot point at their wording is not a schema we can act on.
        $hasText = array_filter($fieldRoles, static fn (WorkbookFieldRoleData $field): bool => $field->role === WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT);

        if ($hasText === []) {
            $errors[] = sprintf('Sheet %d names no requirement_text column.', $sheet->index);

            return null;
        }

        $groupingColumn = $this->validateGroupingColumn($raw['grouping_column_letter'] ?? null, $strategy, $sheet, $dataBounds, $errors, $rejected);

        if ($groupingColumn === false) {
            return null;
        }

        return new WorkbookSheetSchemaData(
            sheetIndex: $sheet->index,
            sheetName: $sheet->name,
            headerRange: $headerRange,
            dataRange: $dataRange,
            logicalUnitStrategy: $strategy,
            fieldRoles: $fieldRoles,
            sectionRowNumbers: $this->validateSectionRows($raw['section_row_numbers'] ?? null, $sheet, $rejected),
            groupingColumnLetter: $groupingColumn,
            warnings: $this->stringList($raw['warnings'] ?? null),
            confidence: $this->confidence($raw['confidence'] ?? null),
            reason: is_string($raw['reason'] ?? null) ? trim($raw['reason']) : null,
        );
    }

    /**
     * @param  list<string>  $errors
     * @param  list<array<string, mixed>>  $rejected
     */
    private function validateRange(mixed $value, WorkbookSheetData $sheet, string $label, array &$errors, array &$rejected): ?string
    {
        $range = is_string($value) ? strtoupper(trim($value)) : '';

        // A cross-sheet reference ("Ark2!A1:B2") is not supported: a sheet schema describes one
        // sheet, and silently reading the range part would attach it to the wrong sheet.
        if (str_contains($range, '!')) {
            $rejected[] = ['kind' => $label, 'value' => $value, 'reason' => 'cross-sheet references are not supported'];
            $errors[] = sprintf('Sheet %d %s crossed sheets.', $sheet->index, $label);

            return null;
        }

        $bounds = WorkbookCoordinate::parseRange($range);

        if ($bounds === null) {
            $rejected[] = ['kind' => $label, 'value' => $value, 'reason' => 'not a valid A1-style range'];
            $errors[] = sprintf('Sheet %d %s was not a valid range.', $sheet->index, $label);

            return null;
        }

        if ($bounds['first_row'] > $bounds['last_row'] || $bounds['first_column_index'] > $bounds['last_column_index']) {
            $rejected[] = ['kind' => $label, 'value' => $value, 'reason' => 'range start is after its end'];
            $errors[] = sprintf('Sheet %d %s started after it ended.', $sheet->index, $label);

            return null;
        }

        $lastRow = $sheet->lastRow ?? 0;
        $lastColumn = $sheet->lastColumnIndex ?? -1;

        if ($bounds['first_row'] < 1 || $bounds['last_row'] > $lastRow) {
            $rejected[] = ['kind' => $label, 'value' => $value, 'reason' => sprintf('sheet rows end at %d', $lastRow)];
            $errors[] = sprintf('Sheet %d %s referenced rows outside the sheet.', $sheet->index, $label);

            return null;
        }

        if ($bounds['first_column_index'] < 0 || $bounds['last_column_index'] > $lastColumn) {
            $rejected[] = ['kind' => $label, 'value' => $value, 'reason' => sprintf('sheet columns end at %s', WorkbookCoordinate::columnLetter(max(0, $lastColumn)))];
            $errors[] = sprintf('Sheet %d %s referenced columns outside the sheet.', $sheet->index, $label);

            return null;
        }

        return $range;
    }

    /**
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}|null  $dataBounds
     * @param  list<string>  $errors
     * @param  list<array<string, mixed>>  $rejected
     * @return list<WorkbookFieldRoleData>
     */
    private function validateFieldRoles(mixed $value, WorkbookSheetData $sheet, ?array $dataBounds, array &$errors, array &$rejected): array
    {
        if (! is_array($value) || $dataBounds === null) {
            return [];
        }

        $roles = [];
        $seenColumns = [];

        foreach ($value as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $letter = is_string($raw['column_letter'] ?? null) ? strtoupper(trim($raw['column_letter'])) : '';

            if ($letter === '' || preg_match('/^[A-Z]+$/', $letter) !== 1) {
                $rejected[] = ['kind' => 'field_role_column', 'value' => $raw['column_letter'] ?? null, 'reason' => 'not a column letter'];

                continue;
            }

            $columnIndex = WorkbookCoordinate::columnIndex($letter);

            if ($columnIndex < $dataBounds['first_column_index'] || $columnIndex > $dataBounds['last_column_index']) {
                $rejected[] = [
                    'kind' => 'field_role_column',
                    'value' => $letter,
                    'reason' => sprintf('outside the data range on sheet %d', $sheet->index),
                ];
                $errors[] = sprintf('Sheet %d field role referenced column %s, which is outside its data range.', $sheet->index, $letter);

                continue;
            }

            $role = is_string($raw['role'] ?? null) ? $raw['role'] : '';

            if (! in_array($role, WorkbookFieldRoleData::ROLES, true)) {
                $rejected[] = ['kind' => 'field_role', 'value' => $raw['role'] ?? null, 'reason' => 'not a supported role'];

                continue;
            }

            // One column, one role: a column claimed twice is ambiguous, and the later claim is
            // not more likely to be right than the earlier one.
            $roleKey = $letter.'|'.$role;

            if (isset($seenColumns[$letter]) && ! isset($seenColumns[$roleKey])) {
                $rejected[] = ['kind' => 'field_role', 'value' => $letter, 'reason' => 'column was given more than one role'];
                $errors[] = sprintf('Sheet %d gave column %s more than one role.', $sheet->index, $letter);

                continue;
            }

            $seenColumns[$letter] = true;
            $seenColumns[$roleKey] = true;

            $roles[] = new WorkbookFieldRoleData(
                columnLetter: $letter,
                columnIndex: $columnIndex,
                role: $role,
                headerLabel: is_string($raw['header_label'] ?? null) && trim($raw['header_label']) !== '' ? trim($raw['header_label']) : null,
                confidence: $this->confidence($raw['confidence'] ?? null),
            );
        }

        return $roles;
    }

    /**
     * @param  array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}|null  $dataBounds
     * @param  list<string>  $errors
     * @param  list<array<string, mixed>>  $rejected
     * @return string|null|false false means the reference was invalid and the sheet must be dropped
     */
    private function validateGroupingColumn(mixed $value, string $strategy, WorkbookSheetData $sheet, ?array $dataBounds, array &$errors, array &$rejected): string|null|false
    {
        if ($value === null || $value === '') {
            if ($strategy === WorkbookSheetSchemaData::UNIT_MERGED_GROUP) {
                $errors[] = sprintf('Sheet %d groups rows into requirements but names no grouping column.', $sheet->index);

                return false;
            }

            return null;
        }

        $letter = is_string($value) ? strtoupper(trim($value)) : '';

        if (preg_match('/^[A-Z]+$/', $letter) !== 1 || $dataBounds === null) {
            $rejected[] = ['kind' => 'grouping_column_letter', 'value' => $value, 'reason' => 'not a column letter'];

            return false;
        }

        $columnIndex = WorkbookCoordinate::columnIndex($letter);

        if ($columnIndex < $dataBounds['first_column_index'] || $columnIndex > $dataBounds['last_column_index']) {
            $rejected[] = ['kind' => 'grouping_column_letter', 'value' => $letter, 'reason' => 'outside the data range'];
            $errors[] = sprintf('Sheet %d grouping column %s is outside its data range.', $sheet->index, $letter);

            return false;
        }

        return $letter;
    }

    /**
     * @param  list<array<string, mixed>>  $rejected
     * @return list<int>
     */
    private function validateSectionRows(mixed $value, WorkbookSheetData $sheet, array &$rejected): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        $lastRow = $sheet->lastRow ?? 0;

        foreach ($value as $row) {
            if (! is_int($row) || $row < 1 || $row > $lastRow) {
                $rejected[] = ['kind' => 'section_row', 'value' => $row, 'reason' => sprintf('sheet rows end at %d', $lastRow)];

                continue;
            }

            $rows[$row] = true;
        }

        $rows = array_keys($rows);
        sort($rows);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rejected
     * @return list<array{sheet_index: int, sheet_name: string, reason: string}>
     */
    private function validateSupportingSheets(mixed $value, WorkbookData $workbook, array &$rejected): array
    {
        if (! is_array($value)) {
            return [];
        }

        $supporting = [];

        foreach ($value as $raw) {
            $sheet = is_array($raw) && is_int($raw['sheet_index'] ?? null)
                ? $workbook->sheetByIndex($raw['sheet_index'])
                : null;

            if ($sheet === null) {
                $rejected[] = ['kind' => 'supporting_sheet_index', 'value' => $raw['sheet_index'] ?? null, 'reason' => 'no such sheet'];

                continue;
            }

            $supporting[] = [
                'sheet_index' => $sheet->index,
                'sheet_name' => $sheet->name,
                'reason' => is_string($raw['reason'] ?? null) ? trim($raw['reason']) : '',
            ];
        }

        return $supporting;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
