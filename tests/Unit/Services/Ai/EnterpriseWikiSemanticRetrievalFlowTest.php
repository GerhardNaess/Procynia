<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Customer;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiQuestionAnswerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class EnterpriseWikiSemanticRetrievalFlowTest extends TestCase
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

    public function test_the_same_semantic_query_reaches_the_canonical_page_in_qa_and_requirement_research(): void
    {
        $customer = $this->createWikiCustomer();
        $canonical = $this->createWikiPageWithVersion(
            $customer,
            'Service Continuity Management',
            "# Service Continuity Management\n\nThis process defines recovery preparation, restoration priorities, and continuity ownership.",
        );
        $input = 'Explain the recovery approach after a serious disruption.';
        $requirement = $this->createRequirement($customer, $input);

        $this->app->instance(EnterpriseWikiSemanticSearchPlanAiClient::class, Mockery::mock(EnterpriseWikiSemanticSearchPlanAiClient::class, function (MockInterface $mock) use ($canonical): void {
            $mock->shouldReceive('planWikiReading')->twice()->andReturn($this->plan($canonical->id));
        }));
        $this->app->instance(WikiQuestionAnswerAiClient::class, Mockery::mock(WikiQuestionAnswerAiClient::class, function (MockInterface $mock) use ($canonical): void {
            $mock->shouldReceive('planRetrieval')->once()->andReturn([
                'question_understanding' => ['topic' => 'service continuity', 'question_scope' => 'domain_or_process', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'question_intent' => 'explain process'],
                'ranked_pages' => [[
                    'page_id' => $canonical->id,
                    'page_scope' => 'domain_or_process',
                    'entities' => [],
                    'services_or_systems' => [],
                    'is_general' => false,
                    'is_specific' => false,
                    'retrieval_fit' => 'primary',
                    'reason' => 'Canonical process page.',
                ]],
            ]);
            $mock->shouldReceive('answer')->once()->andReturnUsing(function (string $question, array $context) use ($canonical): array {
                $this->assertSame([$canonical->id], array_column($context, 'page_id'));

                return ['answer_status' => WikiQuestionAnswerAiClient::STATUS_ANSWERED, 'answer' => 'Recovery is governed by service continuity management.', 'citations' => []];
            });
        }));
        $this->app->instance(RequirementWikiResearchAiClient::class, Mockery::mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use ($canonical): void {
            $mock->shouldReceive('selectNextAction')->once()->andReturnUsing(function (string $identifier, string $text, array $candidates) use ($canonical): array {
                $this->assertContains($canonical->id, array_column($candidates, 'page_id'));

                return ['action' => 'read_pages', 'page_ids' => [$canonical->id], 'search_terms' => [], 'reason' => 'Canonical process page.'];
            });
        }));

        $answer = app(EnterpriseWikiQuestionAnswerService::class)->ask($input, $customer->id, ['approved'], 'en');
        $research = app(RequirementWikiResearchService::class)->research($requirement, $customer->id, 'en');

        $this->assertSame(WikiQuestionAnswerAiClient::STATUS_ANSWERED, $answer['answer_status']);
        $this->assertSame([$canonical->id], array_column($research['pages'], 'page_id'));
    }

    public function test_a_graph_neighbour_is_not_evidence_until_semantic_reranking_selects_it(): void
    {
        $customer = $this->createWikiCustomer();
        $seed = $this->createWikiPageWithVersion($customer, 'Release Governance', '# Release Governance'."\n\nRelease coordination is governed here.");
        $relevant = $this->createWikiPageWithVersion($customer, 'Deployment Practice', '# Deployment Practice'."\n\nDeployment validation is required before release.");
        $irrelevant = $this->createWikiPageWithVersion($customer, 'Office Coffee', '# Office Coffee'."\n\nCoffee machine cleaning happens weekly.");
        $this->createWikilink($customer, $seed, $relevant);
        $this->createWikilink($customer, $seed, $irrelevant);

        $this->app->instance(EnterpriseWikiSemanticSearchPlanAiClient::class, Mockery::mock(EnterpriseWikiSemanticSearchPlanAiClient::class, function (MockInterface $mock) use ($seed): void {
            $mock->shouldReceive('planWikiReading')->once()->andReturn($this->plan($seed->id));
        }));
        $this->app->instance(WikiQuestionAnswerAiClient::class, Mockery::mock(WikiQuestionAnswerAiClient::class, function (MockInterface $mock) use ($relevant, $irrelevant): void {
            $mock->shouldReceive('planRetrieval')->once()->andReturnUsing(function (string $question, array $candidates) use ($relevant, $irrelevant): array {
                $this->assertContains($irrelevant->id, array_column($candidates, 'page_id'));

                return [
                    'question_understanding' => ['topic' => 'release validation', 'question_scope' => 'domain_or_process', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'question_intent' => 'explain validation'],
                    'ranked_pages' => [[
                        'page_id' => $relevant->id,
                        'page_scope' => 'domain_or_process',
                        'entities' => [], 'services_or_systems' => [], 'is_general' => false, 'is_specific' => false,
                        'retrieval_fit' => 'primary', 'reason' => 'Supports release validation.',
                    ]],
                ];
            });
            $mock->shouldReceive('answer')->once()->andReturnUsing(function (string $question, array $context) use ($relevant, $irrelevant): array {
                $this->assertSame([$relevant->id], array_column($context, 'page_id'));
                $this->assertNotContains($irrelevant->id, array_column($context, 'page_id'));

                return ['answer_status' => WikiQuestionAnswerAiClient::STATUS_ANSWERED, 'answer' => 'Deployment validation is required.', 'citations' => []];
            });
        }));

        app(EnterpriseWikiQuestionAnswerService::class)->ask('How is release validation handled?', $customer->id, ['approved'], 'en');
    }

    private function plan(int $canonicalPageId): array
    {
        return [
            'query_understanding' => [
                'topic' => 'service continuity',
                'intent' => 'explain recovery approach',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'scope' => 'domain_or_process',
            ],
            'selected_pages' => [[
                'page_id' => $canonicalPageId,
                'intended_use' => 'primary_evidence',
                'reason' => 'Terminology bridge.',
            ]],
            'model' => 'stub/1.0',
        ];
    }

    private function createRequirement(Customer $customer, string $text): SavedNoticeAiRequirement
    {
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'SEMANTIC-FLOW-'.Str::random(8),
            'title' => 'Semantic retrieval test case',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/semantic-flow-test',
            'summary' => 'Short summary',
            'publication_date' => '2026-04-01 00:00:00',
            'deadline' => '2026-05-01 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_identifier' => '1.1',
            'requirement_text' => $text,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
