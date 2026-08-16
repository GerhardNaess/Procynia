<?php

namespace Tests\Unit\Services\Ai\Requirements;

use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;
use App\Services\Ai\Requirements\Excel\WorkbookOrientationBuilder;
use App\Services\Ai\Requirements\Excel\WorkbookSchemaValidator;
use App\Services\Ai\Requirements\Excel\WorkbookStructureDiscoveryAiClient;
use App\Services\Ai\Requirements\Excel\WorkbookStructureDiscoveryService;
use App\Services\Ai\Requirements\Excel\XlsxWorkbookParser;
use Mockery\MockInterface;
use Tests\Support\XlsxFixtureBuilder;
use Tests\TestCase;

/**
 * Structure discovery: the model may say what a region MEANS, the backend decides what EXISTS.
 *
 * The AI call is mocked throughout — these tests are about the orientation we build, the schema we
 * accept, and above all the coordinates we refuse. No live API calls.
 */
class WorkbookStructureDiscoveryTest extends TestCase
{
    private XlsxFixtureBuilder $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new XlsxFixtureBuilder;
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    private function parse(string $path)
    {
        return app(XlsxWorkbookParser::class)->parse($path);
    }

    private function orientation(string $path): array
    {
        return app(WorkbookOrientationBuilder::class)->build($this->parse($path));
    }

    private function validate(array $discovery, string $path): array
    {
        return app(WorkbookSchemaValidator::class)->validate($discovery, $this->parse($path));
    }

