<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the 8G-5 semantic repair service (EnterpriseWikiSemanticRepairService).
 *
 * All AI calls are mocked — no external model calls.
 *
 * EnterpriseWikiPostIngestQaService no longer orchestrates semantic repair — it was redesigned
 * into a minimal, fully deterministic end check with no AI involvement at all (see
 * EnterpriseWikiPostIngestQaServiceTest for its own coverage). EnterpriseWikiSemanticRepairService
 * itself is unchanged and is now exercised by EnterpriseWikiQaRegressionService (the 8H
 * continuous maintainer / regression-detection loop) instead. Accordingly, every test here calls
 * EnterpriseWikiSemanticRepairService::repair() directly with a hand-built diagnosis array,
 * rather than going through an orchestrator.
 */
class EnterpriseWikiSemanticRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REVISED_MARKDOWN = "# Revised Article\n\nRevised content that covers all missing topics.";

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);

        // Level 1/2 repair generator must not be called in semantic repair tests.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generateFromSource')
            ->never()
            ->byDefault();
    }

    // =========================================================================
    // 1–3: repair() graceful-failure edge cases
    // =========================================================================

    public function test_invalid_repair_action_returns_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);

        $diagnosis = $this->failingAiResult(action: 'escalate');
        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertFalse($result['success']);
        $this->assertSame('repair_action_not_repairable', $result['reason']);
        $this->assertNull($result['page_version_id']);
    }

    public function test_source_type_not_supported_returns_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun(
            $customer,
            sourceType: EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
        );

        $diagnosis = $this->failingAiResult(action: 'targeted_revision');
        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertFalse($result['success']);
        $this->assertSame('source_type_not_supported', $result['reason']);
    }

    public function test_source_document_not_found_returns_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, sourceId: 999999);

        // Must not call AI when source is missing.
        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->never();

        $diagnosis = $this->failingAiResult(action: 'targeted_revision');
        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertFalse($result['success']);
        $this->assertSame('source_document_not_found', $result['reason']);
    }

    // =========================================================================
    // 4: reviser receives source, existing content, and concrete diagnosis
    // =========================================================================

    public function test_reviser_receives_source_content_and_diagnosis(): void
    {
        $sourceText = 'This is the authoritative source. It covers topics A, B, and C.';
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, extractedText: $sourceText);

        $articleContent = "# Article\n\nInitial article without topic B.";
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article', $articleContent);
        $articleVersion = $this->currentVersion($article);

        $diagnosis = $this->failingAiResult(
            action: 'targeted_revision',
            missingTopics: ['Topic B'],
            unsupportedClaims: ['Topic D exists'],
            pageVersionId: $articleVersion->id,
        );

        $capturedSource = null;
        $capturedContent = null;
        $capturedDiagnosis = null;

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->withArgs(function (string $source, string $content, string $pageType, array $diagnosis) use (
                &$capturedSource, &$capturedContent, &$capturedDiagnosis
            ): bool {
                $capturedSource = $source;
                $capturedContent = $content;
                $capturedDiagnosis = $diagnosis;

                return true;
            })
            ->andReturn(self::REVISED_MARKDOWN);

        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertTrue($result['success']);
        $this->assertSame($sourceText, $capturedSource);
        $this->assertSame($articleContent, $capturedContent);
        $this->assertSame('targeted_revision', $capturedDiagnosis['recommended_repair_action']);
        $this->assertSame(['Topic B'], $capturedDiagnosis['missing_topics']);
        $this->assertSame(['Topic D exists'], $capturedDiagnosis['unsupported_claims']);
    }

    // =========================================================================
    // 5: the level 1/2 generator prompt is NOT reused (WikiPageContentAiClient not called)
    // =========================================================================

    public function test_repair_does_not_invoke_generator_client(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->currentVersion($article);

        // Reviser (8G-5) must be called, generator (8G-3 level 1/2) must NOT — setUp() already
        // asserts generateFromSource->never() by default.
        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andReturn(self::REVISED_MARKDOWN);

        $diagnosis = $this->failingAiResult(action: 'targeted_revision', pageVersionId: $articleVersion->id);
        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // 6–8: new page version lifecycle
    // =========================================================================

    public function test_repair_creates_new_page_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->currentVersion($article);

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $diagnosis = $this->failingAiResult(action: 'targeted_revision', pageVersionId: $articleVersion->id);
        $this->repairService()->repair($run, $diagnosis);

        $this->assertSame($versionCountBefore + 1, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_old_version_is_not_overwritten(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article', "# Article\n\nOriginal content.");
        $originalVersion = $this->currentVersion($article);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $diagnosis = $this->failingAiResult(action: 'targeted_revision', pageVersionId: $originalVersion->id);
        $this->repairService()->repair($run, $diagnosis);

        // Original version must still exist, but must no longer be current.
        $originalVersion->refresh();
        $this->assertFalse((bool) $originalVersion->is_current);
        $this->assertSame("# Article\n\nOriginal content.", $originalVersion->content_markdown);
    }

    public function test_new_version_becomes_current_with_revised_content(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->currentVersion($article);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $diagnosis = $this->failingAiResult(action: 'targeted_revision', pageVersionId: $articleVersion->id);
        $result = $this->repairService()->repair($run, $diagnosis);

        $newCurrent = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->first();

        $this->assertNotNull($newCurrent);
        $this->assertSame(2, $newCurrent->version_number);
        $this->assertSame(self::REVISED_MARKDOWN, $newCurrent->content_markdown);
        $this->assertStringContainsString('semantic-repair', $newCurrent->generated_by_model);

        // repair()'s own return value points at the same new/previous version pair.
        $this->assertTrue($result['success']);
        $this->assertSame($newCurrent->id, $result['page_version_id']);
        $this->assertSame($articleVersion->id, $result['previous_version_id']);
    }

    // =========================================================================
    // 9: full_regeneration is a repairable action, exactly like targeted_revision
    // =========================================================================

    public function test_full_regeneration_action_is_repairable(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->currentVersion($article);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')->once()->andReturn(self::REVISED_MARKDOWN);

        $diagnosis = $this->failingAiResult(action: 'full_regeneration', pageVersionId: $articleVersion->id);
        $result = $this->repairService()->repair($run, $diagnosis);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['page_version_id']);
    }

    // =========================================================================
    // 10: an AI/reviser exception propagates out of repair() unhandled
    // =========================================================================

    public function test_reviser_exception_propagates_from_repair(): void
    {
        // EnterpriseWikiQaRegressionService::attemptSemanticRepair() relies on repair() letting
        // an unexpected AI failure propagate as an exception (it wraps the call in its own
        // try/catch) rather than swallowing it into a graceful-failure result array.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->currentVersion($article);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andThrow(new \RuntimeException('OpenAI timeout during revision.'));

        $diagnosis = $this->failingAiResult(action: 'targeted_revision', pageVersionId: $articleVersion->id);

        try {
            $this->repairService()->repair($run, $diagnosis);
            $this->fail('Expected RuntimeException to be re-thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OpenAI timeout', $e->getMessage());
        }

        // No new version should have been created — the failure happened before the
        // create-new-version transaction ever started.
        $articleVersion->refresh();
        $this->assertTrue((bool) $articleVersion->is_current);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function repairService(): EnterpriseWikiSemanticRepairService
    {
        return app(EnterpriseWikiSemanticRepairService::class);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function failingAiResult(
        string $action = 'targeted_revision',
        array $missingTopics = ['Section A'],
        array $unsupportedClaims = [],
        ?int $pageVersionId = null,
    ): array {
        return [
            'pass' => false,
            'quality_score' => 0.45,
            'coverage_score' => 0.40,
            'factual_consistency_score' => 0.80,
            'unsupported_claims' => $unsupportedClaims,
            'missing_topics' => $missingTopics,
            'missing_key_facts' => [],
            'critique' => 'Key topics are missing from the generated content.',
            'recommended_repair_action' => $action,
            'confidence' => 0.85,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
            'page_version_id' => $pageVersionId,
        ];
    }

    private function createCustomer(string $name = 'Repair Test AS'): Customer
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

    private function createDocument(
        Customer $customer,
        string $extractedText = 'Authoritative source document text for semantic repair tests.',
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extractedText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(
        Customer $customer,
        ?string $qaStatus = null,
        ?int $sourceId = null,
        ?string $extractedText = null,
        string $sourceType = EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
    ): EnterpriseWikiIngestRun {
        if ($sourceId === null && $sourceType === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            $document = $this->createDocument($customer, $extractedText ?? 'Authoritative source document text for semantic repair tests.');
            $sourceId = $document->id;
        }

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? 0,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
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

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        string $content = '',
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content !== '' ? $content : "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
