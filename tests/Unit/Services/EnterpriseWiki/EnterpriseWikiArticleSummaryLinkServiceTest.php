<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiArticleSummaryLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiArticleSummaryLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EnterpriseWikiArticleSummaryLinkService
    {
        return app(EnterpriseWikiArticleSummaryLinkService::class);
    }

    public function test_finds_the_unambiguous_paired_summary_for_an_article(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->attachToRun($run, $article);
        $this->attachToRun($run, $summary);

        $paired = $this->service()->findPairedPage($run, $article);

        $this->assertNotNull($paired);
        $this->assertSame($summary->id, $paired->id);
    }

    public function test_finds_the_unambiguous_paired_article_for_a_summary(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->attachToRun($run, $article);
        $this->attachToRun($run, $summary);

        $paired = $this->service()->findPairedPage($run, $summary);

        $this->assertNotNull($paired);
        $this->assertSame($article->id, $paired->id);
    }

    public function test_returns_null_when_two_articles_exist_in_the_same_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer);
        $articleA = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel A');
        $articleB = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel B');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->attachToRun($run, $articleA);
        $this->attachToRun($run, $articleB);
        $this->attachToRun($run, $summary);

        $this->assertNull($this->service()->findPairedPage($run, $summary));
    }

    public function test_returns_null_when_no_summary_exists_in_the_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $this->attachToRun($run, $article);

        $this->assertNull($this->service()->findPairedPage($run, $article));
    }

    public function test_returns_null_for_concept_and_entity_page_types(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer);
        $concept = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept');
        $this->attachToRun($run, $concept);

        $this->assertNull($this->service()->findPairedPage($run, $concept));
    }

    public function test_has_link_to_page_detects_a_valid_wikilink_regardless_of_anchor_text(): void
    {
        $customer = $this->createCustomer();
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $markdown = "# Artikkel\n\nSee our own custom note: [[{$summary->slug}|Klikk her for et sammendrag]].";

        $this->assertTrue($this->service()->hasLinkToPage($article, $markdown, $summary));
    }

    public function test_has_link_to_page_is_false_when_no_link_exists(): void
    {
        $customer = $this->createCustomer();
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $this->assertFalse($this->service()->hasLinkToPage($article, '# Artikkel\n\nNo links here at all.', $summary));
    }

    public function test_has_link_to_page_is_false_for_a_link_to_a_different_page(): void
    {
        $customer = $this->createCustomer();
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $otherPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Annet konsept');
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $markdown = "# Artikkel\n\n[[{$otherPage->slug}|Annet konsept]]";

        $this->assertFalse($this->service()->hasLinkToPage($article, $markdown, $summary));
    }

    public function test_build_link_block_uses_norwegian_label_for_no_language(): void
    {
        $customer = $this->createCustomer();
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');

        $block = $this->service()->buildLinkBlock($summary, 3, 'no');

        $this->assertSame(3, $block['position']);
        $this->assertStringContainsString('Se også', $block['markdown']);
        $this->assertStringContainsString("[[{$summary->slug}|{$summary->title}]]", $block['markdown']);
    }

    public function test_build_link_block_uses_english_label_for_other_languages(): void
    {
        $customer = $this->createCustomer();
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $block = $this->service()->buildLinkBlock($summary, 0, 'en');

        $this->assertStringContainsString('See also', $block['markdown']);
    }

    private function createCustomer(string $name = 'Article Summary Link Test AS'): Customer
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

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createRun(Customer $customer): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function attachToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }
}
