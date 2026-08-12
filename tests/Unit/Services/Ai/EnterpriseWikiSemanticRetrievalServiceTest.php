<?php

namespace Tests\Unit\Services\Ai;

use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticRetrievalService;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class EnterpriseWikiSemanticRetrievalServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());
        parent::tearDown();
    }

    public function test_a_score_zero_page_can_be_selected_from_the_wiki_index(): void
    {
        $customer = $this->createWikiCustomer();
        $canonical = $this->createWikiPageWithVersion(
            $customer,
            'Operational Governance',
            "# Operational Governance\n\nDefines decision rights, controls, and accountability.",
            ['page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT],
        );

        $this->mockPlan(fn (array $index): array => $this->plan([$canonical->id]));
        $result = app(EnterpriseWikiSemanticRetrievalService::class)->retrieve('How do we keep leadership decisions orderly?', $customer->id, 'en');

        $seed = $result['telemetry']['selected_seed_pages'][0];
        $this->assertSame($canonical->id, $seed['page_id']);
        $this->assertSame(0, $seed['lexical_score']);
        $this->assertContains($canonical->id, array_column($result['candidate_pool'], 'page_id'));
        $this->assertSame(1, $result['telemetry']['wiki_index_page_count']);
        $this->assertGreaterThan(0, $result['telemetry']['wiki_index_chars']);
    }

    public function test_index_navigation_can_select_multiple_subprocesses_without_lexical_overlap(): void
    {
        $customer = $this->createWikiCustomer();
        $incident = $this->createWikiPageWithVersion($customer, 'Incident Process', '# Incident Process'."\n\nIncident response is documented here.");
        $change = $this->createWikiPageWithVersion($customer, 'Change Process', '# Change Process'."\n\nChange control is documented here.");
        $problem = $this->createWikiPageWithVersion($customer, 'Problem Process', '# Problem Process'."\n\nRoot cause analysis is documented here.");
        $serviceLevel = $this->createWikiPageWithVersion($customer, 'Service Level Process', '# Service Level Process'."\n\nService targets are documented here.");

        $this->mockPlan(fn (array $index): array => $this->plan([$incident->id, $change->id, $problem->id, $serviceLevel->id]));
        $result = app(EnterpriseWikiSemanticRetrievalService::class)->retrieve('Describe the operating framework.', $customer->id, 'en');

        $this->assertEqualsCanonicalizing([$incident->id, $change->id, $problem->id, $serviceLevel->id], array_column($result['candidate_pool'], 'page_id'));
    }

    public function test_index_contains_compact_metadata_and_limited_wikilinks(): void
    {
        $customer = $this->createWikiCustomer();
        $source = $this->createWikiPageWithVersion($customer, 'Release Governance', '# Release Governance'."\n\nCoordinates releases.");
        $target = $this->createWikiPageWithVersion($customer, 'Deployment Practice', '# Deployment Practice'."\n\nDeployment guidance.");
        $this->createWikilink($customer, $source, $target);

        $this->mockPlan(function (array $index) use ($source, $target): array {
            $sourceIndex = collect($index)->firstWhere('page_id', $source->id);
            $this->assertSame('Release Governance', $sourceIndex['title']);
            $this->assertSame('Coordinates releases.', $sourceIndex['summary']);
            $this->assertSame([['page_id' => $target->id, 'title' => 'Deployment Practice']], $sourceIndex['outgoing_wiki_links']);
            $this->assertArrayNotHasKey('content_markdown', $sourceIndex);

            return $this->plan([$source->id]);
        });

        app(EnterpriseWikiSemanticRetrievalService::class)->retrieve('How are releases coordinated?', $customer->id, 'en');
    }

    public function test_index_navigation_supports_abbreviations_and_multilingual_terms_without_synonym_rules(): void
    {
        $customer = $this->createWikiCustomer();
        $expanded = $this->createWikiPageWithVersion($customer, 'Identity and Access Management', '# Identity and Access Management'."\n\nControls identity lifecycle and access rights.");
        $abbreviated = $this->createWikiPageWithVersion($customer, 'CMDB', '# CMDB'."\n\nRecords service components.");

        $this->mockPlan(fn (array $index): array => $this->plan([$expanded->id, $abbreviated->id]));
        $result = app(EnterpriseWikiSemanticRetrievalService::class)->retrieve('Hvordan sikrer vi at bare autoriserte personer får tilgang og at CMDB er oppdatert?', $customer->id, 'no');

        $this->assertEqualsCanonicalizing([$expanded->id, $abbreviated->id], array_column($result['candidate_pool'], 'page_id'));
    }

    public function test_one_hop_graph_candidates_are_bounded_current_and_customer_scoped_but_not_automatic_evidence(): void
    {
        $customer = $this->createWikiCustomer();
        $otherCustomer = $this->createWikiCustomer('Other customer');
        $seed = $this->createWikiPageWithVersion($customer, 'Release Governance', '# Release Governance'."\n\nRelease coordination.");
        $relevant = $this->createWikiPageWithVersion($customer, 'Deployment Practice', '# Deployment Practice'."\n\nDeployment steps.");
        $irrelevant = $this->createWikiPageWithVersion($customer, 'Office Coffee', '# Office Coffee'."\n\nCoffee machine cleaning.");
        $stale = $this->createWikiPageWithVersion($customer, 'Retired Deployment', '# Retired Deployment'."\n\nOld steps.", ['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);
        $foreign = $this->createWikiPageWithVersion($otherCustomer, 'Foreign Deployment', '# Foreign Deployment'."\n\nConfidential.");
        $this->createWikilink($customer, $seed, $relevant);
        $this->createWikilink($customer, $seed, $irrelevant);
        $this->createWikilink($customer, $seed, $stale);
        $this->createWikilink($otherCustomer, $seed, $foreign);

        $this->mockPlan(fn (array $index): array => $this->plan([$seed->id], 'navigation_seed'));
        $result = app(EnterpriseWikiSemanticRetrievalService::class)->retrieve('How are releases coordinated?', $customer->id, 'en');

        $candidateIds = array_column($result['candidate_pool'], 'page_id');
        $this->assertContains($seed->id, $candidateIds);
        $this->assertContains($relevant->id, $candidateIds);
        $this->assertContains($irrelevant->id, $candidateIds, 'A graph neighbour remains a candidate for semantic reranking, not evidence.');
        $this->assertNotContains($stale->id, $candidateIds);
        $this->assertNotContains($foreign->id, $candidateIds);
        $this->assertLessThanOrEqual(EnterpriseWikiSemanticRetrievalService::MAX_GRAPH_CANDIDATES, count($result['telemetry']['traversed_graph_pages']));
    }

    private function mockPlan(callable $responder): void
    {
        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('planWikiReading')
            ->once()
            ->andReturnUsing(fn (string $input, array $index): array => $responder($index)));
    }

    private function plan(array $pageIds, string $intendedUse = 'primary_evidence'): array
    {
        return [
            'query_understanding' => [
                'topic' => 'operations',
                'intent' => 'find documented practice',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'scope' => 'domain_or_process',
            ],
            'selected_pages' => array_map(fn (int $pageId): array => [
                'page_id' => $pageId,
                'intended_use' => $intendedUse,
                'reason' => 'Selected from the compact Wiki index.',
            ], $pageIds),
            'model' => 'stub/1.0',
        ];
    }
}
