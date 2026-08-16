<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class EnterpriseWikiSemanticSearchPlanAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_it_sends_a_bounded_structured_reading_plan_with_a_compact_wiki_index(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response($this->plan());
            });
        });

        app(EnterpriseWikiSemanticSearchPlanAiClient::class)->planWikiReading('Explain the operational recovery approach.', [$this->indexPage()], 'en');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertSame(1200, $captured['max_output_tokens']);
        $this->assertFalse($captured['store']);
        $this->assertSame('json_schema', data_get($captured, 'text.format.type'));
        $this->assertTrue(data_get($captured, 'text.format.strict'));
        $this->assertSame(8, data_get($captured, 'text.format.schema.properties.selected_pages.maxItems'));
        $this->assertStringContainsString('WIKI INDEX', $captured['input'][1]['content'][0]['text']);
        $this->assertStringContainsString('Operational Governance', $captured['input'][1]['content'][0]['text']);
        $this->assertStringNotContainsString('full page body', $captured['input'][1]['content'][0]['text']);
    }

    public function test_it_rejects_an_unknown_scope(): void
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($this->plan(['query_understanding' => array_merge($this->plan()['query_understanding'], ['scope' => 'everywhere'])]))));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scope was invalid');

        app(EnterpriseWikiSemanticSearchPlanAiClient::class)->planWikiReading('Input', [$this->indexPage()], 'no');
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }

    private function plan(array $overrides = []): array
    {
        return array_merge([
            'query_understanding' => [
                'topic' => 'operational recovery',
                'intent' => 'explain documented approach',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'scope' => 'domain_or_process',
            ],
            'selected_pages' => [[
                'page_id' => 101,
                'intended_use' => 'primary_evidence',
                'reason' => 'The index summary describes the requested concept.',
            ]],
        ], $overrides);
    }

    private function indexPage(): array
    {
        return [
            'page_id' => 101,
            'title' => 'Operational Governance',
            'page_type' => 'concept',
            'slug' => 'operational-governance',
            'scope' => 'company',
            'summary' => 'Defines decision rights and control principles.',
            'headings' => ['Purpose'],
            'outgoing_wiki_links' => [],
        ];
    }
}
