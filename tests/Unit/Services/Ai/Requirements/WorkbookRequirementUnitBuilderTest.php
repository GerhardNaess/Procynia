<?php

namespace Tests\Unit\Services\Ai\Requirements;

use App\Data\Ai\Requirements\Excel\WorkbookFieldRoleData;
use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;
use App\Data\Ai\Requirements\Excel\WorkbookSchemaData;
use App\Data\Ai\Requirements\Excel\WorkbookSheetSchemaData;
use App\Services\Ai\Requirements\Excel\WorkbookRequirementUnitBuilder;
use App\Services\Ai\Requirements\Excel\XlsxWorkbookParser;
use Tests\Support\XlsxFixtureBuilder;
use Tests\TestCase;

/**
 * Building logical requirements from a validated schema. Fully deterministic: no AI, no guessing
 * beyond what the schema states, and nothing produced that the workbook did not say.
 */
class WorkbookRequirementUnitBuilderTest extends TestCase
{
    private XlsxFixtureBuilder $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new XlsxFixtureBuilder;
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    private function role(string $letter, int $index, string $role): WorkbookFieldRoleData
    {
        return new WorkbookFieldRoleData(columnLetter: $letter, columnIndex: $index, role: $role);
    }

    /** @param  list<WorkbookFieldRoleData>  $roles */
    private function sheetSchema(
        int $sheetIndex,
        string $sheetName,
        string $dataRange,
        array $roles,
        string $strategy = WorkbookSheetSchemaData::UNIT_ROW,
        ?string $groupingColumn = null,
        array $sectionRows = [],
    ): WorkbookSheetSchemaData {
        return new WorkbookSheetSchemaData(
            sheetIndex: $sheetIndex,
            sheetName: $sheetName,
            headerRange: 'A1:A1',
            dataRange: $dataRange,
            logicalUnitStrategy: $strategy,
            fieldRoles: $roles,
            sectionRowNumbers: $sectionRows,
            groupingColumnLetter: $groupingColumn,
        );
    }

    private function build(string $path, WorkbookSheetSchemaData ...$sheets): array
    {
        $workbook = app(XlsxWorkbookParser::class)->parse($path);

        return app(WorkbookRequirementUnitBuilder::class)->build($workbook, new WorkbookSchemaData(requirementSheets: $sheets));
    }

