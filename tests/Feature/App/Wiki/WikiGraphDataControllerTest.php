<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8E-19: Enterprise Wiki graph data foundation.
 *
 * GET /app/wiki/graph-data returns a stable JSON payload:
 *   { nodes, edges, summary, scope }
 *
 * Scope priority: ?page_id → neighborhood | ?run_id → applied run | (none) → customer-wide.
 * When both are supplied, page_id wins.
 *
 * This endpoint is read-only: zero writes, no OpenAI.
 */
class WikiGraphDataControllerTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Authentication + access guard
    // =========================================================================

    public function test_guest_is_redirected_or_rejected(): void
    {
        $this->get('/app/wiki/graph-data')
            ->assertStatus(302);
    }

    // =========================================================================
    // Customer-wide scope — basic shape
    // =========================================================================

    public function test_customer_wide_response_has_required_top_level_keys(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $response->assertJsonStructure(['nodes', 'edges', 'summary', 'scope']);
    }

    public function test_customer_wide_scope_field_is_correct(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $response->assertJson([
            'scope' => ['type' => 'customer', 'run_id' => null, 'page_id' => null],
        ]);
    }

    public function test_customer_wide_returns_nodes_for_all_customer_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'Artikkel');
        $b = $this->createPage($customer, 'summary', 'Sammendrag');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($a->id));
        $this->assertTrue($nodeIds->contains($b->id));
    }

    public function test_customer_wide_returns_edges_for_customer_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'Artikkel');
        $b = $this->createPage($customer, 'summary', 'Sammendrag');
        $link = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $linkIds = collect($response->json('edges'))->pluck('link_id');
        $this->assertTrue($linkIds->contains($link->id));
    }

    public function test_empty_customer_returns_empty_nodes_and_edges_with_summary(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $this->assertSame([], $response->json('nodes'));
        $this->assertSame([], $response->json('edges'));
        $this->assertSame(0, $response->json('summary.node_count'));
        $this->assertSame(0, $response->json('summary.edge_count'));
    }

    // =========================================================================
    // Node payload shape
    // =========================================================================

    public function test_node_has_stable_id_and_expected_fields(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Artikkelside');
        $version = $this->createVersion($page, isCurrent: true);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertNotNull($node);
        $this->assertSame("page-{$page->id}", $node['id']);
        $this->assertSame($page->slug, $node['slug']);
        $this->assertSame($page->title, $node['title']);
        $this->assertSame('article', $node['page_type']);
        $this->assertSame("/app/wiki/{$page->slug}", $node['url']);
        $this->assertSame($version->id, $node['current_version_id']);
        $this->assertArrayHasKey('claim_count', $node);
        $this->assertArrayHasKey('source_reference_count', $node);
        $this->assertArrayHasKey('lint_error_count', $node);
        $this->assertArrayHasKey('lint_warning_count', $node);
        $this->assertArrayHasKey('status', $node);
    }

    public function test_node_current_version_id_is_null_when_no_version(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Ingen versjon');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertNull($node['current_version_id']);
    }

    public function test_node_claim_count_matches_actual_claims(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side med påstander');
        $version = $this->createVersion($page, isCurrent: true);
        $this->createClaim($page, $version, 'Påstand 1.');
        $this->createClaim($page, $version, 'Påstand 2.');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame(2, $node['claim_count']);
    }

    public function test_node_source_reference_count_matches_actual_refs(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side med kilder');
        $version = $this->createVersion($page, isCurrent: true);
        $claim = $this->createClaim($page, $version, 'Påstand med kilder.');
        $this->createSourceRef($claim, 'kilde-a.pdf');
        $this->createSourceRef($claim, 'kilde-b.pdf');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame(2, $node['source_reference_count']);
    }

    public function test_node_status_is_error_when_lint_errors_present(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Feilside');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame('error', $node['status']);
        $this->assertSame(1, $node['lint_error_count']);
    }

    public function test_node_status_is_warning_when_only_warnings_present(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Advarselsside');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame('warning', $node['status']);
        $this->assertSame(0, $node['lint_error_count']);
        $this->assertSame(1, $node['lint_warning_count']);
    }

    public function test_node_status_is_ok_when_no_lint_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Grønn side');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame('ok', $node['status']);
    }

    public function test_node_does_not_count_resolved_lint_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side med løste funn');
        $this->createLintFinding($customer, $page, EnterpriseWikiLintFinding::SEVERITY_ERROR, EnterpriseWikiLintFinding::STATUS_RESOLVED);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame('ok', $node['status']);
        $this->assertSame(0, $node['lint_error_count']);
    }

    // =========================================================================
    // Edge payload shape
    // =========================================================================

    public function test_edge_has_stable_id_and_expected_fields(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'A');
        $b = $this->createPage($customer, 'summary', 'B');
        $link = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $edge = collect($response->json('edges'))->firstWhere('link_id', $link->id);
        $this->assertNotNull($edge);
        $this->assertSame("link-{$link->id}", $edge['id']);
        $this->assertSame("page-{$a->id}", $edge['source']);
        $this->assertSame("page-{$b->id}", $edge['target']);
        $this->assertSame($a->id, $edge['from_page_id']);
        $this->assertSame($b->id, $edge['to_page_id']);
        $this->assertSame(EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, $edge['link_type']);
        $this->assertArrayHasKey('confidence', $edge);
    }

    // =========================================================================
    // What an edge says about the relation
    //
    // Grafvisning explains a line from this payload alone — no follow-up request on hover, and
    // nothing generated in the browser. What the domain knows about a wikilink is the two
    // endpoints, the direction, how the row was established, and the anchor the author actually
    // wrote. These tests pin that surface down in both directions: present when it exists, null
    // when it does not.
    // =========================================================================

    public function test_edge_carries_the_anchor_text_the_author_wrote(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'A');
        $b = $this->createPage($customer, 'concept', 'B');
        $link = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $link->update(['metadata' => ['anchor_text' => 'Masterdata for SOC']]);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $edge = collect($response->json('edges'))->firstWhere('link_id', $link->id);
        $this->assertSame('Masterdata for SOC', $edge['anchor_text']);
        $this->assertSame(EnterpriseWikiPageLink::SOURCE_DETERMINISTIC, $edge['origin']);
    }

    /**
     * Rows written before the anchor was recorded, and rows whose anchor is blank, must come back
     * as null rather than as an empty string — the UI hides the field, and "" would render a
     * labelled but empty line.
     */
    public function test_edge_reports_a_missing_anchor_as_null(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'A');
        $b = $this->createPage($customer, 'concept', 'B');
        $noMetadata = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $c = $this->createPage($customer, 'entity', 'C');
        $blank = $this->createPageLink($customer, $a, $c, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $blank->update(['metadata' => ['anchor_text' => '   ']]);

        $edges = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'));

        $this->assertNull($edges->firstWhere('link_id', $noMetadata->id)['anchor_text']);
        $this->assertNull($edges->firstWhere('link_id', $blank->id)['anchor_text']);
    }

    /**
     * The grounding a wikilink already carries and the graph never showed. The AI's page-content
     * contract requires a `reason` on every link intent, the materializer keeps the intent verbatim
     * on its block, and the block service persists link_intents in content_blocks_json — so "why is
     * this link here" has been recorded all along, one layer below the link row.
     */
    public function test_edge_carries_the_link_intent_recorded_on_the_source_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $soc = $this->createPage($customer, 'concept', 'Security Operations Center (SOC)');
        $hunting = $this->createPage($customer, 'concept', 'Threat Hunting');
        $link = $this->createPageLink($customer, $soc, $hunting, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $this->setCurrentVersionBlocks($soc, [[
            'block_key' => 'block-0009',
            'markdown' => 'Proaktiv [[threat-hunting|Threat Hunting]] beskriver praksis og formål utover hendelsesdrevet overvåking.',
            'link_intents' => [[
                'intent_id' => 'i1',
                'target_page_id' => $hunting->id,
                'anchor_text' => 'Threat Hunting',
                'reason' => 'Tilleggspraksis som utfyller SOC-overvåking.',
            ]],
        ]]);

        $edge = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'))
            ->firstWhere('link_id', $link->id);

        $this->assertCount(1, $edge['intents']);
        $this->assertSame('Tilleggspraksis som utfyller SOC-overvåking.', $edge['intents'][0]['reason']);
        $this->assertSame('Threat Hunting', $edge['intents'][0]['anchor_text']);

        // The context is prose, not markup: the reader is shown the sentence, not how it is stored.
        $this->assertStringNotContainsString('[[', $edge['intents'][0]['context']);
        $this->assertStringContainsString('Proaktiv Threat Hunting beskriver praksis', $edge['intents'][0]['context']);
    }

    /**
     * A block can be several sentences with the link in the last of them. Showing the opening words
     * would then present text that has nothing to do with the relation — worse than showing
     * nothing, because it reads as if it explains the link.
     */
    public function test_link_context_is_taken_from_around_the_link_not_the_start_of_the_block(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $from = $this->createPage($customer, 'article', 'Kilde');
        $to = $this->createPage($customer, 'concept', 'Mål');
        $link = $this->createPageLink($customer, $from, $to, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $filler = str_repeat('Innledende tekst som ikke handler om relasjonen. ', 12);

        $this->setCurrentVersionBlocks($from, [[
            'block_key' => 'block-0001',
            'markdown' => $filler.'Til slutt beskriver [[maal|Målsiden]] det vi faktisk er ute etter.',
            'link_intents' => [[
                'intent_id' => 'i1',
                'target_page_id' => $to->id,
                'anchor_text' => 'Målsiden',
                'reason' => 'Peker til siden som eier temaet.',
            ]],
        ]]);

        $context = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'))
            ->firstWhere('link_id', $link->id)['intents'][0]['context'];

        $this->assertStringContainsString('Målsiden', $context);
        $this->assertStringStartsWith('…', $context);
        $this->assertLessThanOrEqual(230, mb_strlen($context));
    }

    /**
     * A page may link the same target from more than one block, but the link table holds one row
     * per ordered pair — so the row alone cannot represent them. All of them travel on the edge.
     */
    public function test_several_intents_to_the_same_target_are_all_returned(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $from = $this->createPage($customer, 'article', 'ISO/IEC 27002');
        $to = $this->createPage($customer, 'entity', 'Nasjonal sikkerhetsmyndighet (NSM)');
        $link = $this->createPageLink($customer, $from, $to, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $this->setCurrentVersionBlocks($from, [
            [
                'block_key' => 'block-0001',
                'markdown' => 'Standarden nevner [[nsm|NSM]] som komplementær referanse.',
                'link_intents' => [['intent_id' => 'a', 'target_page_id' => $to->id, 'anchor_text' => 'NSM', 'reason' => 'Komplementær referanse.']],
            ],
            [
                'block_key' => 'block-0004',
                'markdown' => 'I SOC-arbeidet brukes [[nsm|NSM]] sammen med standarden.',
                'link_intents' => [['intent_id' => 'b', 'target_page_id' => $to->id, 'anchor_text' => 'NSM', 'reason' => 'Anvendes sammen med ISO/IEC 27002.']],
            ],
        ]);

        $edge = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'))
            ->firstWhere('link_id', $link->id);

        $this->assertSame(
            ['Komplementær referanse.', 'Anvendes sammen med ISO/IEC 27002.'],
            array_column($edge['intents'], 'reason'),
        );
    }

    /** A link with no recorded intent gets an empty list, never a fabricated one. */
    public function test_an_edge_without_link_intents_carries_an_empty_list(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $from = $this->createPage($customer, 'article', 'A');
        $to = $this->createPage($customer, 'concept', 'B');
        $link = $this->createPageLink($customer, $from, $to, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $this->setCurrentVersionBlocks($from, [[
            'block_key' => 'block-0001',
            'markdown' => 'Tekst uten registrerte lenkeintensjoner.',
            'link_intents' => [],
        ]]);

        $edge = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'))
            ->firstWhere('link_id', $link->id);

        $this->assertSame([], $edge['intents']);
    }

    /** An intent recorded without a reason yields no reason — the field is not filled in. */
    public function test_an_intent_without_a_reason_reports_null(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $from = $this->createPage($customer, 'article', 'A');
        $to = $this->createPage($customer, 'concept', 'B');
        $link = $this->createPageLink($customer, $from, $to, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $this->setCurrentVersionBlocks($from, [[
            'block_key' => 'block-0001',
            'markdown' => 'Se [[b|B]] for detaljer.',
            'link_intents' => [['intent_id' => 'a', 'target_page_id' => $to->id, 'anchor_text' => 'B', 'reason' => '   ']],
        ]]);

        $edge = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'))
            ->firstWhere('link_id', $link->id);

        $this->assertNull($edge['intents'][0]['reason']);
        $this->assertSame('B', $edge['intents'][0]['anchor_text']);
    }

    /**
     * Two pages that link to each other are two rows, and both must reach the client: the graph
     * draws them as one line, and the detail panel is what tells the user it stands for a mutual
     * cross-reference rather than a one-way citation.
     */
    public function test_both_directions_between_the_same_pages_are_returned(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'A');
        $b = $this->createPage($customer, 'concept', 'B');
        $forward = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $reverse = $this->createPageLink($customer, $b, $a, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $edges = collect($this->actingAs($user)->getJson('/app/wiki/graph-data')->json('edges'));

        $this->assertEqualsCanonicalizing(
            [$forward->id, $reverse->id],
            $edges->pluck('link_id')->all(),
        );
        $this->assertSame("page-{$a->id}", $edges->firstWhere('link_id', $forward->id)['source']);
        $this->assertSame("page-{$b->id}", $edges->firstWhere('link_id', $reverse->id)['source']);
    }

    // =========================================================================
    // Summary
    // =========================================================================

    public function test_summary_page_type_counts_are_correct(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $this->createPage($customer, 'article', 'Artikkel 1');
        $this->createPage($customer, 'article', 'Artikkel 2');
        $this->createPage($customer, 'summary', 'Sammendrag');
        $this->createPage($customer, 'concept', 'Konsept');
        $this->createPage($customer, 'entity', 'Entitet 1');
        $this->createPage($customer, 'entity', 'Entitet 2');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertSame(6, $summary['node_count']);
        $this->assertSame(2, $summary['article_count']);
        $this->assertSame(1, $summary['summary_count']);
        $this->assertSame(1, $summary['concept_count']);
        $this->assertSame(2, $summary['entity_count']);
    }

    public function test_summary_lint_counts_sum_across_all_nodes(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $p1 = $this->createPage($customer, 'article', 'Side 1');
        $p2 = $this->createPage($customer, 'summary', 'Side 2');
        $this->createLintFinding($customer, $p1, EnterpriseWikiLintFinding::SEVERITY_ERROR);
        $this->createLintFinding($customer, $p2, EnterpriseWikiLintFinding::SEVERITY_WARNING);
        $this->createLintFinding($customer, $p2, EnterpriseWikiLintFinding::SEVERITY_WARNING);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.lint_error_count'));
        $this->assertSame(2, $response->json('summary.lint_warning_count'));
    }

    public function test_summary_orphan_count_is_correct(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'Tilkoblet');
        $b = $this->createPage($customer, 'summary', 'Sammendrag');
        $orphan = $this->createPage($customer, 'concept', 'Isolert konsept');
        $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.orphan_count'));
    }

    // =========================================================================
    // Run-scoped graph (?run_id=)
    // =========================================================================

    public function test_run_scoped_graph_includes_only_pages_in_run_pivot(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $inRun = $this->createPage($customer, 'article', 'Med i kjøring');
        $notInRun = $this->createPage($customer, 'summary', 'Utenfor kjøring');
        $run = $this->createAppliedRun($customer, $inRun);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($inRun->id));
        $this->assertFalse($nodeIds->contains($notInRun->id));
    }

    public function test_run_scoped_graph_scope_field_includes_run_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side');
        $run = $this->createAppliedRun($customer, $page);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $response->assertJson([
            'scope' => ['type' => 'run', 'run_id' => $run->id, 'page_id' => null],
        ]);
    }

    public function test_run_scoped_graph_excludes_edges_with_endpoint_outside_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $inRun = $this->createPage($customer, 'article', 'Med i kjøring');
        $outside = $this->createPage($customer, 'summary', 'Utenfor kjøring');
        $run = $this->createAppliedRun($customer, $inRun);
        // Edge goes from inRun to outside — outside is not in run, so edge must be excluded
        $link = $this->createPageLink($customer, $inRun, $outside, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $linkIds = collect($response->json('edges'))->pluck('link_id');
        $this->assertFalse($linkIds->contains($link->id));
    }

    public function test_run_scoped_graph_includes_edges_where_both_endpoints_are_in_run(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $a = $this->createPage($customer, 'article', 'A');
        $b = $this->createPage($customer, 'summary', 'B');
        $run = $this->createAppliedRun($customer, $a, $b);
        $link = $this->createPageLink($customer, $a, $b, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $linkIds = collect($response->json('edges'))->pluck('link_id');
        $this->assertTrue($linkIds->contains($link->id));
    }

    public function test_non_applied_run_returns_422(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $pendingRun = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => str_pad('h', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$pendingRun->id);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_unknown_run_id_returns_422(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id=999999');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_run_from_other_customer_returns_422(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $foreignPage = $this->createPage($other, 'article', 'Fremmed side');
        $foreignRun = $this->createAppliedRun($other, $foreignPage);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$foreignRun->id);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Neighborhood scope (?page_id=)
    // =========================================================================

    public function test_neighborhood_includes_center_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$center->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($center->id));
    }

    public function test_neighborhood_includes_direct_outgoing_neighbors(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');
        $neighbor = $this->createPage($customer, 'summary', 'Nabo');
        $this->createPageLink($customer, $center, $neighbor, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$center->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($neighbor->id));
    }

    public function test_neighborhood_includes_direct_incoming_neighbors(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');
        $incoming = $this->createPage($customer, 'summary', 'Innkommende');
        $this->createPageLink($customer, $incoming, $center, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$center->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($incoming->id));
    }

    public function test_neighborhood_excludes_pages_beyond_one_hop(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');
        $neighbor = $this->createPage($customer, 'summary', 'Nabo');
        $distant = $this->createPage($customer, 'concept', 'Fjern side');
        $this->createPageLink($customer, $center, $neighbor, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $this->createPageLink($customer, $neighbor, $distant, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$center->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertFalse($nodeIds->contains($distant->id));
    }

    public function test_neighborhood_scope_field_includes_page_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$center->id);

        $response->assertOk();
        $response->assertJson([
            'scope' => ['type' => 'page', 'run_id' => null, 'page_id' => $center->id],
        ]);
    }

    public function test_unknown_page_id_returns_422(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id=999999');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_page_from_other_customer_returns_422(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $foreignPage = $this->createPage($other, 'article', 'Fremmed side');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$foreignPage->id);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Scope priority: page_id wins when both supplied
    // =========================================================================

    public function test_page_id_wins_when_both_run_id_and_page_id_supplied(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $center = $this->createPage($customer, 'article', 'Sentrum');
        $runPage = $this->createPage($customer, 'summary', 'Kun i kjøring');
        $run = $this->createAppliedRun($customer, $runPage);

        $response = $this->actingAs($user)->getJson(
            '/app/wiki/graph-data?run_id='.$run->id.'&page_id='.$center->id
        );

        $response->assertOk();
        $response->assertJson(['scope' => ['type' => 'page', 'page_id' => $center->id]]);
        // runPage must NOT appear — page-scope, not run-scope
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertFalse($nodeIds->contains($runPage->id));
    }

    // =========================================================================
    // Customer scoping (cross-customer isolation)
    // =========================================================================

    public function test_customer_wide_excludes_other_customer_pages(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $ownPage = $this->createPage($customer, 'article', 'Eigen side');
        $foreignPage = $this->createPage($other, 'article', 'Fremmed side');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($ownPage->id));
        $this->assertFalse($nodeIds->contains($foreignPage->id));
    }

    public function test_customer_wide_excludes_other_customer_edges(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $ownA = $this->createPage($customer, 'article', 'Eigen A');
        $ownB = $this->createPage($customer, 'summary', 'Eigen B');
        $foreignA = $this->createPage($other, 'article', 'Fremmed A');
        $foreignB = $this->createPage($other, 'summary', 'Fremmed B');
        $ownLink = $this->createPageLink($customer, $ownA, $ownB, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);
        $foreignLink = $this->createPageLink($other, $foreignA, $foreignB, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $linkIds = collect($response->json('edges'))->pluck('link_id');
        $this->assertTrue($linkIds->contains($ownLink->id));
        $this->assertFalse($linkIds->contains($foreignLink->id));
    }

    // =========================================================================
    // Status visibility — read access is not gated by status. Any authorized customer
    // Wiki viewer (including a plain Contributor) sees the same pages in the graph as
    // in the ordinary page list, regardless of draft/pending_review/approved/rejected.
    // =========================================================================

    public function test_customer_wide_includes_draft_page_for_contributor(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $approved = $this->createPage($customer, 'article', 'Godkjent side');
        $draft = $this->createPage($customer, 'article', 'Utkast side', EnterpriseWikiPage::STATUS_DRAFT);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($approved->id));
        $this->assertTrue($nodeIds->contains($draft->id));
    }

    public function test_customer_wide_includes_draft_page_for_system_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createSystemOwner($customer);
        $draft = $this->createPage($customer, 'article', 'Utkast side', EnterpriseWikiPage::STATUS_DRAFT);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($draft->id));
    }

    public function test_customer_wide_includes_edge_when_one_endpoint_is_a_draft(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $approved = $this->createPage($customer, 'article', 'Godkjent side');
        $draft = $this->createPage($customer, 'summary', 'Utkast side', EnterpriseWikiPage::STATUS_DRAFT);
        $link = $this->createPageLink($customer, $approved, $draft, EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $linkIds = collect($response->json('edges'))->pluck('link_id');
        $this->assertTrue($linkIds->contains($link->id));
    }

    public function test_run_scoped_graph_includes_draft_page_for_contributor(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $draft = $this->createPage($customer, 'article', 'Utkast side', EnterpriseWikiPage::STATUS_DRAFT);
        $run = $this->createAppliedRun($customer, $draft);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($draft->id));
    }

    public function test_neighborhood_includes_draft_center_page_for_contributor(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $draft = $this->createPage($customer, 'article', 'Utkast side', EnterpriseWikiPage::STATUS_DRAFT);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?page_id='.$draft->id);

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertTrue($nodeIds->contains($draft->id));
    }

    public function test_customer_wide_still_excludes_other_customer_draft_page(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $foreignDraft = $this->createPage($other, 'article', 'Fremmed utkast', EnterpriseWikiPage::STATUS_DRAFT);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $nodeIds = collect($response->json('nodes'))->pluck('page_id');
        $this->assertFalse($nodeIds->contains($foreignDraft->id));
    }

    // =========================================================================
    // Document provenance (graph filters — search/document/type/status)
    // =========================================================================

    public function test_page_attached_to_an_applied_run_has_the_documents_id_in_document_ids(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Artikkel med dokument');
        $run = $this->createAppliedRun($customer, $page);
        $document = EnterpriseWikiDocument::query()->findOrFail($run->source_id);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame([$document->id], $node['document_ids']);
    }

    public function test_top_level_documents_list_includes_id_and_human_readable_title(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Artikkel med dokument');
        $run = $this->createAppliedRun($customer, $page);
        $document = EnterpriseWikiDocument::query()->findOrFail($run->source_id);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $documents = collect($response->json('documents'));
        $this->assertCount(1, $documents);
        $this->assertSame($document->id, $documents->first()['id']);
        $this->assertSame($document->original_filename, $documents->first()['title']);
    }

    public function test_documents_list_only_includes_documents_referenced_by_at_least_one_node(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $linkedPage = $this->createPage($customer, 'article', 'Med dokument');
        $unlinkedPage = $this->createPage($customer, 'article', 'Uten dokument');
        $run = $this->createAppliedRun($customer, $linkedPage);
        $usedDocument = EnterpriseWikiDocument::query()->findOrFail($run->source_id);
        // A second document exists but is never attached to any page in this graph.
        $this->createDocument($customer);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $documentIds = collect($response->json('documents'))->pluck('id');
        $this->assertSame([$usedDocument->id], $documentIds->all());
        $unlinkedNode = collect($response->json('nodes'))->firstWhere('page_id', $unlinkedPage->id);
        $this->assertSame([], $unlinkedNode['document_ids']);
    }

    public function test_page_with_no_ingest_run_has_empty_document_ids(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'concept', 'Konsept uten kilde');

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame([], $node['document_ids']);
    }

    public function test_a_shared_concept_page_can_have_more_than_one_document_id(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $sharedConcept = $this->createPage($customer, 'concept', 'Delt konsept');
        $runA = $this->createAppliedRun($customer, $sharedConcept);
        $runB = $this->createAppliedRun($customer, $sharedConcept);
        $documentA = EnterpriseWikiDocument::query()->findOrFail($runA->source_id);
        $documentB = EnterpriseWikiDocument::query()->findOrFail($runB->source_id);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $sharedConcept->id);
        $this->assertEqualsCanonicalizing([$documentA->id, $documentB->id], $node['document_ids']);
        $this->assertCount(2, $response->json('documents'));
    }

    public function test_a_run_belonging_to_another_customer_never_leaks_its_document(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side hos egen kunde');
        $foreignDocument = $this->createDocument($other);
        $foreignRun = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $other->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $foreignDocument->id,
            'source_hash' => str_pad('h', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        // Simulates a stale/inconsistent pivot row pointing at a run from a different customer —
        // source_id/source_type carry no foreign key, so this must be defended against explicitly.
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $foreignRun->id,
            'enterprise_wiki_page_id' => $page->id,
        ]);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame([], $node['document_ids']);
        $this->assertSame([], $response->json('documents'));
    }

    public function test_run_scoped_graph_also_includes_document_provenance(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side i kjøring');
        $run = $this->createAppliedRun($customer, $page);
        $document = EnterpriseWikiDocument::query()->findOrFail($run->source_id);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data?run_id='.$run->id);

        $response->assertOk();
        $node = collect($response->json('nodes'))->firstWhere('page_id', $page->id);
        $this->assertSame([$document->id], $node['document_ids']);
    }

    // =========================================================================
    // Document owner provenance (graph owner filter)
    // =========================================================================

    public function test_document_owner_id_is_carried_on_the_documents_list(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $owner = $this->createNamedUser($customer, 'Kari Nordmann');
        $page = $this->createPage($customer, 'article', 'Side med eid dokument');
        $run = $this->createAppliedRunForOwner($customer, $owner, $page);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $document = collect($response->json('documents'))->firstWhere('id', $run->source_id);
        $this->assertSame($owner->id, $document['owner_user_id']);
    }

    public function test_owners_list_shows_the_owners_full_name(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $owner = $this->createNamedUser($customer, 'Kari Nordmann');
        $page = $this->createPage($customer, 'article', 'Side med eid dokument');
        $this->createAppliedRunForOwner($customer, $owner, $page);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $owners = collect($response->json('owners'));
        $this->assertCount(1, $owners);
        $this->assertSame($owner->id, $owners->first()['id']);
        $this->assertSame('Kari Nordmann', $owners->first()['name']);
    }

    public function test_owner_with_no_represented_documents_is_not_listed(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $ownerWithPage = $this->createNamedUser($customer, 'Kari Nordmann');
        $ownerWithoutPage = $this->createNamedUser($customer, 'Ola Hansen');
        $page = $this->createPage($customer, 'article', 'Side med eid dokument');
        $this->createAppliedRunForOwner($customer, $ownerWithPage, $page);
        // Ola owns a document, but it is never attached to any page in this graph.
        $this->createDocument($customer, $ownerWithoutPage->id);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $ownerIds = collect($response->json('owners'))->pluck('id');
        $this->assertTrue($ownerIds->contains($ownerWithPage->id));
        $this->assertFalse($ownerIds->contains($ownerWithoutPage->id));
    }

    public function test_a_user_from_another_customer_never_leaks_as_an_owner(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $user = $this->createUser($customer);
        $foreignUser = $this->createNamedUser($other, 'Fremmed Eier');
        $page = $this->createPage($customer, 'article', 'Side hos egen kunde');
        // Simulates a data inconsistency: a document belonging to this customer whose
        // owner_user_id happens to reference a user from a different customer.
        $document = $this->createDocument($customer, $foreignUser->id);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => str_pad('h', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
        ]);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $ownerIds = collect($response->json('owners'))->pluck('id');
        $this->assertFalse($ownerIds->contains($foreignUser->id));
        $document = collect($response->json('documents'))->first();
        $this->assertNull($document['owner_user_id']);
    }

    public function test_document_with_no_owner_has_null_owner_user_id_and_contributes_no_owner(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Side uten eier');
        $this->createAppliedRunForOwner($customer, null, $page);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $document = collect($response->json('documents'))->first();
        $this->assertNull($document['owner_user_id']);
        $this->assertSame([], $response->json('owners'));
    }

    public function test_page_with_documents_from_two_different_owners_is_associated_with_both(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $ownerA = $this->createNamedUser($customer, 'Kari Nordmann');
        $ownerB = $this->createNamedUser($customer, 'Ola Hansen');
        $sharedConcept = $this->createPage($customer, 'concept', 'Delt konsept');
        $runA = $this->createAppliedRunForOwner($customer, $ownerA, $sharedConcept);
        $runB = $this->createAppliedRunForOwner($customer, $ownerB, $sharedConcept);

        $response = $this->actingAs($user)->getJson('/app/wiki/graph-data');

        $response->assertOk();
        $documents = collect($response->json('documents'))->keyBy('id');
        $this->assertSame($ownerA->id, $documents[$runA->source_id]['owner_user_id']);
        $this->assertSame($ownerB->id, $documents[$runB->source_id]['owner_user_id']);
        $ownerIds = collect($response->json('owners'))->pluck('id');
        $this->assertTrue($ownerIds->contains($ownerA->id));
        $this->assertTrue($ownerIds->contains($ownerB->id));
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_endpoint_does_not_create_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiPage::query()->count();

        $this->actingAs($user)->getJson('/app/wiki/graph-data')->assertOk();

        $this->assertSame($before, EnterpriseWikiPage::query()->count());
    }

    public function test_endpoint_does_not_create_page_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiPageLink::query()->count();

        $this->actingAs($user)->getJson('/app/wiki/graph-data')->assertOk();

        $this->assertSame($before, EnterpriseWikiPageLink::query()->count());
    }

    public function test_endpoint_does_not_create_lint_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Testside');
        $before = EnterpriseWikiLintFinding::query()->count();

        $this->actingAs($user)->getJson('/app/wiki/graph-data')->assertOk();

        $this->assertSame($before, EnterpriseWikiLintFinding::query()->count());
    }

    public function test_endpoint_does_not_create_claims(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $page = $this->createPage($customer, 'article', 'Testside');
        $before = EnterpriseWikiClaim::query()->count();

        $this->actingAs($user)->getJson('/app/wiki/graph-data')->assertOk();

        $this->assertSame($before, EnterpriseWikiClaim::query()->count());
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

    /** A user with a specific, assertable display name — used as a document owner in tests. */
    private function createNamedUser(Customer $customer, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title, string $status = EnterpriseWikiPage::STATUS_APPROVED): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('h', 64, '0'),
        ]);
    }

    private function createSystemOwner(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Give a page a current version whose blocks carry the given link intents. Writing the blocks
     * directly keeps the test about what the GRAPH does with them; the pipeline that produces them
     * is covered where it lives.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function setCurrentVersionBlocks(EnterpriseWikiPage $page, array $blocks): void
    {
        $attributes = [
            'content_blocks_json' => $blocks,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
        ];

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        if ($version instanceof EnterpriseWikiPageVersion) {
            $version->update($attributes);

            return;
        }

        EnterpriseWikiPageVersion::query()->create($attributes + [
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
        ]);
    }

    private function createPageLink(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => $linkType,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }

    private function createVersion(EnterpriseWikiPage $page, bool $isCurrent = false): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => $isCurrent,
            'content_markdown' => '# '.$page->title,
        ]);
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $text,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => 0,
        ]);
    }

    private function createSourceRef(EnterpriseWikiClaim $claim, string $label): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => $label,
            'source_hash' => str_pad('h', 64, '0'),
            'excerpt' => 'Et utdrag.',
        ]);
    }

    private function createLintFinding(
        Customer $customer,
        EnterpriseWikiPage $page,
        string $severity,
        string $status = EnterpriseWikiLintFinding::STATUS_OPEN,
    ): EnterpriseWikiLintFinding {
        return EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => null,
            'enterprise_wiki_document_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => $severity,
            'message' => 'Testfunn',
            'status' => $status,
            'detected_at' => now(),
            'resolved_at' => $status === EnterpriseWikiLintFinding::STATUS_RESOLVED ? now() : null,
        ]);
    }

    private function createDocument(Customer $customer, ?int $ownerUserId = null): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'test-document.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'owner_user_id' => $ownerUserId,
        ]);
    }

    /** Creates an applied run for a document owned by $owner and registers the given pages. */
    private function createAppliedRunForOwner(Customer $customer, ?User $owner, EnterpriseWikiPage ...$pages): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer, $owner?->id);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => str_pad('h', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
            ]);
        }

        return $run;
    }

    /** Creates an applied maintainer decision run and registers the given pages in the pivot. */
    private function createAppliedRun(Customer $customer, EnterpriseWikiPage ...$pages): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => str_pad('h', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
            ]);
        }

        return $run;
    }
}
