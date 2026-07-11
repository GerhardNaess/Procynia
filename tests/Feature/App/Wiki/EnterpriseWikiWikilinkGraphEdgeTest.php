<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8I-1/8I-2: confirms GET /app/wiki/graph-data (8E-19) surfaces materialized
 * wikilinks as real edges, and — per the v0.6 correction — never surfaces the legacy
 * combinatoric link_type rows built by EnterpriseWikiBuildPageLinksService::build(),
 * since EnterpriseWikiGraphDataService now filters every link query to
 * link_type = EnterpriseWikiPageLink::LINK_TYPE_WIKILINK.
 */
class EnterpriseWikiWikilinkGraphEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_materialized_wikilink_produces_a_real_graph_edge(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case');

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($source);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();

        $edges = collect($response->json('edges'));
        $matching = $edges->firstWhere(fn (array $edge) => $edge['from_page_id'] === $source->id && $edge['to_page_id'] === $target->id);

        $this->assertNotNull($matching, 'Expected a graph edge for the materialized wikilink.');
        $this->assertSame(EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, $matching['link_type']);
    }

    public function test_graph_data_ignores_existing_combinatoric_link_rows(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $article = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $summary = $this->createPageWithVersion($customer, 'sammendrag', '# Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        // A legacy combinatoric row (from EnterpriseWikiBuildPageLinksService::build())
        // between article and summary — no inline wikilink corresponds to it.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $summary->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        // A real materialized wikilink between article and the concept page.
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($article);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $edges = collect($response->json('edges'));

        $this->assertSame(1, $edges->count());
        $this->assertSame($target->id, $edges->first()['to_page_id']);
        $this->assertSame(EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, $edges->first()['link_type']);

        $this->assertNull($edges->firstWhere(
            fn (array $edge) => $edge['link_type'] === EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
        ));
    }

    public function test_broken_wikilink_produces_no_edge(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[does-not-exist]].');

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($source);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.edge_count'));
        $this->assertEmpty($response->json('edges'));
    }

    public function test_wikilink_edge_is_scoped_to_the_customer(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $userB = $this->createUser($customerB);

        $sourceA = $this->createPageWithVersion($customerA, 'artikkel', 'Se [[business-case]].');
        $this->createPageWithVersion($customerA, 'business-case', '# Business case');

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($sourceA);

        // Customer B has no pages and no links — the edge materialized for customer A
        // must not leak into customer B's graph-data response.
        $response = $this->actingAs($userB)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.edge_count'));
        $this->assertEmpty($response->json('edges'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Testkunde AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPageWithVersion(
        Customer $customer,
        string $slug,
        string $markdown,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
