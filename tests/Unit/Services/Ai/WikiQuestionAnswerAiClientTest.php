<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiQuestionAnswerAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_retrieval_plan_payload_contract(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response($this->validRetrievalPlan([101]));
            });
        });

        app(WikiQuestionAnswerAiClient::class)->planRetrieval('How does service management work?', [$this->candidate(101)], 'en');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertSame(2200, $captured['max_output_tokens']);
        $this->assertFalse($captured['store']);
        $this->assertSame('json_schema', data_get($captured, 'text.format.type'));
        $this->assertTrue(data_get($captured, 'text.format.strict'));
        $this->assertSame('wiki_question_retrieval_plan', data_get($captured, 'text.format.name'));
        $this->assertSame('question_understanding', data_get($captured, 'text.format.schema.required.0'));
        $this->assertStringContainsString('Return only candidates useful for answering', $captured['input'][0]['content'][0]['text']);
        $this->assertStringContainsString('Returning no', $captured['input'][0]['content'][0]['text']);
    }

    public function test_retrieval_plan_allows_an_ordered_partial_selection(): void
    {
        $plan = $this->clientReturning($this->validRetrievalPlan([4, 2]))
            ->planRetrieval('How does service management work?', [
                $this->candidate(1),
                $this->candidate(2),
                $this->candidate(3),
                $this->candidate(4),
                $this->candidate(5),
            ], 'en');

        $this->assertSame([4, 2], array_column($plan['ranked_pages'], 'page_id'));
    }

    public function test_retrieval_plan_allows_an_empty_selection(): void
    {
        $plan = $this->clientReturning($this->validRetrievalPlan([]))
            ->planRetrieval('How does service management work?', [$this->candidate(101)], 'en');

        $this->assertSame([], $plan['ranked_pages']);
    }

    public function test_retrieval_plan_rejects_unknown_candidate_pages(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown candidate page_id [999]');

        $this->clientReturning($this->validRetrievalPlan([1, 999]))
            ->planRetrieval('How does service management work?', [$this->candidate(1)], 'en');
    }

    public function test_retrieval_plan_rejects_duplicate_candidate_pages(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate candidate page_id [2]');

        $this->clientReturning($this->validRetrievalPlan([2, 2]))
            ->planRetrieval('How does service management work?', [
                $this->candidate(1),
                $this->candidate(2),
                $this->candidate(3),
            ], 'en');
    }

    private function clientReturning(array $body): WikiQuestionAnswerAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiQuestionAnswerAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }

    private function candidate(int $pageId): array
    {
        return [
            'page_id' => $pageId,
            'title' => 'Service Management Framework',
            'page_type' => 'concept',
            'scope' => 'company',
            'headings' => ['Service Management Framework'],
            'excerpt' => 'The organisation uses a shared service management framework.',
            'outgoing_link_count' => 0,
            'backlink_count' => 0,
            'deterministic_score' => 42,
            'deterministic_signals' => [],
        ];
    }

    private function validRetrievalPlan(array $pageIds): array
    {
        return [
            'question_understanding' => [
                'topic' => 'service management',
                'question_scope' => 'customer_or_organisation_general',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'question_intent' => 'explain documented practice',
            ],
            'ranked_pages' => array_map(fn (int $pageId): array => [
                'page_id' => $pageId,
                'page_scope' => 'customer_or_organisation_general',
                'entities' => [],
                'services_or_systems' => [],
                'is_general' => true,
                'is_specific' => false,
                'retrieval_fit' => 'primary',
                'reason' => 'Matches the organisation-level question scope.',
            ], $pageIds),
        ];
    }
}
