<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiCoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Source coverage ──────────────────────────────────────────────────────

    public function test_empty_customer_returns_all_zeros(): void
    {
        $customer = $this->createCustomer();

        $result = $this->service()->computeForCustomer($customer->id);

        $sc = $result['source_coverage'];
        $this->assertSame(0, $sc['extracted_documents']);
        $this->assertSame(0, $sc['documents_with_applied_run']);
        $this->assertSame(0, $sc['documents_with_article']);
        $this->assertSame(0, $sc['documents_with_summary']);
        $this->assertSame([], $sc['gaps']);
    }

    public function test_extracted_document_without_applied_run_appears_in_gaps(): void
    {
        $customer = $this->createCustomer();
        $this->createDocument($customer);

        $result = $this->service()->computeForCustomer($customer->id);

        $sc = $result['source_coverage'];
        $this->assertSame(1, $sc['extracted_documents']);
        $this->assertSame(0, $sc['documents_with_applied_run']);
        $this->assertCount(1, $sc['gaps']);
        $this->assertSame(['applied_run'], $sc['gaps'][0]['missing']);
        $this->assertSame('coverage-test.pdf', $sc['gaps'][0]['filename']);
    }

    public function test_document_with_applied_run_and_article_page_counted(): void
    {
        $customer = $this->createCustomer();
        $doc = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $doc);
        // Create article WITH version+content so it passes content checks
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPageVersion($article, '# Artikkel');
        $this->attachPageToRun($run, $article);

        $result = $this->service()->computeForCustomer($customer->id);

        $sc = $result['source_coverage'];
        $this->assertSame(1, $sc['documents_with_applied_run']);
        $this->assertSame(1, $sc['documents_with_article']);
        $this->assertSame(1, $sc['documents_with_article_content']);
        $this->assertSame(0, $sc['documents_with_summary']);
        $this->assertSame(0, $sc['documents_with_summary_content']);
        // Article OK, only summary missing
        $this->assertCount(1, $sc['gaps']);
        $this->assertContains('summary_missing', $sc['gaps'][0]['missing']);
        $this->assertNotContains('article_missing', $sc['gaps'][0]['missing']);
        $this->assertNotContains('article_missing_current_version', $sc['gaps'][0]['missing']);
        $this->assertNotContains('article_missing_content', $sc['gaps'][0]['missing']);
    }

    public function test_article_page_without_current_version_creates_version_gap(): void
    {
        $customer = $this->createCustomer();
        $doc = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $doc);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        // No version created
        $this->attachPageToRun($run, $article);

        $sc = $this->service()->computeForCustomer($customer->id)['source_coverage'];

        $this->assertSame(1, $sc['documents_with_article']);
        $this->assertSame(0, $sc['documents_with_article_content']);
        $this->assertCount(1, $sc['gaps']);
        $this->assertContains('article_missing_current_version', $sc['gaps'][0]['missing']);
    }

    public function test_article_page_with_empty_content_creates_content_gap(): void
    {
        $customer = $this->createCustomer();
        $doc = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $doc);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPageVersion($article, ''); // empty content
        $this->attachPageToRun($run, $article);

        $sc = $this->service()->computeForCustomer($customer->id)['source_coverage'];

        $this->assertSame(1, $sc['documents_with_article']);
        $this->assertSame(0, $sc['documents_with_article_content']);
        $this->assertContains('article_missing_content', $sc['gaps'][0]['missing']);
    }

    public function test_document_with_both_article_and_summary_pages_has_no_gap(): void
    {
        $customer = $this->createCustomer();
        $doc = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $doc);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        // Both pages need current versions with content to pass all checks
        $this->createPageVersion($article, '# Artikkel');
        $this->createPageVersion($summary, '# Sammendrag');
        $this->attachPageToRun($run, $article);
        $this->attachPageToRun($run, $summary);

        $result = $this->service()->computeForCustomer($customer->id);

        $sc = $result['source_coverage'];
        $this->assertSame(1, $sc['documents_with_article']);
        $this->assertSame(1, $sc['documents_with_summary']);
        $this->assertSame(1, $sc['documents_with_article_content']);
        $this->assertSame(1, $sc['documents_with_summary_content']);
        $this->assertSame([], $sc['gaps']);
    }

    public function test_non_extracted_documents_are_excluded_from_source_coverage(): void
    {
        $customer = $this->createCustomer();
        EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'draft.pdf',
            'file_path' => 'customers/1/wiki-documents/draft.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ]);

        $result = $this->service()->computeForCustomer($customer->id);

        $this->assertSame(0, $result['source_coverage']['extracted_documents']);
    }

    // ─── Page quality ─────────────────────────────────────────────────────────

    public function test_page_quality_counts_by_page_type_and_status(): void
    {
        $customer = $this->createCustomer();
        $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::STATUS_DRAFT);
        $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, EnterpriseWikiPage::STATUS_DRAFT);

        $pq = $this->service()->computeForCustomer($customer->id)['page_quality'];

        $this->assertSame(3, $pq['total']);
        $this->assertSame(2, $pq['by_page_type'][EnterpriseWikiPage::PAGE_TYPE_ARTICLE]);
        $this->assertSame(1, $pq['by_page_type'][EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);
        $this->assertSame(2, $pq['by_status'][EnterpriseWikiPage::STATUS_DRAFT]);
        $this->assertSame(1, $pq['by_status'][EnterpriseWikiPage::STATUS_PENDING_REVIEW]);
    }

    public function test_page_quality_tracks_current_version_and_content(): void
    {
        $customer = $this->createCustomer();
        $withVersion = $this->createPage($customer);
        $this->createPage($customer); // no version created for this one

        $this->createPageVersion($withVersion);

        $pq = $this->service()->computeForCustomer($customer->id)['page_quality'];

        $this->assertSame(1, $pq['with_current_version']);
        $this->assertSame(1, $pq['without_current_version']);
        $this->assertSame(1, $pq['without_content']); // $withoutVersion has no version/content
    }

    public function test_page_quality_tracks_claims(): void
    {
        $customer = $this->createCustomer();
        $withClaim = $this->createPage($customer);
        $this->createPage($customer); // no claim created for this one

        $this->createClaim($withClaim);

        $pq = $this->service()->computeForCustomer($customer->id)['page_quality'];

        $this->assertSame(1, $pq['with_claims']);
        $this->assertSame(1, $pq['without_claims']);
    }

    // ─── Claim coverage ───────────────────────────────────────────────────────

    public function test_claim_coverage_is_null_when_no_pages(): void
    {
        $customer = $this->createCustomer();

        $cc = $this->service()->computeForCustomer($customer->id)['claim_coverage'];

        $this->assertSame(0, $cc['claims_total']);
        $this->assertNull($cc['claim_coverage_pct']);
    }

    public function test_claim_coverage_pct_computed_correctly(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claimWithSource = $this->createClaim($page);
        $this->createClaim($page); // second claim — no source reference added

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claimWithSource->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'Doc',
            'excerpt' => 'Relevant tekst.',
        ]);

        $cc = $this->service()->computeForCustomer($customer->id)['claim_coverage'];

        $this->assertSame(2, $cc['claims_total']);
        $this->assertSame(1, $cc['claims_with_source_reference']);
        $this->assertSame(1, $cc['claims_without_source_reference']);
        $this->assertSame(50.0, $cc['claim_coverage_pct']);
    }

    // ─── Lint ─────────────────────────────────────────────────────────────────

    public function test_lint_counts_open_findings_by_severity(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $lint = $this->service()->computeForCustomer($customer->id)['lint'];

        $this->assertSame(2, $lint['open_errors']);
        $this->assertSame(1, $lint['open_warnings']);
        $this->assertSame(0, $lint['open_info']);
    }

    public function test_orphan_pages_detected_correctly(): void
    {
        $customer = $this->createCustomer();
        $linked = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY); // orphan — no incoming link

        // $source and the summary page have no incoming links; only $linked has one
        $source = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $source->id,
            'to_page_id' => $linked->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => 'test',
        ]);

        $lint = $this->service()->computeForCustomer($customer->id)['lint'];

        // orphan and source have no incoming links → 2 orphans; linked has an incoming link → not orphan
        $this->assertSame(2, $lint['orphan_pages']);
    }

    public function test_closed_lint_findings_not_counted(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => 'resolved',
            'message' => 'Fixed.',
            'detected_at' => now(),
        ]);

        $lint = $this->service()->computeForCustomer($customer->id)['lint'];

        $this->assertSame(0, $lint['open_errors']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function service(): EnterpriseWikiCoverageService
    {
        return app(EnterpriseWikiCoverageService::class);
    }

    private function createCustomer(string $name = 'Coverage Test AS'): Customer
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'coverage-test.pdf',
            'file_path' => "customers/{$customer->id}/wiki-documents/coverage-test.pdf",
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

    private function createPage(
        Customer $customer,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        string $status = EnterpriseWikiPage::STATUS_DRAFT,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'coverage-' . Str::random(8),
            'title' => 'Coverage Test Side',
            'status' => $status,
            'page_type' => $pageType,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', Str::random(16)),
        ]);
    }

    private function createPageVersion(EnterpriseWikiPage $page, string $content = '# Test'): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'content_markdown' => $content,
            'is_current' => true,
        ]);
    }

    private function createClaim(EnterpriseWikiPage $page): EnterpriseWikiClaim
    {
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->first() ?? $this->createPageVersion($page);

        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test-påstand ' . Str::random(6),
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function createLintFinding(Customer $customer, EnterpriseWikiPage $page, string $severity): EnterpriseWikiLintFinding
    {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => $severity,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'message' => 'Test lint finding.',
            'detected_at' => now(),
        ]);
    }

    private function attachPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        DB::table('enterprise_wiki_ingest_run_pages')->insert([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
