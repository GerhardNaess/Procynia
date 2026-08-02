<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiImageBlockBuilder;
use App\Services\EnterpriseWiki\EnterpriseWikiImageClassificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Test-only fixture for the Word image rendering E2E spec (tests/e2e/wiki-word-image.spec.js).
 * Not autoloaded in production (autoload-dev only), not a registered Artisan command — invoked
 * via `php artisan tinker --execute=...` from the Playwright spec, mirroring
 * WikiWordTableE2EFixture exactly.
 *
 * Seeds a real .docx with two images (one informative PNG diagram with caption/alt-text/Norwegian
 * characters, one tiny JPEG logo) across two sections, and builds the page content deterministically
 * via the real EnterpriseWikiImageBlockBuilder (the same class production ingest uses) — the tiny
 * logo is deliberately NOT cited, so it must never appear as a figure block, only the informative
 * diagram should.
 */
class WikiWordImageE2EFixture
{
    private const PAGE_SLUG = 'e2e-word-image-verifisering';

    private const FILE_PATH_PREFIX = 'e2e-word-image/';

    public static function seed(int $customerId): string
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
            <w:r><w:t>1 Integrasjoner</w:t></w:r>
        </w:p>
        <w:p><w:r><w:t>Teksten før figuren beskriver dataflyten mellom CRM og ERP med æøå-tegn.</w:t></w:r></w:p>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="1905000" cy="1905000"/>
                        <wp:docPr id="1" name="Figur 1" title="Figur 1" descr="Arkitekturdiagram som viser integrasjonene mellom CRM og ERP"/>
                        <a:graphic>
                            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:pic>
                                    <pic:blipFill>
                                        <a:blip r:embed="rId1"/>
                                    </pic:blipFill>
                                </pic:pic>
                            </a:graphicData>
                        </a:graphic>
                    </wp:inline>
                </w:drawing>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Caption"/></w:pPr>
            <w:r><w:t>Figur 1: Oversikt over systemintegrasjonene – æøå</w:t></w:r>
        </w:p>
        <w:p><w:r><w:t>Teksten etter figuren følger opp med detaljer om datavask.</w:t></w:r></w:p>
        <w:p>
            <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
            <w:r><w:t>2 Merkevare</w:t></w:r>
        </w:p>
        <w:p><w:r><w:t>Denne seksjonen har kun en dekorativ logo, uten bildetekst.</w:t></w:r></w:p>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="285750" cy="285750"/>
                        <wp:docPr id="2" name="Logo" title="Logo" descr=""/>
                        <a:graphic>
                            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:pic>
                                    <pic:blipFill>
                                        <a:blip r:embed="rId2"/>
                                    </pic:blipFill>
                                </pic:pic>
                            </a:graphicData>
                        </a:graphic>
                    </wp:inline>
                </w:drawing>
            </w:r>
        </w:p>
    </w:body>
</w:document>
XML;

        $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.jpeg"/>
</Relationships>
XML;

        $diagramImage = imagecreatetruecolor(300, 200);
        imagefilledrectangle($diagramImage, 0, 0, 299, 199, (int) imagecolorallocate($diagramImage, 40, 80, 140));
        ob_start();
        imagepng($diagramImage);
        $diagramBytes = (string) ob_get_clean();
        imagedestroy($diagramImage);

        $logoImage = imagecreatetruecolor(30, 30);
        imagefilledrectangle($logoImage, 0, 0, 29, 29, (int) imagecolorallocate($logoImage, 200, 30, 30));
        ob_start();
        imagejpeg($logoImage);
        $logoBytes = (string) ob_get_clean();
        imagedestroy($logoImage);

        $tmpPath = tempnam(sys_get_temp_dir(), 'e2e-word-image-').'.docx';
        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $diagramBytes);
        $zip->addFromString('word/media/image2.jpeg', $logoBytes);
        $zip->close();
        $bytes = (string) file_get_contents($tmpPath);
        unlink($tmpPath);

        $filePath = self::FILE_PATH_PREFIX.Str::random(8).'.docx';
        Storage::disk('local')->put($filePath, $bytes);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => 'Driftsarkitektur (E2E).docx',
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', $bytes),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'E2E-verifikasjonsdokument for Word-bildestøtte.',
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_APPLYING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customerId,
            'slug' => self::PAGE_SLUG,
            'title' => 'Driftsarkitektur (E2E)',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $sourceElementService = app(EnterpriseWikiDocumentSourceElementService::class);
        $imageBlockBuilder = app(EnterpriseWikiImageBlockBuilder::class);
        $classificationService = app(EnterpriseWikiImageClassificationService::class);

        $images = $sourceElementService->imagesForDocument($document);

        // Sanity check the fixture itself classifies as intended (informative diagram citable,
        // tiny logo not) before seeding — a silent classification drift here would otherwise
        // produce a passing E2E spec for the wrong reason.
        $occurrenceCounts = [];
        foreach ($images as $image) {
            $occurrenceCounts[$image->contentHash] = ($occurrenceCounts[$image->contentHash] ?? 0) + 1;
        }
        foreach ($images as $image) {
            $category = $classificationService->classify($image, $occurrenceCounts[$image->contentHash] ?? 1);
            $expected = $image->imageIndex === 0
                ? EnterpriseWikiImageClassificationService::CATEGORY_DIAGRAM
                : EnterpriseWikiImageClassificationService::CATEGORY_LOGO;

            if ($category !== $expected) {
                throw new \RuntimeException("Fixture classification drift: image {$image->imageIndex} classified as [{$category}], expected [{$expected}].");
            }
        }

        // Only the informative diagram (imageIndex 0) is cited — the logo is deliberately never
        // referenced, exercising the same relevance-gating a real AI-generated page would apply.
        $imageBlocks = $imageBlockBuilder->buildImageBlocks($document, $images, [0], 1);

        $proseBlock = [
            'block_key' => 'block-0001',
            'position' => 0,
            'markdown' => "# Driftsarkitektur (E2E)\n\nTeksten før figuren beskriver dataflyten mellom CRM og ERP med æøå-tegn.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => 'Teksten før figuren...',
            'page_reference' => 'Avsnitt 1',
            'source_elements' => [],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];

        $contentBlocks = [$proseBlock, ...$imageBlocks];
        $markdown = trim($proseBlock['markdown']."\n\n".implode("\n\n", array_column($imageBlocks, 'markdown')));

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $contentBlocks,
            'generated_by_model' => 'e2e-word-image-fixture',
        ]);

        return self::PAGE_SLUG;
    }

    public static function cleanup(int $customerId): void
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', self::PAGE_SLUG)
            ->get();

        foreach ($pages as $page) {
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->delete();
            $page->delete();
        }

        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_path', 'like', self::FILE_PATH_PREFIX.'%')
            ->get();

        foreach ($documents as $document) {
            EnterpriseWikiIngestRun::query()
                ->where('customer_id', $customerId)
                ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->delete();
            Storage::disk('local')->delete($document->file_path);
            $document->delete();
        }
    }
}
