<?php

namespace Tests\Unit\Services\Ai\Requirements;

use App\Data\Ai\Requirements\Excel\WorkbookCellData;
use App\Data\Ai\Requirements\Excel\WorkbookCoordinate;
use App\Services\Ai\Requirements\Excel\XlsxWorkbookParser;
use OpenSpout\Common\Entity\Cell;
use RuntimeException;
use Tests\Support\XlsxFixtureBuilder;
use Tests\TestCase;

/**
 * The parser's whole job is to preserve structure without interpreting it. These tests pin both
 * halves: coordinates and identities stay true to the file, and nothing here decides what a
 * requirement is.
 */
class XlsxWorkbookParserTest extends TestCase
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

    private function parse(string $path)
    {
        return app(XlsxWorkbookParser::class)->parse($path);
    }

    private function cellAt($sheet, string $coordinate): ?WorkbookCellData
    {
        foreach ($sheet->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->coordinate === $coordinate) {
                    return $cell;
                }
            }
        }

        return null;
    }

    public function test_a_single_sheet_workbook_is_parsed_with_real_coordinates(): void
    {
        $path = $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['Krav-ID', 'Beskrivelse', 'Vekt'],
                ['K-1', 'Løsningen skal støtte pålogging.', 3],
            ],
        ]);

        $workbook = $this->parse($path);

        $this->assertCount(1, $workbook->sheets);
        $sheet = $workbook->sheets[0];
        $this->assertSame('Kravspesifikasjon', $sheet->name);
        $this->assertSame(0, $sheet->index);
        $this->assertTrue($sheet->isVisible);
        $this->assertSame('A1:C2', $sheet->dimensionRef());

        $this->assertSame('Krav-ID', $this->cellAt($sheet, 'A1')?->value);
        $this->assertSame('Løsningen skal støtte pålogging.', $this->cellAt($sheet, 'B2')?->value);
        $this->assertSame(1, $this->cellAt($sheet, 'B2')?->columnIndex);
        $this->assertSame('B', $this->cellAt($sheet, 'B2')?->columnLetter);
    }

    public function test_several_sheets_keep_their_own_names_and_indexes(): void
    {
        $path = $this->fixtures->build([
            'Innledning' => [['Om anskaffelsen']],
            'Kravspesifikasjon' => [['Krav-ID', 'Beskrivelse']],
            'Prisskjema' => [['Post', 'Pris']],
        ]);

        $workbook = $this->parse($path);

        $this->assertSame(['Innledning', 'Kravspesifikasjon', 'Prisskjema'], array_map(
            static fn ($sheet): string => $sheet->name,
            $workbook->sheets,
        ));
        $this->assertSame([0, 1, 2], array_map(static fn ($sheet): int => $sheet->index, $workbook->sheets));
    }

    public function test_text_and_numeric_cells_keep_their_types(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [['Tekst', 42, 3.5, true]],
        ]);

        $sheet = $this->parse($path)->sheets[0];

        $this->assertSame(WorkbookCellData::TYPE_STRING, $this->cellAt($sheet, 'A1')?->dataType);
        $this->assertSame(WorkbookCellData::TYPE_NUMERIC, $this->cellAt($sheet, 'B1')?->dataType);
        $this->assertSame(42, $this->cellAt($sheet, 'B1')?->value);
        $this->assertSame(WorkbookCellData::TYPE_NUMERIC, $this->cellAt($sheet, 'C1')?->dataType);
        $this->assertSame(WorkbookCellData::TYPE_BOOLEAN, $this->cellAt($sheet, 'D1')?->dataType);
    }

    public function test_a_blank_row_inside_the_sheet_is_kept_so_later_coordinates_do_not_shift(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [
                ['Seksjon A'],
                [null],
                ['Krav etter tom rad'],
            ],
        ]);

        $sheet = $this->parse($path)->sheets[0];

        $this->assertCount(3, $sheet->rows);
        $this->assertTrue($sheet->rows[1]->isEmpty());
        // The point of keeping the blank row: the content below still reports row 3.
        $this->assertSame('Krav etter tom rad', $this->cellAt($sheet, 'A3')?->value);
        $this->assertSame(3, $sheet->lastRow);
    }

    public function test_an_entirely_empty_sheet_reports_no_used_range(): void
    {
        $path = $this->fixtures->build([
            'Tomt' => [[null, null]],
        ]);

        $sheet = $this->parse($path)->sheets[0];

        $this->assertSame([], $sheet->rows);
        $this->assertNull($sheet->dimensionRef());
    }

    public function test_merged_cells_are_preserved_with_an_origin_and_continuations(): void
    {
        $path = $this->fixtures->build(
            ['Kravspesifikasjon' => [
                ['Krav-ID', 'Beskrivelse', 'Kommentar'],
                ['K-1', 'Sammenslått beskrivelse', null],
                [null, null, null],
            ]],
            // OpenSpout takes 0-based columns here: B2:C3.
            [['sheet' => 'Kravspesifikasjon', 'range' => [1, 2, 2, 3]]],
        );

        $sheet = $this->parse($path)->sheets[0];

        $this->assertContains('B2:C3', $sheet->mergedRanges);

        $origin = $this->cellAt($sheet, 'B2');
        $this->assertSame('B2:C3', $origin?->mergeRange);
        $this->assertTrue($origin?->isMergeOrigin);
        $this->assertSame('Sammenslått beskrivelse', $origin?->value);

        $continuation = $this->cellAt($sheet, 'C2');
        $this->assertSame('B2:C3', $continuation?->mergeRange);
        $this->assertTrue($continuation?->isMergeContinuation());
    }

    public function test_a_formula_keeps_its_expression_and_the_value_excel_cached(): void
    {
        $path = $this->fixtures->withCachedFormulaValues(
            $this->fixtures->build([
                'Ark1' => [[
                    Cell::fromValue(2),
                    Cell::fromValue(3),
                    new Cell\FormulaCell('=SUM(A1:B1)', null, null),
                ]],
            ]),
            ['C1' => '5'],
        );

        $sheet = $this->parse($path)->sheets[0];
        $formulaCell = $this->cellAt($sheet, 'C1');

        $this->assertNotNull($formulaCell);
        $this->assertSame('=SUM(A1:B1)', $formulaCell->formula);
        // Read from the file's cached <v>, never computed here.
        $this->assertSame(5, $formulaCell->value);
        $this->assertSame(WorkbookCellData::TYPE_NUMERIC, $formulaCell->dataType);
    }

    public function test_a_formula_without_a_cached_value_does_not_get_one_invented(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [[
                Cell::fromValue(2),
                Cell::fromValue(3),
                new Cell\FormulaCell('=SUM(A1:B1)', null, null),
            ]],
        ]);

        $formulaCell = $this->cellAt($this->parse($path)->sheets[0], 'C1');

        $this->assertSame('=SUM(A1:B1)', $formulaCell?->formula);
        $this->assertNotSame(5, $formulaCell?->value, 'The parser must not evaluate the formula.');
    }

    public function test_a_hidden_sheet_is_kept_and_flagged_rather_than_dropped(): void
    {
        $path = $this->fixtures->build(
            [
                'Synlig' => [['Krav']],
                'Skjult' => [['Skjulte krav teller også']],
            ],
            [],
            ['Skjult'],
        );

        $workbook = $this->parse($path);

        $this->assertCount(2, $workbook->sheets, 'A hidden sheet must not disappear silently.');
        $this->assertFalse($workbook->sheetByIndex(1)?->isVisible);
        $this->assertSame(['Synlig'], array_map(static fn ($sheet): string => $sheet->name, $workbook->visibleSheets()));
        $this->assertSame('Skjulte krav teller også', $this->cellAt($workbook->sheets[1], 'A1')?->value);
    }

    public function test_norwegian_text_survives_intact(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [['Løsningen skal håndtere æøå og «sitattegn» – korrekt.']],
        ]);

        $sheet = $this->parse($path)->sheets[0];

        $this->assertSame('Løsningen skal håndtere æøå og «sitattegn» – korrekt.', $this->cellAt($sheet, 'A1')?->value);
    }

    public function test_two_cells_holding_the_same_value_get_different_source_keys(): void
    {
        $path = $this->fixtures->build([
            'Kravspesifikasjon' => [
                ['Skal støtte SSO'],
                ['Skal støtte SSO'],
            ],
        ]);

        $sheet = $this->parse($path)->sheets[0];

        $first = WorkbookCoordinate::cellKey($sheet->index, $this->cellAt($sheet, 'A1')->coordinate);
        $second = WorkbookCoordinate::cellKey($sheet->index, $this->cellAt($sheet, 'A2')->coordinate);

        $this->assertSame($this->cellAt($sheet, 'A1')->value, $this->cellAt($sheet, 'A2')->value);
        $this->assertNotSame($first, $second, 'Identity must be positional, never derived from the value.');
        $this->assertSame('sheet:0:cell:A1', $first);
    }

    public function test_the_same_coordinate_on_two_sheets_gets_different_source_keys(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [['Krav']],
            'Ark2' => [['Krav']],
        ]);

        $workbook = $this->parse($path);

        $this->assertNotSame(
            WorkbookCoordinate::cellKey($workbook->sheets[0]->index, 'A1'),
            WorkbookCoordinate::cellKey($workbook->sheets[1]->index, 'A1'),
        );
    }

    public function test_every_source_key_in_a_workbook_is_unique(): void
    {
        $path = $this->fixtures->build([
            'Ark1' => [['a', 'a', 'a'], ['a', 'a', 'a']],
            'Ark2' => [['a', 'a', 'a'], ['a', 'a', 'a']],
        ]);

        $keys = [];

        foreach ($this->parse($path)->sheets as $sheet) {
            foreach ($sheet->rows as $row) {
                foreach ($row->cells as $cell) {
                    $keys[] = WorkbookCoordinate::cellKey($sheet->index, $cell->coordinate);
                }
            }
        }

        $this->assertCount(12, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)), 'No two cells may share a source key.');
    }

    public function test_a_range_label_is_human_readable_while_the_key_stays_machine_stable(): void
    {
        // The label is what a user reads; the key is what the system stores. A sheet name may
        // legally contain ':' and '!', which is exactly why the key uses the index instead.
        $this->assertSame(
            'Kravspesifikasjon!B17:G18',
            WorkbookCoordinate::label('Kravspesifikasjon', 'B17:G18'),
        );
        $this->assertSame('sheet:1:range:B17:G18', WorkbookCoordinate::rangeKey(1, 'B17:G18'));
        $this->assertSame('sheet:1:row:17', WorkbookCoordinate::rowKey(1, 17));
    }

    public function test_column_letters_and_indexes_round_trip_beyond_z(): void
    {
        foreach ([0 => 'A', 25 => 'Z', 26 => 'AA', 51 => 'AZ', 52 => 'BA', 701 => 'ZZ'] as $index => $letter) {
            $this->assertSame($letter, WorkbookCoordinate::columnLetter($index));
            $this->assertSame($index, WorkbookCoordinate::columnIndex($letter));
        }
    }

    public function test_an_unreadable_file_fails_loudly_instead_of_returning_an_empty_workbook(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parse(sys_get_temp_dir().'/procynia-does-not-exist-'.bin2hex(random_bytes(4)).'.xlsx');
    }

    public function test_the_parser_does_no_requirement_extraction_and_makes_no_ai_calls(): void
    {
        $source = file_get_contents(base_path('app/Services/Ai/Requirements/Excel/XlsxWorkbookParser.php'));

        foreach (['OpenAiClient', 'RequirementExtraction', 'RequirementCandidate', 'createResponse', 'AiClient'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The parser must stay a parser: found {$forbidden}.");
        }
    }
}
