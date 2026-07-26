<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\DocxTableRowData;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\EnterpriseWiki\EnterpriseWikiTableBlockBuilder;
use Tests\TestCase;

class EnterpriseWikiTableBlockBuilderTest extends TestCase
{
    private function document(): EnterpriseWikiDocument
    {
        $document = new EnterpriseWikiDocument([
            'original_filename' => 'Tjenestebeskrivelse.docx',
            'file_hash_sha256' => str_repeat('a', 64),
        ]);
        $document->id = 7;

        return $document;
    }

    private function cell(int $columnIndex, ?string $header, string $columnKey, string $value): DocxTableCellData
    {
        return new DocxTableCellData(
            columnIndex: $columnIndex,
            originalHeader: $header,
            normalizedColumnKey: $columnKey,
            value: $value,
        );
    }

    private function simpleTable(): DocxTableData
    {
        return new DocxTableData(
            tableIndex: 2,
            headerLabels: ['Tjeneste', 'SLA', 'Pris'],
            rows: [
                new DocxTableRowData(
                    sourceRowKey: 'tbl2-row0',
                    tableIndex: 2,
                    rowIndex: 0,
                    charStart: 0,
                    charEnd: 10,
                    cells: [
                        $this->cell(0, 'Tjeneste', 'tjeneste', 'Administrert klient'),
                        $this->cell(1, 'SLA', 'sla', '99,5 %'),
                        $this->cell(2, 'Pris', 'pris', '£42'),
                    ],
                    sectionNumber: '2.1',
                    sectionTitle: 'Tjenestekatalog',
                ),
                new DocxTableRowData(
                    sourceRowKey: 'tbl2-row1',
                    tableIndex: 2,
                    rowIndex: 1,
                    charStart: 10,
                    charEnd: 20,
                    cells: [
                        $this->cell(0, 'Tjeneste', 'tjeneste', 'Standard support'),
                        $this->cell(1, 'SLA', 'sla', ''),
                        $this->cell(2, 'Pris', 'pris', '£10'),
                    ],
                    sectionNumber: '2.1',
                    sectionTitle: 'Tjenestekatalog',
                ),
            ],
            sectionNumber: '2.1',
            sectionTitle: 'Tjenestekatalog',
        );
    }

    // ── referencedTableIndexes ──────────────────────────────────────────────

