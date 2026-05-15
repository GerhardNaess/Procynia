<?php

namespace Tests\Unit;

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

            $extractor = new DocumentTextExtractor();

            $this->assertSame(
                "Overskrift\n\nFørste avsnitt med tekst.\n\nAndre avsnitt med mer tekst.",
                $extractor->extractText($path),
            );
        } finally {
            @unlink($path);
        }
    }

    private function writeValidTestPdf(string $path): void
    {
        // A minimal but structurally complete PDF that pdftotext can parse.
        // WinAnsiEncoding maps 0xF8 → ø, giving us "Første" with correct UTF-8 output.
        $header = "%PDF-1.4\n";
        $obj1   = "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $obj2   = "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $obj3   = "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
            . " /Resources <</Font <</F1 <</Type /Font /Subtype /Type1 /BaseFont /Helvetica"
            . " /Encoding /WinAnsiEncoding>>>>>>\n>>\nendobj\n";
        // \370 is octal 0xF8 = ø in WinAnsiEncoding
        $stream = "BT /F1 14 Tf 72 720 Td (Overskrift) Tj"
            . " 0 -100 Td (F\370rste avsnitt med tekst.) Tj"
            . " 0 -100 Td (Andre avsnitt med mer tekst.) Tj ET\n";
        $obj4 = "4 0 obj\n<</Length " . strlen($stream) . ">>\nstream\n{$stream}endstream\nendobj\n";

        $o1 = strlen($header);
        $o2 = $o1 + strlen($obj1);
        $o3 = $o2 + strlen($obj2);
        $o4 = $o3 + strlen($obj3);
        $xrefOffset = $o4 + strlen($obj4);

        $xref = "xref\n0 5\n"
            . "0000000000 65535 f \n"
            . str_pad((string) $o1, 10, '0', STR_PAD_LEFT) . " 00000 n \n"
            . str_pad((string) $o2, 10, '0', STR_PAD_LEFT) . " 00000 n \n"
            . str_pad((string) $o3, 10, '0', STR_PAD_LEFT) . " 00000 n \n"
            . str_pad((string) $o4, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $trailer = "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($path, $header . $obj1 . $obj2 . $obj3 . $obj4 . $xref . $trailer);
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
