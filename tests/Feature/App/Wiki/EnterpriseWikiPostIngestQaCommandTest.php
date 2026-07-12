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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-ingest QA is now a deterministic end check with no AI calls (see
 * EnterpriseWikiPostIngestQaService) — these tests exercise the console command's own
 * plumbing (argument validation, --all-pending, --retry) against that deterministic service.
 */
class EnterpriseWikiPostIngestQaCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_fails_when_neither_option_provided(): void
    {
        $this->artisan('wiki:run-post-ingest-qa')
            ->expectsOutputToContain('--run-id')
            ->assertExitCode(1);
    }

    public function test_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createPendingRun($customer);

        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Single run: --run-id
    // =========================================================================

    public function test_exits_zero_for_run_that_passes(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_reports_skipped_when_already_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => $run->id])
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);
    }

    // =========================================================================
    // All-pending: --all-pending
    // =========================================================================

    public function test_all_pending_reports_no_runs_when_none(): void
    {
        $this->artisan('wiki:run-post-ingest-qa --all-pending')
            ->expectsOutputToContain('No pending runs')
            ->assertExitCode(0);
    }

    public function test_all_pending_processes_multiple_runs(): void
    {
        $customer = $this->createCustomer();
        $runA = $this->createAppliedRun($customer);
        $runB = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'A Article');
        $this->createVersionedPage($customer, $runA, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'A Summary');
        $this->createVersionedPage($customer, $runB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'B Article');
        $this->createVersionedPage($customer, $runB, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'B Summary');

        $this->artisan('wiki:run-post-ingest-qa --all-pending')
            ->assertExitCode(0);

        $runA->refresh();
        $runB->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $runA->qa_status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $runB->qa_status);
    }

    public function test_all_pending_skips_already_passed_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->artisan('wiki:run-post-ingest-qa --all-pending')
            ->expectsOutputToContain('No pending runs')
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_all_pending_skips_failed_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->artisan('wiki:run-post-ingest-qa --all-pending')
            ->expectsOutputToContain('No pending runs')
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_all_pending_skips_escalated_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->artisan('wiki:run-post-ingest-qa --all-pending')
            ->expectsOutputToContain('No pending runs')
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    // =========================================================================
    // Retry flag
    // =========================================================================

    public function test_retry_flag_processes_failed_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => $run->id, '--retry' => true])
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_retry_flag_processes_escalated_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->artisan('wiki:run-post-ingest-qa', ['--run-id' => $run->id, '--retry' => true])
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_all_pending_with_retry_processes_failed_runs(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->artisan('wiki:run-post-ingest-qa', ['--all-pending' => true, '--retry' => true])
            ->assertExitCode(0);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'QA Test AS'): Customer
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
            'extracted_text' => 'Source text.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, ?string $qaStatus = null): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
        ]);
    }

    private function createPendingRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
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
            // Marks page generation and claim extraction complete so QA can reach a real
            // passed/failed verdict instead of escalating on "not finished yet" — these tests
            // are about command plumbing, not the deterministic step-completeness checks.
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => now(),
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