    /** ID | Krav | Skal/Bør | Vekt | Svar | Kommentar — data on rows 2-4. */
    private function matrixPath(): string
    {
        return $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Krav', 'Skal/Bør', 'Vekt', 'Svar', 'Kommentar'],
                ['K-1', 'Løsningen skal støtte SSO.', 'Skal', '30', null, 'Se vedlegg 2'],
                ['K-2', 'Løsningen bør støtte SCIM.', 'Bør', '10', null, null],
                ['K-3', 'Løsningen skal logge tilgang.', 'Skal', '20', null, null],
            ],
        ]);
    }

    private function matrixSchema(): WorkbookSheetSchemaData
    {
        return $this->sheetSchema(0, 'Kravspesifikasjon', 'A2:F4', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('C', 2, WorkbookFieldRoleData::ROLE_QUALIFICATION),
            $this->role('D', 3, WorkbookFieldRoleData::ROLE_WEIGHTING),
            $this->role('E', 4, WorkbookFieldRoleData::ROLE_RESPONSE),
            $this->role('F', 5, WorkbookFieldRoleData::ROLE_COMMENT),
        ]);
    }

    // ── row strategy ─────────────────────────────────────────────────────────

    public function test_one_row_becomes_one_requirement_with_its_metadata_kept_separate(): void
    {
        $result = $this->build($this->matrixPath(), $this->matrixSchema());

        $this->assertCount(3, $result['units']);
        $this->assertTrue($result['is_complete']);

        $first = $result['units'][0];
        $this->assertSame('Løsningen skal støtte SSO.', $first->requirementText);
        $this->assertSame('K-1', $first->requirementId);
        $this->assertSame('Skal', $first->qualification);
        $this->assertSame('30', $first->weighting);
        $this->assertSame('Se vedlegg 2', $first->comment);
        // Metadata must not have been folded into the requirement wording.
        $this->assertStringNotContainsString('Skal/Bør', $first->requirementText);
        $this->assertStringNotContainsString('30', $first->requirementText);
    }

    public function test_column_order_in_the_schema_does_not_have_to_match_the_sheet(): void
    {
        $schema = $this->sheetSchema(0, 'Kravspesifikasjon', 'A2:F4', [
            $this->role('C', 2, WorkbookFieldRoleData::ROLE_QUALIFICATION),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
        ]);

        $unit = $this->build($this->matrixPath(), $schema)['units'][0];

        $this->assertSame('K-1', $unit->requirementId);
        $this->assertSame('Løsningen skal støtte SSO.', $unit->requirementText);
    }

    public function test_requirement_text_from_two_columns_is_combined_in_schema_order(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['ID', 'Korttekst', 'Utfyllende beskrivelse'],
                ['K-1', 'Pålogging', 'Skal støtte SSO mot Entra ID.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:C2', [
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('C', 2, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]);

        $unit = $this->build($path, $schema)['units'][0];

        $this->assertSame(['Pålogging', 'Skal støtte SSO mot Entra ID.'], $unit->requirementTextParts);
        $this->assertSame("Pålogging\n\nSkal støtte SSO mot Entra ID.", $unit->requirementText);
    }

    public function test_a_text_column_repeating_another_verbatim_is_not_duplicated(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Tittel', 'Beskrivelse'],
                ['Systemet skal ha logg.', 'Systemet skal ha logg.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:B2', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]);

        $unit = $this->build($path, $schema)['units'][0];

        $this->assertSame(['Systemet skal ha logg.'], $unit->requirementTextParts);
    }

    public function test_columns_the_schema_could_not_name_are_kept_rather_than_dropped(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Krav', 'Referanse'],
                ['Systemet skal ha logg.', 'ISO 27001 A.12.4'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:B2', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_OTHER),
        ]);

        $this->assertSame(['B' => 'ISO 27001 A.12.4'], $this->build($path, $schema)['units'][0]->otherFields);
    }

    // ── merged_group strategy ────────────────────────────────────────────────

    public function test_a_requirement_spanning_several_rows_becomes_one_unit(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['ID', 'Krav'],
                ['K-1', 'Systemet skal støtte SSO.'],
                [null, 'Og skal logge påloggingsforsøk.'],
                ['K-2', 'Systemet skal ha 99,5 % oppetid.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:B4', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ], WorkbookSheetSchemaData::UNIT_MERGED_GROUP, 'A');

        $units = $this->build($path, $schema)['units'];

        $this->assertCount(2, $units);
        $this->assertSame(2, $units[0]->startRow);
        $this->assertSame(3, $units[0]->endRow);
        $this->assertSame("Systemet skal støtte SSO.\n\nOg skal logge påloggingsforsøk.", $units[0]->requirementText);
        $this->assertSame('K-1', $units[0]->requirementId);
        $this->assertSame(4, $units[1]->startRow);
        $this->assertSame(4, $units[1]->endRow);
    }

    public function test_a_merged_group_gets_one_source_range_spanning_all_its_rows(): void
    {
        $path = $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['ID', 'Krav', 'Svar'],
                ['K-1', 'Første del.', null],
                [null, 'Andre del.', null],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Kravspesifikasjon', 'A2:C3', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ], WorkbookSheetSchemaData::UNIT_MERGED_GROUP, 'A');

        $unit = $this->build($path, $schema)['units'][0];

        $this->assertSame('A2:C3', $unit->sourceRange);
        $this->assertSame('Kravspesifikasjon!A2:C3', $unit->humanSourceRef);
        $this->assertSame('sheet:0:range:A2:C3', $unit->sourceElementKey);
    }

    public function test_a_data_region_starting_mid_group_fails_closed(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['ID', 'Krav'],
                ['K-1', 'Første del.'],
                [null, 'Fortsettelse uten egen ID.'],
            ],
        ]);

        // The data range starts on row 3, which continues a group rather than beginning one.
        $schema = $this->sheetSchema(0, 'Krav', 'A3:B3', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ], WorkbookSheetSchemaData::UNIT_MERGED_GROUP, 'A');

        $result = $this->build($path, $schema);

        $this->assertSame([], $result['units']);
        $this->assertFalse($result['is_complete']);
        $this->assertStringContainsString('mid-group', $result['warnings'][0]);
    }

    // ── section_grouped_row strategy ─────────────────────────────────────────

    public function test_rows_inherit_the_nearest_preceding_section_and_sections_are_not_requirements(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Krav'],                       // 1 header
                ['2. Sikkerhet'],               // 2 section
                ['Systemet skal kryptere.'],    // 3
                ['Systemet skal ha MFA.'],      // 4
                ['3. Drift'],                   // 5 section
                ['Systemet skal overvåkes.'],   // 6
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:A6', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ], WorkbookSheetSchemaData::UNIT_SECTION_GROUPED_ROW, null, [2, 5]);

        $units = $this->build($path, $schema)['units'];

        $this->assertCount(3, $units, 'The two section rows must not become requirements.');
        $this->assertSame('2. Sikkerhet', $units[0]->sectionContext);
        $this->assertSame(2, $units[0]->sectionRowNumber);
        $this->assertSame('2. Sikkerhet', $units[1]->sectionContext);
        $this->assertSame('3. Drift', $units[2]->sectionContext);
        $this->assertSame('Systemet skal overvåkes.', $units[2]->requirementText);
    }

    public function test_rows_before_any_section_have_no_section_context(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Krav'],
                ['Et krav før noen seksjon.'],
                ['1. Sikkerhet'],
                ['Et krav i seksjonen.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:A4', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ], WorkbookSheetSchemaData::UNIT_SECTION_GROUPED_ROW, null, [3]);

        $units = $this->build($path, $schema)['units'];

        $this->assertNull($units[0]->sectionContext);
        $this->assertSame('1. Sikkerhet', $units[1]->sectionContext);
    }

    // ── what must never become a requirement ─────────────────────────────────

    public function test_an_empty_row_produces_no_requirement(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['ID', 'Krav'],
                ['K-1', 'Systemet skal ha logg.'],
                [null, null],
                ['K-2', 'Systemet skal ha MFA.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:B4', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]);

        $result = $this->build($path, $schema);

        $this->assertCount(2, $result['units']);
        $this->assertSame(3, $result['skipped'][0]['start_row']);
    }

    public function test_a_row_with_only_a_response_is_not_a_requirement(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Krav', 'Svar'],
                [null, 'Ja, dette støttes.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:B2', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_RESPONSE),
        ]);

        $result = $this->build($path, $schema);

        $this->assertSame([], $result['units']);
        $this->assertStringContainsString('no requirement text', $result['skipped'][0]['reason']);
    }

    // ── provenance ───────────────────────────────────────────────────────────

    public function test_identical_text_on_two_rows_gets_different_source_keys(): void
    {
        $path = $this->fixtures->build([
            'Krav' => [
                ['Krav'],
                ['Systemet skal ha logg.'],
                ['Systemet skal ha logg.'],
            ],
        ]);

        $schema = $this->sheetSchema(0, 'Krav', 'A2:A3', [$this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT)]);
        $units = $this->build($path, $schema)['units'];

        $this->assertSame($units[0]->requirementText, $units[1]->requirementText);
        $this->assertSame('sheet:0:range:A2:A2', $units[0]->sourceElementKey);
        $this->assertSame('sheet:0:range:A3:A3', $units[1]->sourceElementKey);
    }

    public function test_the_same_range_on_two_sheets_gets_different_source_keys(): void
    {
        $path = $this->fixtures->build([
            'Funksjonelle krav' => [['Krav'], ['Systemet skal ha søk.']],
            'Tekniske krav' => [['Krav'], ['Systemet skal kjøre i EØS.']],
        ]);

        $units = $this->build(
            $path,
            $this->sheetSchema(0, 'Funksjonelle krav', 'A2:A2', [$this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT)]),
            $this->sheetSchema(1, 'Tekniske krav', 'A2:A2', [$this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT)]),
        )['units'];

        $this->assertCount(2, $units, 'Both requirement sheets must contribute units.');
        $this->assertSame('sheet:0:range:A2:A2', $units[0]->sourceElementKey);
        $this->assertSame('sheet:1:range:A2:A2', $units[1]->sourceElementKey);
        $this->assertSame('Funksjonelle krav!A2:A2', $units[0]->humanSourceRef);
        $this->assertSame('Tekniske krav!A2:A2', $units[1]->humanSourceRef);
    }

    public function test_source_keys_are_deterministic_across_runs(): void
    {
        $path = $this->matrixPath();

        $first = array_map(static fn (WorkbookRequirementUnitData $unit): string => $unit->sourceElementKey, $this->build($path, $this->matrixSchema())['units']);
        $second = array_map(static fn (WorkbookRequirementUnitData $unit): string => $unit->sourceElementKey, $this->build($path, $this->matrixSchema())['units']);

        $this->assertSame($first, $second);
        $this->assertSame(['sheet:0:range:A2:F2', 'sheet:0:range:A3:F3', 'sheet:0:range:A4:F4'], $first);
    }

    public function test_the_source_reference_payload_carries_excel_provenance(): void
    {
        $reference = $this->build($this->matrixPath(), $this->matrixSchema())['units'][0]->toSourceReference();

        $this->assertSame('sheet_range', $reference['source_element_type']);
        $this->assertSame('sheet:0:range:A2:F2', $reference['source_element_key']);
        $this->assertSame('Kravspesifikasjon!A2:F2', $reference['source_label']);
        $this->assertSame('Skal', $reference['source_qualification']);
        $this->assertSame('30', $reference['source_weighting']);
    }

    // ── whole data region, hidden sheets, truncation ─────────────────────────

    public function test_the_builder_reads_the_whole_data_region_not_just_the_sampled_rows(): void
    {
        $rows = [['ID', 'Krav']];

        for ($index = 1; $index <= 300; $index++) {
            $rows[] = ['K-'.$index, 'Krav nummer '.$index];
        }

        $path = $this->fixtures->build(['Krav' => $rows]);
        $schema = $this->sheetSchema(0, 'Krav', 'A2:B301', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]);

        $units = $this->build($path, $schema)['units'];

        // Structure discovery only ever saw a sample; unit building must see every row.
        $this->assertCount(300, $units);
        $this->assertSame('Krav nummer 300', $units[299]->requirementText);
    }

    public function test_a_hidden_sheet_produces_units_when_the_schema_approved_it(): void
    {
        $path = $this->fixtures->build(
            [
                'Forside' => [['Anskaffelse 2026/1']],
                'Skjulte krav' => [['Krav'], ['Systemet skal ha logg.']],
            ],
            [],
            ['Skjulte krav'],
        );

        $units = $this->build($path, $this->sheetSchema(1, 'Skjulte krav', 'A2:A2', [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]))['units'];

        $this->assertCount(1, $units);
        $this->assertSame('Systemet skal ha logg.', $units[0]->requirementText);
    }

    public function test_a_truncated_sheet_is_reported_as_incomplete_rather_than_passed_off_as_whole(): void
    {
        $rows = [['ID', 'Krav']];

        for ($index = 1; $index <= XlsxWorkbookParser::MAX_ROWS_PER_SHEET + 20; $index++) {
            $rows[] = ['K-'.$index, 'Krav nummer '.$index];
        }

        $path = $this->fixtures->build(['Krav' => $rows]);
        $schema = $this->sheetSchema(0, 'Krav', 'A2:B'.XlsxWorkbookParser::MAX_ROWS_PER_SHEET, [
            $this->role('A', 0, WorkbookFieldRoleData::ROLE_REQUIREMENT_ID),
            $this->role('B', 1, WorkbookFieldRoleData::ROLE_REQUIREMENT_TEXT),
        ]);

        $result = $this->build($path, $schema);

        $this->assertFalse($result['is_complete']);
        $this->assertStringContainsString('truncated', $result['warnings'][0]);
        // The units it did build are still real and still returned.
        $this->assertNotSame([], $result['units']);
    }

    public function test_the_builder_uses_no_ai(): void
    {
        $source = file_get_contents(base_path('app/Services/Ai/Requirements/Excel/WorkbookRequirementUnitBuilder.php'));

        // Symbols, not prose: the class docblock legitimately discusses what it does NOT do.
        foreach (['OpenAiClient', 'AiClient', 'createResponse', 'similar_text', 'levenshtein', 'Embedding'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The builder must stay deterministic: found {$forbidden}.");
        }
    }
}
