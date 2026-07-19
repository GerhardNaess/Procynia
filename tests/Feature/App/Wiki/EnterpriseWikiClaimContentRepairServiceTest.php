<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Controlled, bounded block-content repair (Del 5) for runs stopped with
 * qa_status=repair_required. All AI calls are mocked — no external model calls.
 */
class EnterpriseWikiClaimContentRepairServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_no_repairables_returns_not_attempted(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [], 'Clean content.');
        $this->attachPageToRun($run, $article, $version);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $result = $this->service()->attempt($run);

        $this->assertFalse($result['attempted']);
        $this->assertSame('no_repairables', $result['reason']);
    }

    public function test_max_attempts_reached_skips_without_calling_ai(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $run->update(['claim_content_repair_attempt_count' => EnterpriseWikiIngestRun::MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS]);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $result = $this->service()->attempt($run);

        $this->assertFalse($result['attempted']);
        $this->assertSame('max_attempts_reached', $result['reason']);
    }

    public function test_claim_without_block_anchor_is_left_unrepaired(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [], 'Some content.');
        $this->attachPageToRun($run, $article, $version);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Unsupported claim with no block anchor.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'content_block_key' => null,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->mock(WikiSemanticReviserAiClient::class)->shouldReceive('revise')->never();

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertTrue($result['attempted']);
        $this->assertSame('unrepairable_blocks_present', $result['reason']);
        $this->assertContains($article->id, $result['unrepairable_page_ids']);
        $this->assertSame([], $result['repaired_page_ids']);
        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_reviser_failure_leaves_page_unrepaired(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $version = $this->createVersion($article, [$this->block('block-0001', 'Bad text.', $document)], 'Bad text.');
        $this->attachPageToRun($run, $article, $version);
        $this->createBadClaim($article, $version, 'block-0001');

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andThrow(new \RuntimeException('OpenAI timeout.'));

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertContains($article->id, $result['unrepairable_page_ids']);
        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());

        $version->refresh();
        $this->assertTrue((bool) $version->is_current);
    }

    public function test_repairs_block_creates_new_version_and_flow_reaches_passed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRepairRequiredRun($customer, $document);

        // Article: one bad block/claim to repair.
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $articleVersion = $this->createVersion($article, [$this->block('block-0001', 'Bad text.', $document)], 'Bad text.');
        $articlePivot = $this->attachPageToRun($run, $article, $articleVersion);
        $this->createBadClaim($article, $articleVersion, 'block-0001');

        // Summary: already clean and already extracted — must not be touched by re-extraction.
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $summaryVersion = $this->createVersion($summary, [$this->block('block-0001', 'Clean summary text.', $document)], 'Clean summary text.');
        $this->attachPageToRun($run, $summary, $summaryVersion, extracted: true);
        $summaryClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $summary->id,
            'enterprise_wiki_page_version_id' => $summaryVersion->id,
            'claim_text' => 'Clean summary text.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $summaryClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Clean summary text.',
        ]);

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->withArgs(function (string $source, string $existingContent) use ($document): bool {
                return $source === $document->extracted_text && $existingContent === 'Bad text.';
            })
            ->andReturn('Revised text confirmed by the source.');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn([
                'claims' => [[
                    'text' => 'Revised text confirmed by the source.',
                    'confidence' => 'high',
                    'excerpt' => 'Revised text confirmed by the source.',
                    'conflict_note' => null,
                ]],
            ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult());

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();
        $result = $this->service()->attempt($run);

        $this->assertTrue($result['attempted']);
        $this->assertContains($article->id, $result['repaired_page_ids']);
        $this->assertSame($versionCountBefore + 1, EnterpriseWikiPageVersion::query()->count());

        // Old version preserved, no longer current.
        $articleVersion->refresh();
        $this->assertFalse((bool) $articleVersion->is_current);
        $this->assertSame('Bad text.', $articleVersion->content_markdown);

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->firstOrFail();
        $this->assertSame('Revised text confirmed by the source.', $newVersion->content_markdown);
        $this->assertStringContainsString('claim-content-repair', $newVersion->generated_by_model);

        $articlePivot->refresh();
        $this->assertSame($newVersion->id, $articlePivot->generated_page_version_id);

        $run->refresh();
        $this->assertSame(1, $run->claim_content_repair_attempt_count);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertSame(
            0,
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $newVersion->id)
                ->whereIn('content_origin', [
                    EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                ])
                ->count(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiClaimContentRepairService
    {
        return app(EnterpriseWikiClaimContentRepairService::class);
    }

    private function createCustomer(string $name = 'Claim Content Repair Test AS'): Customer
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
            'extracted_text' => 'Authoritative source document text for claim content repair tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRepairRequiredRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            'qa_started_at' => now()->subMinute(),
            'qa_completed_at' => now(),
            'qa_attempt_count' => 1,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function createVersion(EnterpriseWikiPage $page, array $blocks, string $markdown): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function block(string $blockKey, string $markdown, EnterpriseWikiDocument $document): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256,
            'source_element_key' => 'paragraph-0',
            'source_element_type' => 'paragraph',
        ];
    }

    private function attachPageToRun(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        bool $extracted = false,
    ): EnterpriseWikiIngestRunPage {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generation_started_at' => now()->subMinute(),
            'generation_completed_at' => now(),
            'claims_extracted_at' => $extracted ? now() : null,
        ]);
    }

    private function createBadClaim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $blockKey): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Bad text.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'content_block_key' => $blockKey,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }
}
