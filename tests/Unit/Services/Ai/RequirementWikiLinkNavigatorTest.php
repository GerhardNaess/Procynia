<?php

namespace Tests\Unit\Services\Ai;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Services\Ai\Wiki\RequirementWikiLinkNavigator;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class RequirementWikiLinkNavigatorTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_relevant_outgoing_wikilink_becomes_a_next_candidate(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Incident Management', 'Innhold om Incident Management.');
        $to = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');
        $this->createWikilink($customer, $from, $to);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, []);

        $this->assertCount(1, $discovered);
        $this->assertSame($to->id, $discovered[0]['page_id']);
        $this->assertSame('outgoing', $discovered[0]['link_direction']);
        $this->assertSame($from->id, $discovered[0]['discovered_from_page_id']);
        $this->assertSame($from->title, $discovered[0]['discovered_from_title']);
    }

    public function test_a_relevant_backlink_becomes_a_next_candidate(): void
    {
        $customer = $this->createWikiCustomer();
        $target = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');
        $backlinkingPage = $this->createWikiPageWithVersion($customer, 'Incident Management', 'Innhold om Incident Management.');
        $this->createWikilink($customer, $backlinkingPage, $target);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$target], $customer->id, []);

        $this->assertCount(1, $discovered);
        $this->assertSame($backlinkingPage->id, $discovered[0]['page_id']);
        $this->assertSame('incoming', $discovered[0]['link_direction']);
    }

    public function test_a_page_with_no_link_connection_is_not_discovered(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A.');
        $this->createWikiPageWithVersion($customer, 'Ikke tilkoblet side', 'Denne siden er ikke lenket til noe.');

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, []);

        $this->assertSame([], $discovered);
    }

    public function test_a_linked_draft_page_is_excluded(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A.');
        $draft = $this->createWikiPageWithVersion($customer, 'Kladd', 'Innhold i kladden.', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $this->createWikilink($customer, $from, $draft);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, []);

        $this->assertSame([], $discovered);
    }

    public function test_a_linked_page_from_another_customer_is_excluded(): void
    {
        $customerA = $this->createWikiCustomer('Customer A');
        $customerB = $this->createWikiCustomer('Customer B');
        $from = $this->createWikiPageWithVersion($customerA, 'Side A', 'Innhold A.');
        $otherCustomerPage = $this->createWikiPageWithVersion($customerB, 'Side hos B', 'Innhold hos B.');

        // A link row scoped to customer A but pointing at a page owned by customer B should never
        // occur in real data (link materialization is customer-scoped), but the navigator must
        // still defend against it rather than trust the link row's customer_id alone.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customerA->id,
            'from_page_id' => $from->id,
            'to_page_id' => $otherCustomerPage->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customerA->id, []);

        $this->assertSame([], $discovered);
    }

    public function test_the_same_page_reachable_via_multiple_links_is_deduplicated(): void
    {
        $customer = $this->createWikiCustomer();
        $fromA = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A.');
        $fromB = $this->createWikiPageWithVersion($customer, 'Side B', 'Innhold B.');
        $shared = $this->createWikiPageWithVersion($customer, 'Delt side', 'Innhold som begge lenker til.');
        $this->createWikilink($customer, $fromA, $shared);
        $this->createWikilink($customer, $fromB, $shared);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$fromA, $fromB], $customer->id, []);

        $this->assertCount(1, $discovered);
        $this->assertSame($shared->id, $discovered[0]['page_id']);
    }

    public function test_already_visited_pages_are_excluded_from_discovery(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A.');
        $alreadyRead = $this->createWikiPageWithVersion($customer, 'Allerede lest', 'Innhold allerede lest.');
        $this->createWikilink($customer, $from, $alreadyRead);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, [$alreadyRead->id]);

        $this->assertSame([], $discovered);
    }

    public function test_only_link_type_wikilink_rows_are_used_never_the_structural_combinatoric_types(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Artikkel', 'Innhold.', ['page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE]);
        $summary = $this->createWikiPageWithVersion($customer, 'Sammendrag', 'Innhold.', ['page_type' => EnterpriseWikiPage::PAGE_TYPE_SUMMARY]);

        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $summary->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, []);

        $this->assertSame([], $discovered);
    }

    public function test_discovery_order_is_deterministic_by_page_id(): void
    {
        $customer = $this->createWikiCustomer();
        $from = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A.');
        $second = $this->createWikiPageWithVersion($customer, 'Side C', 'Innhold C.');
        $first = $this->createWikiPageWithVersion($customer, 'Side B', 'Innhold B.');
        $this->createWikilink($customer, $from, $second);
        $this->createWikilink($customer, $from, $first);

        $discovered = app(RequirementWikiLinkNavigator::class)->discoverNeighbors([$from], $customer->id, []);

        $expectedOrder = [$first->id, $second->id];
        sort($expectedOrder);
        $this->assertSame($expectedOrder, array_column($discovered, 'page_id'));
    }
}
