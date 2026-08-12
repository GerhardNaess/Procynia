<?php

namespace Tests\Feature\App\Wiki;

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
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 8I-4: deterministic validation of generated inline wikilinks before a page
 * version is persisted.
 */
class EnterpriseWikiGeneratePageWikilinkValidationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Valid wikilinks are accepted, for every page type
    // =========================================================================

    public function test_article_output_with_a_valid_wikilink_is_accepted(): void
    {
        $this->assertGenerationAccepted(EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
    }

    public function test_summary_output_with_a_valid_wikilink_is_accepted(): void
    {
        $this->assertGenerationAccepted(EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
    }

    public function test_concept_output_with_a_valid_wikilink_is_accepted(): void
    {
        $this->assertGenerationAccepted(EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
    }

    public function test_entity_output_with_a_valid_wikilink_is_accepted(): void
    {
        $this->assertGenerationAccepted(EnterpriseWikiPage::PAGE_TYPE_ENTITY);
    }

    private function assertGenerationAccepted(string $pageType): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'target-page', 'Target Page', $pageType);
        $other = $this->createPage($customer, 'other-page', 'Other Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $other]);

        $this->mockAiResponse("# {$generated->title}\n\nSee [[other-page]] for details.");

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $generated->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    // =========================================================================
    // Invalid wikilinks are rejected before persistence
    // =========================================================================

    public function test_unknown_slug_is_rejected_before_persistence(): void
    {
        $this->assertGenerationRejected(
            fn (EnterpriseWikiPage $generated, EnterpriseWikiPage $other) => "# {$generated->title}\n\nSee [[does-not-exist]].",
        );
    }

    public function test_self_link_is_rejected_before_persistence(): void
    {
        $this->assertGenerationRejected(
            fn (EnterpriseWikiPage $generated, EnterpriseWikiPage $other) => "# {$generated->title}\n\nSee [[{$generated->slug}]].",
        );
    }

    public function test_cross_customer_target_is_rejected_before_persistence(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Other Customer');
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'target-page', 'Target Page');
        $otherCustomerPage = $this->createPage($otherCustomer, 'foreign-page', 'Foreign Page');
        $run = $this->createAppliedRun($customer, $document, [$generated]);

        $this->mockAiResponse("# {$generated->title}\n\nSee [[{$otherCustomerPage->slug}]].");

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());
    }

    public function test_structured_link_intent_materializes_a_same_run_target_without_a_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'operational-improvement-a1b2c3', 'Operational Improvement');
        $concept = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());
        $this->mockAiResponse("# {$concept->title}\n\n{{wiki_link:improvement-link|Operational Improvement}} is relevant.", [[
            'intent_id' => 'improvement-link',
            'target_page_id' => $article->id,
            'reason' => 'Points to the page that owns the operational improvement process.',
        ]]);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle($this->service());

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->where('is_current', true)->firstOrFail();
        $this->assertStringContainsString('[[operational-improvement-a1b2c3|Operational Improvement]]', $version->content_markdown);
    }

    public function test_structured_intent_overrides_a_model_authored_base_slug(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'service-improvement-f93a12', 'Service Improvement');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nSee [[service-improvement|Service Improvement]].", [[
            'intent_id' => 'service-improvement-link',
            'target_page_id' => $target->id,
            'reason' => 'References the owning page.',
        ]]);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->where('is_current', true)->firstOrFail();
        $this->assertStringContainsString('[[service-improvement-f93a12|Service Improvement]]', $version->content_markdown);
        $this->assertStringNotContainsString('[[service-improvement|Service Improvement]]', $version->content_markdown);
    }

    public function test_unknown_structured_target_is_rejected_before_persistence(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nUnknown target.", [[
            'intent_id' => 'unknown-target',
            'target_page_id' => 999999,
            'reason' => 'Invalid target identity.',
        ]]);

        Queue::fake();
        $this->assertGenerationFails($run, $generated);
    }

    public function test_structured_self_link_is_rejected_before_persistence(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nConcept is self-referential.", [[
            'intent_id' => 'self-link',
            'target_page_id' => $generated->id,
            'reason' => 'Invalid self identity.',
        ]]);

        Queue::fake();
        $this->assertGenerationFails($run, $generated);
    }

    public function test_cross_customer_structured_target_is_rejected_before_persistence(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Other Customer');
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $foreign = $this->createPage($otherCustomer, 'foreign-page', 'Foreign Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nForeign Page is mentioned.", [[
            'intent_id' => 'foreign-link',
            'target_page_id' => $foreign->id,
            'reason' => 'Invalid cross-customer identity.',
        ]]);

        Queue::fake();
        $this->assertGenerationFails($run, $generated);
    }

    public function test_valid_intent_without_a_marker_is_dropped_without_changing_prose(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'service-improvement-f93a12', 'Service Improvement');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nThe service improvement process is reviewed monthly.", [[
            'intent_id' => 'service-improvement-link',
            'target_page_id' => $target->id,
            'reason' => 'The link could not be placed by the model.',
        ]]);

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->where('is_current', true)->firstOrFail();
        $this->assertStringContainsString('The service improvement process is reviewed monthly.', $version->content_markdown);
        $this->assertStringNotContainsString('[[service-improvement-f93a12|', $version->content_markdown);
        $this->assertSame([], $version->content_blocks_json[0]['link_intents']);
    }

    public function test_unknown_wikilink_marker_is_rejected_before_persistence(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\n{{wiki_link:not-listed|Target Page}} is relevant.", [[
            'intent_id' => 'listed-link',
            'target_page_id' => $target->id,
            'reason' => 'Marker identity is invalid.',
        ]]);

        Queue::fake();
        $this->assertGenerationFails($run, $generated);
    }

    private function assertGenerationRejected(\Closure $markdownFor): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'target-page', 'Target Page');
        $other = $this->createPage($customer, 'other-page', 'Other Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $other]);

        $this->mockAiResponse($markdownFor($generated, $other));

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected — the job's catch block turns this into a failed pivot.
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $generated->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, $pivot->generation_status);
        $this->assertNull($pivot->generated_page_version_id);
    }

    // =========================================================================
    // Links are optional semantic enrichment
    // =========================================================================

    public function test_zero_link_article_is_accepted_when_the_run_has_relevant_targets(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $concept = $this->createPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockAiResponse("# {$article->title}\n\nNo links in this content at all.");

        Queue::fake();

        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());
    }

    public function test_zero_link_summary_is_accepted_when_the_run_has_relevant_targets(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $summary = $this->createPage($customer, 'sammendrag', 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $entity = $this->createPage($customer, 'entitet', 'Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $run = $this->createAppliedRun($customer, $document, [$summary, $entity]);

        $this->mockAiResponse("# {$summary->title}\n\nNo links in this content at all.");

        Queue::fake();

        (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $summary->id)->exists());
    }

    public function test_concept_without_wikilinks_is_accepted_without_a_forced_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$concept, $target]);

        $this->mockAiResponse("# {$concept->title}\n\nTarget Page is important.");
        $this->mock(WikiLinkRevisionAiClient::class)->shouldNotReceive('reviseLinks');

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle($this->service());

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->where('is_current', true)->firstOrFail();
        $this->assertStringNotContainsString('[[target-page|Target Page]]', $version->content_markdown);
    }

    public function test_concept_without_a_relevant_link_is_not_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$concept, $target]);

        $this->mockAiResponse("# {$concept->title}\n\nNo links yet.");
        $this->mock(WikiLinkRevisionAiClient::class)->shouldNotReceive('reviseLinks');

        Queue::fake();

        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->exists());
    }

    public function test_concept_with_valid_wikilink_is_not_repaired(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createPage($customer, 'concept', 'Concept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $target = $this->createPage($customer, 'target-page', 'Target Page');
        $run = $this->createAppliedRun($customer, $document, [$concept, $target]);

        $this->mockAiResponse("# {$concept->title}\n\nSee [[target-page]].");
        $this->mock(WikiLinkRevisionAiClient::class)->shouldNotReceive('reviseLinks');

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $concept->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $concept->id)->exists());
    }

    public function test_page_with_empty_catalog_can_be_generated_without_a_wikilink(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->mockAiResponse("# {$article->title}\n\nNo links needed — this page stands alone.");

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($this->service());

        $this->assertTrue(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->exists());
    }

    // =========================================================================
    // Runtime fix: safe canonicalization before final validation (run 18)
    // =========================================================================

    public function test_bare_title_cased_slug_is_canonicalized_and_generation_completes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'artikkel', 'Artikkel');
        $target = $this->createPage($customer, 'advania', 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        // The model writes the page's title, differently cased, as if it were the slug —
        // exactly the run 18 failure ([[Advania]] instead of [[advania|Advania]]).
        $this->mockAiResponse("# {$generated->title}\n\nProsjektet eies av [[Advania]].");

        Queue::fake();
        (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $generated->id)
            ->where('is_current', true)
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('[[advania|Advania]]', $version->content_markdown);
        $this->assertStringNotContainsString('[[Advania]]', $version->content_markdown);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $generated->id)
            ->first();
        $this->assertSame(EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED, $pivot->generation_status);
    }

    public function test_unknown_target_is_still_rejected_despite_canonicalization(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'artikkel', 'Artikkel');
        $target = $this->createPage($customer, 'advania', 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $run = $this->createAppliedRun($customer, $document, [$generated, $target]);

        $this->mockAiResponse("# {$generated->title}\n\nSee [[TotallyUnrelatedTarget]] here.");

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());
    }

    public function test_ambiguous_title_match_is_still_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'artikkel', 'Artikkel');
        $riskA = $this->createPage($customer, 'risiko-a', 'Risiko', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $riskB = $this->createPage($customer, 'risiko-b', 'Risiko', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$generated, $riskA, $riskB]);

        $this->mockAiResponse("# {$generated->title}\n\nSe [[Risiko]] for mer.");

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected — two catalog pages share the title "Risiko", so canonicalization
            // must not guess which one was meant.
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());
    }

    public function test_self_link_by_own_title_is_still_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'artikkel', 'Artikkel');
        $other = $this->createPage($customer, 'other-page', 'Other Page');
        $run = $this->createAppliedRun($customer, $document, [$generated, $other]);

        // The generated page's own title is never in its own link catalog, so canonicalization
        // cannot rewrite it — it must still be rejected as a self-link by the resolver.
        $this->mockAiResponse("# {$generated->title}\n\nThis is the [[Artikkel]] itself.");

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());
    }

    public function test_cross_customer_title_match_is_still_rejected(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Other Customer');
        $document = $this->createDocument($customer);
        $generated = $this->createPage($customer, 'artikkel', 'Artikkel');
        $other = $this->createPage($customer, 'other-page', 'Other Page');
        // Another customer happens to have a page with the exact same title — must never be
        // treated as a canonicalization candidate since it was never in this run's catalog.
        $this->createPage($otherCustomer, 'advania', 'Advania', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $run = $this->createAppliedRun($customer, $document, [$generated, $other]);

        $this->mockAiResponse("# {$generated->title}\n\nSee [[Advania]] here.");

        Queue::fake();

        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $generated->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $generated->id)->exists());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiGenerateAppliedPagesService
    {
        return app(EnterpriseWikiGenerateAppliedPagesService::class);
    }

    private function assertGenerationFails(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle($this->service());
            $this->fail('Expected generation to be rejected.');
        } catch (RuntimeException) {
            // The job records the failed generation before rethrowing.
        }

        $this->assertFalse(EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->exists());
    }

    private function mockAiResponse(string $markdown, array $linkIntents = []): void
    {
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
            ): array => $this->structuredPageResult($markdown, $sourceElements, $linkIntents));
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

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
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
    private function structuredPageResult(string $markdown, array $sourceElements, array $linkIntents = []): array
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
                    'link_intents' => $linkIntents,
                ],
            ],
        ];
    }
}
