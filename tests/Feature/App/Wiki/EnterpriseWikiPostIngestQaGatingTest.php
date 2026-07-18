<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiPageGeneration;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runtime fix (run 18): a run that failed during the ordinary document flow — before QA ever
 * started (qa_status still null) — must never be picked up again by scheduled QA polling or
 * explicit retry. Its original failure status and error_message must be preserved exactly.
 */
class EnterpriseWikiPostIngestQaGatingTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 13: a run that failed during page generation is not returned by findPendingRuns()
    // =========================================================================

    public function test_run_failed_during_page_generation_is_not_returned_by_find_pending_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer, status: EnterpriseWikiIngestRun::STATUS_FAILED, qaStatus: null);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->pluck('id')->contains($run->id));
    }

    // =========================================================================
    // 14: the same run is not claimed even with an explicit --retry
    // =========================================================================

    public function test_run_failed_during_wikilink_validation_is_not_claimed_even_with_explicit_retry(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun(
            $customer,
            status: EnterpriseWikiIngestRun::STATUS_FAILED,
            qaStatus: null,
            errorMessage: '1 of 2 article/summary page(s) failed to generate: Artikkel (article): [EnterpriseWikiInvalidWikilinksException] Run [1] page [2] (article): 1 invalid wikilink slug(s): Advania.',
        );

        $retryable = $this->service()->findRetryableRuns();
        $this->assertFalse($retryable->pluck('id')->contains($run->id));

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->qa_status);
    }

    // =========================================================================
    // 15: a run that legitimately reached QA remains eligible for maintenance/polling
    // =========================================================================

    public function test_run_awaiting_first_qa_pass_remains_eligible(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer, status: EnterpriseWikiIngestRun::STATUS_QA, qaStatus: null);

        $pending = $this->service()->findPendingRuns();

        $this->assertTrue($pending->pluck('id')->contains($run->id));
    }

    public function test_decision_only_run_remains_eligible_regardless_of_main_status(): void
    {
        $customer = $this->createCustomer();
        // Decision-only runs never transition their main `status` away from `decision_only` —
        // that state machine only exists in EnterpriseWikiDocumentFlowService — yet they still
        // rely on qa_status-based polling exactly as before this fix.
        $run = $this->createRun($customer, status: EnterpriseWikiIngestRun::STATUS_DECISION_ONLY, qaStatus: null);

        $pending = $this->service()->findPendingRuns();

        $this->assertTrue($pending->pluck('id')->contains($run->id));
    }

    public function test_a_run_that_failed_during_qa_itself_remains_eligible_for_retry(): void
    {
        $customer = $this->createCustomer();
        // status=failed but qa_status=failed (set together by markRunFailed(qaContext: true))
        // means QA legitimately started and failed there — unlike run 18, this must remain
        // retryable.
        $run = $this->createRun($customer, status: EnterpriseWikiIngestRun::STATUS_FAILED, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $retryable = $this->service()->findRetryableRuns();

        $this->assertTrue($retryable->pluck('id')->contains($run->id));
    }

    // =========================================================================
    // 18: original failure status and error_message are preserved
    // =========================================================================

    public function test_original_failure_status_and_error_message_are_preserved_after_a_no_op_retry_attempt(): void
    {
        $customer = $this->createCustomer();
        $originalMessage = '1 of 2 article/summary page(s) failed to generate: Artikkel (article): [EnterpriseWikiInvalidWikilinksException] Run page (article): 1 invalid wikilink slug(s): Advania.';
        $run = $this->createRun(
            $customer,
            status: EnterpriseWikiIngestRun::STATUS_FAILED,
            qaStatus: null,
            errorMessage: $originalMessage,
        );

        $this->service()->runForRun($run, retry: true);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->qa_status);
        $this->assertSame($originalMessage, $run->error_message);
    }

    public function test_run_error_message_shows_phase_page_title_page_type_invalid_slug_and_exception_type(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPage($customer, 'sammendrag', 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
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

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        // Summary generation writes a bare, wrongly-cased title as a slug with no matching
        // catalog page at all — canonicalization cannot help, and the real
        // EnterpriseWikiInvalidWikilinksException is thrown organically, exactly like run 18.
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
            ) => $this->structuredPageResult($pageType === 'article'
                ? "# Artikkel\n\nSee [[sammendrag|Sammendrag]] here."
                : "# Sammendrag\n\nSe [[Advania]] her.", $sourceElements));

        Queue::fake();

        $service = app(EnterpriseWikiGenerateAppliedPagesService::class);

        // Article succeeds.
        (new GenerateEnterpriseWikiAppliedPage($run->id, $article->id))->handle($service);

        // Summary fails — real exception path.
        try {
            (new GenerateEnterpriseWikiAppliedPage($run->id, $summary->id))->handle($service);
        } catch (\Throwable) {
            // expected
        }

        (new FinalizeEnterpriseWikiPageGeneration($run->id))->handle();

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('article/summary', $run->error_message);
        $this->assertStringContainsString('Sammendrag', $run->error_message);
        $this->assertStringContainsString('summary', $run->error_message);
        $this->assertStringContainsString('Advania', $run->error_message);
        $this->assertStringContainsString('EnterpriseWikiInvalidWikilinksException', $run->error_message);
    }

    // =========================================================================
    // 20: legacy ProcessEnterpriseWikiIngest is untouched
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiWikilinkCanonicalizer', $source);
        $this->assertStringNotContainsString('scopeToRunsReadyForQa', $source);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
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
            'extracted_text' => 'Source text for QA gating tests.',
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

    private function createRun(
        Customer $customer,
        string $status,
        ?string $qaStatus,
        ?string $errorMessage = null,
    ): EnterpriseWikiIngestRun {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
            'error_message' => $errorMessage,
            'finished_at' => in_array($status, EnterpriseWikiIngestRun::TERMINAL_STATUSES, true) ? now() : null,
        ]);
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
                    'content_origin' => 'source_based',
                    'source_element_keys' => [(string) $sourceElement['source_element_key']],
                    'source_element_types' => [(string) $sourceElement['source_element_type']],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ],
            ],
        ];
    }
}
