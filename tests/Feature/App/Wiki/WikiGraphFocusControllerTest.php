<?php

namespace Tests\Feature\App\Wiki;

use App\Models\User;
use App\Services\EnterpriseWiki\GraphQuery\GraphQueryService;
use App\Services\EnterpriseWiki\GraphQuery\GraphSubgraph;
use App\Services\EnterpriseWiki\GraphQuery\NullGraphQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\FakeGraphQueryService;
use Tests\TestCase;

/**
 * GET /app/wiki/graph-focus — the narrow focus/subgraph endpoint.
 *
 * Input validation is the whole point of this layer: depth and relation type end up inside a
 * Cypher pattern, which has no parameter form for either.
 */
class WikiGraphFocusControllerTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $this->get('/app/wiki/graph-focus?page_id=1')->assertStatus(302);
    }

    public function test_it_returns_the_focus_neighbourhood(): void
    {
        $customer = $this->createWikiCustomer();
        $user = $this->createCustomerUser($customer->id);
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $neighbour = $this->createWikiPageWithVersion($customer, 'Neighbour', 'Text.');

        $this->bindGraph(new GraphSubgraph(
            [$this->graphNode($customer->id, $focus->id), $this->graphNode($customer->id, $neighbour->id)],
            [$this->graphEdge(1, $customer->id, $focus->id, $neighbour->id)],
        ));

        $response = $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}&depth=2&direction=outgoing");

        $response->assertOk();
        $response->assertJsonStructure(['available', 'focus', 'nodes', 'edges', 'scope', 'summary']);
        $response->assertJson([
            'available' => true,
            'scope' => [
                'type' => 'focus',
                'page_id' => $focus->id,
                'depth' => 2,
                'direction' => 'outgoing',
                'relation_types' => ['WIKILINK'],
            ],
            'summary' => ['node_count' => 2, 'edge_count' => 1],
        ]);
    }

    public function test_it_defaults_to_one_hop_in_both_directions(): void
    {
        $customer = $this->createWikiCustomer();
        $user = $this->createCustomerUser($customer->id);
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $this->bindGraph();

        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}")
            ->assertOk()
            ->assertJson(['scope' => ['depth' => 1, 'direction' => 'both']]);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $customer = $this->createWikiCustomer();
        $user = $this->createCustomerUser($customer->id);
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $this->bindGraph();

        $this->actingAs($user)->getJson('/app/wiki/graph-focus')->assertStatus(422);
        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}&depth=3")->assertStatus(422);
        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}&depth=0")->assertStatus(422);
        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}&direction=sideways")->assertStatus(422);
        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}&relation_types[]=MENTIONS")->assertStatus(422);
        $this->actingAs($user)->getJson('/app/wiki/graph-focus?page_id=abc')->assertStatus(422);
    }

    public function test_another_customers_page_is_not_a_valid_focus(): void
    {
        $customer = $this->createWikiCustomer('Customer A');
        $other = $this->createWikiCustomer('Customer B');
        $user = $this->createCustomerUser($customer->id);
        $foreign = $this->createWikiPageWithVersion($other, 'Foreign', 'Text.');
        $this->bindGraph();

        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$foreign->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => "Page [{$foreign->id}] not found or does not belong to this customer."]);
    }

    public function test_it_reports_unavailable_without_disturbing_the_sql_backed_graph(): void
    {
        $customer = $this->createWikiCustomer();
        $user = $this->createCustomerUser($customer->id);
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');

        $this->app->instance(GraphQueryService::class, new NullGraphQueryService);

        $this->actingAs($user)->getJson("/app/wiki/graph-focus?page_id={$focus->id}")
            ->assertStatus(503)
            ->assertJson(['available' => false]);

        // The pilot being off must cost nothing elsewhere: /graph-data reads PostgreSQL and is the
        // graph the UI actually renders today.
        $this->actingAs($user)->getJson('/app/wiki/graph-data')
            ->assertOk()
            ->assertJsonStructure(['nodes', 'edges', 'summary', 'scope']);
    }

    private function bindGraph(?GraphSubgraph $subgraph = null): FakeGraphQueryService
    {
        $fake = new FakeGraphQueryService($subgraph ?? new GraphSubgraph);
        $this->app->instance(GraphQueryService::class, $fake);

        return $fake;
    }

    private function createCustomerUser(int $customerId): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customerId,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function graphNode(int $customerId, int $pageId): array
    {
        return [
            'customer_id' => $customerId,
            'page_id' => $pageId,
            'slug' => "projected-{$pageId}",
            'title' => "Projected {$pageId}",
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => null,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function graphEdge(int $linkId, int $customerId, int $from, int $to): array
    {
        return [
            'link_id' => $linkId,
            'customer_id' => $customerId,
            'from_page_id' => $from,
            'to_page_id' => $to,
            'relation_type' => 'WIKILINK',
            'link_type' => 'wikilink',
            'source' => 'deterministic',
            'confidence' => 'certain',
            'from_page_version_id' => null,
            'to_page_version_id' => null,
            'metadata' => ['anchor_text' => 'Nabo'],
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }
}
