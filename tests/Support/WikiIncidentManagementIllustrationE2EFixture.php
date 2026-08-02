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
 * Test-only fixture reproducing the exact real production document that failed in ingest run 475
 * ("Incident Management Illustration.docx") — an image pasted from a web page, wrapped in a Word
 * INCLUDEPICTURE field (not a bare <w:drawing>), with no formal alt-text (only Word's
 * auto-generated docPr name="Picture 1") and no formal Word caption paragraph anywhere. The only
 * signal that the image is informative is the preceding paragraph explicitly introducing it
 * ("Figuren under illustrerer ..."). Not autoloaded in production, invoked via
 * `php artisan tinker --execute=...` from tests/e2e/wiki-word-image-includepicture.spec.js,
 * mirroring WikiWordImageE2EFixture/WikiWordTableE2EFixture.
 */
class WikiIncidentManagementIllustrationE2EFixture
{
    private const PAGE_SLUG = 'e2e-incident-management-illustration-verifisering';

    private const FILE_PATH_PREFIX = 'e2e-incident-management-illustration/';

    public static function seed(int $customerId): string
    {
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

        $mediaImage = imagecreatetruecolor(554, 554);
        imagefilledrectangle($mediaImage, 0, 0, 553, 553, (int) imagecolorallocate($mediaImage, 50, 90, 140));
        ob_start();
        imagepng($mediaImage);
        $mediaBytes = (string) ob_get_clean();
        imagedestroy($mediaImage);

        $tmpPath = tempnam(sys_get_temp_dir(), 'e2e-incident-illustration-').'.docx';
        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $mediaBytes);
        $zip->close();
        $bytes = (string) file_get_contents($tmpPath);
        unlink($tmpPath);

        $filePath = self::FILE_PATH_PREFIX.Str::random(8).'.docx';
        Storage::disk('local')->put($filePath, $bytes);

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => 'Incident Management Illustration.docx',
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', $bytes),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'E2E-verifikasjonsdokument for run-475-regresjonen.',
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
            'title' => 'Incident Management Illustration (E2E)',
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
        $image = $images[0] ?? null;

        if ($image === null) {
            throw new \RuntimeException('Fixture regression: the INCLUDEPICTURE image was not extracted at all.');
        }

        $category = $classificationService->classify($image, 1);

        if ($category !== EnterpriseWikiImageClassificationService::CATEGORY_INFORMATIVE) {
            throw new \RuntimeException("Fixture classification drift: expected 'informative', got [{$category}].");
        }

        $imageBlocks = $imageBlockBuilder->buildImageBlocks($document, $images, [0], 1);

        $proseBlock = [
            'block_key' => 'block-0001',
            'position' => 0,
            'markdown' => "# Incident Management Illustration (E2E)\n\nFiguren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren i forbindelse med Incident prosessen.",
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'document_version_hash' => $document->file_hash_sha256,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
            'source_row_key' => null,
            'source_excerpt' => 'Figuren under illustrerer...',
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
            'generated_by_model' => 'e2e-incident-management-illustration-fixture',
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
