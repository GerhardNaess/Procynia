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
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiExtractPageClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_CLAIMS = [
        ['text' => 'Test claim alpha', 'confidence' => 'high',   'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
        ['text' => 'Test claim beta',  'confidence' => 'medium', 'excerpt' => 'Supporting excerpt beta.',  'conflict_note' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // No real OpenAI calls in any test.
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => self::FAKE_CLAIMS])
            ->byDefault();
    }

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:extract-page-claims')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:extract-page-claims', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guard: run not applied
    // =========================================================================

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createRunPending($customer);

        $this->artisan('wiki:extract-page-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Successful extraction
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $this->artisan('wiki:extract-page-claims', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_creates_claims_for_article_page(): void
    {
        $customer      = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_summary_page(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_concept_page(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_entity_page(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_correct_number_of_claims(): void
    {
        $customer      = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            count(self::FAKE_CLAIMS),
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count()
        );
    }

    // =========================================================================
    // Claim field values
    // =========================================================================

    public function test_claim_has_correct_page_and_version_ids(): void
    {
        $customer          = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->first();

        $this->assertSame($page->id, $claim->enterprise_wiki_page_id);
        $this->assertSame($version->id, $claim->enterprise_wiki_page_version_id);
    }

    public function test_claim_has_correct_claim_text(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $texts = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->pluck('claim_text')
            ->all();

        $this->assertSame('Test claim alpha', $texts[0]);
        $this->assertSame('Test claim beta',  $texts[1]);
    }

    public function test_claim_has_correct_confidence(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $first = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->first();

        $this->assertSame(EnterpriseWikiClaim::CONFIDENCE_HIGH, $first->confidence);
    }

    public function test_claim_approval_status_is_pending(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            0,
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->where('approval_status', '!=', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
                ->count()
        );
    }

    public function test_claim_position_order_is_sequential(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $orders = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->pluck('position_order')
            ->all();

        $this->assertSame([0, 1], $orders);
    }

    // =========================================================================
    // Skip: no current version
    // =========================================================================

    public function test_command_skips_page_without_current_version(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createRunApplied($customer);
        $page     = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Version');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertStringContainsString('Pages skipped:    1', Artisan::output());
    }

    // =========================================================================
    // Idempotency: existing claims skipped
    // =========================================================================

    public function test_command_skips_page_that_already_has_claims(): void
    {
        $customer          = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        // Pre-create a claim for this version
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id'         => $page->id,
            'enterprise_wiki_page_version_id'  => $version->id,
            'claim_text'                       => 'Existing claim',
            'position_order'                   => 0,
            'confidence'                       => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag'                    => false,
            'approval_status'                  => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_reports_skipped_when_claims_already_exist(): void
    {
        $customer          = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id'         => $page->id,
            'enterprise_wiki_page_version_id'  => $version->id,
            'claim_text'                       => 'Existing claim',
            'position_order'                   => 0,
            'confidence'                       => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag'                    => false,
            'approval_status'                  => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Pages skipped:    1', Artisan::output());
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_pages_processed_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Pages processed:  1', Artisan::output());
    }

    public function test_command_outputs_claims_created_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Claims created:   2', Artisan::output());
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_does_not_create_source_references(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    public function test_command_does_not_create_additional_ingest_runs(): void
    {
        $customer   = $this->createCustomer();
        [$run]      = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    public function test_command_does_not_modify_existing_page_versions(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $originalMarkdown  = $version->content_markdown;

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($originalMarkdown, $version->fresh()->content_markdown);
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
            'extracted_text'    => 'Source text for testing.',
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

    private function createRunPending(Customer $customer): EnterpriseWikiIngestRun
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

    private function createRunApplied(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * Applied run with one page (of the given type) in the pivot, already having a current version.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion}
     */
    private function createAppliedRunWithVersionedPage(Customer $customer, string $pageType): array
    {
        $run  = $this->createRunApplied($customer);
        $page = $this->createPage($customer, $pageType, 'Test Page ' . Str::random(4));

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => "# Test Page\n\nThis is test content with verifiable facts.",
            'generated_by_model'      => 'gpt-5',
        ]);

        return [$run, $page, $version];
    }
}
