<?php

namespace Tests\Unit;

use App\Services\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class DocumentTextExtractorTest extends TestCase
{
    public function test_it_preserves_docx_paragraph_blocks(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive();
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

            $extractor = new DocumentTextExtractor();

            $this->assertSame(
                "Dokumenttittel\n\nFørste avsnitt med tekst.\n\nAndre avsnitt med mer tekst.",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_it_extracts_structured_docx_blocks_with_heading_levels(): void
    {
        $path = $this->tempDocumentPath('docx');

        try {
            $zip = new ZipArchive();
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

            $extractor = new DocumentTextExtractor();
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
            $zip = new ZipArchive();
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

            $extractor = new DocumentTextExtractor();

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
        $path = $this->tempDocumentPath('pdf');

        try {
            $pdf = <<<'PDF'
%PDF-1.4
1 0 obj
<< /Length 161 >>
stream
BT
(Overskrift) Tj
ET
BT
(Første avsnitt med tekst.) Tj
ET
BT
(Andre avsnitt med mer tekst.) Tj
ET
endstream
endobj
trailer
%%EOF
PDF;

            file_put_contents($path, $pdf);

            $extractor = new DocumentTextExtractor();

            $this->assertSame(
                "Overskrift\n\nFørste avsnitt med tekst.\n\nAndre avsnitt med mer tekst.",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
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
}
