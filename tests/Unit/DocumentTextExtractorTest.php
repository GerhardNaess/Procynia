<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\DocxImageData;
use App\Data\Ai\Requirements\DocxTableData;
use App\Services\DocumentTextExtractor;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DocumentTextExtractorTest extends TestCase
{
    public function test_it_preserves_docx_paragraph_blocks(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Dokumenttittel</w:t></w:r></w:p>
        <w:p><w:r><w:t>Første avsnitt med tekst.</w:t></w:r></w:p>
        <w:p><w:r><w:t>Andre avsnitt med mer tekst.</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

            $zip->addFromString('word/document.xml', $xml);
            $zip->close();

            $extractor = new DocumentTextExtractor;

            $this->assertSame(
                "Dokumenttittel\n\nFørste avsnitt med tekst.\n\nAndre avsnitt med mer tekst.",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Regression for the ANNEX 01A structure-loss report: a requirement table like
     * Req. No. / Requirement text / Type / Response instruction / Y/N / Detailed response
     * (the last three columns blank, as in the real source document) must parse into a
     * DocxTableData with the exact original headers preserved, one data row with all six
     * columns present (blank ones included, not silently dropped), and a stable source_row_key.
     */
    public function test_it_parses_docx_table_into_structured_rows_preserving_headers_and_blank_cells(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>2.1 Buying responsibility, not activities</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Type</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Response instruction</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Y/N</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Detailed response</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.1</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>The Services in the Agreement are described in this Annex.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>
                <w:tc><w:p></w:p></w:tc>
                <w:tc><w:p></w:p></w:tc>
                <w:tc><w:p></w:p></w:tc>
            </w:tr>
        </w:tbl>
        <w:p><w:r><w:t>Etterfølgende avsnitt utenfor tabellen.</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

            $zip->addFromString('word/document.xml', $xml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            // Flat text (backward-compatible with extractText()) still contains the section
            // heading and trailing paragraph, unaffected by the table-structure extraction.
            $this->assertStringContainsString('2.1 Buying responsibility, not activities', $result['text']);
            $this->assertStringContainsString('Etterfølgende avsnitt utenfor tabellen.', $result['text']);

            $this->assertCount(1, $result['tables']);
            $table = $result['tables'][0];

            $this->assertSame(
                ['Req. No.', 'Requirement text', 'Type', 'Response instruction', 'Y/N', 'Detailed response'],
                $table->headerLabels,
            );
            $this->assertCount(1, $table->rows);

            $row = $table->rows[0];
            $this->assertSame('tbl0-row0', $row->sourceRowKey);
            $this->assertCount(6, $row->cells);

            $cellsByHeader = collect($row->cells)->keyBy('originalHeader');

            $this->assertSame('2.1.1', $cellsByHeader['Req. No.']->value);
            $this->assertSame('req_no', $cellsByHeader['Req. No.']->normalizedColumnKey);
            $this->assertSame(
                'The Services in the Agreement are described in this Annex.',
                $cellsByHeader['Requirement text']->value,
            );
            $this->assertSame('requirement_text', $cellsByHeader['Requirement text']->normalizedColumnKey);
            $this->assertSame('M', $cellsByHeader['Type']->value);
            $this->assertSame('type', $cellsByHeader['Type']->normalizedColumnKey);

            // Blank source columns must still be present as empty-value cells, not dropped.
            $this->assertSame('', $cellsByHeader['Response instruction']->value);
            $this->assertSame('response_instruction', $cellsByHeader['Response instruction']->normalizedColumnKey);
            $this->assertSame('', $cellsByHeader['Y/N']->value);
            $this->assertSame('y_n', $cellsByHeader['Y/N']->normalizedColumnKey);
            $this->assertSame('', $cellsByHeader['Detailed response']->value);
            $this->assertSame('detailed_response', $cellsByHeader['Detailed response']->normalizedColumnKey);

            // The row's char range must correspond to real positions within the flat text.
            $rowSlice = mb_substr($result['text'], $row->charStart, $row->charEnd - $row->charStart, 'UTF-8');
            $this->assertStringContainsString('2.1.1', $rowSlice);
            $this->assertStringContainsString('The Services in the Agreement are described in this Annex.', $rowSlice);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_stamps_document_scoped_source_row_keys_and_preserves_them_through_json_round_trip(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.1</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>First requirement.</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.2</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Second requirement.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

            $zip->addFromString('word/document.xml', $xml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);
            $table = $result['tables'][0]->withDocumentId(42);

            $this->assertSame('doc42-tbl0-row0', $table->rows[0]->sourceRowKey);
            $this->assertSame('doc42-tbl0-row1', $table->rows[1]->sourceRowKey);

            // JSON round trip (the canonical form for provenance/persistence, per requirement)
            // must preserve every field exactly, including the stamped source_row_key.
            $decoded = DocxTableData::fromArray(
                json_decode(json_encode($table), true),
            );

            $this->assertSame('doc42-tbl0-row0', $decoded->rows[0]->sourceRowKey);
            $this->assertSame('2.1.1', $decoded->rows[0]->cells[0]->value);
            $this->assertSame('First requirement.', $decoded->rows[0]->cells[1]->value);
            $this->assertSame('doc42-tbl0-row1', $decoded->rows[1]->sourceRowKey);
            $this->assertSame('Second requirement.', $decoded->rows[1]->cells[1]->value);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_captures_the_nearest_preceding_heading_as_table_section_context(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr><w:pStyle w:val="Overskrift1"/></w:pPr>
            <w:r><w:t>2. Buying responsibility</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Overskrift2"/></w:pPr>
            <w:r><w:t>2.1 Buying responsibility, not activities</w:t></w:r>
        </w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Type</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.1</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>The Services in the Agreement are described in Annex 1.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
        <w:p>
            <w:pPr><w:pStyle w:val="Overskrift2"/></w:pPr>
            <w:r><w:t>Untitled section without a leading number</w:t></w:r>
        </w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>3.1.1</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

            $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Overskrift1">
        <w:name w:val="Overskrift 1"/>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Overskrift2">
        <w:name w:val="Overskrift 2"/>
    </w:style>
</w:styles>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/styles.xml', $stylesXml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(2, $result['tables']);

            $firstTable = $result['tables'][0];
            $this->assertSame('2.1', $firstTable->sectionNumber);
            $this->assertSame('Buying responsibility, not activities', $firstTable->sectionTitle);
            $this->assertSame('2.1', $firstTable->rows[0]->sectionNumber);
            $this->assertSame('Buying responsibility, not activities', $firstTable->rows[0]->sectionTitle);
            $this->assertSame('2.1.1', $firstTable->rows[0]->identifierCellValue());
            $this->assertSame('M', $firstTable->rows[0]->typeCellValue());

            $secondTable = $result['tables'][1];
            $this->assertNull($secondTable->sectionNumber);
            $this->assertSame('Untitled section without a leading number', $secondTable->sectionTitle);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_keeps_the_same_normalized_column_key_across_every_row_in_a_table(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Type</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.1</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.2</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:r><w:t>2.1.3</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

            $zip->addFromString('word/document.xml', $xml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(1, $result['tables']);
            $table = $result['tables'][0];
            $this->assertCount(3, $table->rows);

            // Regression guard: normalized_column_key must stay stable ("req_no"/"type") across
            // every row — it must never accumulate a disambiguation suffix ("req_no_2", ...) just
            // because a earlier row already used that key. That suffixing is only meant to
            // disambiguate multiple blank/duplicate headers within the SAME row.
            foreach ($table->rows as $row) {
                $this->assertSame('req_no', $row->cells[0]->normalizedColumnKey);
                $this->assertSame('type', $row->cells[1]->normalizedColumnKey);
            }

            $this->assertSame('2.1.1', $table->rows[0]->identifierCellValue());
            $this->assertSame('2.1.2', $table->rows[1]->identifierCellValue());
            $this->assertSame('2.1.3', $table->rows[2]->identifierCellValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_it_keeps_many_tables_distinct_and_uncontaminated(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $tableCount = 30;
            $bodyParts = [];

            for ($i = 1; $i <= $tableCount; $i++) {
                $bodyParts[] = sprintf(
                    '<w:p><w:r><w:t>Heading %1$d</w:t></w:r></w:p>'
                    .'<w:tbl>'
                    .'<w:tr><w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc></w:tr>'
                    .'<w:tr><w:tc><w:p><w:r><w:t>%1$d.1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Text for table %1$d.</w:t></w:r></w:p></w:tc></w:tr>'
                    .'</w:tbl>',
                    $i,
                );
            }

            $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:body>'.implode('', $bodyParts).'</w:body></w:document>';

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            // Regression guard for a real bug: docxTableCellAncestry() creates transient DOM
            // wrapper objects while walking parentNode. Without holding a live reference, PHP can
            // free an earlier <w:tbl>/<w:tr> wrapper and reuse its spl_object_id for a LATER,
            // unrelated table/row — silently merging distinct tables and cross-contaminating
            // their rows. Confirmed against the real ANNEX 01A document: 50 real tables collapsed
            // to 6 apparent ones before the fix (holding a strong reference in
            // $tableObjectKeepAlive/$rowObjectKeepAlive).
            $this->assertCount(
                $tableCount,
                $result['tables'],
                'Every authored table must remain distinct — a lower count means table/row identity is being corrupted and content from different tables is being merged.',
            );

            foreach ($result['tables'] as $index => $table) {
                $expectedOrdinal = $index + 1;
                $this->assertCount(1, $table->rows, "table {$index} should have exactly one data row");
                $this->assertSame($expectedOrdinal.'.1', $table->rows[0]->identifierCellValue());
                $this->assertSame("Text for table {$expectedOrdinal}.", $table->rows[0]->cells[1]->value);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_it_resolves_word_auto_numbering_for_headings_and_textless_table_cells(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            // Mirrors the real ANNEX 01A document exactly: Overskrift1/2/3 each embed their own
            // numPr (no per-paragraph override needed), all sharing numId=1, and the "Req. No."
            // table cells are Overskrift3-styled paragraphs with NO literal text at all — the
            // number only exists via the numbering definition.
            $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:pStyle w:val="Overskrift1"/></w:pPr><w:r><w:t>Chapter title</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Overskrift2"/></w:pPr><w:r><w:t>Buying responsibility, not activities</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:pPr><w:pStyle w:val="Overskrift3"/></w:pPr></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>First requirement text.</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:pPr><w:pStyle w:val="Overskrift3"/></w:pPr></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Second requirement text.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
        <w:p><w:pPr><w:pStyle w:val="Overskrift2"/></w:pPr><w:r><w:t>Second section</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:pPr><w:pStyle w:val="Overskrift3"/></w:pPr></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Third requirement text.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
        <w:p><w:pPr><w:pStyle w:val="Overskrift1"/></w:pPr><w:r><w:t>Second chapter title</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Overskrift2"/></w:pPr><w:r><w:t>First section of second chapter</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:pPr><w:pStyle w:val="Overskrift3"/></w:pPr></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Fourth requirement text.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

            $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Overskrift1">
        <w:name w:val="heading 1"/>
        <w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Overskrift2">
        <w:name w:val="heading 2"/>
        <w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Overskrift3">
        <w:name w:val="heading 3"/>
        <w:pPr><w:numPr><w:ilvl w:val="2"/><w:numId w:val="1"/></w:numPr></w:pPr>
    </w:style>
</w:styles>
XML;

            $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="34">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1"/></w:lvl>
        <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2"/></w:lvl>
        <w:lvl w:ilvl="2"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2.%3"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="34"/></w:num>
</w:numbering>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/styles.xml', $stylesXml);
            $zip->addFromString('word/numbering.xml', $numberingXml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(3, $result['tables']);

            // Chapter title = H1 #1 -> "1"; "Buying responsibility..." = H2 #1 under it -> "1.1".
            $firstTable = $result['tables'][0];
            $this->assertSame('1.1', $firstTable->sectionNumber);
            $this->assertSame('Buying responsibility, not activities', $firstTable->sectionTitle);
            $this->assertSame('1.1.1', $firstTable->rows[0]->identifierCellValue());
            $this->assertSame('1.1.2', $firstTable->rows[1]->identifierCellValue());

            // "Second section" = H2 #2 under the same H1 -> "1.2".
            $secondTable = $result['tables'][1];
            $this->assertSame('1.2', $secondTable->sectionNumber);
            $this->assertSame('1.2.1', $secondTable->rows[0]->identifierCellValue());

            // A new top-level (ilvl 0) heading must reset the deeper-level counters back to
            // their start value — "2.1", not "2.3" (continuing) or "1.1" (no advance at all).
            $thirdTable = $result['tables'][2];
            $this->assertSame('2.1', $thirdTable->sectionNumber);
            $this->assertSame('2.1.1', $thirdTable->rows[0]->identifierCellValue());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Builds a minimal .docx fixture from raw XML parts and returns its path. Callers are
     * responsible for unlinking it.
     */
    private function buildDocxFixture(string $documentXml, ?string $stylesXml = null, ?string $numberingXml = null): string
    {
        $path = $this->tempDocumentPath('docx');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('word/document.xml', $documentXml);

        if ($stylesXml !== null) {
            $zip->addFromString('word/styles.xml', $stylesXml);
        }

        if ($numberingXml !== null) {
            $zip->addFromString('word/numbering.xml', $numberingXml);
        }

        $zip->close();

        return $path;
    }

    public function test_it_collects_headings_as_canonical_source_elements_with_stable_keys_and_document_order(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Some intro text.</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Chapter One</w:t></w:r></w:p>
        <w:p><w:r><w:t>Body text under chapter one.</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Section one point one</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style>
    <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr></w:style>
</w:styles>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1"/></w:lvl>
        <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, $stylesXml, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(2, $result['headings']);

            $first = $result['headings'][0];
            $this->assertSame('heading-0', $first->sourceKey);
            $this->assertSame(1, $first->documentOrder);
            $this->assertSame(1, $first->level);
            $this->assertSame('Chapter One', $first->text);
            $this->assertSame('1', $first->number);
            $this->assertGreaterThanOrEqual(0, $first->charStart);
            $this->assertGreaterThan($first->charStart, $first->charEnd);

            $second = $result['headings'][1];
            $this->assertSame('heading-1', $second->sourceKey);
            $this->assertSame(3, $second->documentOrder);
            $this->assertSame(2, $second->level);
            $this->assertSame('Section one point one', $second->text);
            $this->assertSame('1.1', $second->number);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_collects_multi_level_list_items_with_mixed_formats_as_canonical_source_elements(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>First top item</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Sub item a</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Sub item b</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Second top item</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="upperLetter"/><w:lvlText w:val="%1."/></w:lvl>
        <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, null, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(4, $result['list_items']);
            $numbers = array_map(static fn ($item) => $item->number, $result['list_items']);
            $this->assertSame(['A.', 'A.1', 'A.2', 'B.'], $numbers);
            $this->assertSame([0, 1, 1, 0], array_map(static fn ($item) => $item->ilvl, $result['list_items']));

            foreach ($result['list_items'] as $index => $item) {
                $this->assertSame('listitem-'.$index, $item->sourceKey);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_it_renders_letter_and_roman_numbering_formats(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>lower letter 1</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>lower letter 2</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>upper roman 1</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>upper roman 2</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="2"/><w:numId w:val="3"/></w:numPr></w:pPr><w:r><w:t>decimal zero 1</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/><w:lvlText w:val="%1)"/></w:lvl>
    </w:abstractNum>
    <w:abstractNum w:abstractNumId="2">
        <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="upperRoman"/><w:lvlText w:val="%2."/></w:lvl>
    </w:abstractNum>
    <w:abstractNum w:abstractNumId="3">
        <w:lvl w:ilvl="2"><w:start w:val="1"/><w:numFmt w:val="decimalZero"/><w:lvlText w:val="%3"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
    <w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>
    <w:num w:numId="3"><w:abstractNumId w:val="3"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, null, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $numbers = array_map(static fn ($item) => $item->number, $result['list_items']);
            $this->assertSame(['a)', 'b)', 'I.', 'II.', '01'], $numbers);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_renders_repeating_letter_sequences_past_z_like_word_does(): void
    {
        $paragraphs = [];

        for ($i = 0; $i < 28; $i++) {
            $paragraphs[] = '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Item '.$i.'</w:t></w:r></w:p>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.implode('', $paragraphs).'</w:body></w:document>';

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/><w:lvlText w:val="%1"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, null, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $numbers = array_map(static fn ($item) => $item->number, $result['list_items']);
            // Word repeats the letter (a, b, ..., z, aa, bb) rather than incrementing like a
            // spreadsheet column (a, b, ..., z, aa, ab, ac) — getting this wrong would silently
            // mislabel every 27th+ item.
            $this->assertSame('z', $numbers[25]);
            $this->assertSame('aa', $numbers[26]);
            $this->assertSame('bb', $numbers[27]);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_honors_an_explicit_start_override_for_a_specific_num_instance(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Restarted item</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Next item</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1">
        <w:abstractNumId w:val="1"/>
        <w:lvlOverride w:ilvl="0"><w:startOverride w:val="5"/></w:lvlOverride>
    </w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, null, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $numbers = array_map(static fn ($item) => $item->number, $result['list_items']);
            $this->assertSame(['5', '6'], $numbers);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_does_not_render_a_number_for_bullet_or_none_formatted_levels(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Bulleted item</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="&#8226;"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, null, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            // A bulleted paragraph is still structurally a list item — it just has no
            // sequential number to reconstruct. Capturing it with number=null (rather than
            // dropping it) keeps the canonical model honest: "this is a list item with no
            // renderable number" is a real, meaningful state, not the absence of an element.
            $this->assertCount(1, $result['list_items']);
            $this->assertNull($result['list_items'][0]->number);
            $this->assertSame('Bulleted item', $result['list_items'][0]->text);
            $this->assertStringContainsString('Bulleted item', $result['text']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_handles_a_document_with_no_numbering_at_all_gracefully(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Just a plain paragraph.</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>A heading with no numbering definition</w:t></w:r></w:p>
        <w:tbl>
            <w:tr><w:tc><w:p><w:r><w:t>Col A</w:t></w:r></w:p></w:tc></w:tr>
            <w:tr><w:tc><w:p><w:r><w:t>Value 1</w:t></w:r></w:p></w:tc></w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

        // No word/numbering.xml at all, and no word/styles.xml either — resolveDocxHeadingLevel()
        // must still classify "Heading1" by style ID alone, and everything else must degrade to
        // "no number" rather than throwing.
        $path = $this->buildDocxFixture($documentXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(1, $result['headings']);
            $this->assertSame(1, $result['headings'][0]->level);
            $this->assertNull($result['headings'][0]->number);
            $this->assertCount(0, $result['list_items']);
            $this->assertCount(1, $result['tables']);
            $this->assertNull($result['tables'][0]->sectionNumber);
            $this->assertSame('A heading with no numbering definition', $result['tables'][0]->sectionTitle);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_recognizes_both_norwegian_and_english_heading_style_names(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:pStyle w:val="Overskrift1"/></w:pPr><w:r><w:t>Norsk overskrift</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>English heading</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Overskrift1"><w:name w:val="overskrift 1"/></w:style>
    <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>
</w:styles>
XML;

        $path = $this->buildDocxFixture($documentXml, $stylesXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(2, $result['headings']);
            $this->assertSame(1, $result['headings'][0]->level);
            $this->assertSame('Norsk overskrift', $result['headings'][0]->text);
            $this->assertSame(1, $result['headings'][1]->level);
            $this->assertSame('English heading', $result['headings'][1]->text);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_produces_identical_results_on_repeated_parsing_of_the_same_document(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Determinism chapter</w:t></w:r></w:p>
        <w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Determinism section</w:t></w:r></w:p>
        <w:tbl>
            <w:tr>
                <w:tc><w:p><w:r><w:t>Req. No.</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>Requirement text</w:t></w:r></w:p></w:tc>
            </w:tr>
            <w:tr>
                <w:tc><w:p><w:pPr><w:pStyle w:val="Heading3"/></w:pPr></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>The system shall be deterministic.</w:t></w:r></w:p></w:tc>
            </w:tr>
        </w:tbl>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>A list item</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style>
    <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr></w:style>
    <w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:pPr><w:numPr><w:ilvl w:val="2"/><w:numId w:val="1"/></w:numPr></w:pPr></w:style>
</w:styles>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="1">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1"/></w:lvl>
        <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2"/></w:lvl>
        <w:lvl w:ilvl="2"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2.%3"/></w:lvl>
    </w:abstractNum>
    <w:abstractNum w:abstractNumId="2">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerRoman"/><w:lvlText w:val="%1."/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
    <w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, $stylesXml, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $first = $extractor->extractDocxTextAndTables($path);
            $second = $extractor->extractDocxTextAndTables($path);

            $this->assertSame($first['text'], $second['text']);
            $this->assertEquals($first['tables'], $second['tables']);
            $this->assertEquals($first['headings'], $second['headings']);
            $this->assertEquals($first['list_items'], $second['list_items']);

            // Not just structurally equal — the actual stable identity fields must match too.
            $this->assertSame(
                array_map(static fn ($r) => $r->sourceRowKey, $first['tables'][0]->rows),
                array_map(static fn ($r) => $r->sourceRowKey, $second['tables'][0]->rows),
            );
            $this->assertSame(
                array_map(static fn ($h) => $h->sourceKey, $first['headings']),
                array_map(static fn ($h) => $h->sourceKey, $second['headings']),
            );
            $this->assertSame($first['tables'][0]->rows[0]->identifierCellValue(), '1.1.1');
            $this->assertSame($second['tables'][0]->rows[0]->identifierCellValue(), '1.1.1');

            // text_elements (the flat paragraph/list-item provenance model consumed by
            // RequirementCandidateExtractor::reconcileCandidatesWithTextElements()) must be just
            // as stable across re-parses as tables/headings — same element_keys, same content.
            $this->assertEquals($first['text_elements'], $second['text_elements']);
            $this->assertSame(
                array_map(static fn (array $e) => $e['element_key'], $first['text_elements']),
                array_map(static fn (array $e) => $e['element_key'], $second['text_elements']),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * A plain (non-numbered) body paragraph and a numbered list item must both be collected as
     * canonical `text_elements` — the requirement-provenance model paragraph/list_item candidates
     * are reconciled against (see RequirementCandidateExtractor::reconcileCandidatesWithTextElements()).
     * Headings are section context, not requirement sources, so they must not appear here.
     */
    public function test_it_collects_plain_paragraphs_and_list_items_as_text_elements_with_section_context(): void
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Buying responsibility, not activities</w:t></w:r></w:p>
        <w:p><w:r><w:t>The Contractor shall provide documentation within 10 days.</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>The Contractor shall notify the Customer.</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

        $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>
</w:styles>
XML;

        $numberingXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="2">
        <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1"/></w:lvl>
    </w:abstractNum>
    <w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>
</w:numbering>
XML;

        $path = $this->buildDocxFixture($documentXml, $stylesXml, $numberingXml);

        try {
            $extractor = new DocumentTextExtractor;
            $result = $extractor->extractDocxTextAndTables($path);

            $this->assertCount(2, $result['text_elements']);

            $paragraphElement = $result['text_elements'][0];
            $this->assertSame('paragraph-0', $paragraphElement['element_key']);
            $this->assertSame('paragraph', $paragraphElement['element_type']);
            $this->assertSame('The Contractor shall provide documentation within 10 days.', $paragraphElement['text']);
            $this->assertNull($paragraphElement['number']);
            $this->assertSame('Buying responsibility, not activities', $paragraphElement['section_title']);
            $this->assertGreaterThanOrEqual(0, $paragraphElement['char_start']);
            $this->assertGreaterThan($paragraphElement['char_start'], $paragraphElement['char_end']);

            $listItemElement = $result['text_elements'][1];
            $this->assertSame('listitem-0', $listItemElement['element_key']);
            $this->assertSame('list_item', $listItemElement['element_type']);
            $this->assertSame('The Contractor shall notify the Customer.', $listItemElement['text']);
            $this->assertSame('1', $listItemElement['number']);
            $this->assertSame('Buying responsibility, not activities', $listItemElement['section_title']);

            // Headings are section context, never a requirement-source text_element.
            foreach ($result['text_elements'] as $element) {
                $this->assertNotSame('heading', $element['element_type']);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_it_extracts_structured_docx_blocks_with_heading_levels(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>Forord før første heading.</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Overskrift1"/></w:pPr>
            <w:r><w:t>Applikasjonsdrift</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>Første avsnitt under hovedseksjonen.</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
            <w:r><w:t>Underseksjon</w:t></w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Normal"/></w:pPr>
            <w:r><w:t>Mer innhold i underseksjonen.</w:t></w:r>
        </w:p>
    </w:body>
</w:document>
XML;

            $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Normal">
        <w:name w:val="Normal"/>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Overskrift1">
        <w:name w:val="Overskrift 1"/>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="Heading 2"/>
    </w:style>
</w:styles>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/styles.xml', $stylesXml);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $blocks = $extractor->extractStructuredText($path);

            $this->assertCount(5, $blocks);
            $this->assertSame('Forord før første heading.', $blocks[0]['text']);
            $this->assertNull($blocks[0]['level']);
            $this->assertSame('Overskrift 1', $blocks[1]['style']);
            $this->assertSame(1, $blocks[1]['level']);
            $this->assertSame('Heading 2', $blocks[3]['style']);
            $this->assertSame(2, $blocks[3]['level']);
            $this->assertSame('Mer innhold i underseksjonen.', $blocks[4]['text']);
            $this->assertNull($blocks[4]['level']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_preserves_xlsx_row_blocks(): void
    {
        $path = $this->tempDocumentPath('xlsx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $sheetXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="inlineStr"><is><t>Kolonne A</t></is></c>
            <c r="B1" t="inlineStr"><is><t>Kolonne B</t></is></c>
        </row>
        <row r="2">
            <c r="A2" t="inlineStr"><is><t>Verdi 1</t></is></c>
            <c r="B2" t="inlineStr"><is><t>Verdi 2</t></is></c>
        </row>
    </sheetData>
</worksheet>
XML;

            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->close();

            $extractor = new DocumentTextExtractor;

            $this->assertSame(
                "Kolonne A Kolonne B\n\nVerdi 1 Verdi 2",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_it_preserves_pdf_block_boundaries(): void
    {
        // Respect configured binary; fall back to PATH for local dev; skip if unavailable.
        $binary = config('services.pdftotext.binary');

        if (empty($binary)) {
            $binary = trim((string) shell_exec('which pdftotext 2>/dev/null'));
        }

        if (empty($binary) || ! is_executable($binary)) {
            $this->markTestSkipped('pdftotext is not available; set PDFTOTEXT_BINARY to run this test.');
        }

        config(['services.pdftotext.binary' => $binary]);

        $path = $this->tempDocumentPath('pdf');

        try {
            $this->writeValidTestPdf($path);

            $extractor = new DocumentTextExtractor;

            $this->assertSame(
                "Overskrift\n\nFørste avsnitt med tekst.\n\nAndre avsnitt med mer tekst.",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_it_detects_extended_pdf_heading_patterns(): void
    {
        $extractor = new DocumentTextExtractor;

        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, '1 Innledning'));
        $this->assertSame(2, $this->invokePdfHeadingLevel($extractor, '1.1 Bakgrunn'));
        $this->assertSame(2, $this->invokePdfHeadingLevel($extractor, '1.1.1 Krav til leveranse'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'Kapittel 3'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'Del 1'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'A. Vedlegg'));
    }

    public function test_it_does_not_classify_bullet_lists_as_poppler_table_runs(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                '• Prosjektleder hos Leverandøren med operativt koordineringsansvar',
                100,
                72,
                440,
                112,
                [
                    ['text' => '•', 'left' => 72, 'width' => 12],
                    ['text' => 'Prosjektleder hos Leverandøren med operativt koordineringsansvar', 'left' => 96, 'width' => 320],
                ],
            ),
            $this->popplerLineGroup(
                '- Prosjekteier / styringsgruppe med representasjon fra Kunden',
                122,
                72,
                440,
                134,
                [
                    ['text' => '-', 'left' => 72, 'width' => 10],
                    ['text' => 'Prosjekteier / styringsgruppe med representasjon fra Kunden', 'left' => 96, 'width' => 300],
                ],
            ),
            $this->popplerLineGroup(
                '1. Tekniske og funksjonelle delansvarlige',
                144,
                72,
                440,
                156,
                [
                    ['text' => '1.', 'left' => 72, 'width' => 18],
                    ['text' => 'Tekniske og funksjonelle delansvarlige', 'left' => 96, 'width' => 260],
                ],
            ),
            $this->popplerLineGroup(
                'a) Navngitte kontaktpunkter hos Kundens øvrige leverandører',
                166,
                72,
                440,
                178,
                [
                    ['text' => 'a)', 'left' => 72, 'width' => 16],
                    ['text' => 'Navngitte kontaktpunkter hos Kundens øvrige leverandører', 'left' => 96, 'width' => 340],
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertSame([], $runs);
    }

    public function test_it_keeps_poppler_list_lines_as_list_blocks(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                '• Prosjektleder hos Leverandøren med operativt koordineringsansvar',
                100,
                72,
                440,
                112,
                [
                    ['text' => '•', 'left' => 72, 'width' => 12],
                    ['text' => 'Prosjektleder hos Leverandøren med operativt koordineringsansvar', 'left' => 96, 'width' => 320],
                ],
            ),
            $this->popplerLineGroup(
                '- Prosjekteier / styringsgruppe med representasjon fra Kunden',
                122,
                72,
                440,
                134,
                [
                    ['text' => '-', 'left' => 72, 'width' => 10],
                    ['text' => 'Prosjekteier / styringsgruppe med representasjon fra Kunden', 'left' => 96, 'width' => 300],
                ],
            ),
            $this->popplerLineGroup(
                '1. Tekniske og funksjonelle delansvarlige',
                144,
                72,
                440,
                156,
                [
                    ['text' => '1.', 'left' => 72, 'width' => 18],
                    ['text' => 'Tekniske og funksjonelle delansvarlige', 'left' => 96, 'width' => 260],
                ],
            ),
            $this->popplerLineGroup(
                'a) Navngitte kontaktpunkter hos Kundens øvrige leverandører',
                166,
                72,
                440,
                178,
                [
                    ['text' => 'a)', 'left' => 72, 'width' => 16],
                    ['text' => 'Navngitte kontaktpunkter hos Kundens øvrige leverandører', 'left' => 96, 'width' => 340],
                ],
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(4, $blocks);
        $this->assertSame(['list', 'list', 'list', 'list'], array_values(array_map(
            static fn (array $block): string => (string) ($block['type'] ?? ''),
            $blocks,
        )));
        $this->assertSame('• Prosjektleder hos Leverandøren med operativt koordineringsansvar', $blocks[0]['text']);
        $this->assertSame('- Prosjekteier / styringsgruppe med representasjon fra Kunden', $blocks[1]['text']);
        $this->assertSame('1. Tekniske og funksjonelle delansvarlige', $blocks[2]['text']);
        $this->assertSame('a) Navngitte kontaktpunkter hos Kundens øvrige leverandører', $blocks[3]['text']);
    }

    public function test_it_does_not_treat_sentence_like_two_fragment_blocks_as_tables(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'I praksis pleier migreringsstrategier ved overtakelse av IT-drift å kategoriseres etter hvordan',
                100,
                72,
                720,
                122,
                [
                    ['text' => 'I praksis pleier migreringsstrategier ved overtakelse av IT-drift å kategoriseres etter hvordan', 'left' => 72, 'width' => 520],
                    ['text' => 'En vanlig og faglig ryddig', 'left' => 610, 'width' => 92],
                ],
            ),
            $this->popplerLineGroup(
                'overgangen fra eksisterende miljø til nytt driftsregime skjer i tid og risiko kategorisering kan se slik ut:',
                124,
                72,
                720,
                146,
                [
                    ['text' => 'overgangen fra eksisterende miljø til nytt driftsregime skjer i tid og risiko', 'left' => 72, 'width' => 550],
                    ['text' => 'kategorisering kan se slik ut:', 'left' => 610, 'width' => 102],
                ],
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 6]);
        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            ['start_index' => 0, 'end_index' => 1],
            6,
            1,
            842,
            1200,
        ]);

        $this->assertCount(1, $blocks);
        $this->assertSame('paragraph', $blocks[0]['type']);
        $this->assertStringContainsString('I praksis pleier migreringsstrategier ved overtakelse av IT-drift å kategoriseres etter hvordan', $blocks[0]['text']);
        $this->assertStringContainsString('overgangen fra eksisterende miljø til nytt driftsregime skjer i tid og risiko kategorisering kan se slik ut:', $blocks[0]['text']);
        $this->assertNull($tableBlock);
    }

    public function test_it_does_not_classify_toc_dotted_leader_lines_with_page_numbers_as_tables(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                '1.1 Koordinering og samhandling i Etableringsprosjektet .................................................... 2',
                100,
                72,
                760,
                118,
                [
                    ['text' => '1.1', 'left' => 72, 'width' => 26],
                    ['text' => 'Koordinering og samhandling i Etableringsprosjektet', 'left' => 112, 'width' => 430],
                    ['text' => '....................................................', 'left' => 548, 'width' => 178],
                    ['text' => '2', 'left' => 735, 'width' => 18],
                ],
                [
                    'raw_text' => 'Koordinering og samhandling i Etableringsprosjektet .................................................... 2',
                ],
            ),
            $this->popplerLineGroup(
                '1.2 Leverandørens Testmetode .................................................... 4',
                122,
                72,
                760,
                140,
                [
                    ['text' => '1.2', 'left' => 72, 'width' => 26],
                    ['text' => 'Leverandørens Testmetode', 'left' => 112, 'width' => 300],
                    ['text' => '....................................................', 'left' => 548, 'width' => 178],
                    ['text' => '4', 'left' => 735, 'width' => 18],
                ],
                [
                    'raw_text' => 'Leverandørens Testmetode .................................................... 4',
                ],
            ),
            $this->popplerLineGroup(
                '1.3 Prosjektets faser .................................................... 5',
                144,
                72,
                760,
                162,
                [
                    ['text' => '1.3', 'left' => 72, 'width' => 26],
                    ['text' => 'Prosjektets faser', 'left' => 112, 'width' => 220],
                    ['text' => '....................................................', 'left' => 548, 'width' => 178],
                    ['text' => '5', 'left' => 735, 'width' => 18],
                ],
                [
                    'raw_text' => 'Prosjektets faser .................................................... 5',
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertSame([], $runs, 'TOC dotted-leader lines with page numbers must not be detected as Poppler table runs.');
    }

    public function test_it_does_not_emit_toc_dotted_leader_lines_into_semantic_blocks(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Innholdsfortegnelse',
                92,
                72,
                240,
                112,
                [
                    ['text' => 'Innholdsfortegnelse', 'left' => 72, 'width' => 168],
                ],
            ),
            $this->popplerLineGroup(
                '1.11 Leverandørens oppbygging av prosjektplanen (WBS struktur mm.) .................................... 21',
                120,
                72,
                760,
                142,
                [
                    ['text' => '1.11', 'left' => 72, 'width' => 28],
                    ['text' => 'Leverandørens oppbygging av prosjektplanen (WBS struktur mm.)', 'left' => 112, 'width' => 434],
                    ['text' => '....................................', 'left' => 548, 'width' => 178],
                    ['text' => '21', 'left' => 735, 'width' => 18],
                ],
            ),
            $this->popplerLineGroup(
                '1.12 Risikostyring av prosjekter .................................... 22',
                144,
                72,
                760,
                166,
                [
                    ['text' => '1.12', 'left' => 72, 'width' => 28],
                    ['text' => 'Risikostyring av prosjekter', 'left' => 112, 'width' => 260],
                    ['text' => '....................................', 'left' => 548, 'width' => 178],
                    ['text' => '22', 'left' => 735, 'width' => 18],
                ],
            ),
            $this->popplerLineGroup(
                '1.13 Kostnadsstyring i prosjekter .................................... 23',
                168,
                72,
                760,
                190,
                [
                    ['text' => '1.13', 'left' => 72, 'width' => 28],
                    ['text' => 'Kostnadsstyring i prosjekter', 'left' => 112, 'width' => 248],
                    ['text' => '....................................', 'left' => 548, 'width' => 178],
                    ['text' => '23', 'left' => 735, 'width' => 18],
                ],
            ),
            $this->popplerLineGroup(
                '1.14 Avvikshåndtering i prosjekter .................................... 24',
                192,
                72,
                760,
                214,
                [
                    ['text' => '1.14', 'left' => 72, 'width' => 28],
                    ['text' => 'Avvikshåndtering i prosjekter', 'left' => 112, 'width' => 256],
                    ['text' => '....................................', 'left' => 548, 'width' => 178],
                    ['text' => '24', 'left' => 735, 'width' => 18],
                ],
            ),
            $this->popplerLineGroup(
                'Dette er reell innholdstekst etter TOC.',
                236,
                72,
                410,
                256,
                [
                    ['text' => 'Dette er reell innholdstekst etter TOC.', 'left' => 72, 'width' => 338],
                ],
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 2]);
        $semanticBlocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => in_array((string) ($block['type'] ?? ''), ['paragraph', 'heading', 'list'], true),
        ));
        $semanticText = implode("\n", array_map(
            static fn (array $block): string => trim(preg_replace('/\s+/u', ' ', (string) ($block['text'] ?? ''))),
            $semanticBlocks,
        ));

        $this->assertStringNotContainsString('Innholdsfortegnelse', $semanticText);
        $this->assertStringNotContainsString('1.11 Leverandørens oppbygging av prosjektplanen (WBS struktur mm.)', $semanticText);
        $this->assertStringNotContainsString('1.12 Risikostyring av prosjekter', $semanticText);
        $this->assertStringNotContainsString('1.13 Kostnadsstyring i prosjekter', $semanticText);
        $this->assertStringNotContainsString('1.14 Avvikshåndtering i prosjekter', $semanticText);
        $this->assertStringContainsString('Dette er reell innholdstekst etter TOC.', $semanticText);
    }

    public function test_pdf_visually_wrapped_lines_are_joined_with_space_into_one_paragraph(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Dette er første del av en lang setning som',
                100,
                72,
                620,
                120,
            ),
            $this->popplerLineGroup(
                'fortsetter på neste linje i PDF-filen.',
                122,
                72,
                540,
                142,
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(1, $blocks);
        $this->assertSame('paragraph', $blocks[0]['type']);
        $this->assertSame(
            'Dette er første del av en lang setning som fortsetter på neste linje i PDF-filen.',
            $blocks[0]['text'],
        );
    }

    public function test_pdf_real_paragraph_break_produces_two_separate_blocks(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup('Første avsnitt.', 100, 72, 400, 120),
            $this->popplerLineGroup('Andre avsnitt.', 160, 72, 380, 180),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(2, $blocks);
        $this->assertSame('Første avsnitt.', $blocks[0]['text']);
        $this->assertSame('Andre avsnitt.', $blocks[1]['text']);
    }

    public function test_pdf_hyphenated_line_ending_is_merged_without_hyphen_when_next_line_starts_lowercase(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Leverandøren skal sikre kontinuer-',
                100,
                72,
                580,
                120,
            ),
            $this->popplerLineGroup(
                'lig tilgang til tjenesten.',
                122,
                72,
                460,
                142,
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(1, $blocks);
        $this->assertSame(
            'Leverandøren skal sikre kontinuerlig tilgang til tjenesten.',
            $blocks[0]['text'],
        );
    }

    public function test_pdf_hyphen_is_preserved_when_next_line_starts_with_uppercase(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Tjenesten oppfyller kravene i EU-',
                100,
                72,
                560,
                120,
            ),
            $this->popplerLineGroup(
                'Kommisjonen sine retningslinjer.',
                122,
                72,
                500,
                142,
            ),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(1, $blocks);
        $this->assertSame(
            'Tjenesten oppfyller kravene i EU- Kommisjonen sine retningslinjer.',
            $blocks[0]['text'],
        );
    }

    public function test_pdf_list_items_are_emitted_individually_not_merged_into_paragraph(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup('• Første listepunkt', 100, 72, 400, 120, [
                ['text' => '•', 'left' => 72, 'width' => 12],
                ['text' => 'Første listepunkt', 'left' => 96, 'width' => 280],
            ]),
            $this->popplerLineGroup('• Andre listepunkt', 122, 72, 390, 142, [
                ['text' => '•', 'left' => 72, 'width' => 12],
                ['text' => 'Andre listepunkt', 'left' => 96, 'width' => 270],
            ]),
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTextBlocks', [$lineGroups, [], 1]);

        $this->assertCount(2, $blocks);
        $this->assertSame('list', $blocks[0]['type']);
        $this->assertSame('list', $blocks[1]['type']);
        $this->assertSame('• Første listepunkt', $blocks[0]['text']);
        $this->assertSame('• Andre listepunkt', $blocks[1]['text']);
    }

    public function test_it_keeps_real_page_six_failure_level_rows_in_one_table_run(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Nivå Kategori Beskrivelse',
                163,
                107,
                371,
                183,
                [
                    ['text' => 'Nivå', 'left' => 107, 'width' => 34],
                    ['text' => 'Kategori', 'left' => 157, 'width' => 60],
                    ['text' => 'Beskrivelse', 'left' => 292, 'width' => 79],
                ],
            ),
            $this->popplerLineGroup(
                'A Kritisk feil • Feil som medfører at utstyret eller programvaren',
                187,
                107,
                680,
                208,
                [
                    ['text' => 'A', 'left' => 107, 'width' => 16],
                    ['text' => 'Kritisk feil', 'left' => 157, 'width' => 70],
                    ['text' => '•', 'left' => 319, 'width' => 8],
                    ['text' => 'Feil som medfører at utstyret eller programvaren', 'left' => 346, 'width' => 334],
                ],
            ),
            $this->popplerLineGroup(
                'stopper, at data går tapt, eller at andre funksjoner som',
                211,
                346,
                719,
                231,
                [
                    ['text' => 'stopper, at data går tapt, eller at andre funksjoner som', 'left' => 346, 'width' => 373],
                ],
            ),
            $this->popplerLineGroup(
                'etter en objektiv vurdering er kritiske for Kunden, ikke',
                234,
                346,
                713,
                254,
                [
                    ['text' => 'etter en objektiv vurdering er kritiske for Kunden, ikke', 'left' => 346, 'width' => 367],
                ],
            ),
            $this->popplerLineGroup(
                'er levert eller ikke virker som avtalt.',
                257,
                346,
                595,
                277,
                [
                    ['text' => 'er levert eller ikke virker som avtalt.', 'left' => 346, 'width' => 249],
                ],
            ),
            $this->popplerLineGroup(
                '• Dokumentasjonen er så ufullstendig eller misvisende at',
                281,
                319,
                721,
                302,
                [
                    ['text' => '•', 'left' => 319, 'width' => 10],
                    ['text' => 'Dokumentasjonen er så ufullstendig eller misvisende at', 'left' => 346, 'width' => 375],
                ],
            ),
            $this->popplerLineGroup(
                'Kunden ikke kan bruke utstyret eller programvaren',
                305,
                346,
                692,
                325,
                [
                    ['text' => 'Kunden ikke kan bruke utstyret eller programvaren', 'left' => 346, 'width' => 346],
                ],
            ),
            $this->popplerLineGroup(
                'eller vesentlige deler av det.',
                328,
                346,
                543,
                348,
                [
                    ['text' => 'eller vesentlige deler av det.', 'left' => 346, 'width' => 197],
                ],
            ),
            $this->popplerLineGroup(
                'B Alvorlig feil • Feil som fører til at funksjoner som, ut fra en objektiv',
                352,
                107,
                707,
                373,
                [
                    ['text' => 'B', 'left' => 107, 'width' => 16],
                    ['text' => 'Alvorlig feil', 'left' => 157, 'width' => 79],
                    ['text' => '•', 'left' => 319, 'width' => 8],
                    ['text' => 'Feil som fører til at funksjoner som, ut fra en objektiv', 'left' => 346, 'width' => 361],
                ],
            ),
            $this->popplerLineGroup(
                'vurdering, er viktige for Kunden, ikke virker som',
                376,
                346,
                672,
                396,
                [
                    ['text' => 'vurdering, er viktige for Kunden, ikke virker som', 'left' => 346, 'width' => 326],
                ],
            ),
            $this->popplerLineGroup(
                'beskrevet i avtalen, og som det er tids- og',
                399,
                346,
                630,
                419,
                [
                    ['text' => 'beskrevet i avtalen, og som det er tids- og', 'left' => 346, 'width' => 284],
                ],
            ),
            $this->popplerLineGroup(
                'ressurskrevende å omgå.',
                422,
                346,
                525,
                442,
                [
                    ['text' => 'ressurskrevende å omgå.', 'left' => 346, 'width' => 179],
                ],
            ),
            $this->popplerLineGroup(
                '• Dokumentasjonen er så ufullstendig eller misvisende at',
                446,
                319,
                721,
                467,
                [
                    ['text' => '•', 'left' => 319, 'width' => 10],
                    ['text' => 'Dokumentasjonen er så ufullstendig eller misvisende at', 'left' => 346, 'width' => 375],
                ],
            ),
            $this->popplerLineGroup(
                'Kunden ikke kan benytte funksjoner som etter en',
                470,
                346,
                681,
                490,
                [
                    ['text' => 'Kunden ikke kan benytte funksjoner som etter en', 'left' => 346, 'width' => 335],
                ],
            ),
            $this->popplerLineGroup(
                'objektiv vurdering er viktige for Kunden.',
                493,
                346,
                624,
                513,
                [
                    ['text' => 'objektiv vurdering er viktige for Kunden.', 'left' => 346, 'width' => 278],
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertCount(1, $runs, 'The wrapped page 6 failure-level table must stay one continuous table run.');
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(14, $runs[0]['end_index'], 'The run must continue through the B row instead of splitting after A.');

        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            $runs[0],
            6,
            1,
            842,
            1200,
        ]);

        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('Tabell 1 – side 6', $tableBlock['title']);
        $this->assertStringContainsString('A | Kritisk feil | • Feil som medfører at utstyret eller programvaren', $tableBlock['table_text']);
        $this->assertStringContainsString('stopper, at data går tapt, eller at andre funksjoner som', $tableBlock['table_text']);
        $this->assertStringContainsString("B | Alvorlig feil | • Feil som fører til at funksjoner som, ut fra en objektiv\nvurdering, er viktige for Kunden, ikke virker som\nbeskrevet i avtalen, og som det er tids- og\nressurskrevende å omgå.", $tableBlock['table_text']);
    }

    public function test_it_still_detects_real_poppler_table_runs_for_multicolumn_rows(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Krav-ID Krav Dokumentasjon',
                100,
                72,
                480,
                112,
                [
                    ['text' => 'Krav-ID', 'left' => 72, 'width' => 70],
                    ['text' => 'Krav', 'left' => 200, 'width' => 80],
                    ['text' => 'Dokumentasjon', 'left' => 360, 'width' => 110],
                ],
            ),
            $this->popplerLineGroup(
                'K-01 Leverandøren skal Beskrivelse vedlegges',
                122,
                72,
                480,
                134,
                [
                    ['text' => 'K-01', 'left' => 72, 'width' => 48],
                    ['text' => 'Leverandøren skal', 'left' => 200, 'width' => 118],
                    ['text' => 'Beskrivelse vedlegges', 'left' => 360, 'width' => 140],
                ],
            ),
            $this->popplerLineGroup(
                'K-02 Leverandøren skal Sertifikat vedlegges',
                144,
                72,
                480,
                156,
                [
                    ['text' => 'K-02', 'left' => 72, 'width' => 48],
                    ['text' => 'Leverandøren skal', 'left' => 200, 'width' => 118],
                    ['text' => 'Sertifikat vedlegges', 'left' => 360, 'width' => 140],
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertCount(1, $runs);
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(2, $runs[0]['end_index']);

        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            $runs[0],
            1,
            1,
            842,
            800,
        ]);

        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('Tabell 1 – side 1', $tableBlock['title']);
        $this->assertStringContainsString('Krav-ID | Krav | Dokumentasjon', $tableBlock['text']);
        $this->assertStringContainsString('K-01 | Leverandøren skal | Beskrivelse vedlegges', $tableBlock['text']);
    }

    public function test_it_detects_poppler_tables_when_bullet_markers_share_the_description_cell(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Nivå Kategori Beskrivelse',
                163,
                107,
                371,
                183,
                [
                    ['text' => 'Nivå', 'left' => 107, 'width' => 34],
                    ['text' => 'Kategori', 'left' => 157, 'width' => 60],
                    ['text' => 'Beskrivelse', 'left' => 292, 'width' => 79],
                ],
            ),
            $this->popplerLineGroup(
                'A Kritisk feil • Feil som medfører at utstyret eller programvaren stopper',
                187,
                107,
                680,
                208,
                [
                    ['text' => 'A', 'left' => 107, 'width' => 17],
                    ['text' => 'Kritisk feil', 'left' => 157, 'width' => 70],
                    ['text' => '•', 'left' => 319, 'width' => 8],
                    ['text' => 'Feil som medfører at utstyret eller programvaren stopper', 'left' => 346, 'width' => 334],
                ],
            ),
            $this->popplerLineGroup(
                'B Alvorlig feil • Feil som fører til at funksjoner som, ut fra en objektiv vurdering',
                352,
                107,
                707,
                373,
                [
                    ['text' => 'B', 'left' => 107, 'width' => 16],
                    ['text' => 'Alvorlig feil', 'left' => 157, 'width' => 79],
                    ['text' => '•', 'left' => 319, 'width' => 8],
                    ['text' => 'Feil som fører til at funksjoner som, ut fra en objektiv vurdering', 'left' => 346, 'width' => 361],
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 792]);

        $this->assertCount(1, $runs);
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(2, $runs[0]['end_index']);

        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            $runs[0],
            4,
            1,
            792,
            1024,
        ]);

        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('Tabell 1 – side 4', $tableBlock['title']);
        $this->assertStringContainsString('Nivå | Kategori | Beskrivelse', $tableBlock['table_markdown']);
        $this->assertStringContainsString('A | Kritisk feil | • Feil som medfører at utstyret eller programvaren stopper', $tableBlock['table_text']);
        $this->assertStringContainsString('B | Alvorlig feil | • Feil som fører til at funksjoner som, ut fra en objektiv vurdering', $tableBlock['table_text']);
    }

    public function test_it_detects_flattened_poppler_table_rows_when_columns_are_separated_by_whitespace(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Krav-ID    Krav    Dokumentasjon',
                100,
                72,
                512,
                112,
            ),
            $this->popplerLineGroup(
                'K-01    Leverandøren skal    Beskrivelse vedlegges',
                122,
                72,
                512,
                134,
            ),
            $this->popplerLineGroup(
                'K-02    Leverandøren skal    Sertifikat vedlegges',
                144,
                72,
                512,
                156,
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertCount(1, $runs);
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(2, $runs[0]['end_index']);

        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            $runs[0],
            1,
            1,
            842,
            800,
        ]);

        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('Tabell 1 – side 1', $tableBlock['title']);
        $this->assertSame("Krav-ID | Krav | Dokumentasjon\nK-01 | Leverandøren skal | Beskrivelse vedlegges\nK-02 | Leverandøren skal | Sertifikat vedlegges", $tableBlock['table_text']);
        $this->assertStringContainsString('| Krav-ID | Krav | Dokumentasjon |', $tableBlock['table_markdown']);
        $this->assertStringContainsString('| K-02 | Leverandøren skal | Sertifikat vedlegges |', $tableBlock['table_markdown']);
    }

    public function test_it_keeps_wrapped_first_column_rows_inside_poppler_tables(): void
    {
        $extractor = new DocumentTextExtractor;
        $lineGroups = [
            $this->popplerLineGroup(
                'Ansvarlig Leverandøren',
                215,
                115,
                339,
                235,
                [
                    ['text' => 'Ansvarlig', 'left' => 115, 'width' => 67],
                    ['text' => 'Leverandøren', 'left' => 242, 'width' => 97],
                ],
            ),
            $this->popplerLineGroup(
                'Agenda • Status på fremdrift',
                250,
                115,
                440,
                270,
                [
                    ['text' => 'Agenda', 'left' => 115, 'width' => 56],
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Status på fremdrift', 'left' => 310, 'width' => 130],
                ],
            ),
            $this->popplerLineGroup(
                '• Problemstillinger',
                270,
                283,
                428,
                290,
                [
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Problemstillinger', 'left' => 310, 'width' => 118],
                ],
            ),
            $this->popplerLineGroup(
                '• Prosjektplan',
                291,
                283,
                397,
                311,
                [
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Prosjektplan', 'left' => 310, 'width' => 87],
                ],
            ),
            $this->popplerLineGroup(
                '• Kvalitet i leveransene',
                311,
                283,
                457,
                331,
                [
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Kvalitet i leveransene', 'left' => 310, 'width' => 147],
                ],
            ),
            $this->popplerLineGroup(
                'Deltagere fra TBD',
                448,
                115,
                273,
                468,
                [
                    ['text' => 'Deltagere fra', 'left' => 115, 'width' => 94],
                    ['text' => 'TBD', 'left' => 242, 'width' => 31],
                ],
            ),
            $this->popplerLineGroup(
                'Kunden',
                468,
                115,
                171,
                488,
                [
                    ['text' => 'Kunden', 'left' => 115, 'width' => 56],
                ],
            ),
            $this->popplerLineGroup(
                'Deltagere fra • Prosjektleder',
                504,
                115,
                403,
                524,
                [
                    ['text' => 'Deltagere fra', 'left' => 115, 'width' => 94],
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Prosjektleder', 'left' => 310, 'width' => 93],
                ],
            ),
            $this->popplerLineGroup(
                'Leverandøren • Technical Manager',
                524,
                115,
                441,
                544,
                [
                    ['text' => 'Leverandøren', 'left' => 115, 'width' => 99],
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Technical Manager', 'left' => 310, 'width' => 131],
                ],
            ),
            $this->popplerLineGroup(
                '• Fagressurser ved behov',
                548,
                283,
                472,
                568,
                [
                    ['text' => '•', 'left' => 283, 'width' => 10],
                    ['text' => 'Fagressurser ved behov', 'left' => 310, 'width' => 162],
                ],
            ),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        $this->assertCount(1, $runs);
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(9, $runs[0]['end_index']);

        $tableBlock = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableBlock', [
            $lineGroups,
            $runs[0],
            14,
            2,
            842,
            1200,
        ]);

        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('Tabell 2 – side 14', $tableBlock['title']);
        $this->assertStringContainsString('Ansvarlig | Leverandøren', $tableBlock['table_text']);
        $this->assertStringContainsString("Deltagere fra\nKunden | TBD", $tableBlock['table_text']);
        $this->assertStringContainsString('Agenda | • Status på fremdrift', $tableBlock['table_text']);
    }

    // Regression: 4-column tables where the second column starts beyond firstCellLeft+45 (e.g. left=120)
    // were broken into single-row fragments because continuation lines at that x-position were rejected.
    public function test_it_detects_table_run_through_continuation_in_second_column(): void
    {
        $extractor = new DocumentTextExtractor;

        // 4-column layout: col1=50, col2=120, col3=300, col4=540.
        // firstCellLeft=50 → old isLeftColumnContinuation zone is [30,95].
        // A continuation at left=120 exceeds 95, so the old code breaks the run here.
        $lineGroups = [
            // Row 1 — 4-column candidate line.
            $this->popplerLineGroup('R1 Levere dokumentasjon H K', 100, 50, 620, 114, [
                ['text' => 'R1', 'left' => 50, 'width' => 30],
                ['text' => 'Levere dokumentasjon', 'left' => 120, 'width' => 140],
                ['text' => 'H', 'left' => 300, 'width' => 30],
                ['text' => 'K', 'left' => 540, 'width' => 30],
            ]),
            // Row 1 continuation — single item at col-2 position (left=120). Old code rejects this.
            $this->popplerLineGroup('og sørge for sporbarhet', 116, 120, 260, 130, [
                ['text' => 'og sørge for sporbarhet', 'left' => 120, 'width' => 140],
            ]),
            // Row 2 — 4-column candidate line.
            $this->popplerLineGroup('R2 Godkjenne og signere I I', 132, 50, 620, 146, [
                ['text' => 'R2', 'left' => 50, 'width' => 30],
                ['text' => 'Godkjenne og signere', 'left' => 120, 'width' => 140],
                ['text' => 'I', 'left' => 300, 'width' => 30],
                ['text' => 'I', 'left' => 540, 'width' => 30],
            ]),
            // Row 2 continuation — same col-2 position.
            $this->popplerLineGroup('avtale og protokoll', 148, 120, 260, 162, [
                ['text' => 'avtale og protokoll', 'left' => 120, 'width' => 140],
            ]),
            // Row 3 — 4-column candidate line, no continuation.
            $this->popplerLineGroup('R3 Rapportere status U U', 164, 50, 620, 178, [
                ['text' => 'R3', 'left' => 50, 'width' => 30],
                ['text' => 'Rapportere status', 'left' => 120, 'width' => 140],
                ['text' => 'U', 'left' => 300, 'width' => 30],
                ['text' => 'U', 'left' => 540, 'width' => 30],
            ]),
            // Body text after the table — starts at left=72 but spans most of the page;
            // width guard must keep it out of the table even after the fix.
            $this->popplerLineGroup('Dette er fritekst som ikke tilhører tabellen over.', 194, 72, 770, 208, [
                ['text' => 'Dette er fritekst som ikke tilhører tabellen over.', 'left' => 72, 'width' => 698],
            ]),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 842]);

        // Currently FAILS: old code breaks the run at each col-2 continuation, yielding no complete runs.
        $this->assertCount(1, $runs, 'There must be exactly one table run spanning all three rows and their continuations.');
        $this->assertSame(0, $runs[0]['start_index']);
        $this->assertSame(4, $runs[0]['end_index'], 'Run must end at the last data row (index 4), not absorb the body text (index 5).');
    }

    public function test_it_appends_second_column_continuation_text_to_correct_cell(): void
    {
        $extractor = new DocumentTextExtractor;

        // Same geometry: col2 starts at left=120, beyond the old left-zone boundary of firstCellLeft+45=95.
        $tableLines = [
            // Row 1 — 4-column candidate line.
            $this->popplerLineGroup('R1 Levere løsning H K', 100, 50, 620, 114, [
                ['text' => 'R1', 'left' => 50, 'width' => 30],
                ['text' => 'Levere løsning', 'left' => 120, 'width' => 140],
                ['text' => 'H', 'left' => 300, 'width' => 30],
                ['text' => 'K', 'left' => 540, 'width' => 30],
            ]),
            // Row 1 continuation at col-2 position.
            $this->popplerLineGroup('innen avtalt frist', 116, 120, 260, 130, [
                ['text' => 'innen avtalt frist', 'left' => 120, 'width' => 140],
            ]),
            // Row 2 — no continuation.
            $this->popplerLineGroup('R2 Godkjenne dokumentasjon I I', 132, 50, 620, 146, [
                ['text' => 'R2', 'left' => 50, 'width' => 30],
                ['text' => 'Godkjenne dokumentasjon', 'left' => 120, 'width' => 140],
                ['text' => 'I', 'left' => 300, 'width' => 30],
                ['text' => 'I', 'left' => 540, 'width' => 30],
            ]),
        ];

        $rows = $this->invokeDocumentTextExtractorMethod($extractor, 'buildPopplerPdfTableLogicalRows', [$tableLines, 842]);

        $this->assertCount(2, $rows, 'Two data rows must produce two logical rows.');

        $row1Cells = array_values((array) ($rows[0]['cells'] ?? []));

        $this->assertSame('R1', (string) ($row1Cells[0]['text'] ?? ''));
        $this->assertStringContainsString('Levere løsning', (string) ($row1Cells[1]['text'] ?? ''));

        // Currently FAILS: old code silently drops the continuation line instead of merging it.
        $this->assertStringContainsString('innen avtalt frist', (string) ($row1Cells[1]['text'] ?? ''), 'Continuation text must be merged into the second cell of the first row.');

        $this->assertSame('H', (string) ($row1Cells[2]['text'] ?? ''), 'Third cell must be unchanged.');
        $this->assertSame('K', (string) ($row1Cells[3]['text'] ?? ''), 'Fourth cell must be unchanged.');

        $row2Cells = array_values((array) ($rows[1]['cells'] ?? []));
        $this->assertSame('R2', (string) ($row2Cells[0]['text'] ?? ''));
        $this->assertSame('Godkjenne dokumentasjon', (string) ($row2Cells[1]['text'] ?? ''), 'Row 2 must be unaffected.');
    }

    // Regression: when one item in a line group is taller than the others (e.g. bold H/U with height=28
    // while surrounding text uses height=20), the group's bottom is extended by the taller item.
    // A continuation line whose top falls inside that extended span gets a negative verticalGap
    // (e.g. 319 - 324 = -5) and is rejected by the < -2 guard, breaking the table run prematurely.
    // Coordinates are taken directly from the real ANSVARSMATRISE table on PDF page 29.
    public function test_it_detects_table_run_when_continuation_top_falls_within_tall_item_span(): void
    {
        $extractor = new DocumentTextExtractor;

        $lineGroups = [
            // Header (index 0): 4 columns matching the real ANSVARSMATRISE header layout.
            $this->popplerLineGroup('I Aktivitet/Leveranse Leverandør Kunde', 224, 107, 761, 244, [
                ['text' => 'I', 'left' => 107, 'width' => 8],
                ['text' => 'Aktivitet/Leveranse', 'left' => 158, 'width' => 139],
                ['text' => 'Leverandør', 'left' => 615, 'width' => 82],
                ['text' => 'Kunde', 'left' => 713, 'width' => 48],
            ]),
            // R1 main row (index 1): all items at normal height, bottom = top+20 = 269.
            $this->popplerLineGroup('R1 Etablere Leverandørens roller H/U I', 249, 107, 756, 269, [
                ['text' => 'R1', 'left' => 107, 'width' => 21],
                ['text' => 'Etablere Leverandørens roller', 'left' => 158, 'width' => 403],
                ['text' => 'H/U', 'left' => 650, 'width' => 31],
                ['text' => 'I', 'left' => 744, 'width' => 12],
            ]),
            // R1 continuation at col 2 (index 2): gap = 272-269 = +3, well within tolerance.
            $this->popplerLineGroup('spesifisert i kontrakten.', 272, 158, 327, 292, [
                ['text' => 'spesifisert i kontrakten.', 'left' => 158, 'width' => 169],
            ]),
            // R2 main row (index 3): the H/U cell renders with height=28 in the real PDF.
            // This extends the line group bottom to 296+28=324 instead of the expected 316.
            $this->popplerLineGroup('R2 Etablere kundens roller I H/U', 296, 107, 788, 324, [
                ['text' => 'R2', 'left' => 107, 'width' => 21],
                ['text' => 'Etablere kundens roller', 'left' => 158, 'width' => 439],
                ['text' => 'I', 'left' => 660, 'width' => 12],
                ['text' => 'H/U', 'left' => 733, 'width' => 55],
            ]),
            // R2 continuation at col 2 (index 4): top=319 is inside the R2 group span (296-324).
            // verticalGap = 319 - 324 = -5. Currently rejected by verticalGap < -2.
            $this->popplerLineGroup('kontrakten.', 319, 158, 247, 339, [
                ['text' => 'kontrakten.', 'left' => 158, 'width' => 89],
            ]),
            // R3 main row (index 5): would start a fresh run if the R2 continuation breaks the current one.
            $this->popplerLineGroup('R3 Gjøre tilgjengelig dokumentasjon I H/U', 344, 107, 765, 364, [
                ['text' => 'R3', 'left' => 107, 'width' => 21],
                ['text' => 'Gjøre tilgjengelig dokumentasjon', 'left' => 158, 'width' => 428],
                ['text' => 'I', 'left' => 660, 'width' => 12],
                ['text' => 'H/U', 'left' => 734, 'width' => 31],
            ]),
        ];

        $runs = $this->invokeDocumentTextExtractorMethod($extractor, 'detectPopplerPdfTableRuns', [$lineGroups, 892]);

        $this->assertCount(1, $runs, 'One continuous table run must be detected across all three data rows.');
        $this->assertSame(0, $runs[0]['start_index']);

        // Currently FAILS: run ends at index 3 (R2 main row) because the R2 continuation at
        // top=319 gets verticalGap = 319-324 = -5, which fails the verticalGap < -2 guard.
        // After the fix, the run must extend through R3 at index 5.
        $this->assertSame(5, $runs[0]['end_index'], 'Run must extend through R3 (index 5), not stop at R2 main row (index 3).');
    }

    public function test_it_merges_adjacent_poppler_table_blocks_across_a_page_break(): void
    {
        $extractor = new DocumentTextExtractor;
        $previousTableBlock = [
            'type' => 'table',
            'page_number' => 13,
            'page_height' => 1200,
            'top' => 190,
            'left' => 115,
            'width' => 620,
            'height' => 1005,
            'title' => 'Tabell 8 – side 13',
            'text' => "Ansvarlig | Leverandøren\nAgenda | • Status på fremdrift\nLokasjon | Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.",
            'table_text' => "Ansvarlig | Leverandøren\nAgenda | • Status på fremdrift\nLokasjon | Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.",
            'table_markdown' => "| Ansvarlig | Leverandøren |\n| Agenda | • Status på fremdrift |\n| Lokasjon | Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler. |",
            'table_complexity' => 'simple',
            'table_warnings' => [],
            'table_json' => [
                'source_type' => 'pdf_table',
                'complexity' => 'simple',
                'warnings' => [],
                'row_count' => 3,
                'column_count' => 2,
                'title_row_index' => null,
                'header_row_indices' => [],
                'rows' => [
                    [
                        'row_index' => 0,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 0, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Ansvarlig', 'is_empty' => false],
                            ['row_index' => 0, 'cell_index' => 1, 'column_index' => 1, 'text' => 'Leverandøren', 'is_empty' => false],
                        ],
                    ],
                    [
                        'row_index' => 1,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 1, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Agenda', 'is_empty' => false],
                            ['row_index' => 1, 'cell_index' => 1, 'column_index' => 1, 'text' => '• Status på fremdrift', 'is_empty' => false],
                        ],
                    ],
                    [
                        'row_index' => 2,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 2, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Lokasjon', 'is_empty' => false],
                            ['row_index' => 2, 'cell_index' => 1, 'column_index' => 1, 'text' => 'Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.', 'is_empty' => false],
                        ],
                    ],
                ],
            ],
        ];

        $continuationTableBlock = [
            'type' => 'table',
            'page_number' => 14,
            'page_height' => 1200,
            'top' => 72,
            'left' => 115,
            'width' => 620,
            'height' => 180,
            'title' => 'Tabell 9 – side 14',
            'text' => "Deltagere fra\nKunden | TBD\nResultat | Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet\nFrekvens | Hver 4 uke, oftere ved behov",
            'table_text' => "Deltagere fra\nKunden | TBD\nResultat | Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet\nFrekvens | Hver 4 uke, oftere ved behov",
            'table_markdown' => "| Deltagere fra | TBD |\n| Resultat | Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet |\n| Frekvens | Hver 4 uke, oftere ved behov |",
            'table_complexity' => 'simple',
            'table_warnings' => [],
            'table_json' => [
                'source_type' => 'pdf_table',
                'complexity' => 'simple',
                'warnings' => [],
                'row_count' => 3,
                'column_count' => 2,
                'title_row_index' => null,
                'header_row_indices' => [],
                'rows' => [
                    [
                        'row_index' => 0,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 0, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Deltagere fra', 'is_empty' => false],
                            ['row_index' => 0, 'cell_index' => 1, 'column_index' => 1, 'text' => "Kunden\nTBD", 'is_empty' => false],
                        ],
                    ],
                    [
                        'row_index' => 1,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 1, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Resultat', 'is_empty' => false],
                            ['row_index' => 1, 'cell_index' => 1, 'column_index' => 1, 'text' => 'Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet', 'is_empty' => false],
                        ],
                    ],
                    [
                        'row_index' => 2,
                        'row_type' => 'data',
                        'is_title' => false,
                        'is_header' => false,
                        'is_empty' => false,
                        'explicit_header' => false,
                        'cells' => [
                            ['row_index' => 2, 'cell_index' => 0, 'column_index' => 0, 'text' => 'Frekvens', 'is_empty' => false],
                            ['row_index' => 2, 'cell_index' => 1, 'column_index' => 1, 'text' => 'Hver 4 uke, oftere ved behov', 'is_empty' => false],
                        ],
                    ],
                ],
            ],
        ];

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$previousTableBlock, $continuationTableBlock],
        ]);

        $this->assertCount(1, $blocks);
        $this->assertSame('table', $blocks[0]['type']);
        $this->assertSame(14, $blocks[0]['page_end']);
        $this->assertSame("Ansvarlig | Leverandøren\nAgenda | • Status på fremdrift\nLokasjon | Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.\nDeltagere fra\nKunden | TBD\nResultat | Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet\nFrekvens | Hver 4 uke, oftere ved behov", $blocks[0]['table_text']);
        $this->assertSame(6, data_get($blocks[0], 'table_json.row_count'));
        $this->assertStringContainsString('Lokasjon | Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.', $blocks[0]['table_text']);
        $this->assertStringContainsString('Frekvens | Hver 4 uke, oftere ved behov', $blocks[0]['table_text']);
    }

    public function test_it_merges_table_across_page_break_with_intervening_footer_and_separator_blocks(): void
    {
        $extractor = new DocumentTextExtractor;

        $page13Table = $this->makeSimpleTableBlock(13, 1000, 842, 200, 750, 'Tabell 8 – side 13', [
            ['Ansvarlig', 'Leverandøren'],
            ['Agenda', 'Status på fremdrift'],
            ['Lokasjon', 'Møtet avholdes som Teams-møte'],
        ]);

        // Footer on page 13, appears after the table.
        $page13Footer = [
            'type' => 'paragraph',
            'page_number' => 13,
            'page_height' => 1000,
            'page_width' => 842,
            'top' => 970,
            'left' => 50,
            'width' => 750,
            'height' => 20,
            'text' => '© Advania Norge AS   29.09.2025   Side 13 av 31',
            'source_metadata' => ['source_type' => 'pdf_text', 'page_number' => 13],
        ];

        // ALL-CAPS section label at the top of page 14 (e.g. "BILAG 1-11").
        $page14BilagHeading = [
            'type' => 'heading',
            'page_number' => 14,
            'page_height' => 1000,
            'page_width' => 842,
            'top' => 40,
            'left' => 50,
            'width' => 200,
            'height' => 20,
            'text' => 'BILAG 1-11',
            'level' => 1,
            'source_metadata' => ['source_type' => 'pdf_text', 'page_number' => 14],
        ];

        // Company logo image at the top of page 14.
        $page14Logo = [
            'type' => 'image',
            'page_number' => 14,
            'page_height' => 1000,
            'page_width' => 842,
            'top' => 40,
            'left' => 600,
            'width' => 150,
            'height' => 40,
            'text' => '',
            'image_bytes' => null,
            'source_metadata' => ['source_type' => 'pdf_graphic', 'page_number' => 14],
        ];

        $page14TableCont = $this->makeSimpleTableBlock(14, 1000, 842, 90, 120, 'Tabell 9 – side 14', [
            ['Resultat', 'Fatte beslutninger som sikrer fremdriften'],
            ['Frekvens', 'Hver 4 uke, oftere ved behov'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page13Table, $page13Footer, $page14BilagHeading, $page14Logo, $page14TableCont],
        ]);

        $this->assertCount(4, $blocks, 'Separator blocks are preserved; only the two table blocks merge.');
        $merged = $blocks[0];
        $this->assertSame('table', $merged['type']);
        $this->assertSame(13, $merged['page_number']);
        $this->assertSame(14, $merged['page_end']);
        $this->assertSame(5, data_get($merged, 'table_json.row_count'));
        $this->assertStringContainsString('Lokasjon | Møtet avholdes som Teams-møte', $merged['table_text']);
        $this->assertStringContainsString('Frekvens | Hver 4 uke, oftere ved behov', $merged['table_text']);
        $this->assertSame('Tabell 8 – side 13–14', $merged['title']);
    }

    public function test_it_merges_a_continuing_table_across_a_page_break_even_when_the_second_page_block_is_wider(): void
    {
        $extractor = new DocumentTextExtractor;

        $page14Table = $this->makeSimpleTableBlock(14, 1262, 892, 750, 393, 'Tabell 3 – side 14', [
            ['Ansvarlig', 'Leverandøren'],
            ['Agenda', 'Status på fremdrift'],
            ['Kvalitet', 'Kvalitet i leveransene'],
            ['Lokasjon', 'Møtet avholdes som Teams-møte, eventuelt i Kundens lokaler.'],
            ['Deltagere fra Kunden', 'TBD – 3 representanter'],
            ['Deltagere fra Leverandøren', 'Prosjekteier, Ressurseier, Prosjektleder, Andre ved behov'],
        ]);
        $page14Table['width'] = 360;

        $page15Table = $this->makeSimpleTableBlock(15, 1262, 892, 107, 56, 'Tabell 4 – side 15', [
            ['Resultat', 'Fatte beslutninger som sikrer fremdriften og kvaliteten i prosjektet'],
            ['Frekvens', 'Hver 4 uke, oftere ved behov'],
        ]);
        $page15Table['width'] = 714;

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page14Table, $page15Table],
        ]);

        $this->assertCount(1, $blocks, 'A table that clearly continues on the next page must remain one merged table block.');
        $this->assertSame('table', $blocks[0]['type']);
        $this->assertSame(14, $blocks[0]['page_number']);
        $this->assertSame(15, $blocks[0]['page_end']);
        $this->assertSame(8, data_get($blocks[0], 'table_json.row_count'));
        $this->assertStringContainsString('Deltagere fra Leverandøren', $blocks[0]['table_text']);
        $this->assertStringContainsString('Frekvens | Hver 4 uke, oftere ved behov', $blocks[0]['table_text']);
    }

    public function test_it_does_not_merge_tables_separated_by_a_content_heading(): void
    {
        $extractor = new DocumentTextExtractor;

        $page13Table = $this->makeSimpleTableBlock(13, 1000, 842, 200, 750, 'Tabell 8 – side 13', [
            ['Ansvarlig', 'Leverandøren'],
            ['Agenda', 'Status på fremdrift'],
            ['Lokasjon', 'Møtet avholdes som Teams-møte'],
        ]);

        // Real content heading on page 14 – this must prevent the merge.
        $page14Heading = [
            'type' => 'heading',
            'page_number' => 14,
            'page_height' => 1000,
            'page_width' => 842,
            'top' => 40,
            'left' => 50,
            'width' => 400,
            'height' => 20,
            'text' => '3 Ny seksjon med eget innhold',
            'level' => 1,
            'source_metadata' => ['source_type' => 'pdf_text', 'page_number' => 14],
        ];

        $page14Table = $this->makeSimpleTableBlock(14, 1000, 842, 90, 180, 'Tabell 9 – side 14', [
            ['Felt A', 'Verdi A'],
            ['Felt B', 'Verdi B'],
            ['Felt C', 'Verdi C'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page13Table, $page14Heading, $page14Table],
        ]);

        $this->assertCount(3, $blocks, 'A content heading between tables must prevent the merge.');
        $this->assertNull($blocks[0]['page_end'] ?? null, 'First table should not have page_end when not merged.');
        $this->assertSame(13, $blocks[0]['page_number']);
        $this->assertSame(14, $blocks[2]['page_number']);
    }

    public function test_it_does_not_merge_tables_separated_by_body_text(): void
    {
        $extractor = new DocumentTextExtractor;

        $page13Table = $this->makeSimpleTableBlock(13, 1000, 842, 200, 750, 'Tabell 8 – side 13', [
            ['Ansvarlig', 'Leverandøren'],
            ['Lokasjon', 'Teams-møte'],
            ['Frekvens', 'Hver 4 uke'],
        ]);

        $page14Body = [
            'type' => 'paragraph',
            'page_number' => 14,
            'page_height' => 1000,
            'page_width' => 842,
            'top' => 40,
            'left' => 50,
            'width' => 600,
            'height' => 40,
            'text' => 'Dette er brødtekst som innleder en ny separat tabell på neste side.',
            'source_metadata' => ['source_type' => 'pdf_text', 'page_number' => 14],
        ];

        $page14Table = $this->makeSimpleTableBlock(14, 1000, 842, 120, 180, 'Tabell 9 – side 14', [
            ['Felt A', 'Verdi A'],
            ['Felt B', 'Verdi B'],
            ['Felt C', 'Verdi C'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page13Table, $page14Body, $page14Table],
        ]);

        $this->assertCount(3, $blocks, 'Body text between tables must prevent the merge.');
        $this->assertSame(13, $blocks[0]['page_number']);
        $this->assertSame(14, $blocks[2]['page_number']);
    }

    public function test_it_merges_table_across_three_pages_using_page_end(): void
    {
        $extractor = new DocumentTextExtractor;

        $page13Table = $this->makeSimpleTableBlock(13, 1000, 842, 200, 750, 'Tabell 5 – side 13', [
            ['Rad 1A', 'Rad 1B'],
            ['Rad 2A', 'Rad 2B'],
            ['Rad 3A', 'Rad 3B'],
        ]);
        $page14Table = $this->makeSimpleTableBlock(14, 1000, 842, 50, 200, 'Tabell 6 – side 14', [
            ['Rad 4A', 'Rad 4B'],
            ['Rad 5A', 'Rad 5B'],
            ['Rad 6A', 'Rad 6B'],
        ]);
        $page15Table = $this->makeSimpleTableBlock(15, 1000, 842, 50, 180, 'Tabell 7 – side 15', [
            ['Rad 7A', 'Rad 7B'],
            ['Rad 8A', 'Rad 8B'],
            ['Rad 9A', 'Rad 9B'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page13Table, $page14Table, $page15Table],
        ]);

        $this->assertCount(1, $blocks);
        $this->assertSame('table', $blocks[0]['type']);
        $this->assertSame(13, $blocks[0]['page_number']);
        $this->assertSame(15, $blocks[0]['page_end']);
        $this->assertSame(9, data_get($blocks[0], 'table_json.row_count'));
        $this->assertSame('Tabell 5 – side 13–15', $blocks[0]['title']);
    }

    public function test_it_updates_merged_table_title_with_page_range(): void
    {
        $extractor = new DocumentTextExtractor;

        $page5Table = $this->makeSimpleTableBlock(5, 1000, 842, 200, 750, 'Tabell 3 – side 5', [
            ['Kolonne A', 'Kolonne B'],
            ['Verdi 1', 'Verdi 2'],
            ['Verdi 3', 'Verdi 4'],
        ]);
        $page6Table = $this->makeSimpleTableBlock(6, 1000, 842, 50, 180, 'Tabell 4 – side 6', [
            ['Verdi 5', 'Verdi 6'],
            ['Verdi 7', 'Verdi 8'],
            ['Verdi 9', 'Verdi 10'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page5Table, $page6Table],
        ]);

        $this->assertCount(1, $blocks);
        $this->assertSame('Tabell 3 – side 5–6', $blocks[0]['title']);
    }

    public function test_it_does_not_merge_separate_tables_on_non_consecutive_pages(): void
    {
        $extractor = new DocumentTextExtractor;

        $page5Table = $this->makeSimpleTableBlock(5, 1000, 842, 200, 400, 'Tabell 1 – side 5', [
            ['A', 'B'],
            ['C', 'D'],
            ['E', 'F'],
        ]);
        $page7Table = $this->makeSimpleTableBlock(7, 1000, 842, 50, 200, 'Tabell 2 – side 7', [
            ['X', 'Y'],
            ['Z', 'W'],
            ['Q', 'R'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page5Table, $page7Table],
        ]);

        $this->assertCount(2, $blocks, 'Tables on non-consecutive pages must not be merged.');
    }

    public function test_it_does_not_merge_table_whose_previous_part_ends_too_high_on_the_page(): void
    {
        $extractor = new DocumentTextExtractor;

        // Table ending at 60% of the page — below the 0.70 threshold.
        $page1Table = $this->makeSimpleTableBlock(1, 1000, 842, 100, 500, 'Tabell 1 – side 1', [
            ['A', 'B'],
            ['C', 'D'],
            ['E', 'F'],
        ]);
        $page2Table = $this->makeSimpleTableBlock(2, 1000, 842, 50, 200, 'Tabell 2 – side 2', [
            ['X', 'Y'],
            ['Z', 'W'],
            ['Q', 'R'],
        ]);

        $blocks = $this->invokeDocumentTextExtractorMethod($extractor, 'mergePopplerPdfTableBlocksAcrossPages', [
            [$page1Table, $page2Table],
        ]);

        $this->assertCount(2, $blocks, 'Table not reaching page bottom must not trigger a merge.');
    }

    /**
     * Build a minimal table block compatible with mergePopplerPdfTableBlocks requirements.
     *
     * @param  array<int, array{0: string, 1: string}>  $rows  Two-column row data.
     * @return array<string, mixed>
     */
    private function makeSimpleTableBlock(
        int $pageNumber,
        int $pageHeight,
        int $pageWidth,
        int $top,
        int $height,
        string $title,
        array $rows,
    ): array {
        $jsonRows = [];
        $textLines = [];

        foreach ($rows as $index => [$cellA, $cellB]) {
            $textLines[] = "{$cellA} | {$cellB}";
            $jsonRows[] = [
                'row_index' => $index,
                'row_type' => 'data',
                'is_title' => false,
                'is_header' => false,
                'is_empty' => false,
                'explicit_header' => false,
                'cells' => [
                    ['row_index' => $index, 'cell_index' => 0, 'column_index' => 0, 'text' => $cellA, 'is_empty' => false, 'source_metadata' => []],
                    ['row_index' => $index, 'cell_index' => 1, 'column_index' => 1, 'text' => $cellB, 'is_empty' => false, 'source_metadata' => []],
                ],
                'source_metadata' => ['source_type' => 'pdf_table', 'page_number' => $pageNumber],
            ];
        }

        $tableText = implode("\n", $textLines);

        return [
            'type' => 'table',
            'page_number' => $pageNumber,
            'page_height' => $pageHeight,
            'page_width' => $pageWidth,
            'top' => $top,
            'left' => 100,
            'width' => 650,
            'height' => $height,
            'title' => $title,
            'text' => $tableText,
            'table_text' => $tableText,
            'table_markdown' => implode("\n", array_map(
                static fn (array $r): string => "| {$r[0]} | {$r[1]} |",
                $rows,
            )),
            'table_complexity' => 'simple',
            'table_warnings' => [],
            'table_json' => [
                'source_type' => 'pdf_table',
                'complexity' => 'simple',
                'warnings' => [],
                'row_count' => count($rows),
                'column_count' => 2,
                'title_row_index' => null,
                'header_row_indices' => [],
                'rows' => $jsonRows,
                'source_metadata' => ['source_type' => 'pdf_table', 'page_number' => $pageNumber],
                'table_text' => $tableText,
                'table_markdown' => '',
            ],
            'source_metadata' => ['source_type' => 'pdf_table', 'page_number' => $pageNumber],
        ];
    }

    private function writeValidTestPdf(string $path): void
    {
        // A minimal but structurally complete PDF that pdftotext can parse.
        // WinAnsiEncoding maps 0xF8 → ø, giving us "Første" with correct UTF-8 output.
        $header = "%PDF-1.4\n";
        $obj1 = "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $obj2 = "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $obj3 = "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
            .' /Resources <</Font <</F1 <</Type /Font /Subtype /Type1 /BaseFont /Helvetica'
            ." /Encoding /WinAnsiEncoding>>>>>>\n>>\nendobj\n";
        // \370 is octal 0xF8 = ø in WinAnsiEncoding
        $stream = 'BT /F1 14 Tf 72 720 Td (Overskrift) Tj'
            ." 0 -100 Td (F\370rste avsnitt med tekst.) Tj"
            ." 0 -100 Td (Andre avsnitt med mer tekst.) Tj ET\n";
        $obj4 = "4 0 obj\n<</Length ".strlen($stream).">>\nstream\n{$stream}endstream\nendobj\n";

        $o1 = strlen($header);
        $o2 = $o1 + strlen($obj1);
        $o3 = $o2 + strlen($obj2);
        $o4 = $o3 + strlen($obj3);
        $xrefOffset = $o4 + strlen($obj4);

        $xref = "xref\n0 5\n"
            ."0000000000 65535 f \n"
            .str_pad((string) $o1, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o2, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o3, 10, '0', STR_PAD_LEFT)." 00000 n \n"
            .str_pad((string) $o4, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        $trailer = "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($path, $header.$obj1.$obj2.$obj3.$obj4.$xref.$trailer);
    }

    public function test_it_extracts_png_and_jpeg_images_in_document_order_with_relationship_and_content_hash(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $imageParagraph1 = $this->docxImageParagraphXml('rId1', 'Figur 1', 'Alt-tekst for bilde 1');
            $imageParagraph2 = $this->docxImageParagraphXml('rId4', 'Figur 2', 'Alt-tekst for bilde 2');

            $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Tekst før første bilde.</w:t></w:r></w:p>
        {$imageParagraph1}
        <w:p><w:r><w:t>Tekst mellom bildene.</w:t></w:r></w:p>
        {$imageParagraph2}
        <w:p><w:r><w:t>Tekst etter siste bilde.</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
    <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.jpeg"/>
</Relationships>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            $zip->addFromString('word/media/image1.png', $this->docxSamplePngBytes());
            $zip->addFromString('word/media/image2.jpeg', $this->docxSampleJpegBytes());
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);

            $this->assertCount(2, $images);

            $this->assertSame('rId1', $images[0]->relationshipId);
            $this->assertSame('word/media/image1.png', $images[0]->originalMediaPath);
            $this->assertSame('image/png', $images[0]->mimeType);
            $this->assertSame(1, $images[0]->width);
            $this->assertSame(1, $images[0]->height);
            $this->assertSame(hash('sha256', $this->docxSamplePngBytes()), $images[0]->contentHash);
            $this->assertSame(0, $images[0]->imageIndex);

            $this->assertSame('rId4', $images[1]->relationshipId);
            $this->assertSame('word/media/image2.jpeg', $images[1]->originalMediaPath);
            $this->assertSame('image/jpeg', $images[1]->mimeType);
            $this->assertSame(1, $images[1]->imageIndex);

            // Document order reflects the flat paragraph walk (0-indexed body paragraphs), not
            // just image ordinal — the second image's paragraph comes after 3 preceding paragraphs.
            $this->assertGreaterThan($images[0]->documentOrder, $images[1]->documentOrder);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_preserves_alt_text_caption_heading_context_and_norwegian_characters(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $imageParagraph = $this->docxImageParagraphXml(
                'rId1',
                'Figur 3',
                'Diagram som viser dataflyt mellom CRM og ERP – æøå',
            );

            $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
            <w:r><w:t>1 Integrasjoner</w:t></w:r>
        </w:p>
        <w:p><w:r><w:t>Figuren følger et avsnitt om dataflyt mellom CRM og ERP.</w:t></w:r></w:p>
        {$imageParagraph}
        <w:p>
            <w:pPr><w:pStyle w:val="Caption"/></w:pPr>
            <w:r><w:t>Figur 3: Oversikt over systemintegrasjonene – æøå</w:t></w:r>
        </w:p>
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            $zip->addFromString('word/media/image1.png', $this->docxSamplePngBytes());
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);

            $this->assertCount(1, $images);
            $image = $images[0];

            $this->assertSame('Diagram som viser dataflyt mellom CRM og ERP – æøå', $image->altText);
            $this->assertSame('Figur 3: Oversikt over systemintegrasjonene – æøå', $image->caption);
            $this->assertSame('1', $image->sectionNumber);
            $this->assertSame('Integrasjoner', $image->sectionTitle);
            $this->assertSame('Figuren følger et avsnitt om dataflyt mellom CRM og ERP.', $image->textBefore);
            $this->assertSame('Figur 3: Oversikt over systemintegrasjonene – æøå', $image->textAfter);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_skips_images_inside_table_cells(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $imageParagraph = $this->docxImageParagraphXml('rId1', 'Figur 1', 'Logo i tabellcelle');

            $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:tbl>
            <w:tr>
                <w:tc>{$imageParagraph}</w:tc>
            </w:tr>
        </w:tbl>
        <w:p><w:r><w:t>Vanlig avsnitt utenfor tabellen.</w:t></w:r></w:p>
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            $zip->addFromString('word/media/image1.png', $this->docxSamplePngBytes());
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);

            $this->assertSame([], $images);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_skips_images_with_unresolvable_or_missing_relationship_without_breaking_extraction(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            // rId1: relationship entry exists but references a media file never added to the ZIP.
            // rId2: no relationship entry at all (missing from document.xml.rels).
            // rId3: a valid, resolvable image — must still be extracted despite the two failures.
            $brokenMediaImage = $this->docxImageParagraphXml('rId1', 'Figur 1', 'Manglende mediefil');
            $missingRelationshipImage = $this->docxImageParagraphXml('rId2', 'Figur 2', 'Manglende relasjon');
            $validImage = $this->docxImageParagraphXml('rId3', 'Figur 3', 'Gyldig bilde');

            $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        {$brokenMediaImage}
        {$missingRelationshipImage}
        {$validImage}
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image3.png"/>
</Relationships>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            // Deliberately no word/media/image1.png entry.
            $zip->addFromString('word/media/image3.png', $this->docxSamplePngBytes());
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);

            $this->assertCount(1, $images);
            $this->assertSame('rId3', $images[0]->relationshipId);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_stamps_document_scoped_source_image_key_via_many_with_document_id(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $imageParagraph = $this->docxImageParagraphXml('rId1', 'Figur 1', 'Alt-tekst');

            $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        {$imageParagraph}
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            $zip->addFromString('word/media/image1.png', $this->docxSamplePngBytes());
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);
            $this->assertSame('img0', $images[0]->sourceImageKey);

            $stamped = DocxImageData::manyWithDocumentId($images, 42);

            $this->assertSame('doc42-img0', $stamped[0]->sourceImageKey);
            // Stamping must not mutate the original, already-returned DTOs.
            $this->assertSame('img0', $images[0]->sourceImageKey);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Regression for a real production document ("Incident Management Illustration.docx", run
     * 475): an image pasted from a web page becomes an INCLUDEPICTURE field (fldChar begin/
     * instrText/fldChar separate/<w:drawing>/fldChar end, all inside one paragraph) rather than a
     * bare <w:drawing> — Word still caches a normal embedded relationship+drawing inside the field
     * result run, so extraction must still find it. The image's wp:docPr in this real document
     * carries only Word's auto-generated `name="Picture 1"` — no descr, no title — and there is no
     * formal Word caption paragraph anywhere. Before the fix, `docxImageAltText()` fell back to
     * `name`, producing the meaningless alt-text "Picture 1"; this test locks in that it now
     * resolves to null instead.
     */
    public function test_it_extracts_an_includepicture_field_image_with_no_real_alt_text_or_caption(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren i forbindelse med Incident prosessen.</w:t></w:r></w:p>
        <w:p/>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r><w:fldChar w:fldCharType="begin"/></w:r>
            <w:r><w:instrText xml:space="preserve"> INCLUDEPICTURE "/tmp/some-temp-file" \* MERGEFORMATINET </w:instrText></w:r>
            <w:r><w:fldChar w:fldCharType="separate"/></w:r>
            <w:r>
                <w:rPr><w:noProof/></w:rPr>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="5731510" cy="5731510"/>
                        <wp:docPr id="1828865866" name="Picture 1"/>
                        <a:graphic>
                            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:pic>
                                    <pic:blipFill>
                                        <a:blip r:embed="rId4"/>
                                    </pic:blipFill>
                                </pic:pic>
                            </a:graphicData>
                        </a:graphic>
                    </wp:inline>
                </w:drawing>
            </w:r>
            <w:r><w:fldChar w:fldCharType="end"/></w:r>
        </w:p>
    </w:body>
</w:document>
XML;

            $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

            // A real-sized (not tiny) image — the actual production document's picture was 554x554.
            $mediaImage = imagecreatetruecolor(554, 554);
            imagefilledrectangle($mediaImage, 0, 0, 553, 553, (int) imagecolorallocate($mediaImage, 50, 90, 140));
            ob_start();
            imagepng($mediaImage);
            $mediaBytes = (string) ob_get_clean();
            imagedestroy($mediaImage);

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
            $zip->addFromString('word/media/image1.png', $mediaBytes);
            $zip->close();

            $extractor = new DocumentTextExtractor;
            $images = $extractor->extractDocxImages($path);

            $this->assertCount(1, $images);
            $image = $images[0];

            $this->assertSame('rId4', $image->relationshipId);
            $this->assertSame('image/png', $image->mimeType);
            $this->assertSame(554, $image->width);
            $this->assertSame(554, $image->height);
            // Word's auto-generated shape name must never be mistaken for real alt-text.
            $this->assertNull($image->altText);
            $this->assertNull($image->caption);
            $this->assertSame(
                'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren i forbindelse med Incident prosessen.',
                $image->textBefore,
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Purpose: Build an inline DOCX image paragraph fixture (mirrors the shape produced by real
     * Word documents for an ordinary, non-anchored inline image).
     * Inputs: The relationship ID to reference, and the docPr title/descr values.
     * Returns: A <w:p> XML fragment containing a <w:drawing><wp:inline>...<a:blip>.
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
     * Purpose: Return a compact 1x1 PNG image fixture for image-extraction tests.
     * Inputs: None.
     * Returns: Binary PNG bytes suitable for a DOCX media entry.
     * Side effects: None.
     */
    private function docxSamplePngBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2X3b8AAAAASUVORK5CYII=', true);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to build a PNG image fixture.');
        }

        return $bytes;
    }

    /**
     * Purpose: Return a compact 1x1 JPEG image fixture for image-extraction tests.
     * Inputs: None.
     * Returns: Binary JPEG bytes suitable for a DOCX media entry.
     * Side effects: None.
     */
    private function docxSampleJpegBytes(): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagefilledrectangle($image, 0, 0, 0, 0, (int) imagecolorallocate($image, 10, 20, 30));

        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to build a JPEG image fixture.');
        }

        return $bytes;
    }

    private function tempDocumentPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-extractor-');

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

    private function invokePdfHeadingLevel(DocumentTextExtractor $extractor, string $line): ?int
    {
        $method = new \ReflectionMethod($extractor, 'detectPdfHeadingLevel');
        $method->setAccessible(true);

        return $method->invoke($extractor, $line);
    }

    /**
     * Purpose: Invoke a private method on the DocumentTextExtractor for focused unit tests.
     * Inputs: The extractor instance, a private method name, and ordered arguments.
     * Returns: The invoked method result.
     * Side effects: None.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function invokeDocumentTextExtractorMethod(DocumentTextExtractor $extractor, string $methodName, array $arguments = []): mixed
    {
        $method = new \ReflectionMethod($extractor, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($extractor, $arguments);
    }

    /**
     * Purpose: Build one synthetic Poppler-like line group for private extractor tests.
     * Inputs: The visible line text, geometry, optional already split items, and optional extra fields.
     * Returns: A line-group array compatible with the Poppler table/list detector.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function popplerLineGroup(string $text, int $top, int $left, int $right, int $bottom, array $items = [], array $overrides = []): array
    {
        return array_merge([
            'text' => $text,
            'items' => $items !== [] ? $items : [
                [
                    'text' => $text,
                    'left' => $left,
                    'width' => max(1, $right - $left),
                ],
            ],
            'top' => $top,
            'left' => $left,
            'right' => $right,
            'bottom' => $bottom,
            'page_number' => 1,
        ], $overrides);
    }

    // --- Running header/footer suppression tests ---

    public function test_it_suppresses_pdf_copyright_footer_with_side_x_av_y(): void
    {
        $extractor = new DocumentTextExtractor;

        // A4 page (842pt). Footer sits at ~795pt — top ratio 0.944, inside the bottom edge zone.
        $blocks = [
            $this->fakePdfBlock('paragraph', 'Ukentlige prosjektmøter med gjennomgang av fremdrift.', 300, 842, 2),
            $this->fakePdfBlock('paragraph', '© Advania Norge AS 29.09.2025 Side 2 av 31', 795, 842, 2),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $texts = array_column($result, 'text');
        $this->assertContains('Ukentlige prosjektmøter med gjennomgang av fremdrift.', $texts);
        $this->assertNotContains('© Advania Norge AS 29.09.2025 Side 2 av 31', $texts);
    }

    public function test_it_suppresses_pdf_page_x_of_y_footer(): void
    {
        $extractor = new DocumentTextExtractor;

        $blocks = [
            $this->fakePdfBlock('paragraph', 'Egne tekniske koordineringsmøter ved behov.', 400, 842, 5),
            $this->fakePdfBlock('paragraph', 'Page 5 of 31', 800, 842, 5),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $texts = array_column($result, 'text');
        $this->assertContains('Egne tekniske koordineringsmøter ved behov.', $texts);
        $this->assertNotContains('Page 5 of 31', $texts);
    }

    public function test_it_keeps_body_text_containing_the_word_side(): void
    {
        $extractor = new DocumentTextExtractor;

        // "side" inside a sentence, positioned well within the body — must not be removed.
        $blocks = [
            $this->fakePdfBlock('paragraph', 'Se side 12 for mer informasjon om dette temaet.', 400, 842, 3),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $this->assertCount(1, $result);
        $this->assertSame('Se side 12 for mer informasjon om dette temaet.', $result[0]['text']);
    }

    public function test_it_keeps_real_text_near_bottom_without_page_marker(): void
    {
        $extractor = new DocumentTextExtractor;

        // Legitimate last sentence on a page, near the bottom but no page-number pattern and no digits.
        // top=795, page_height=842 → ratio 0.944 (inside edge zone), but text has no digits and is long.
        $blocks = [
            $this->fakePdfBlock('paragraph', 'Referater med tydelig ansvar og frister distribueres etter hvert møte.', 795, 842, 1),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $this->assertCount(1, $result);
        $this->assertSame('Referater med tydelig ansvar og frister distribueres etter hvert møte.', $result[0]['text']);
    }

    public function test_it_suppresses_repeated_header_text_across_pages_by_repetition_rule(): void
    {
        $extractor = new DocumentTextExtractor;

        // Company header repeated on three pages — no page number, but repetition rule should catch it.
        $blocks = [
            $this->fakePdfBlock('paragraph', 'Advania Norge AS – Internt dokument', 25, 842, 1),
            $this->fakePdfBlock('paragraph', 'Advania Norge AS – Internt dokument', 25, 842, 2),
            $this->fakePdfBlock('paragraph', 'Advania Norge AS – Internt dokument', 25, 842, 3),
            $this->fakePdfBlock('paragraph', 'Styringsgruppemøter på avtalte milepæler.', 400, 842, 2),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $texts = array_column($result, 'text');
        $this->assertContains('Styringsgruppemøter på avtalte milepæler.', $texts);
        $this->assertNotContains('Advania Norge AS – Internt dokument', $texts);
    }

    public function test_it_does_not_suppress_heading_blocks_near_page_edge(): void
    {
        $extractor = new DocumentTextExtractor;

        // Headings must never be filtered regardless of position or content.
        $blocks = [
            $this->fakePdfBlock('heading', '3 Konklusjon', 800, 842, 10),
            $this->fakePdfBlock('heading', '1.1 Innledning', 20, 842, 4),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $this->assertCount(2, $result);
    }

    public function test_it_leaves_blocks_without_page_height_untouched(): void
    {
        $extractor = new DocumentTextExtractor;

        // page_height=0 means the block did not come through the PDF pipeline.
        // These must never be filtered — ensures DOCX/non-PDF blocks are safe.
        $blocks = [
            $this->fakePdfBlock('paragraph', 'Side 2 av 31', 0, 0, 1),
            $this->fakePdfBlock('list', 'Page 1 of 10', 0, 0, 1),
        ];

        $result = $this->invokeDocumentTextExtractorMethod($extractor, 'suppressPdfRunningHeaderFooterBlocks', [$blocks]);

        $this->assertCount(2, $result);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakePdfBlock(string $type, string $text, int $top, int $pageHeight, int $pageNumber = 1, array $overrides = []): array
    {
        return array_merge([
            'type' => $type,
            'text' => $text,
            'top' => $top,
            'page_height' => $pageHeight,
            'page_number' => $pageNumber,
            'left' => 72,
            'width' => 400,
            'height' => 14,
            'style' => null,
            'level' => null,
        ], $overrides);
    }
}
