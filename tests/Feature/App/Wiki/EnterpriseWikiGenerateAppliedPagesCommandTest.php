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
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiGenerateAppliedPagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MARKDOWN = "# Test Page\n\nThis is generated content for testing purposes.";

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a mock so no real OpenAI calls are made in any test.
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

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:generate-applied-pages')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:generate-applied-pages', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guard: run not applied
    // =========================================================================

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createDecisionOnlyRunPending($customer);

        $this->artisan('wiki:generate-applied-pages', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Successful generation — article and summary (unchanged from 8E-12)
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithArticleAndSummary($customer);

        $this->artisan('wiki:generate-applied-pages', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_generates_version_for_article_page(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->exists()
        );
    }

    public function test_command_generates_version_for_summary_page(): void
    {
        $customer         = $this->createCustomer();
        [$run,, $summary] = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $summary->id)
                ->exists()
        );
    }

    public function test_command_fills_content_markdown_for_article_page(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(self::FAKE_MARKDOWN, $version->content_markdown);
    }

    public function test_command_marks_version_as_current(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $this->assertTrue((bool) $version->is_current);
    }

    public function test_command_stores_ai_declared_source_element_provenance_per_block(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);
        $capturedSourceElements = null;

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
            ) use (&$capturedSourceElements): array {
                $capturedSourceElements = $sourceElements;

                return $this->structuredPageResult(self::FAKE_MARKDOWN, $sourceElements);
            });

        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', '<>', $article->id)
            ->delete();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->firstOrFail();

        $this->assertNotEmpty($capturedSourceElements);
        $this->assertSame($capturedSourceElements[0]['source_element_key'], $version->content_blocks_json[0]['source_element_key']);
        $this->assertSame($capturedSourceElements[0]['source_element_key'], $version->content_blocks_json[0]['source_elements'][0]['source_element_key']);
    }

    // =========================================================================
    // Successful generation — concept and entity (8E-13)
    // =========================================================================

    public function test_command_generates_version_for_concept_page(): void
    {
        $customer         = $this->createCustomer();
        [,,,  $concept]   = $this->createAppliedRunWithAllPageTypes($customer);

        $run = EnterpriseWikiIngestRun::query()
            ->whereHas('pages', fn ($q) => $q->where('enterprise_wiki_page_id', $concept->id))
            ->first();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $concept->id)
                ->exists()
        );
    }

    public function test_command_generates_version_for_entity_page(): void
    {
        $customer              = $this->createCustomer();
        [$run,,, , $entity]    = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $entity->id)
                ->exists()
        );
    }

    public function test_command_fills_content_markdown_for_concept_page(): void
    {
        $customer              = $this->createCustomer();
        [$run,,,  $concept]    = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $concept->id)
            ->first();

        $this->assertNotNull($version);
        $this->assertNotEmpty($version->content_markdown);
        $this->assertSame(self::FAKE_MARKDOWN, $version->content_markdown);
    }

    public function test_command_fills_content_markdown_for_entity_page(): void
    {
        $customer              = $this->createCustomer();
        [$run,,,, $entity]     = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $entity->id)
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(self::FAKE_MARKDOWN, $version->content_markdown);
    }

    public function test_command_generates_all_four_page_types(): void
    {
        $customer                             = $this->createCustomer();
        [$run, $article, $summary, $concept, $entity] = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        foreach ([$article, $summary, $concept, $entity] as $page) {
            $this->assertTrue(
                EnterpriseWikiPageVersion::query()
                    ->where('enterprise_wiki_page_id', $page->id)
                    ->exists(),
                "Expected version for page [{$page->id}] type [{$page->page_type}]"
            );
        }
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_article_generated_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Article:  1 generated', Artisan::output());
    }

    public function test_command_outputs_summary_generated_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithArticleAndSummary($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Summary:  1 generated', Artisan::output());
    }

    public function test_command_outputs_concept_generated_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Concept:  1 generated', Artisan::output());
    }

    public function test_command_outputs_entity_generated_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Entity:   1 generated', Artisan::output());
    }

    public function test_command_reports_skipped_count(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '# Existing',
            'generated_by_model'      => 'gpt-5',
        ]);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Skipped:  1', Artisan::output());
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    public function test_command_skips_page_that_already_has_a_version(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithArticleAndSummary($customer);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '# Existing',
            'generated_by_model'      => 'gpt-5',
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame(
            $versionsBefore,
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->count()
        );
    }

    public function test_command_skips_concept_page_that_already_has_a_version(): void
    {
        $customer              = $this->createCustomer();
        [$run,,,  $concept]    = $this->createAppliedRunWithAllPageTypes($customer);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $concept->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '# Existing Concept',
            'generated_by_model'      => 'gpt-5',
        ]);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $concept->id)
                ->count()
        );
    }

    // =========================================================================
    // No side effects: claims, ingest runs
    // =========================================================================

    public function test_command_does_not_create_claims(): void
    {
        $customer     = $this->createCustomer();
        [$run]        = $this->createAppliedRunWithAllPageTypes($customer);
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_does_not_create_additional_ingest_runs(): void
    {
        $customer   = $this->createCustomer();
        [$run]      = $this->createAppliedRunWithAllPageTypes($customer);
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithAllPageTypes($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'extracted_text'    => 'This is the extracted text from the source document. It contains relevant information.',
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createDecisionOnlyRunPending(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createDecisionOnlyRunApplied(Customer $customer, array $decisionJson = []): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json'         => ! empty($decisionJson) ? $decisionJson : null,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * Applied run with one article page and one summary page in the pivot.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createAppliedRunWithArticleAndSummary(Customer $customer): array
    {
        $run     = $this->createDecisionOnlyRunApplied($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id'       => $page->id,
                'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return [$run, $article, $summary];
    }

    /**
     * Applied run with all four page types in the pivot.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage, 3: EnterpriseWikiPage, 4: EnterpriseWikiPage}
     */
    private function createAppliedRunWithAllPageTypes(Customer $customer): array
    {
        $decision = [
            'source_article' => ['action' => 'create', 'title' => 'Test Artikkel', 'proposed_slug' => 'test-artikkel', 'reason' => 'New article.'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag', 'reason' => 'Companion summary.'],
            'concept_pages'  => [['action' => 'create', 'title' => 'Test Konsept', 'proposed_slug' => 'test-konsept', 'reason' => 'Key concept.']],
            'entity_pages'   => [['action' => 'create', 'title' => 'Test Entitet', 'proposed_slug' => 'test-entitet', 'reason' => 'Key entity.']],
            'no_action_reason' => null,
            'warnings'         => [],
        ];

        $run     = $this->createDecisionOnlyRunApplied($customer, $decision);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Test Konsept');
        $entity  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Test Entitet');

        foreach ([$article, $summary, $concept, $entity] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id'       => $page->id,
                'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return [$run, $article, $summary, $concept, $entity];
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
