<?php

namespace Tests\Unit\Services;

use App\Services\Knowledge\KnowledgeDocumentStructureParser;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class KnowledgeDocumentStructureParserTest extends TestCase
{
    public function test_it_parses_headings_lists_tables_and_offsets_from_docx_documents(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>Forord før første heading.</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
            <w:r><w:t>Strategisk samhandling</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>Første avsnitt under hovedseksjonen.</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>• Første punkt i listen</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>• Andre punkt i listen</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
            <w:r><w:t>Underseksjon A</w:t></w:r>
        </w:p>
        <w:tbl>
            <w:tblGrid>
                <w:gridCol w:w="2400"/>
                <w:gridCol w:w="2400"/>
            </w:tblGrid>
            <w:tr>
                <w:trPr><w:tblHeader/></w:trPr>
                <w:tc><w:p><w:r><w:t>Tabell A</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Tabell B</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Rad 1</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Rad 2</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

        $result = $this->parseDocxFixture($documentXml);

        $this->assertSame('docx', $result['document_format']);
        $this->assertGreaterThan(0, $result['word_count']);
        $this->assertStringContainsString('Forord før første heading.', $result['source_text']);
        $this->assertStringContainsString('Tabell A | Tabell B', $result['source_text']);
        $this->assertStringContainsString('Rad 1 | Rad 2', $result['source_text']);

        $elements = $result['elements'];

        $this->assertCount(4, $elements);
        $this->assertSame('paragraph', $elements[0]['type']);
        $this->assertNull($elements[0]['heading_path']);
        $this->assertSame('paragraph', $elements[1]['type']);
        $this->assertSame('Strategisk samhandling', $elements[1]['heading_path']);
        $this->assertSame('list', $elements[2]['type']);
        $this->assertSame('Strategisk samhandling', $elements[2]['heading_path']);
        $this->assertSame("• Første punkt i listen\n• Andre punkt i listen", $elements[2]['text']);
        $this->assertSame('list_group', $elements[2]['relation_hint']);
        $this->assertSame('table', $elements[3]['type']);
        $this->assertSame('Strategisk samhandling > Underseksjon A', $elements[3]['heading_path']);
        $this->assertSame('Strategisk samhandling > Underseksjon A', $elements[3]['heading_context']);
        $this->assertStringContainsString('Tabell A | Tabell B', $elements[3]['text']);
        $this->assertStringContainsString('Rad 1 | Rad 2', $elements[3]['text']);
        $this->assertStringContainsString('| Tabell A | Tabell B |', $elements[3]['table_markdown']);
        $this->assertStringContainsString('<table', (string) $elements[3]['table_html']);
        $this->assertSame('simple', $elements[3]['table_complexity']);
        $this->assertSame([], $elements[3]['table_warnings']);
        $this->assertSame($elements[3]['text'], $elements[3]['table_text']);
        $this->assertSame(2, $elements[3]['row_count']);
        $this->assertSame(2, $elements[3]['column_count']);
        $this->assertSame(0, $elements[3]['table_index_in_document']);
        $this->assertSame(0, $elements[0]['start_offset']);
        $this->assertGreaterThan($elements[0]['start_offset'], $elements[0]['end_offset']);
    }

    public function test_it_detects_title_rows_and_keeps_them_out_of_column_headers(): void
    {
        $documentXml = $this->buildDocxDocumentXml([
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
    <w:r><w:t>Kapittel 1</w:t></w:r>
</w:p>
XML,
            <<<'XML'
<w:tbl>
    <w:tblGrid>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
    </w:tblGrid>
    <w:tr>
        <w:tc>
            <w:tcPr><w:gridSpan w:val="3"/></w:tcPr>
            <w:p><w:r><w:t>Oversikt over tjenester</w:t></w:r></w:p>
        </w:tc>
    </w:tr>
    <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc><w:p><w:r><w:t>Navn</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Type</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc><w:p><w:r><w:t>Tjeneste A</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Kritisk</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Aktiv</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML,
        ]);

        $result = $this->parseDocxFixture($documentXml);
        $tableElement = $this->firstTableElement($result['elements']);

        $this->assertNotNull($tableElement);
        $this->assertSame('table', $tableElement['type']);
        $this->assertSame('complex', $tableElement['table_complexity']);
        $this->assertContains('title_row_detected', $tableElement['table_warnings']);
        $this->assertNotContains('markdown_is_simplified', $tableElement['table_warnings']);
        $this->assertSame(0, $tableElement['table_json']['title_row_index']);
        $this->assertSame([1], $tableElement['table_json']['header_row_indices']);
        $this->assertSame('title', $tableElement['table_json']['rows'][0]['row_type']);
        $this->assertSame('header', $tableElement['table_json']['rows'][1]['row_type']);
        $this->assertTrue($tableElement['table_json']['rows'][0]['is_title']);
        $this->assertTrue($tableElement['table_json']['rows'][1]['is_header']);
        $this->assertStringContainsString('Oversikt over tjenester', (string) $tableElement['table_html']);
        $this->assertStringContainsString('colspan="3"', (string) $tableElement['table_html']);
        $this->assertStringContainsString('Oversikt over tjenester', (string) $tableElement['table_markdown']);
    }

    public function test_it_keeps_pre_h2_text_and_tables_under_the_previous_h1_context(): void
    {
        $documentXml = $this->buildDocxDocumentXml([
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
    <w:r><w:t>1 Overskrift test</w:t></w:r>
</w:p>
XML,
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
    <w:r><w:t>Tekst før tabell.</w:t></w:r>
</w:p>
XML,
            <<<'XML'
<w:tbl>
    <w:tblGrid>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
    </w:tblGrid>
    <w:tr>
        <w:tc>
            <w:tcPr><w:gridSpan w:val="2"/></w:tcPr>
            <w:p><w:r><w:t>Heading</w:t></w:r></w:p>
        </w:tc>
    </w:tr>
    <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc><w:p><w:r><w:t>Kolonne A</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Kolonne B</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc><w:p><w:r><w:t>Verdi 1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Verdi 2</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML,
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
    <w:r><w:t>1.1 Dokumentasjonskrav for drift</w:t></w:r>
</w:p>
XML,
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
    <w:r><w:t>Tekst etter H2.</w:t></w:r>
</w:p>
XML,
        ]);

        $result = $this->parseDocxFixture($documentXml);

        $this->assertSame('docx', $result['document_format']);
        $this->assertCount(3, $result['elements']);

        $paragraphElement = $result['elements'][0];
        $tableElement = $result['elements'][1];
        $h2Element = $result['elements'][2];

        $this->assertSame('paragraph', $paragraphElement['type']);
        $this->assertSame('1 Overskrift test', $paragraphElement['heading_path']);
        $this->assertSame('1 Overskrift test', $paragraphElement['heading_context']);
        $this->assertStringContainsString('Tekst før tabell.', $paragraphElement['text']);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', $paragraphElement['text']);

        $this->assertSame('table', $tableElement['type']);
        $this->assertSame('1 Overskrift test', $tableElement['heading_path']);
        $this->assertSame('1 Overskrift test', $tableElement['heading_context']);
        $this->assertSame('title', $tableElement['table_json']['rows'][0]['row_type']);
        $this->assertSame(0, $tableElement['table_json']['title_row_index']);
        $this->assertSame('complex', $tableElement['table_complexity']);
        $this->assertContains('title_row_detected', $tableElement['table_warnings']);
        $this->assertStringContainsString('Heading', (string) $tableElement['table_html']);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', $tableElement['text']);

        $this->assertSame('h2_section', $h2Element['type']);
        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $h2Element['heading_path']);
        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $h2Element['heading_context']);
        $this->assertStringContainsString('Tekst etter H2.', $h2Element['text']);
        $this->assertStringNotContainsString('Tekst før tabell.', $h2Element['text']);
    }

    public function test_it_preserves_grid_span_cells_in_table_json_and_html(): void
    {
        $documentXml = $this->buildDocxDocumentXml([
            <<<'XML'
<w:tbl>
    <w:tblGrid>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
    </w:tblGrid>
    <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc>
            <w:tcPr><w:gridSpan w:val="2"/></w:tcPr>
            <w:p><w:r><w:t>Område</w:t></w:r></w:p>
        </w:tc>
        <w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc><w:p><w:r><w:t>Operativt</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>24/7</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Aktiv</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML,
        ]);

        $result = $this->parseDocxFixture($documentXml);
        $tableElement = $this->firstTableElement($result['elements']);

        $this->assertNotNull($tableElement);
        $this->assertSame('complex', $tableElement['table_complexity']);
        $this->assertContains('merged_cells_detected', $tableElement['table_warnings']);
        $this->assertNotContains('markdown_is_simplified', $tableElement['table_warnings']);
        $this->assertSame(2, $tableElement['table_json']['rows'][0]['cells'][0]['colspan']);
        $this->assertSame(2, $tableElement['table_json']['rows'][0]['cells'][0]['source_metadata']['grid_span']);
        $this->assertStringContainsString('colspan="2"', (string) $tableElement['table_html']);
    }

    public function test_it_preserves_vertical_merge_information_in_table_json_and_html(): void
    {
        $documentXml = $this->buildDocxDocumentXml([
            <<<'XML'
<w:tbl>
    <w:tblGrid>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
    </w:tblGrid>
    <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc><w:p><w:r><w:t>Gruppe</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc>
            <w:tcPr><w:vMerge w:val="restart"/></w:tcPr>
            <w:p><w:r><w:t>Team A</w:t></w:r></w:p>
        </w:tc>
        <w:tc><w:p><w:r><w:t>Aktiv</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc>
            <w:tcPr><w:vMerge/></w:tcPr>
            <w:p><w:r><w:t></w:t></w:r></w:p>
        </w:tc>
        <w:tc><w:p><w:r><w:t>Oppfølging</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML,
        ]);

        $result = $this->parseDocxFixture($documentXml);
        $tableElement = $this->firstTableElement($result['elements']);

        $this->assertNotNull($tableElement);
        $this->assertSame('complex', $tableElement['table_complexity']);
        $this->assertContains('vertical_merge_detected', $tableElement['table_warnings']);
        $this->assertNotContains('markdown_is_simplified', $tableElement['table_warnings']);
        $this->assertSame(2, $tableElement['table_json']['rows'][1]['cells'][0]['rowspan']);
        $this->assertSame('restart', $tableElement['table_json']['rows'][1]['cells'][0]['source_metadata']['v_merge']);
        $this->assertSame('continue', $tableElement['table_json']['rows'][2]['cells'][0]['source_metadata']['v_merge']);
        $this->assertStringContainsString('rowspan="2"', (string) $tableElement['table_html']);
    }

    public function test_it_keeps_text_only_documents_without_table_elements_as_plain_text(): void
    {
        $documentXml = $this->buildDocxDocumentXml([
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
    <w:r><w:t>Første avsnitt.</w:t></w:r>
</w:p>
XML,
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
    <w:r><w:t>Andre avsnitt.</w:t></w:r>
</w:p>
XML,
        ]);

        $result = $this->parseDocxFixture($documentXml);

        $this->assertSame('docx', $result['document_format']);
        $this->assertCount(2, $result['elements']);
        $this->assertSame(['paragraph', 'paragraph'], array_values(array_map(
            static fn (array $element): string => (string) $element['type'],
            $result['elements'],
        )));
        $this->assertSame([], array_values(array_filter(
            $result['elements'],
            static fn (array $element): bool => (string) ($element['type'] ?? '') === 'table',
        )));
    }

    /**
     * Purpose: Return the first parsed table element from a parser result.
     * Inputs: The ordered parser elements.
     * Returns: The first table element or null when the document contains no tables.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<string, mixed>|null
     */
    private function firstTableElement(array $elements): ?array
    {
        foreach ($elements as $element) {
            if ((string) ($element['type'] ?? '') === 'table') {
                return $element;
            }
        }

        return null;
    }

    private function tempDocumentPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-structure-parser-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary fixture file.');
        }

        $targetPath = $path.'.'.$extension;

        if (! rename($path, $targetPath)) {
            @unlink($path);

            throw new RuntimeException('Unable to prepare a temporary fixture file.');
        }

        return $targetPath;
    }

    /**
     * Purpose: Parse a temporary DOCX fixture built from the supplied document XML.
     * Inputs: The WordprocessingML document.xml content and optional styles.xml content.
     * Returns: The parsed structure array produced by the knowledge document parser.
     * Side effects: Writes and deletes a temporary DOCX archive on disk.
     *
     * @return array<string, mixed>
     */
    private function parseDocxFixture(string $documentXml, ?string $stylesXml = null): array
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString('word/document.xml', $documentXml);

            if (is_string($stylesXml) && trim($stylesXml) !== '') {
                $zip->addFromString('word/styles.xml', $stylesXml);
            }

            $zip->close();

            return app(KnowledgeDocumentStructureParser::class)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Purpose: Wrap ordered body block fragments in a minimal DOCX document.xml payload.
     * Inputs: Ordered WordprocessingML body block fragments.
     * Returns: A complete document.xml payload with the standard DOCX namespace.
     * Side effects: None.
     *
     * @param array<int, string> $blocks
     */
    private function buildDocxDocumentXml(array $blocks): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .implode("\n", $blocks)
            .'</w:body>'
            .'</w:document>';
    }
}
