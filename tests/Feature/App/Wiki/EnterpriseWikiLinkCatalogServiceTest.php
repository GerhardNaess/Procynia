<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiLinkCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_page_is_never_in_the_catalog(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $result = $this->service()->buildForPage($run, $article);

        $this->assertNotContains($article->slug, array_column($result['catalog'], 'slug'));
    }

    public function test_all_other_applied_run_pages_are_in_the_catalog(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $summary = $this->createPage($customer, 'sammendrag', 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $concept = $this->createPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$article, $summary, $concept]);

        $result = $this->service()->buildForPage($run, $article);

        $slugs = array_column($result['catalog'], 'slug');
        $this->assertContains($summary->slug, $slugs);
        $this->assertContains($concept->slug, $slugs);
        $this->assertSame(2, $result['run_page_count']);
    }

    public function test_relevant_existing_customer_pages_can_be_included(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $existing = $this->createPage($customer, 'eksisterende-side', 'Eksisterende side');

        $result = $this->service()->buildForPage($run, $article);

        $this->assertContains($existing->slug, array_column($result['catalog'], 'slug'));
        $this->assertSame(0, $result['run_page_count']);
    }

    public function test_other_customers_pages_are_never_included(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $document = $this->createDocument($customerA);
        $article = $this->createPage($customerA, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customerA, $document, [$article]);

        $otherCustomerPage = $this->createPage($customerB, 'business-case', 'Business Case');

        $result = $this->service()->buildForPage($run, $article);

        $this->assertNotContains($otherCustomerPage->slug, array_column($result['catalog'], 'slug'));
    }

    public function test_catalog_cap_never_removes_applied_run_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');

        $runPages = [$article];
        for ($i = 0; $i < 5; $i++) {
            $runPages[] = $this->createPage($customer, "run-page-{$i}", "Run page {$i}", EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        }
        $run = $this->createAppliedRun($customer, $document, $runPages);

        // More "other" customer pages than the cap, to prove the cap only trims these.
        for ($i = 0; $i < EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES + 10; $i++) {
            $this->createPage($customer, "other-page-{$i}", "Other page {$i}");
        }

        $result = $this->service()->buildForPage($run, $article);

        $runPageSlugs = collect($runPages)->reject(fn (EnterpriseWikiPage $p) => $p->id === $article->id)->pluck('slug');
        $catalogSlugs = array_column($result['catalog'], 'slug');

        foreach ($runPageSlugs as $slug) {
            $this->assertContains($slug, $catalogSlugs, "Expected run page slug [{$slug}] to always be in the catalog.");
        }

        $this->assertSame(5, $result['run_page_count']);
        $this->assertLessThanOrEqual(5 + EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES, count($catalogSlugs));
    }

    public function test_catalog_entries_do_not_include_content_markdown(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);
        $this->createPage($customer, 'business-case', 'Business Case');

        $result = $this->service()->buildForPage($run, $article);

        foreach ($result['catalog'] as $entry) {
            $this->assertSame(['page_id', 'slug', 'title', 'page_type'], array_keys($entry));
        }
    }

    private function service(): EnterpriseWikiLinkCatalogService
    {
        return app(EnterpriseWikiLinkCatalogService::class);
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
}
