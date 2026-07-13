<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use Tests\TestCase;

/**
 * Purpose: Verify structured DOCX table rows are appended to the AI input text as canonical JSON.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class FullDocumentRequirementExtractionPromptTableRowsTest extends TestCase
{
    public function test_it_leaves_input_text_unchanged_when_no_structured_table_rows_are_given(): void
    {
        $documentText = 'Leverandøren skal levere dokumentasjon innen 10 dager.';

        $withoutTables = FullDocumentRequirementExtractionPrompt::inputTextForDocument($documentText);
        $withEmptyTables = FullDocumentRequirementExtractionPrompt::inputTextForDocumentWithTables($documentText, []);

        $this->assertSame($withoutTables, $withEmptyTables);
        $this->assertStringNotContainsString('STRUKTURERTE TABELLRADER', $withEmptyTables);
    }

    public function test_it_appends_a_canonical_json_block_with_source_row_key_and_cell_values(): void
    {
        $documentText = "Innledning.\n\n2.1 Buying responsibility, not activities";

        $row = new DocxTableRowData(
            sourceRowKey: 'doc10-tbl0-row0',
            tableIndex: 0,
            rowIndex: 0,
            charStart: 0,
            charEnd: 10,
            cells: [
                new DocxTableCellData(0, 'Req. No.', 'req_no', '2.1.1'),
                new DocxTableCellData(1, 'Requirement text', 'requirement_text', 'The Services in the Agreement are described in Annex 1.'),
                new DocxTableCellData(2, 'Type', 'type', 'M'),
                new DocxTableCellData(3, 'Response instruction', 'response_instruction', ''),
            ],
        );

        $result = FullDocumentRequirementExtractionPrompt::inputTextForDocumentWithTables(
            $documentText,
            [$row->toAiPayloadArray()],
        );

        $this->assertStringContainsString($documentText, $result);
        $this->assertStringContainsString('STRUKTURERTE TABELLRADER:', $result);
        $this->assertStringContainsString('doc10-tbl0-row0', $result);

        $tableBlockJson = trim(substr($result, strpos($result, 'STRUKTURERTE TABELLRADER:') + strlen('STRUKTURERTE TABELLRADER:')));
        $decoded = json_decode($tableBlockJson, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('doc10-tbl0-row0', $decoded[0]['source_row_key']);
        $this->assertSame('req_no', $decoded[0]['cells'][0]['column_key']);
        $this->assertSame('2.1.1', $decoded[0]['cells'][0]['value']);
        $this->assertSame('', $decoded[0]['cells'][3]['value']);
    }

    public function test_request_payload_schema_requires_source_row_key_and_permits_null(): void
    {
        $payload = FullDocumentRequirementExtractionPrompt::requestPayload('Leverandøren skal levere dokumentasjon innen 10 dager.');

        $required = data_get($payload, 'text.format.schema.properties.candidates.items.required');
        $properties = data_get($payload, 'text.format.schema.properties.candidates.items.properties');

        $this->assertContains('source_row_key', $required);
        $this->assertSame([
            ['type' => 'string'],
            ['type' => 'null'],
        ], $properties['source_row_key']['anyOf']);
    }
}
