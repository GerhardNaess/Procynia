<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiFigureMaterializationException;
use App\Exceptions\EnterpriseWikiPageGenerationIncompleteException;
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

    public function test_concept_page_receives_its_own_maintainer_assigned_responsibility_as_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'ITIL');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => 'ITIL',
                'proposed_slug' => $concept->slug,
                'reason' => 'Overordnet rammeverk.',
                'owned_topics' => ['Definer ITIL som rammeverk for tjenestestyring.'],
                'reference_only_topics' => ['Bruk av prosessillustrasjonen.'],
                'excluded_topics' => ['Detaljert Incident Management-arbeidsflyt.', 'Detaljerte KPI-kataloger.'],
                'related_page_guidance' => [
                    ['page_title' => 'Incident Management', 'relationship' => 'Lenk hit for detaljert hendelseshåndtering.'],
                ],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

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

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertStringContainsString('Definer ITIL som rammeverk for tjenestestyring.', $capturedContext);
        $this->assertStringContainsString('Bruk av prosessillustrasjonen.', $capturedContext);
        $this->assertStringContainsString('Detaljert Incident Management-arbeidsflyt.', $capturedContext);
        $this->assertStringContainsString('Detaljerte KPI-kataloger.', $capturedContext);
        $this->assertStringContainsString('Incident Management: Lenk hit for detaljert hendelseshåndtering.', $capturedContext);
    }

    public function test_article_page_receives_its_own_maintainer_assigned_responsibility_as_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag av Incident Management Illustration');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => [
                'action' => 'create',
                'title' => $article->title,
                'proposed_slug' => $article->slug,
                'reason' => 'r',
                'owned_topics' => ['Beskriv hva illustrasjonen viser og bruksområder for den.'],
                'reference_only_topics' => ['ITIL som overordnet rammeverk.'],
                'excluded_topics' => ['Full generell definisjon av ITIL.'],
                'related_page_guidance' => [
                    ['page_title' => 'ITIL', 'relationship' => 'Lenk hit for rammeverksforklaring.'],
                ],
            ],
            'source_summary' => ['action' => 'create', 'title' => $summary->title, 'proposed_slug' => $summary->slug, 'reason' => 'r'],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

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
            ) use (&$capturedContext, $summary): array {
                $capturedContext = $additionalContext;

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$summary->slug}]] for details.", $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertStringContainsString('Beskriv hva illustrasjonen viser og bruksområder for den.', $capturedContext);
        $this->assertStringContainsString('ITIL som overordnet rammeverk.', $capturedContext);
        $this->assertStringContainsString('Full generell definisjon av ITIL.', $capturedContext);
        $this->assertStringContainsString('ITIL: Lenk hit for rammeverksforklaring.', $capturedContext);
    }

    public function test_summary_page_receives_the_finished_article_content_as_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag av Incident Management Illustration');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary]);

        // Simulate the article having already been generated (phase 1's article-first ordering).
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Incident Management Illustration'."\n\nDenne siden beskriver figuren i detalj.",
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

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertStringContainsString('Finished article to summarize', $capturedContext);
        $this->assertStringContainsString('Denne siden beskriver figuren i detalj.', $capturedContext);
    }

    public function test_summary_page_falls_back_gracefully_when_article_is_not_yet_generated(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustration');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag av Incident Management Illustration');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary]);

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

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertStringNotContainsString('Finished article to summarize', (string) $capturedContext);
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

    /**
     * Regression for run 574's finding #5560: a sentence the model returns as its own leading
     * paragraph and then repeats verbatim as the opening sentence of a later block must be
     * removed at generation time, on the real per-page production path this job drives.
     */
    public function test_job_removes_a_verbatim_duplicate_sentence_across_generated_blocks(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Incident Management Illustrasjon');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Incident Management Illustrasjon');

        $run = $this->createAppliedRun($customer, $document, [$article, $summary]);

        $repeatedSentence = 'Som oversiktsbilde over en Incident-prosess viser illustrasjoner et løp fra innmelding til avslutning.';
        $usedSourceElementKey = null;

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
            ) use ($repeatedSentence, $summary, &$usedSourceElementKey): array {
                $sourceElement = $sourceElements[0] ?? [
                    'source_element_key' => 'document-1-full-text',
                    'source_element_type' => 'manual',
                ];
                $usedSourceElementKey = (string) $sourceElement['source_element_key'];

                $blocks = [
                    [
                        'markdown' => "# Incident Management Illustrasjon\n\n{$repeatedSentence}",
                        'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                        'source_element_keys' => [(string) $sourceElement['source_element_key']],
                        'source_element_types' => [(string) $sourceElement['source_element_type']],
                        'best_practice_reason' => null,
                        'link_intents' => [],
                    ],
                    [
                        'markdown' => "{$repeatedSentence} Se også [[{$summary->slug}]] for detaljer.",
                        'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                        'source_element_keys' => [],
                        'source_element_types' => [],
                        'best_practice_reason' => 'Generell fagkunnskap om hendelseshåndtering.',
                        'link_intents' => [],
                    ],
                ];

                return [
                    'markdown' => trim(implode("\n\n", array_column($blocks, 'markdown'))),
                    'blocks' => $blocks,
                ];
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->firstOrFail();

        // The repeated sentence survives exactly once in the persisted content_markdown — the
        // duplicate is not merely hidden by the frontend, it is not stored a second time at all.
        $this->assertSame(1, substr_count($version->content_markdown, $repeatedSentence));
        $this->assertStringContainsString('# Incident Management Illustrasjon', $version->content_markdown);

        $blocks = collect($version->content_blocks_json);

        $firstBlock = $blocks->first(fn (array $b): bool => str_contains($b['markdown'] ?? '', $repeatedSentence));
        $this->assertNotNull($firstBlock, 'Expected the first, kept occurrence of the sentence.');
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $firstBlock['content_origin']);
        $this->assertSame($usedSourceElementKey, $firstBlock['source_element_key']);

        $secondBlock = $blocks->first(fn (array $b): bool => str_contains($b['markdown'] ?? '', 'Se også'));
        $this->assertNotNull($secondBlock, 'Expected the second block to survive with its unique tail.');
        $this->assertStringNotContainsString($repeatedSentence, $secondBlock['markdown']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $secondBlock['content_origin']);
        $this->assertSame('Generell fagkunnskap om hendelseshåndtering.', $secondBlock['best_practice_reason']);
    }

    // =========================================================================
    // Wiki run-586: planned section coverage — repair and hard block
    // =========================================================================

    /**
     * The exact run-586 shape: a concept page with two owned_topics, both headings present in
     * the AI's first response but with zero body text under either. Confirms today's contract:
     * without this task's fix, this response would have been accepted and persisted as a
     * successfully completed page (there was no check anywhere for heading/body pairing) — see
     * the "no repair available" test below for the same shape with AI unavailable, which
     * reproduces exactly that historical acceptance.
     */
    public function test_repair_fills_empty_planned_sections_and_the_page_is_accepted(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlings- og styringsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

        $emptySectionsMarkdown = "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n## Roller i styringsmodellen\n\n## Møtefora og beslutningsflyt\n\nSee [[{$article->slug}]] for details.";

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult($emptySectionsMarkdown, $sourceElements))
            ->shouldReceive('repairPlannedSections')
            ->once()
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $existingMarkdown,
                array $issues,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ) use ($article): array {
                $this->assertCount(2, $issues);
                $this->assertStringContainsString('Roller i styringsmodellen', $existingMarkdown);

                $repairedMarkdown = "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n"
                    ."## Roller i styringsmodellen\n\nDelivery Executive har overordnet ansvar for leveransen.\n\n"
                    ."## Møtefora og beslutningsflyt\n\nStrategisk forum møtes årlig og behandler prioriteringer. See [[{$article->slug}]] for details.";

                return $this->structuredPageResult($repairedMarkdown, $sourceElements);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->firstOrFail();
        $this->assertStringContainsString('Delivery Executive har overordnet ansvar', $version->content_markdown);
        $this->assertStringContainsString('Strategisk forum møtes årlig', $version->content_markdown);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
        $this->assertSame($version->id, $pivot->generated_page_version_id);
    }

    public function test_repair_that_still_leaves_empty_sections_stops_generation_with_a_domain_exception(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlings- og styringsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt'],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

        $emptySectionsMarkdown = "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n## Roller i styringsmodellen\n\n## Møtefora og beslutningsflyt\n\nSee [[{$article->slug}]] for details.";

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult($emptySectionsMarkdown, $sourceElements))
            // The repair attempt returns exactly the same broken shape — still empty.
            ->shouldReceive('repairPlannedSections')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $existingMarkdown,
                array $issues,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult($emptySectionsMarkdown, $sourceElements));

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
                app(EnterpriseWikiGenerateAppliedPagesService::class)
            );
            $this->fail('Expected EnterpriseWikiPageGenerationIncompleteException to propagate.');
        } catch (EnterpriseWikiPageGenerationIncompleteException $e) {
            $this->assertSame($run->id, $e->runId);
            $this->assertSame($concept->id, $e->pageId);
            $this->assertTrue($e->repairAttempted);
            $this->assertContains('Roller i styringsmodellen', $e->missingOrEmptySections);
            $this->assertContains('Møtefora og beslutningsflyt', $e->missingOrEmptySections);
        }

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();

        // The job's existing exception handling (markPivotFailed()) does all of this — no new
        // wiring was needed for the pivot/run to correctly reflect the failure.
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $pivot->generation_status);
        $this->assertStringContainsString('EnterpriseWikiPageGenerationIncompleteException', $pivot->generation_error);
        $this->assertNull($pivot->generated_page_version_id);
        $this->assertFalse(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->exists(),
            'An incomplete page must never be persisted as a version.',
        );

        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_page_with_no_owned_topics_is_never_checked_or_repaired(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createAppliedRunWithTwoPages($customer);

        // No maintainer_decision_json owned_topics set for this page — repairPlannedSections
        // must never be called regardless of what the AI returns.
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
            ->shouldNotReceive('repairPlannedSections');

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());
    }

    public function test_planned_sections_with_real_substance_never_trigger_a_repair_call(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'ITIL');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => ['Rammeverk for tjenestestyring'],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

        $goodMarkdown = "# ITIL\n\nInnledende avsnitt.\n\n## Rammeverk for tjenestestyring\n\nITIL er et rammeverk med definerte prosesser. See [[{$article->slug}]] for details.";

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult($goodMarkdown, $sourceElements))
            ->shouldNotReceive('repairPlannedSections');

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->exists());
    }

    // =========================================================================
    // Wiki run-587: planned figure coverage — gating, placement, repair, and hard block
    // =========================================================================

    /**
     * The run-587 fix itself: a figure explicitly planned onto a CONCEPT page (previously
     * structurally impossible — appendImageBlocksIfRelevant() was hardcoded to article/summary
     * only) is materialized as a real image block once the AI cites it.
     */
    public function test_figure_planned_onto_a_concept_page_is_materialized_as_an_image_block(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlingsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => [],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
                'planned_figures' => [$this->plannedFigure('img0', required: true)],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

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
            ) use ($article): array {
                $imageElement = collect($sourceElements)->firstWhere('source_element_type', 'image');

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", [$imageElement]);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->firstOrFail();

        $imageBlocks = collect($version->content_blocks_json)
            ->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')
            ->values();

        $this->assertCount(1, $imageBlocks);
        $this->assertSame('img0', $imageBlocks[0]['image_data']['source_image_key']);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    /**
     * The other half of the run-587 fix: a concept/entity page only gets an image the maintainer
     * decision explicitly planned onto it — an image the AI cites but that was never planned onto
     * this page is silently dropped, not materialized.
     */
    public function test_figure_cited_by_ai_but_not_planned_onto_a_concept_page_is_not_materialized(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlingsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => [],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
                // No planned_figures at all for this page — the AI citing the image below must
                // not be enough to materialize it here.
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]]);

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
            ) use ($article): array {
                $imageElement = collect($sourceElements)->firstWhere('source_element_type', 'image');

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", [$imageElement]);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->firstOrFail();

        $this->assertSame(
            0,
            collect($version->content_blocks_json)->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')->count(),
        );
    }

    /**
     * Backward compatibility: article/summary pages keep the pre-existing, unrestricted "any cited
     * image is materialized" behavior regardless of planned_figures — this is not a new gate.
     */
    public function test_article_page_still_materializes_any_cited_image_without_being_planned(): void
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

                return $this->structuredPageResult(self::FAKE_MARKDOWN, [$imageElement]);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->firstOrFail();

        $this->assertSame(
            1,
            collect($version->content_blocks_json)->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')->count(),
        );
    }

    /**
     * A required planned figure the AI's first response fails to cite triggers exactly one bounded
     * repair (repairPlannedFigures()) — when the repair cites it, generation succeeds normally.
     */
    public function test_required_figure_missing_from_first_response_is_recovered_by_one_bounded_repair(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlingsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => $this->decisionWithPlannedFigure($article, $concept, required: true)]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $this->nonImageSourceElements($sourceElements)))
            ->shouldReceive('repairPlannedFigures')
            ->once()
            ->andReturnUsing(function (
                string $pageTitle,
                string $pageType,
                string $existingMarkdown,
                array $issues,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ) use ($article): array {
                $this->assertNotEmpty($issues);
                $this->assertSame('img0', $issues[0]['source_element_key']);

                $imageElement = collect($sourceElements)->firstWhere('source_element_type', 'image');

                return $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", [$imageElement]);
            });

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->firstOrFail();

        $this->assertSame(
            1,
            collect($version->content_blocks_json)->filter(fn (array $block): bool => ($block['block_type'] ?? null) === 'image')->count(),
        );

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    /**
     * When even the bounded repair still fails to materialize a REQUIRED figure, generation stops
     * with EnterpriseWikiFigureMaterializationException and the pivot is marked failed — no
     * unlimited retry, no page silently accepted without its required figure.
     */
    public function test_required_figure_still_missing_after_repair_throws_and_marks_pivot_failed(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlingsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => $this->decisionWithPlannedFigure($article, $concept, required: true)]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $this->nonImageSourceElements($sourceElements)))
            // The repair attempt still never cites the image.
            ->shouldReceive('repairPlannedFigures')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $existingMarkdown,
                array $issues,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $this->nonImageSourceElements($sourceElements)));

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
                app(EnterpriseWikiGenerateAppliedPagesService::class)
            );
            $this->fail('Expected EnterpriseWikiFigureMaterializationException to propagate.');
        } catch (EnterpriseWikiFigureMaterializationException $e) {
            $this->assertSame($run->id, $e->runId);
            $this->assertSame($concept->id, $e->pageId);
            $this->assertTrue($e->repairAttempted);
            $this->assertContains('img0', $e->failedSourceElementKeys);
        }

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();

        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $pivot->generation_status);
        $this->assertStringContainsString('EnterpriseWikiFigureMaterializationException', $pivot->generation_error);
        $this->assertNull($pivot->generated_page_version_id);
        $this->assertFalse(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->exists(),
            'A page with a still-missing required figure must never be persisted as a version.',
        );

        Queue::assertPushed(FinalizeEnterpriseWikiPageGeneration::class, fn ($job) => $job->runId === $run->id);
    }

    /**
     * An OPTIONAL planned figure the AI never cites is not blocking — generation completes
     * normally, with no repair call at all.
     */
    public function test_missing_optional_figure_does_not_block_generation_or_trigger_repair(): void
    {
        Storage::fake('local');

        $customer = $this->createCustomer();
        $document = $this->createDocxDocumentWithOneImage($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Samhandlingsmodell');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Masterdata Samhandling');

        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => $this->decisionWithPlannedFigure($article, $concept, required: false)]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->once()
            ->andReturnUsing(fn (
                string $pageTitle,
                string $pageType,
                string $sourceText,
                string $languageCode,
                string $additionalContext = '',
                array $linkCatalog = [],
                array $sourceElements = [],
            ): array => $this->structuredPageResult(self::FAKE_MARKDOWN." See [[{$article->slug}]] for details.", $this->nonImageSourceElements($sourceElements)))
            ->shouldNotReceive('repairPlannedFigures');

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle(
            app(EnterpriseWikiGenerateAppliedPagesService::class)
        );

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    private function plannedFigure(string $sourceElementKey, bool $required): array
    {
        return [
            'source_element_key' => $sourceElementKey,
            'classification' => 'diagram',
            'section_placement' => null,
            'purpose' => 'Illustrates the collaboration model between customer and supplier.',
            'required' => $required,
            'caption_hint' => null,
        ];
    }

    private function decisionWithPlannedFigure(EnterpriseWikiPage $article, EnterpriseWikiPage $concept, bool $required): array
    {
        return [
            'source_article' => ['action' => 'create', 'title' => $article->title, 'proposed_slug' => $article->slug, 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => [[
                'action' => 'create',
                'page_id' => null,
                'title' => $concept->title,
                'proposed_slug' => $concept->slug,
                'reason' => 'r',
                'owned_topics' => [],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
                'planned_figures' => [$this->plannedFigure('img0', required: $required)],
            ]],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
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
     * @return list<array<string, mixed>>
     */
    private function nonImageSourceElements(array $sourceElements): array
    {
        return array_values(array_filter(
            $sourceElements,
            fn (array $element): bool => ($element['source_element_type'] ?? null) !== 'image',
        ));
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
