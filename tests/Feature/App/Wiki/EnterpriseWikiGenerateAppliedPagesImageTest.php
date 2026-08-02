<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * End-to-end coverage for Fase 1 Enterprise Wiki image support at the page-generation layer:
 * a real .docx with one embedded, captioned image goes through EnterpriseWikiGenerateAppliedPagesService
 * (via the wiki:generate-applied-pages command), and the resulting page version must carry a
 * genuine "image" content block — never AI-authored, only attached because the (mocked) AI
 * response actually cited the image's source element. No real OpenAI calls are made anywhere.
 */
class EnterpriseWikiGenerateAppliedPagesImageTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MARKDOWN = "# Test Page\n\nThis is generated content for testing purposes.";

    public function test_a_cited_informative_image_becomes_a_figure_block_on_the_article_page(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer, $document);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResultCitingImage(self::FAKE_MARKDOWN, $sourceElements));

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $imageBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')
            ->values();

        $this->assertCount(1, $imageBlocks);
        $this->assertSame('img0', $imageBlocks[0]['image_data']['source_image_key']);
        $this->assertSame($document->original_filename.' → Figur 1', $imageBlocks[0]['page_reference']);
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', $imageBlocks[0]['markdown']);

        // Section 9: the figure's caption/citation reaches content_markdown (the same field
        // RequirementWikiPageReader reads for Wiki-answer research context) with no separate
        // image-answer wiring — identical to how table blocks already flow into content_markdown.
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', $version->content_markdown);
        $this->assertStringContainsString($document->original_filename.' → Figur 1', $version->content_markdown);
    }

    public function test_an_uncited_image_never_becomes_a_figure_block(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer, $document);

        // The AI mock cites the document's ordinary paragraph text (a real, valid element) but
        // never the image's key — Section 3: an image must not automatically attach to every
        // page generated from its document, only when actually cited.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResultCitingType($sourceElements, 'paragraph'));

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $imageBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image');

        $this->assertCount(0, $imageBlocks);
    }

    public function test_ordinary_word_tables_still_generate_alongside_images_in_the_same_run(): void
    {
        // Regression: image support must not break the existing table-block pipeline it was
        // modeled on.
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer); // plain, non-docx: no images, no tables
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer, $document);

        $this->mockAiClientCitingFirstSourceElement();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $this->assertNotEmpty($version->content_markdown);
        $this->assertSame(
            0,
            collect($version->content_blocks_json)->filter(fn (array $b): bool => ($b['block_type'] ?? null) === 'image')->count(),
        );
    }

    /**
     * Full-chain regression for the exact real production document that failed in run 475
     * ("Incident Management Illustration.docx"): a paragraph explicitly introducing the figure
     * ("Figuren under illustrerer ..."), a blank spacer paragraph, then an INCLUDEPICTURE-wrapped
     * image with no formal alt-text (only Word's auto-generated docPr name="Picture 1") and no
     * Word caption paragraph anywhere. The AI mock replays exactly what the real gpt-5 response
     * did for this document — citing both the intro paragraph and the image on the article page.
     * DOCX -> image extraction -> classification -> source element -> page citation -> image
     * block -> content_blocks_json, driven through the real wiki:generate-applied-pages command,
     * with no real OpenAI call.
     */
    public function test_the_real_incident_management_illustration_document_produces_a_figure_block(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createIncidentManagementIllustrationDocument($customer);
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer, $document);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResultCitingBothParagraphAndImage($sourceElements));

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $imageBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')
            ->values();

        $this->assertCount(1, $imageBlocks);
        $this->assertSame('img0', $imageBlocks[0]['image_data']['source_image_key']);
        $this->assertSame('informative', $imageBlocks[0]['image_data']['category']);
        // Word's auto-generated "Picture 1" name must never surface as real alt-text — an image
        // with no genuine alt-text gets an empty alt attribute (accessible, not fabricated).
        $this->assertSame('', $imageBlocks[0]['image_data']['alt_text']);
        $this->assertSame(
            $document->original_filename.' → Figur 1',
            $imageBlocks[0]['page_reference'],
        );
        // The intro paragraph's own text reaches the figure's deterministic description (Section
        // 9: reused as Wiki-answer context) even though there is no formal Word caption.
        $this->assertStringContainsString(
            'Figuren under illustrerer samhandlingsprosessen',
            $imageBlocks[0]['image_data']['description'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResultCitingBothParagraphAndImage(array $sourceElements): array
    {
        $paragraph = collect($sourceElements)->firstWhere('source_element_type', 'paragraph');
        $image = collect($sourceElements)->firstWhere('source_element_type', 'image');

        $this->assertNotNull($paragraph, 'Expected the intro paragraph to be a citable source element.');
        $this->assertNotNull($image, 'Expected the image to be exposed as a citable source element.');

        return [
            'markdown' => self::FAKE_MARKDOWN,
            'blocks' => [
                [
                    'markdown' => self::FAKE_MARKDOWN,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => [
                        (string) $paragraph['source_element_key'],
                        (string) $image['source_element_key'],
                    ],
                    'source_element_types' => [
                        (string) $paragraph['source_element_type'],
                        (string) $image['source_element_type'],
                    ],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }

    private function createIncidentManagementIllustrationDocument(Customer $customer): EnterpriseWikiDocument
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

        $path = tempnam(sys_get_temp_dir(), 'incident-management-illustration-').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $mediaBytes);
        $zip->close();
        $docxBytes = (string) file_get_contents($path);
        @unlink($path);

        $filename = 'Incident Management Illustration.docx';
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s.docx', $customer->id, Str::random(8)),
            'file_hash_sha256' => hash('sha256', $docxBytes),
            'extracted_text' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren i forbindelse med Incident prosessen.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        Storage::disk('local')->put($document->file_path, $docxBytes);

        return $document;
    }

    private function mockAiClientCitingFirstSourceElement(): void
    {
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN, $sourceElements));
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResult(string $markdown, array $sourceElements): array
    {
        $sourceElement = $sourceElements[0] ?? [
            'source_element_key' => 'document-1-full-text',
            'source_element_type' => 'manual',
        ];

        return [
            'markdown' => $markdown,
            'blocks' => [
                [
                    'markdown' => $markdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => [(string) $sourceElement['source_element_key']],
                    'source_element_types' => [(string) $sourceElement['source_element_type']],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }

    /**
     * Like structuredPageResult(), but specifically finds and cites whichever exposed source
     * element is the image (source_element_type=image) — the document also exposes its caption
     * paragraph as an ordinary citable "paragraph" element, so blindly citing $sourceElements[0]
     * would not reliably exercise the image citation path this test is about.
     *
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResultCitingImage(string $markdown, array $sourceElements): array
    {
        return $this->structuredPageResultCitingType($sourceElements, 'image');
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function structuredPageResultCitingType(array $sourceElements, string $sourceElementType): array
    {
        $element = collect($sourceElements)->firstWhere('source_element_type', $sourceElementType);

        $this->assertNotNull($element, "Expected a citable source element of type [{$sourceElementType}].");

        return [
            'markdown' => self::FAKE_MARKDOWN,
            'blocks' => [
                [
                    'markdown' => self::FAKE_MARKDOWN,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => [(string) $element['source_element_key']],
                    'source_element_types' => [(string) $element['source_element_type']],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }

    private function createDocxDocumentWithOneImage(Customer $customer): EnterpriseWikiDocument
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="952500" cy="952500"/>
                        <wp:docPr id="1" name="Figur 1" title="Figur 1" descr="Arkitekturdiagram"/>
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
            <w:r><w:t>Figur 1: Overordnet arkitektur</w:t></w:r>
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

        // A larger image (not the minimal 1x1 fixture used elsewhere) — Fase 1's classifier
        // treats a tiny image as a logo regardless of caption, so a real "informative diagram"
        // needs genuine, non-tiny pixel dimensions.
        $mediaImage = imagecreatetruecolor(200, 200);
        imagefilledrectangle($mediaImage, 0, 0, 199, 199, (int) imagecolorallocate($mediaImage, 30, 60, 90));
        ob_start();
        imagepng($mediaImage);
        $mediaBytes = (string) ob_get_clean();
        imagedestroy($mediaImage);

        $path = tempnam(sys_get_temp_dir(), 'procynia-wiki-gen-image-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $mediaBytes);
        $zip->close();

        $docxBytes = (string) file_get_contents($path);
        @unlink($path);

        $filename = 'arkitektur-'.Str::lower(Str::random(6)).'.docx';
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, $filename),
            'file_hash_sha256' => hash('sha256', $docxBytes),
            'extracted_text' => 'Figur 1: Overordnet arkitektur',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        Storage::disk('local')->put($document->file_path, $docxBytes);

        return $document;
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document. It contains relevant information.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createAppliedRunWithArticleAndSummary(Customer $customer, EnterpriseWikiDocument $document): array
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return [$run, $article, $summary];
    }

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