    /** A well-formed discovery result for the simple matrix fixture. */
    private function matrixDiscovery(array $overrides = []): array
    {
        return array_merge([
            'requirement_sheets' => [array_merge([
                'sheet_index' => 0,
                'sheet_name' => 'Kravspesifikasjon',
                'header_range' => 'A1:D1',
                'data_range' => 'A2:D4',
                'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_ROW,
                'grouping_column_letter' => null,
                'section_row_numbers' => [],
                'field_roles' => [
                    ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
                    ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.95],
                    ['column_letter' => 'C', 'role' => WorkbookFieldRoleData::ROLE_QUALIFICATION, 'header_label' => 'Skal/Bør', 'confidence' => 0.8],
                    ['column_letter' => 'D', 'role' => WorkbookFieldRoleData::ROLE_RESPONSE, 'header_label' => 'Svar', 'confidence' => 0.85],
                ],
                'warnings' => [],
                'confidence' => 0.9,
                'reason' => 'Header on row 1, one requirement per row.',
            ], $overrides['sheet'] ?? [])],
            'supporting_sheets' => [],
            'warnings' => [],
            'confidence' => 0.9,
        ], array_diff_key($overrides, ['sheet' => null]));
    }

    private function matrixWorkbookPath(): string
    {
        return $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Krav', 'Skal/Bør', 'Svar'],
                ['K-1', 'Løsningen skal støtte SSO.', 'Skal', null],
                ['K-2', 'Løsningen bør støtte SCIM.', 'Bør', null],
                ['K-3', 'Løsningen skal logge tilgang.', 'Skal', null],
            ],
        ]);
    }

    // ── Orientation ──────────────────────────────────────────────────────────

    public function test_the_orientation_describes_shape_without_interpreting_it(): void
    {
        $orientation = $this->orientation($this->matrixWorkbookPath());
        $sheet = $orientation['sheets'][0];

        $this->assertSame('Kravspesifikasjon', $sheet['sheet_name']);
        $this->assertSame('A1:D4', $sheet['dimensions']['ref']);
        $this->assertCount(4, $sheet['columns']);
        $this->assertSame('A1', $sheet['rows'][0]['cells'][0]['coordinate']);

        // The response column is mostly empty; the orientation reports that as a fill ratio and
        // draws no conclusion from it.
        $this->assertSame(0.25, $sheet['columns'][3]['fill_ratio']);
        $encoded = json_encode($orientation);
        foreach (['mandatory', 'requirement_text', 'is_requirement'] as $interpretation) {
            $this->assertStringNotContainsString($interpretation, $encoded);
        }
    }

    public function test_a_column_without_a_requirement_like_name_is_still_described(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [
                ['Kategori', 'Beskrivelse', 'Kommentar'],
                ['Sikkerhet', 'Systemet skal kryptere data i ro.', 'Se vedlegg 3'],
            ],
        ]);

        $sheet = $this->orientation($path)['sheets'][0];

        $this->assertSame(['Kategori', 'Beskrivelse', 'Kommentar'], array_map(
            static fn (array $column): string => (string) $column['sample_values'][0],
            $sheet['columns'],
        ));
        // The long-text column is visible in the statistics, not in a label we invented.
        $this->assertGreaterThan($sheet['columns'][0]['max_text_length'], $sheet['columns'][1]['max_text_length']);
    }

    public function test_a_large_sheet_is_sampled_deterministically_and_says_so(): void
    {
        $rows = [['ID', 'Krav']];

        for ($index = 1; $index <= 400; $index++) {
            $rows[] = ['K-'.$index, 'Krav nummer '.$index];
        }

        $path = $this->fixtures->build(['Stort' => $rows]);

        $first = $this->orientation($path)['sheets'][0];
        $second = $this->orientation($path)['sheets'][0];

        $this->assertTrue($first['sampling']['is_sampled']);
        $this->assertLessThan(401, count($first['rows']));
        $this->assertSame($first['sampling']['included_row_numbers'], $second['sampling']['included_row_numbers'], 'Sampling must be reproducible.');
        // Head, body sample and tail are all represented.
        $this->assertContains(1, $first['sampling']['included_row_numbers']);
        $this->assertContains(401, $first['sampling']['included_row_numbers']);
    }

    public function test_decorative_rows_before_the_header_are_kept_with_their_real_row_numbers(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [
                ['Anskaffelse 2026/123'],
                [null],
                ['Kravtabell'],
                [null],
                ['ID', 'Krav'],
                ['K-1', 'Systemet skal ha tofaktor.'],
            ],
        ]);

        $sheet = $this->orientation($path)['sheets'][0];
        $rowNumbers = array_column($sheet['rows'], 'row_number');

        $this->assertSame([1, 2, 3, 4, 5, 6], $rowNumbers);
        $this->assertSame(2, $sheet['rows'][4]['non_empty_cell_count'], 'The real header row is row 5, not row 1.');
    }

    public function test_a_merged_header_is_reported_as_a_merge_not_as_blank_cells(): void
    {
        $path = $this->fixtures->build(
            ['Ark1' => [
                ['Krav', null, 'Svar'],
                ['ID', 'Tekst', 'Leverandør'],
                ['K-1', 'Systemet skal logge.', null],
            ]],
            [['sheet' => 'Ark1', 'range' => [0, 1, 1, 1]]],
        );

        $sheet = $this->orientation($path)['sheets'][0];

        $this->assertContains('A1:B1', $sheet['merged_ranges']);
        $mergedCell = $sheet['rows'][0]['cells'][0];
        $this->assertSame('A1:B1', $mergedCell['merge_range']);
        $this->assertFalse($mergedCell['is_merge_continuation']);
    }

    public function test_a_hidden_sheet_reaches_the_orientation_flagged_as_hidden(): void
    {
        $path = $this->fixtures->build(
            [
                'Forside' => [['Anskaffelse']],
                'Skjulte krav' => [['ID', 'Krav'], ['K-1', 'Systemet skal ha logg.']],
            ],
            [],
            ['Skjulte krav'],
        );

        $orientation = $this->orientation($path);

        $this->assertCount(2, $orientation['sheets']);
        $this->assertFalse($orientation['sheets'][1]['is_visible']);
        $this->assertSame('Skjulte krav', $orientation['sheets'][1]['sheet_name']);
    }

    // ── Validation: accepting a sound schema ─────────────────────────────────

    public function test_a_sound_discovery_result_validates_into_a_schema(): void
    {
        $path = $this->matrixWorkbookPath();

        $result = $this->validate($this->matrixDiscovery(), $path);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['errors']);
        $sheet = $result['schema']->requirementSheets[0];
        $this->assertSame('A2:D4', $sheet->dataRange);
        $this->assertSame(WorkbookSheetSchemaData::UNIT_ROW, $sheet->logicalUnitStrategy);
        $this->assertSame('B', $sheet->rolesOf(WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT)[0]->columnLetter);
        $this->assertSame(1, $sheet->rolesOf(WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT)[0]->columnIndex);
    }

    public function test_requirement_text_may_span_two_columns(): void
    {
        $path = $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Tittel', 'Utdypning', 'Svar'],
                ['K-1', 'Pålogging', 'Skal støtte SSO mot Entra ID.', null],
                ['K-2', 'Logging', 'Skal logge all tilgang i 12 mnd.', null],
                ['K-3', 'Drift', 'Skal ha 99,5 % oppetid.', null],
            ],
        ]);

        $discovery = $this->matrixDiscovery(['sheet' => [
            'field_roles' => [
                ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
                ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Tittel', 'confidence' => 0.7],
                ['column_letter' => 'C', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Utdypning', 'confidence' => 0.9],
            ],
        ]]);

        $result = $this->validate($discovery, $path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(2, $result['schema']->requirementSheets[0]->rolesOf(WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT));
    }

    public function test_two_sheets_can_both_hold_requirements(): void
    {
        $path = $this->fixtures->build([
            'Funksjonelle krav' => [['ID', 'Krav'], ['F-1', 'Systemet skal ha søk.']],
            'Tekniske krav' => [['ID', 'Krav'], ['T-1', 'Systemet skal kjøre i EØS.']],
        ]);

        $sheetSchema = static fn (int $index, string $name): array => [
            'sheet_index' => $index,
            'sheet_name' => $name,
            'header_range' => 'A1:B1',
            'data_range' => 'A2:B2',
            'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_ROW,
            'grouping_column_letter' => null,
            'section_row_numbers' => [],
            'field_roles' => [
                ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
                ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.9],
            ],
            'warnings' => [],
            'confidence' => 0.9,
            'reason' => 'Same layout on both sheets.',
        ];

        $result = $this->validate([
            'requirement_sheets' => [$sheetSchema(0, 'Funksjonelle krav'), $sheetSchema(1, 'Tekniske krav')],
            'supporting_sheets' => [],
            'warnings' => [],
            'confidence' => 0.9,
        ], $path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(2, $result['schema']->requirementSheets);
    }

    public function test_supporting_sheets_are_kept_separate_from_requirement_sheets(): void
    {
        $path = $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Krav', 'Skal/Bør', 'Svar'],
                ['K-1', 'Systemet skal ha SSO.', 'Skal', null],
                ['K-2', 'Systemet bør ha SCIM.', 'Bør', null],
                ['K-3', 'Systemet skal logge.', 'Skal', null],
            ],
            'Prisskjema' => [['Post', 'Pris'], ['Lisens', 1000]],
        ]);

        $result = $this->validate($this->matrixDiscovery([
            'supporting_sheets' => [['sheet_index' => 1, 'sheet_name' => 'Prisskjema', 'reason' => 'Pricing, not requirements.']],
        ]), $path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(1, $result['schema']->requirementSheets);
        $this->assertSame(1, $result['schema']->supportingSheets[0]['sheet_index']);
    }

    public function test_section_rows_and_grouped_units_are_carried_through(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['ID', 'Krav'],
                ['Sikkerhet', null],
                ['K-1', 'Systemet skal kryptere.'],
                ['Drift', null],
                ['K-2', 'Systemet skal overvåkes.'],
            ],
        ]);

        $result = $this->validate($this->matrixDiscovery(['sheet' => [
            'sheet_name' => 'Krav',
            'header_range' => 'A1:B1',
            'data_range' => 'A2:B5',
            'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_SECTION_GROUPED_ROW,
            'section_row_numbers' => [2, 4],
            'field_roles' => [
                ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.8],
                ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.9],
            ],
        ]]), $path);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([2, 4], $result['schema']->requirementSheets[0]->sectionRowNumbers);
        $this->assertSame(WorkbookSheetSchemaData::UNIT_SECTION_GROUPED_ROW, $result['schema']->requirementSheets[0]->logicalUnitStrategy);
    }

    public function test_a_requirement_spanning_several_rows_needs_a_grouping_column(): void
    {
        $path = $this->matrixWorkbookPath();

        $withColumn = $this->validate($this->matrixDiscovery(['sheet' => [
            'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_MERGED_GROUP,
            'grouping_column_letter' => 'A',
        ]]), $path);

        $withoutColumn = $this->validate($this->matrixDiscovery(['sheet' => [
            'logical_unit_strategy' => WorkbookSheetSchemaData::UNIT_MERGED_GROUP,
            'grouping_column_letter' => null,
        ]]), $path);

        $this->assertTrue($withColumn['is_valid']);
        $this->assertSame('A', $withColumn['schema']->requirementSheets[0]->groupingColumnLetter);
        $this->assertFalse($withoutColumn['is_valid'], 'Grouping rows without saying what groups them is not actionable.');
    }

    // ── Validation: refusing invented coordinates ────────────────────────────

    public function test_a_sheet_index_that_does_not_exist_is_refused(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['sheet_index' => 7]]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertNull($result['schema']);
        $this->assertSame('sheet_index', $result['rejected_references'][0]['kind']);
    }

    public function test_a_sheet_name_that_contradicts_its_index_is_refused(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['sheet_name' => 'Et annet ark']]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertSame('sheet_name', $result['rejected_references'][0]['kind']);
    }

    public function test_a_range_running_past_the_end_of_the_sheet_is_refused_not_clamped(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['data_range' => 'A2:D900']]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertNull($result['schema'], 'The range must not be trimmed to fit.');
        $this->assertStringContainsString('rows end at 4', $result['rejected_references'][0]['reason']);
    }

    public function test_a_malformed_or_reversed_range_is_refused(): void
    {
        $path = $this->matrixWorkbookPath();

        $this->assertFalse($this->validate($this->matrixDiscovery(['sheet' => ['data_range' => 'ikke-en-range']]), $path)['is_valid']);
        $this->assertFalse($this->validate($this->matrixDiscovery(['sheet' => ['data_range' => 'D4:A2']]), $path)['is_valid']);
    }

    public function test_a_cross_sheet_range_is_refused(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['data_range' => 'Ark2!A2:D4']]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('cross-sheet', $result['rejected_references'][0]['reason']);
    }

    public function test_a_column_that_does_not_exist_is_refused(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => [
            'field_roles' => [
                ['column_letter' => 'B', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT, 'header_label' => 'Krav', 'confidence' => 0.9],
                ['column_letter' => 'Z', 'role' => WorkbookFieldRoleData::ROLE_WEIGHTING, 'header_label' => 'Vekt', 'confidence' => 0.5],
            ],
        ]]), $this->matrixWorkbookPath());

        // The invented column is dropped; the sheet survives on its real columns.
        $this->assertTrue($result['is_valid']);
        $this->assertSame(['B'], array_map(
            static fn ($field): string => $field->columnLetter,
            $result['schema']->requirementSheets[0]->fieldRoles,
        ));
        $this->assertSame('Z', $result['rejected_references'][0]['value']);
    }

    public function test_a_schema_without_any_requirement_text_column_fails_closed(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => [
            'field_roles' => [
                ['column_letter' => 'A', 'role' => WorkbookFieldRoleData::ROLE_REQUIREMENT_ID, 'header_label' => 'ID', 'confidence' => 0.9],
            ],
        ]]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertNull($result['schema']);
    }

    public function test_an_unsupported_logical_unit_strategy_fails_closed(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['logical_unit_strategy' => 'one_requirement_per_workbook']]), $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertSame('logical_unit_strategy', $result['rejected_references'][0]['kind']);
    }

    public function test_an_empty_discovery_result_fails_closed(): void
    {
        $result = $this->validate(['requirement_sheets' => [], 'supporting_sheets' => [], 'warnings' => [], 'confidence' => 0.1], $this->matrixWorkbookPath());

        $this->assertFalse($result['is_valid']);
        $this->assertNull($result['schema']);
    }

    public function test_a_section_row_outside_the_sheet_is_dropped_without_killing_the_schema(): void
    {
        $result = $this->validate($this->matrixDiscovery(['sheet' => ['section_row_numbers' => [2, 900]]]), $this->matrixWorkbookPath());

        $this->assertTrue($result['is_valid']);
        $this->assertSame([2], $result['schema']->requirementSheets[0]->sectionRowNumbers);
        $this->assertSame('section_row', $result['rejected_references'][0]['kind']);
    }

    // ── Orchestration ────────────────────────────────────────────────────────

    public function test_discovery_returns_a_validated_schema_and_a_content_free_trace(): void
    {
        $path = $this->matrixWorkbookPath();
        $discovery = $this->matrixDiscovery();

        $this->mock(WorkbookStructureDiscoveryAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('discoverStructure')->once()->andReturn([
                'discovery' => $discovery,
                'metrics' => ['latency_ms' => 10, 'input_tokens' => 100, 'output_tokens' => 50, 'orientation_chars' => 1234],
            ]));

        $result = app(WorkbookStructureDiscoveryService::class)->discover($this->parse($path));

        $this->assertTrue($result['is_valid']);
        $this->assertSame('A2:D4', $result['schema']->requirementSheets[0]->dataRange);
        $this->assertSame(1234, $result['trace']['orientation']['chars']);
        $this->assertCount(1, $result['trace']['sheets_considered']);

        // The trace is for debugging, not a copy of the customer's file.
        $encodedTrace = json_encode($result['trace']);
        $this->assertStringNotContainsString('Løsningen skal støtte SSO', $encodedTrace);
        $this->assertStringNotContainsString('sample_values', $encodedTrace);
    }

    public function test_discovery_returns_no_schema_when_validation_fails(): void
    {
        $this->mock(WorkbookStructureDiscoveryAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('discoverStructure')->once()->andReturn([
                'discovery' => $this->matrixDiscovery(['sheet' => ['sheet_index' => 9]]),
                'metrics' => ['latency_ms' => 10, 'input_tokens' => 100, 'output_tokens' => 50, 'orientation_chars' => 10],
            ]));

        $result = app(WorkbookStructureDiscoveryService::class)->discover($this->parse($this->matrixWorkbookPath()));

        $this->assertFalse($result['is_valid']);
        $this->assertNull($result['schema']);
        $this->assertNotSame([], $result['trace']['validation']['errors']);
    }

    public function test_this_phase_extracts_no_requirements(): void
    {
        foreach ([
            'app/Services/Ai/Requirements/Excel/WorkbookOrientationBuilder.php',
            'app/Services/Ai/Requirements/Excel/WorkbookSchemaValidator.php',
            'app/Services/Ai/Requirements/Excel/WorkbookStructureDiscoveryService.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            foreach (['RequirementExtraction', 'RequirementCandidate', 'SavedNoticeAiRequirement'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file} must not reach into requirement extraction.");
            }
        }
    }
}
