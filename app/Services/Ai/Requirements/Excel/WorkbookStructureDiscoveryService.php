<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookData;
use App\Data\Ai\Requirements\Excel\WorkbookSchemaData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;
use Illuminate\Support\Facades\Log;

/**
 * One controlled discovery pass: orient, ask once, validate, report.
 *
 * Exactly one AI call per workbook, not one per sheet — the model needs to see the workbook as a
 * whole to tell a requirement sheet from a price form, and per-sheet calls would both cost more
 * and lose that context.
 *
 * The trace it returns exists because "why did Excel import read it that way?" has to be
 * answerable afterwards. It records what was considered, what was chosen and what was rejected —
 * but never the orientation itself and never cell values, since a customer's requirement text is
 * in there and logs are not the place for it. Sizes and coordinates, not content.
 */
class WorkbookStructureDiscoveryService
{
    public function __construct(
        private readonly WorkbookOrientationBuilder $orientationBuilder,
        private readonly WorkbookStructureDiscoveryAiClient $discoveryAiClient,
        private readonly WorkbookSchemaValidator $validator,
    ) {}

    /**
     * Purpose: Discover how one workbook expresses requirements.
     * Inputs: The parsed workbook and the language its content is in.
     * Returns: {schema: ?WorkbookSchemaData, is_valid: bool, trace: array}. schema is null when
     *          validation failed — callers must check is_valid and must not fall back to a guess.
     * Side effects: One OpenAI call; one log line. Persists nothing.
     *
     * @return array{schema: ?WorkbookSchemaData, is_valid: bool, trace: array<string, mixed>}
     */
    public function discover(WorkbookData $workbook, string $languageCode = 'no'): array
    {
        $orientation = $this->orientationBuilder->build($workbook);
        $result = $this->discoveryAiClient->discoverStructure($orientation, $languageCode);
        $validation = $this->validator->validate($result['discovery'], $workbook);

        $trace = [
            'sheets_considered' => array_map(
                static fn ($sheet): array => [
                    'sheet_index' => $sheet->index,
                    'sheet_name' => $sheet->name,
                    'is_visible' => $sheet->isVisible,
                    'used_row_count' => count($sheet->rows),
                    'dimension_ref' => $sheet->dimensionRef(),
                    'parser_truncated' => $sheet->truncated,
                ],
                $workbook->sheets,
            ),
            'orientation' => [
                'chars' => $result['metrics']['orientation_chars'] ?? null,
                'sampled_sheet_count' => $orientation['totals']['sampled_sheet_count'] ?? 0,
                'sampled_row_counts' => array_map(
                    static fn (array $sheet): array => [
                        'sheet_index' => $sheet['sheet_index'],
                        'included_rows' => count($sheet['sampling']['included_row_numbers']),
                        'is_sampled' => $sheet['sampling']['is_sampled'],
                    ],
                    $orientation['sheets'],
                ),
            ],
            'ai' => $result['metrics'],
            'validation' => [
                'is_valid' => $validation['is_valid'],
                'errors' => $validation['errors'],
                'rejected_references' => $validation['rejected_references'],
            ],
            'schema' => $validation['schema'] instanceof WorkbookSchemaData
                ? $this->schemaSummary($validation['schema'])
                : null,
        ];

        Log::info('[EXCEL_STRUCTURE_DISCOVERY] Workbook structure discovery completed.', $trace);

        return [
            'schema' => $validation['schema'],
            'is_valid' => $validation['is_valid'],
            'trace' => $trace,
        ];
    }

    /**
     * Coordinates and decisions only — deliberately no cell values, no header labels read from the
     * customer's file, nothing that would put requirement content into a log line.
     *
     * @return array<string, mixed>
     */
    private function schemaSummary(WorkbookSchemaData $schema): array
    {
        return [
            'confidence' => $schema->confidence,
            'warning_count' => count($schema->warnings),
            'supporting_sheet_indexes' => array_column($schema->supportingSheets, 'sheet_index'),
            'requirement_sheets' => array_map(
                static fn (WorkbookSheetSchemaData $sheet): array => [
                    'sheet_index' => $sheet->sheetIndex,
                    'header_range' => $sheet->headerRange,
                    'data_range' => $sheet->dataRange,
                    'logical_unit_strategy' => $sheet->logicalUnitStrategy,
                    'grouping_column_letter' => $sheet->groupingColumnLetter,
                    'section_row_count' => count($sheet->sectionRowNumbers),
                    'roles' => array_count_values(array_map(
                        static fn ($field): string => $field->role,
                        $sheet->fieldRoles,
                    )),
                    'confidence' => $sheet->confidence,
                    'warning_count' => count($sheet->warnings),
                ],
                $schema->requirementSheets,
            ),
        ];
    }
}
