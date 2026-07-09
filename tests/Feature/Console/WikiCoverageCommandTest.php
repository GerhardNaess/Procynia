<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WikiCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_customer_option(): void
    {
        $this->artisan('wiki:coverage')
            ->assertFailed()
            ->expectsOutputToContain('--customer option is required');
    }

    public function test_fails_for_nonexistent_customer(): void
    {
        $this->artisan('wiki:coverage', ['--customer' => '99999'])
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }

    public function test_succeeds_for_empty_customer(): void
    {
        $customer = $this->createCustomer();

        $this->artisan('wiki:coverage', ['--customer' => (string) $customer->id])
            ->assertSuccessful();
    }

    public function test_output_contains_section_headers(): void
    {
        $customer = $this->createCustomer();

        $this->artisan('wiki:coverage', ['--customer' => (string) $customer->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Kildedekning')
            ->expectsOutputToContain('Sidekvalitet')
            ->expectsOutputToContain('Claim-dekning')
            ->expectsOutputToContain('Lint og struktur');
    }

    public function test_reports_gap_for_document_without_applied_run(): void
    {
        $customer = $this->createCustomer();
        $this->createDocument($customer);

        $this->artisan('wiki:coverage', ['--customer' => (string) $customer->id])
            ->assertSuccessful()
            ->expectsOutputToContain('coverage-cmd-test.pdf');
    }

    public function test_reports_coverage_for_customer_with_data(): void
    {
        $customer = $this->createCustomer();
        $doc = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $doc);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        DB::table('enterprise_wiki_ingest_run_pages')->insert([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('wiki:coverage', ['--customer' => (string) $customer->id])
            ->assertSuccessful()
            ->expectsOutputToContain('1'); // at least one numeric count in output
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $name = 'Coverage CMD Test ' . Str::random(4);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
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
            'original_filename' => 'coverage-cmd-test.pdf',
            'file_path' => "customers/{$customer->id}/wiki-documents/coverage-cmd-test.pdf",
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'cmd-coverage-' . Str::random(8),
            'title' => 'CMD Coverage Side',
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'page_type' => $pageType,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', Str::random(16)),
        ]);
    }
}
