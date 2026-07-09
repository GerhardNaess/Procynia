<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiPageTraversalServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnterpriseWikiPageTraversalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseWikiPageTraversalService::class);
    }

    // =========================================================================
    // outgoing / incoming
    // =========================================================================

    public function test_outgoing_returns_linked_pages(): void
    {
        $customer = $this->createCustomer();
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');

        $this->createLink($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);

        $result = $this->service->outgoing($article);

        $this->assertCount(2, $result);
        $resultIds = $result->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$summary->id, $concept->id])->sort()->values()->all(), $resultIds);
    }

    public function test_incoming_returns_pages_that_link_to_page(): void
    {
        $customer = $this->createCustomer();
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $entity   = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entity');

        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE);
        $this->createLink($customer, $entity, $article, EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE);

        $result = $this->service->incoming($article);

        $this->assertCount(2, $result);
        $resultIds = $result->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$concept->id, $entity->id])->sort()->values()->all(), $resultIds);
    }

    // =========================================================================
    // Customer scoping
    // =========================================================================

    public function test_outgoing_is_scoped_to_customer(): void
    {
        $customer1 = $this->createCustomer('Customer One');
        $customer2 = $this->createCustomer('Customer Two');

        $article1 = $this->createPage($customer1, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article C1');
        $summary1 = $this->createPage($customer1, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary C1');
        $this->createLink($customer1, $article1, $summary1, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

        // Customer 2 has a page with the same type but different customer
        $article2 = $this->createPage($customer2, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article C2');

        $result = $this->service->outgoing($article2);

        $this->assertCount(0, $result);
    }

    public function test_incoming_is_scoped_to_customer(): void
    {
        $customer1 = $this->createCustomer('Customer One');
        $customer2 = $this->createCustomer('Customer Two');

        $article1 = $this->createPage($customer1, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article C1');
        $concept1 = $this->createPage($customer1, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept C1');
        $this->createLink($customer1, $concept1, $article1, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE);

        $article2 = $this->createPage($customer2, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article C2');

        $result = $this->service->incoming($article2);

        $this->assertCount(0, $result);
    }

    // =========================================================================
    // Related articles / concepts / entities
    // =========================================================================

    public function test_related_articles_for_concept(): void
    {
        $customer = $this->createCustomer();
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->createLink($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE);
        // Add a non-article link to verify filtering
        $this->createLink($customer, $concept, $summary, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY);

        $result = $this->service->relatedArticles($concept);

        $this->assertCount(1, $result);
        $this->assertSame($article->id, $result->first()->id);
    }

    public function test_related_articles_for_entity(): void
    {
        $customer = $this->createCustomer();
        $entity   = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entity');
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');

        $this->createLink($customer, $entity, $article, EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE);

        $result = $this->service->relatedArticles($entity);

        $this->assertCount(1, $result);
        $this->assertSame($article->id, $result->first()->id);
    }

    public function test_related_concepts_for_article(): void
    {
        $customer = $this->createCustomer();
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');
        $entity   = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entity');

        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);
        // Add an entity link to verify filtering
        $this->createLink($customer, $article, $entity, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY);

        $result = $this->service->relatedConcepts($article);

        $this->assertCount(1, $result);
        $this->assertSame($concept->id, $result->first()->id);
    }

    public function test_related_entities_for_article(): void
    {
        $customer = $this->createCustomer();
        $article  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $entity   = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entity');
        $concept  = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');

        $this->createLink($customer, $article, $entity, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY);
        // Add a concept link to verify filtering
        $this->createLink($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT);

        $result = $this->service->relatedEntities($article);

        $this->assertCount(1, $result);
        $this->assertSame($entity->id, $result->first()->id);
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

    private function createLink(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id'  => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id'   => $to->id,
            'link_type'    => $linkType,
            'source'       => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence'   => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }
}
