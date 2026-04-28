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
            <w:tr>
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

            $zip->addFromString('word/document.xml', $documentXml);
            $zip->close();

            $parser = app(KnowledgeDocumentStructureParser::class);
            $result = $parser->parse($path);

            $this->assertSame('docx', $result['document_format']);
            $this->assertGreaterThan(0, $result['word_count']);
            $this->assertStringContainsString('Forord før første heading.', $result['source_text']);
            $this->assertStringContainsString('Tabell A | Tabell B', $result['source_text']);
            $this->assertStringContainsString('Rad 1 | Rad 2', $result['source_text']);

            $elements = $result['elements'];

            $this->assertCount(6, $elements);
            $this->assertSame('paragraph', $elements[0]['type']);
            $this->assertNull($elements[0]['heading_path']);
            $this->assertSame('heading', $elements[1]['type']);
            $this->assertSame(1, $elements[1]['heading_level']);
            $this->assertSame('Strategisk samhandling', $elements[1]['text']);
            $this->assertSame('Strategisk samhandling', $elements[2]['heading_path']);
            $this->assertSame('list', $elements[3]['type']);
            $this->assertSame("• Første punkt i listen\n• Andre punkt i listen", $elements[3]['text']);
            $this->assertSame('list_group', $elements[3]['relation_hint']);
            $this->assertSame('heading', $elements[4]['type']);
            $this->assertSame(2, $elements[4]['heading_level']);
            $this->assertSame('Strategisk samhandling > Underseksjon A', $elements[4]['heading_path']);
            $this->assertSame('table', $elements[5]['type']);
            $this->assertSame('Strategisk samhandling > Underseksjon A', $elements[5]['heading_path']);
            $this->assertStringContainsString('Tabell A | Tabell B', $elements[5]['text']);
            $this->assertStringContainsString('Rad 1 | Rad 2', $elements[5]['text']);
            $this->assertSame(0, $elements[0]['start_offset']);
            $this->assertGreaterThan($elements[0]['start_offset'], $elements[0]['end_offset']);
        } finally {
            @unlink($path);
        }
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
}
