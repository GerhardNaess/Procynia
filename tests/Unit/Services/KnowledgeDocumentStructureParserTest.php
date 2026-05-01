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

    public function test_it_extracts_images_as_dedicated_elements_with_caption_and_heading_context(): void
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
    <w:r><w:t>Tekst før bilde.</w:t></w:r>
</w:p>
XML,
            $this->docxImageParagraphXml('rId1', 'Figure 1', 'Arkitekturdiagram med integrasjoner'),
            <<<'XML'
<w:p>
    <w:pPr><w:pStyle w:val="Caption"/></w:pPr>
    <w:r><w:t>Figur 1: Overordnet arkitektur</w:t></w:r>
</w:p>
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

        $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;
        $mediaFiles = [
            'word/media/image1.png' => $this->docxSampleImageBytes(),
        ];

        $result = $this->parseDocxFixture($documentXml, null, $relationshipsXml, $mediaFiles);

        $this->assertSame('docx', $result['document_format']);
        $this->assertCount(3, $result['elements']);

        $paragraphElement = $result['elements'][0];
        $imageElement = $result['elements'][1];
        $h2Element = $result['elements'][2];

        $this->assertSame('paragraph', $paragraphElement['type']);
        $this->assertSame('1 Overskrift test', $paragraphElement['heading_path']);
        $this->assertStringContainsString('Tekst før bilde.', $paragraphElement['text']);

        $this->assertSame('image', $imageElement['type']);
        $this->assertSame('1 Overskrift test', $imageElement['heading_path']);
        $this->assertSame('1 Overskrift test', $imageElement['heading_context']);
        $this->assertSame('Arkitekturdiagram med integrasjoner', $imageElement['image_alt_text']);
        $this->assertSame('Figur 1: Overordnet arkitektur', $imageElement['image_caption']);
        $this->assertSame('image/png', $imageElement['image_metadata']['mime_type']);
        $this->assertSame('png', $imageElement['image_metadata']['extension']);
        $this->assertSame('unknown', $imageElement['image_metadata']['image_kind']);
        $this->assertSame(1, $imageElement['image_metadata']['width']);
        $this->assertSame(1, $imageElement['image_metadata']['height']);
        $this->assertSame('rId1', $imageElement['image_metadata']['relationship_id']);
        $this->assertStringContainsString('Bilde i seksjon: 1 Overskrift test', $imageElement['text']);
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', $imageElement['text']);
        $this->assertStringContainsString('Arkitekturdiagram med integrasjoner', $imageElement['text']);

        $this->assertSame('h2_section', $h2Element['type']);
        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $h2Element['heading_path']);
        $this->assertStringContainsString('Tekst etter H2.', $h2Element['text']);
        $this->assertStringNotContainsString('Tekst før bilde.', $h2Element['text']);
        $this->assertStringNotContainsString('Figur 1: Overordnet arkitektur', $h2Element['text']);
    }

    public function test_it_keeps_images_before_h2_under_the_previous_h1_context_even_when_tables_precede_them(): void
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
        <w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>B</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML,
            $this->docxImageParagraphXml('rId1', 'Figure 1', 'Arkitekturdiagram med integrasjoner'),
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

        $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;
        $mediaFiles = [
            'word/media/image1.png' => $this->docxSampleImageBytes(),
        ];

        $result = $this->parseDocxFixture($documentXml, null, $relationshipsXml, $mediaFiles);

        $this->assertSame('docx', $result['document_format']);
        $this->assertCount(4, $result['elements']);

        $paragraphElement = $result['elements'][0];
        $tableElement = $result['elements'][1];
        $imageElement = $result['elements'][2];
        $h2Element = $result['elements'][3];

        $this->assertSame('paragraph', $paragraphElement['type']);
        $this->assertSame('1 Overskrift test', $paragraphElement['heading_path']);
        $this->assertStringContainsString('Tekst før tabell.', $paragraphElement['text']);

        $this->assertSame('table', $tableElement['type']);
        $this->assertSame('1 Overskrift test', $tableElement['heading_path']);
        $this->assertSame('1 Overskrift test', $tableElement['heading_context']);
        $this->assertStringContainsString('Heading', $tableElement['text']);
        $this->assertStringContainsString('A | B', $tableElement['text']);

        $this->assertSame('image', $imageElement['type']);
        $this->assertSame('1 Overskrift test', $imageElement['heading_path']);
        $this->assertSame('1 Overskrift test', $imageElement['heading_context']);
        $this->assertStringContainsString('Bilde i seksjon: 1 Overskrift test', $imageElement['text']);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', $imageElement['text']);

        $this->assertSame('h2_section', $h2Element['type']);
        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $h2Element['heading_path']);
        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $h2Element['heading_context']);
        $this->assertStringContainsString('Tekst etter H2.', $h2Element['text']);
        $this->assertStringNotContainsString('Tekst før tabell.', $h2Element['text']);
        $this->assertStringNotContainsString('Bilde i seksjon: 1 Overskrift test', $h2Element['text']);
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
    private function parseDocxFixture(string $documentXml, ?string $stylesXml = null, ?string $relationshipsXml = null, array $mediaFiles = []): array
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString('word/document.xml', $documentXml);

            if (is_string($stylesXml) && trim($stylesXml) !== '') {
                $zip->addFromString('word/styles.xml', $stylesXml);
            }

            if (is_string($relationshipsXml) && trim($relationshipsXml) !== '') {
                $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            }

            foreach ($mediaFiles as $mediaPath => $mediaBytes) {
                $zip->addFromString((string) $mediaPath, (string) $mediaBytes);
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

    /**
     * Purpose: Build one DOCX image paragraph for a parser fixture.
     * Inputs: The relationship id, image title, and alt text.
     * Returns: A WordprocessingML paragraph with an embedded drawing reference.
     * Side effects: None.
     */
    private function docxImageParagraphXml(string $relationshipId, string $title, string $altText): string
    {
        $relationshipId = htmlspecialchars($relationshipId, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $title = htmlspecialchars($title, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $altText = htmlspecialchars($altText, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return <<<XML
<w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
    <w:r>
        <w:drawing>
            <wp:inline>
                <wp:extent cx="952500" cy="952500"/>
                <wp:docPr id="1" name="{$title}" title="{$title}" descr="{$altText}"/>
                <a:graphic>
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic>
                            <pic:blipFill>
                                <a:blip r:embed="{$relationshipId}"/>
                            </pic:blipFill>
                        </pic:pic>
                    </a:graphicData>
                </a:graphic>
            </wp:inline>
        </w:drawing>
    </w:r>
</w:p>
XML;
    }

    /**
     * Purpose: Return a compact PNG image fixture for parser tests.
     * Inputs: None.
     * Returns: Binary PNG bytes suitable for a DOCX media entry.
     * Side effects: None.
     */
    private function docxSampleImageBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2X3b8AAAAASUVORK5CYII=', true);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to build a DOCX image fixture.');
        }

        return $bytes;
    }
}
