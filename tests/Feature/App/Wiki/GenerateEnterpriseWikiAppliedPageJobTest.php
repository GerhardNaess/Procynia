<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
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
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class GenerateEnterpriseWikiAppliedPageJobTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MARKDOWN = "# Test Page\n\nGenerated content for testing.";

    protected function setUp(): void
    {
        parent::setUp();

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
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN, $sourceElements))
            ->byDefault();
    }

    public function test_queue_name_is_enterprise_wiki_pages(): void
    {
        $job = new GenerateEnterpriseWikiAppliedPage(1, 2);

        $this->assertSame('enterprise-wiki-pages', $job->queue);
    }

    public function test_job_generates_only_the_targeted_page(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createAppliedRunWithTwoPages($customer);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists()
        );
        $this->assertFalse(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $summary->id)->exists()
        );
    }

    public function test_job_dispatches_finalize_after_success(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_generation_status_transitions_to_completed_and_persists_version_id(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->first();

        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
        $this->assertNotNull($pivot->generation_started_at);
        $this->assertNotNull($pivot->generation_completed_at);
        $this->assertSame($version->id, $pivot->generated_page_version_id);
    }

    public function test_double_dispatch_for_same_run_page_creates_exactly_one_version(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        Queue::fake();
        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($service);
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($service);

        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );
    }

    public function test_stale_version_from_another_run_does_not_block_a_new_run_from_updating_the_page(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        // Simulate an older run that already produced a current version for this page.
        $olderVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Older content',
            'generated_by_model' => 'gpt-5',
        ]);

        $newRun = $this->createAppliedRun($customer, $document, [$article]);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($newRun->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());

        $this->assertFalse($olderVersion->fresh()->is_current);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('version_number', 2)
            ->first();
        $this->assertTrue($newVersion->is_current);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $newRun->id)
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();
        $this->assertSame($newVersion->id, $pivot->generated_page_version_id);
    }

    public function test_job_marks_pivot_failed_and_dispatches_finalize_when_ai_client_throws(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithTwoPages($customer);

        // $this->mock() rebinds the container on every call, so this must run AFTER
        // createAppliedRunWithTwoPages() (which sets its own default success mock) to
        // actually take effect — see the note in that helper.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andThrow(new RuntimeException('AI unavailable'));

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
                app(EnterpriseWikiGenerateAppliedPagesService::class)
            );
            $this->fail('Expected the job to rethrow.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI unavailable', $e->getMessage());
        }

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $pivot->generation_status);
        $this->assertSame('[RuntimeException] AI unavailable', $pivot->generation_error);
        $this->assertNull($pivot->generated_page_version_id);

        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_concept_page_receives_finished_article_and_summary_content_as_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel om Procynia');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag om Procynia');
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Nøkkelbegrep');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary, $concept]);

        // Simulate phase 1 (article/summary) having already completed successfully.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Artikkel om Procynia'."\n\nProcynia styrer hele tilbudsprosessen.",
            'generated_by_model' => 'gpt-5',
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $summary->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Sammendrag om Procynia'."\n\nKort sammendrag av kontrollert tilbudsarbeid.",
            'generated_by_model' => 'gpt-5',
        ]);

        $capturedContext = null;

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ) use (&$capturedContext, $article): array {
                $capturedContext = $additionalContext;

                // The run has other applied pages (article, summary), so 8I-4's
                // minimum-wikilink domain rule requires at least one valid link.
                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertStringContainsString('Procynia styrer hele tilbudsprosessen', $capturedContext);
        $this->assertStringContainsString('Kort sammendrag av kontrollert tilbudsarbeid', $capturedContext);
    }

    /**
     * Regression for run 475/480: EnterpriseWikiGenerateAppliedPagesService has two generation
     * paths — generate() (used by the wiki:generate-applied-pages command, and by every other
     * test in this suite) and generatePageForRun() (used by THIS job — the actual queued,
     * per-page production path on the enterprise-wiki-pages queue). generatePageForRun() called
     * appendTableBlocksIfRelevant() but never appendImageBlocksIfRelevant(), so a cited image
     * never became a figure block for any document processed through real ingest, even though
     * every other image-support test (which exercises generate(), not this job) passed. This test
     * drives the job's handle() method directly — the same entrypoint the real queue worker
     * calls — with a real .docx containing an embedded image, and confirms the resulting page
     * version now carries a genuine "image" content block.
     */
    public function test_job_creates_a_figure_block_for_a_cited_image_via_the_real_production_path(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array {
                $imageElement = collect($sourceElements)->firstWhere('source_element_type', 'image');
                $this->assertNotNull($imageElement, 'Expected the image to be exposed as a citable source element.');

                return $this->structuredPageResult(self::FAKE_MARKDOWN, [$imageElement]);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $imageBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')
            ->values();

        $this->assertCount(1, $imageBlocks);
        $this->assertSame('img0', $imageBlocks[0]['image_data']['source_image_key']);
    }

    private function createDocxDocumentWithOneImage(Customer $customer): EnterpriseWikiDocument
    {
        $documentXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p><w:r><w:t>Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.</w:t></w:r></w:p>
        <w:p xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <w:r>
                <w:drawing>
                    <wp:inline>
                        <wp:extent cx="1905000" cy="1905000"/>
                        <wp:docPr id="1" name="Picture 1"/>
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
    </w:body>
</w:document>
XML;

        $relationshipsXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML;

        $mediaImage = imagecreatetruecolor(300, 200);
        imagefilledrectangle($mediaImage, 0, 0, 299, 199, (int) imagecolorallocate($mediaImage, 40, 80, 140));
        ob_start();
        imagepng($mediaImage);
        $mediaBytes = (string) ob_get_clean();
        imagedestroy($mediaImage);

        $path = tempnam(sys_get_temp_dir(), 'wiki-page-job-image-').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $relationshipsXml);
        $zip->addFromString('word/media/image1.png', $mediaBytes);
        $zip->close();
        $docxBytes = (string) file_get_contents($path);
        @unlink($path);

        $filename = 'bilder-'.Str::lower(Str::random(6)).'.docx';
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, $filename),
            'file_hash_sha256' => hash('sha256', $docxBytes),
            'extracted_text' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        Storage::disk('local')->put($document->file_path, $docxBytes);

        return $document;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document.',
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
    private function createAppliedRunWithTwoPages(Customer $customer): array
    {
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary]);

        // The run has another applied page (summary), so 8I-4's minimum-wikilink domain
        // rule requires the generated article to contain at least one valid inline
        // wikilink. Point the mocked AI response at the summary's real slug so these
        // orchestration-focused tests satisfy that rule without asserting on it directly.
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
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$summary->slug}]] for details.", $sourceElements))
            ->byDefault();

        return [$run, $article, $summary];
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $pages): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return $run;
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
}