    public function test_referenced_table_indexes_detects_table_row_citations(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;

        $blocks = [
            [
                'source_elements' => [
                    ['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW, 'source_row_key' => 'tbl2-row0'],
                ],
            ],
            [
                'source_elements' => [
                    ['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH, 'source_row_key' => null],
                ],
            ],
        ];

        $this->assertSame([2], $builder->referencedTableIndexes($blocks));
    }

    public function test_referenced_table_indexes_is_empty_when_no_table_rows_cited(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;

        $blocks = [
            ['source_elements' => [['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH]]],
        ];

        $this->assertSame([], $builder->referencedTableIndexes($blocks));
    }

    public function test_referenced_table_indexes_deduplicates_and_sorts_multiple_tables(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;

        $blocks = [
            ['source_elements' => [['source_element_type' => 'table_row', 'source_row_key' => 'tbl3-row0']]],
            ['source_elements' => [['source_element_type' => 'table_row', 'source_row_key' => 'tbl1-row2']]],
            ['source_elements' => [['source_element_type' => 'table_row', 'source_row_key' => 'tbl3-row1']]],
        ];

        $this->assertSame([1, 3], $builder->referencedTableIndexes($blocks));
    }

    // ── buildTableBlocks ─────────────────────────────────────────────────────

    public function test_build_table_blocks_produces_a_block_type_table_with_structured_data(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $table = $this->simpleTable();

        $blocks = $builder->buildTableBlocks($document, [$table], [2], 5);

        $this->assertCount(1, $blocks);
        $block = $blocks[0];

        $this->assertSame('table', $block['block_type']);
        $this->assertSame(5, $block['position']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $block['content_origin']);
        $this->assertSame($document->id, $block['source_id']);
        $this->assertSame(['Tjeneste', 'SLA', 'Pris'], $block['table_data']['headers']);
        $this->assertCount(2, $block['table_data']['rows']);
        $this->assertSame('Administrert klient', $block['table_data']['rows'][0]['label']);
        $this->assertSame('tbl2-row0', $block['table_data']['rows'][0]['row_key']);
    }

    public function test_build_table_blocks_renders_a_valid_markdown_table_as_fallback(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $blocks = $builder->buildTableBlocks($this->document(), [$this->simpleTable()], [2], 0);

        $markdown = $blocks[0]['markdown'];

        $this->assertStringContainsString('| Tjeneste | SLA | Pris |', $markdown);
        $this->assertStringContainsString('| --- | --- | --- |', $markdown);
        $this->assertStringContainsString('| Administrert klient | 99,5 % | £42 |', $markdown);
        // Empty cell must still render as an empty cell, not collapse the column.
        $this->assertStringContainsString('| Standard support |  | £10 |', $markdown);
    }

    public function test_build_table_blocks_escapes_pipe_characters_in_cell_values(): void
    {
        $table = new DocxTableData(
            tableIndex: 0,
            headerLabels: ['Navn'],
            rows: [
                new DocxTableRowData(
                    sourceRowKey: 'tbl0-row0',
                    tableIndex: 0,
                    rowIndex: 0,
                    charStart: 0,
                    charEnd: 1,
                    cells: [$this->cell(0, 'Navn', 'navn', 'A | B')],
                ),
            ],
        );

        $builder = new EnterpriseWikiTableBlockBuilder;
        $markdown = $builder->buildTableBlocks($this->document(), [$table], [0], 0)[0]['markdown'];

        $this->assertStringContainsString('A \\| B', $markdown);
    }

    public function test_build_table_blocks_skips_unreferenced_or_empty_tables(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;

        $this->assertSame([], $builder->buildTableBlocks($this->document(), [$this->simpleTable()], [], 0));
        $this->assertSame([], $builder->buildTableBlocks($this->document(), [$this->simpleTable()], [99], 0));
    }

    public function test_build_table_blocks_handles_multiple_tables_in_document_order(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $tableA = $this->simpleTable(); // tableIndex 2
        $tableB = new DocxTableData(
            tableIndex: 5,
            headerLabels: ['X'],
            rows: [new DocxTableRowData('tbl5-row0', 5, 0, 0, 1, [$this->cell(0, 'X', 'x', 'Y')])],
        );

        $blocks = $builder->buildTableBlocks($this->document(), [$tableA, $tableB], [2, 5], 0);

        $this->assertCount(2, $blocks);
        $this->assertSame(0, $blocks[0]['position']);
        $this->assertSame(1, $blocks[1]['position']);
        $this->assertSame('table-block-0003', $blocks[0]['block_key']);
        $this->assertSame('table-block-0006', $blocks[1]['block_key']);
    }

    // ── tableClaimPayloads ───────────────────────────────────────────────────

    public function test_table_claim_payloads_generates_one_claim_per_non_label_cell(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $block = $builder->buildTableBlocks($document, [$this->simpleTable()], [2], 0)[0];

        $payloads = $builder->tableClaimPayloads($document, $block);

        // Row 0: 2 non-label cells (SLA, Pris). Row 1: SLA is empty (skipped), Pris present — 1 cell.
        $this->assertCount(3, $payloads);

        $this->assertSame('Administrert klient: SLA er 99,5 %.', $payloads[0]['claim_text']);
        $this->assertSame('Administrert klient: Pris er £42.', $payloads[1]['claim_text']);
        $this->assertSame('Standard support: Pris er £10.', $payloads[2]['claim_text']);
    }

    public function test_table_claim_payloads_carry_precise_row_and_cell_provenance(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $block = $builder->buildTableBlocks($document, [$this->simpleTable()], [2], 0)[0];

        $payload = $builder->tableClaimPayloads($document, $block)[0];

        $this->assertSame('tbl2-row0', $payload['source_row_key']);
        $this->assertSame('tbl2-row0-col1', $payload['source_cell_key']);
        $this->assertSame('sla', $payload['source_column_key']);
        $this->assertSame(
            'Tjenestebeskrivelse.docx → Tabell 3 → Rad «Administrert klient» → Kolonne «SLA»',
            $payload['page_reference'],
        );
    }

    public function test_table_claim_payloads_skips_empty_cells(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $block = $builder->buildTableBlocks($document, [$this->simpleTable()], [2], 0)[0];

        $payloads = $builder->tableClaimPayloads($document, $block);

        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString('Standard support: SLA', $payload['claim_text']);
        }
    }

    public function test_table_claim_payloads_never_claims_about_the_labels_own_column(): void
    {
        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $block = $builder->buildTableBlocks($document, [$this->simpleTable()], [2], 0)[0];

        foreach ($builder->tableClaimPayloads($document, $block) as $payload) {
            $this->assertStringNotContainsString('Tjeneste er', $payload['claim_text']);
        }
    }

    public function test_table_claim_payloads_handle_norwegian_characters_and_currency(): void
    {
        $table = new DocxTableData(
            tableIndex: 0,
            headerLabels: ['Tjeneste', 'Pris'],
            rows: [
                new DocxTableRowData(
                    sourceRowKey: 'tbl0-row0',
                    tableIndex: 0,
                    rowIndex: 0,
                    charStart: 0,
                    charEnd: 1,
                    cells: [
                        $this->cell(0, 'Tjeneste', 'tjeneste', 'Døgnbemannet støtte æøå'),
                        $this->cell(1, 'Pris', 'pris', 'kr 1 200,50'),
                    ],
                ),
            ],
        );

        $builder = new EnterpriseWikiTableBlockBuilder;
        $document = $this->document();
        $block = $builder->buildTableBlocks($document, [$table], [0], 0)[0];
        $payload = $builder->tableClaimPayloads($document, $block)[0];

        $this->assertSame('Døgnbemannet støtte æøå: Pris er kr 1 200,50.', $payload['claim_text']);
    }
}
