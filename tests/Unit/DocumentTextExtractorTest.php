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

    public function test_it_detects_extended_pdf_heading_patterns(): void
    {
        $extractor = new DocumentTextExtractor();

        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, '1 Innledning'));
        $this->assertSame(2, $this->invokePdfHeadingLevel($extractor, '1.1 Bakgrunn'));
        $this->assertSame(2, $this->invokePdfHeadingLevel($extractor, '1.1.1 Krav til leveranse'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'Kapittel 3'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'Del 1'));
        $this->assertSame(1, $this->invokePdfHeadingLevel($extractor, 'A. Vedlegg'));
    }

    public function test_it_does_not_classify_bullet_lists_as_poppler_table_runs(): void
    {
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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

    public function test_it_still_detects_real_poppler_table_runs_for_multicolumn_rows(): void
    {
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();
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
        $extractor = new DocumentTextExtractor();

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

    public function test_it_does_not_merge_tables_separated_by_a_content_heading(): void
    {
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
        $extractor = new DocumentTextExtractor();

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
     * @param array<int, array{0: string, 1: string}> $rows  Two-column row data.
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
     * @param array<int, mixed> $arguments
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
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $overrides
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
}
