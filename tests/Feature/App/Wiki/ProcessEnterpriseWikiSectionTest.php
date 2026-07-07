<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessEnterpriseWikiSectionTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Test 1: Stores claim and source reference from fake AI response
    // -------------------------------------------------------------------------

    public function test_job_stores_claim_and_source_reference_from_ai_response(): void
    {
        ['run' => $run, 'section' => $section] = $this->createScaffold();

        $aiClient = $this->mockAiClient([
            ['text' => 'Vi er ISO 9001-sertifisert.', 'confidence' => 'high', 'excerpt' => 'ISO 9001-sertifisert siden 2015.'],
        ]);

        $this->runSection($section, $aiClient);

        $this->assertDatabaseCount('enterprise_wiki_claims', 1);
        $this->assertDatabaseHas('enterprise_wiki_claims', [
            'claim_text' => 'Vi er ISO 9001-sertifisert.',
            'confidence' => 'high',
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $claim = EnterpriseWikiClaim::query()->first();
        $this->assertDatabaseCount('enterprise_wiki_source_references', 1);
        $this->assertDatabaseHas('enterprise_wiki_source_references', [
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $run->source_id,
            'source_label' => 'kompetanse.docx',
            'excerpt' => 'ISO 9001-sertifisert siden 2015.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 2: Claim without text is rejected (parser drops it silently)
    // -------------------------------------------------------------------------

    public function test_claim_without_text_is_rejected(): void
    {
        ['section' => $section] = $this->createScaffold();

        $aiClient = $this->mockAiClient([
            ['text' => '', 'confidence' => 'medium', 'excerpt' => 'Noe innhold.'],
        ]);

        $this->runSection($section, $aiClient);

        $this->assertDatabaseCount('enterprise_wiki_claims', 0);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 0);
    }

    // -------------------------------------------------------------------------
    // Test 3: Claim without excerpt is rejected (job filters out empty excerpt)
    // -------------------------------------------------------------------------

    public function test_claim_without_excerpt_is_rejected(): void
    {
        ['section' => $section] = $this->createScaffold();

        // No 'excerpt' key → parser sets excerpt='' → job filters it out.
        $aiClient = $this->mockAiClient([
            ['text' => 'Et krav uten kilde.', 'confidence' => 'low'],
        ]);

        $this->runSection($section, $aiClient);

        $this->assertDatabaseCount('enterprise_wiki_claims', 0);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 0);
    }

    // -------------------------------------------------------------------------
    // Test 4: Excerpt is trimmed to 500 characters
    // -------------------------------------------------------------------------

    public function test_excerpt_is_trimmed_to_500_characters(): void
    {
        ['section' => $section] = $this->createScaffold();

        $aiClient = $this->mockAiClient([
            ['text' => 'Et gyldig krav.', 'confidence' => 'medium', 'excerpt' => str_repeat('X', 600)],
        ]);

        $this->runSection($section, $aiClient);

        $ref = EnterpriseWikiSourceReference::query()->first();
        $this->assertNotNull($ref);
        $this->assertSame(500, mb_strlen($ref->excerpt));
    }

    // -------------------------------------------------------------------------
    // Test 5: conflict_note sets conflict_flag = true
    // -------------------------------------------------------------------------

    public function test_conflict_note_sets_conflict_flag_true(): void
    {
        ['section' => $section] = $this->createScaffold();

        $aiClient = $this->mockAiClient([
            [
                'text' => 'Vi har 10 ansatte.',
                'confidence' => 'medium',
                'excerpt' => 'Antall ansatte: 10.',
                'conflict_note' => 'Annet dokument sier 12 ansatte.',
            ],
        ]);

        $this->runSection($section, $aiClient);

        $this->assertDatabaseHas('enterprise_wiki_claims', [
            'claim_text' => 'Vi har 10 ansatte.',
            'conflict_flag' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 6: Section status becomes 'completed' on success
    // -------------------------------------------------------------------------

    public function test_section_is_marked_completed_on_success(): void
    {
        ['section' => $section] = $this->createScaffold();

        $aiClient = $this->mockAiClient([
            ['text' => 'Et gyldig krav.', 'confidence' => 'high', 'excerpt' => 'Kildesetning.'],
        ]);

        $this->runSection($section, $aiClient);

        $section->refresh();
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_COMPLETED, $section->status);
    }

    // -------------------------------------------------------------------------
    // Test 7: Exception from AI client marks section failed and rethrows
    // -------------------------------------------------------------------------

    public function test_section_is_marked_failed_when_ai_client_throws(): void
    {
        ['section' => $section] = $this->createScaffold();

        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldReceive('fetchClaims')->andThrow(new \RuntimeException('Tilkobling feilet.'));

        $caught = false;

        try {
            $this->runSection($section, $mock);
        } catch (\RuntimeException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'RuntimeException must be rethrown by the section job.');
        $section->refresh();
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_FAILED, $section->status);
        $this->assertStringContainsString('Tilkobling feilet.', (string) $section->error_message);
        $this->assertDatabaseCount('enterprise_wiki_claims', 0);
    }

    // -------------------------------------------------------------------------
    // Test 8: Re-run of completed section returns early — no duplicates
    // -------------------------------------------------------------------------

    public function test_rerun_of_completed_section_does_not_create_duplicates(): void
    {
        ['section' => $section] = $this->createScaffold();

        $section->update(['status' => EnterpriseWikiIngestSection::STATUS_COMPLETED]);

        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldNotReceive('fetchClaims');

        $this->runSection($section, $mock);

        $this->assertDatabaseCount('enterprise_wiki_claims', 0);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 0);
        $section->refresh();
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_COMPLETED, $section->status);
    }

    // -------------------------------------------------------------------------
    // Test 9: No real AI calls — only the mocked client is invoked
    // -------------------------------------------------------------------------

    public function test_job_makes_no_real_ai_calls(): void
    {
        // WikiSectionAiClient::fetchClaims() throws RuntimeException("not implemented")
        // in production. If the real class were called, this test would fail with that
        // exception. The mock returning a valid response proves the job only uses the mock.
        ['section' => $section] = $this->createScaffold();

        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldReceive('fetchClaims')
            ->once()
            ->andReturn(['claims' => []]);

        $this->runSection($section, $mock);

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Test 10: Job does not modify KnowledgeBase/RAG tables
    // -------------------------------------------------------------------------

    public function test_job_does_not_modify_rag_tables(): void
    {
        ['version' => $version, 'section' => $section] = $this->createScaffold();

        $originalText = $version->extracted_text;
        $originalApproval = $version->approval_status;
        $countVersionsBefore = KnowledgeItemVersion::query()->count();

        $aiClient = $this->mockAiClient([
            ['text' => 'Et krav.', 'confidence' => 'high', 'excerpt' => 'Kildesetning.'],
        ]);

        $this->runSection($section, $aiClient);

        $version->refresh();
        $this->assertSame($originalText, $version->extracted_text);
        $this->assertSame($originalApproval, $version->approval_status);
        $this->assertSame($countVersionsBefore, KnowledgeItemVersion::query()->count());
        $this->assertDatabaseCount('knowledge_item_chunks', 0);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{customer: Customer, item: KnowledgeItem, version: KnowledgeItemVersion, run: EnterpriseWikiIngestRun, page: EnterpriseWikiPage, pageVersion: EnterpriseWikiPageVersion, section: EnterpriseWikiIngestSection}
     */
    private function createScaffold(array $versionOverrides = [], array $sectionOverrides = []): array
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, $versionOverrides);
        $run = $this->createRun($customer, $version);
        [$page, $pageVersion] = $this->createDraftPage($customer, $run);
        $section = $this->createSection($run, $sectionOverrides);

        return compact('customer', 'item', 'version', 'run', 'page', 'pageVersion', 'section');
    }

    private function runSection(EnterpriseWikiIngestSection $section, WikiSectionAiClient $aiClient): void
    {
        (new ProcessEnterpriseWikiSection($section->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
            $aiClient,
        );
    }

    private function mockAiClient(array $claims): WikiSectionAiClient
    {
        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldReceive('fetchClaims')->andReturn(['claims' => $claims]);

        return $mock;
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
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createKnowledgeItem(Customer $customer): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => true,
        ]);
    }

    private function createVersion(KnowledgeItem $item, Customer $customer, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => "## Kompetanse\nVi leverer ISO 9001-sertifisert service.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
            'original_filename' => 'kompetanse.docx',
        ], $overrides));
    }

    private function createRun(Customer $customer, KnowledgeItemVersion $version): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => str_pad('hash', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        ]);
    }

    /**
     * @return array{EnterpriseWikiPage, EnterpriseWikiPageVersion}
     */
    private function createDraftPage(Customer $customer, EnterpriseWikiIngestRun $run): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-draft-' . $run->id,
            'title' => 'Test Document',
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => $run->source_hash,
        ]);

        $run->update(['enterprise_wiki_page_id' => $page->id]);

        $pageVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
        ]);

        return [$page, $pageVersion];
    }

    private function createSection(EnterpriseWikiIngestRun $run, array $overrides = []): EnterpriseWikiIngestSection
    {
        return EnterpriseWikiIngestSection::query()->create(array_merge([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'section_index' => 0,
            'heading' => 'Kompetanse',
            'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
        ], $overrides));
    }
}
