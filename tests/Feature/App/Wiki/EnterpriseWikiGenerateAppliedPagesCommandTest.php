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
            ->shouldReceive('generateFromSource')
            ->andReturn(self::FAKE_MARKDOWN)
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
    // Successful generation
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithPages($customer);

        $this->artisan('wiki:generate-applied-pages', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_generates_version_for_article_page(): void
    {
        $customer             = $this->createCustomer();
        [$run, $article]      = $this->createAppliedRunWithPages($customer);
        $versionsBefore       = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertGreaterThan($versionsBefore, EnterpriseWikiPageVersion::query()->count());

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->exists()
        );
    }

    public function test_command_generates_version_for_summary_page(): void
    {
        $customer        = $this->createCustomer();
        [$run,, $summary] = $this->createAppliedRunWithPages($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $summary->id)
                ->exists()
        );
    }

    public function test_command_fills_content_markdown_on_version(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithPages($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $this->assertNotNull($version);
        $this->assertNotEmpty($version->content_markdown);
        $this->assertSame(self::FAKE_MARKDOWN, $version->content_markdown);
    }

    public function test_command_marks_version_as_current(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithPages($customer);

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->first();

        $this->assertTrue((bool) $version->is_current);
    }

    public function test_command_outputs_generated_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithPages($customer); // 1 article + 1 summary

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Generated: 2', Artisan::output());
    }

    // =========================================================================
    // Concept and entity pages are skipped
    // =========================================================================

    public function test_command_skips_concept_pages(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createDecisionOnlyRunApplied($customer);
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Test Concept');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $concept->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());

        $this->assertFalse(
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $concept->id)
                ->exists()
        );
    }

    public function test_command_skips_entity_pages(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createDecisionOnlyRunApplied($customer);
        $entity   = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Test Entity');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $entity->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    // =========================================================================
    // Idempotency: page already has a version
    // =========================================================================

    public function test_command_skips_page_that_already_has_a_version(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithPages($customer);

        // Pre-create a version so the page is already generated
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '# Existing',
            'generated_by_model'      => 'gpt-5',
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        // Article is skipped; summary may still be generated
        $this->assertSame(
            $versionsBefore,
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->count()
        );
    }

    public function test_command_reports_skipped_count(): void
    {
        $customer        = $this->createCustomer();
        [$run, $article] = $this->createAppliedRunWithPages($customer);

        // Pre-generate both pages
        foreach ([$article] as $page) {
            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number'          => 1,
                'is_current'              => true,
                'content_markdown'        => '# Existing',
                'generated_by_model'      => 'gpt-5',
            ]);
        }

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Skipped: 1', Artisan::output());
    }

    // =========================================================================
    // No side effects: claims, ingest runs
    // =========================================================================

    public function test_command_does_not_create_claims(): void
    {
        $customer      = $this->createCustomer();
        [$run]         = $this->createAppliedRunWithPages($customer);
        $claimsBefore  = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_does_not_create_additional_ingest_runs(): void
    {
        $customer   = $this->createCustomer();
        [$run]      = $this->createAppliedRunWithPages($customer);
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:generate-applied-pages', ['--run-id' => $run->id]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithPages($customer);

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

    private function createDecisionOnlyRunApplied(Customer $customer): EnterpriseWikiIngestRun
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
     * Applied run with one article page and one summary page in the pivot.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createAppliedRunWithPages(Customer $customer): array
    {
        $run     = $this->createDecisionOnlyRunApplied($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $article->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $summary->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        return [$run, $article, $summary];
    }
}
