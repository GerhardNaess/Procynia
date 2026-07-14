<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RequirementWikiResearchAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    private function candidates(): array
    {
        return [
            ['page_id' => 101, 'title' => 'Problem Management', 'page_type' => 'concept', 'headings' => [], 'excerpt' => '', 'discovered_from_title' => null, 'link_direction' => null],
            ['page_id' => 102, 'title' => 'Incident Management', 'page_type' => 'concept', 'headings' => [], 'excerpt' => '', 'discovered_from_title' => null, 'link_direction' => null],
        ];
    }

    private function budget(): array
    {
        return ['round_number' => 1, 'remaining_rounds' => 3, 'remaining_pages' => 8, 'remaining_context_chars' => 24000];
    }

    public function test_payload_contract(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['action' => 'enough_context', 'page_ids' => [], 'search_terms' => [], 'reason' => 'ok']);
            });
        });

        app(RequirementWikiResearchAiClient::class)->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertFalse($captured['store']);
        $this->assertSame('json_schema', data_get($captured, 'text.format.type'));
        $this->assertTrue(data_get($captured, 'text.format.strict'));
    }

    public function test_throws_when_ai_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiResearchAiClient::class)->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');
    }

    public function test_read_pages_action_is_returned_with_valid_page_ids(): void
    {
        $client = $this->clientReturning(['action' => 'read_pages', 'page_ids' => [101], 'search_terms' => [], 'reason' => 'Relevant.']);

        $result = $client->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');

        $this->assertSame('read_pages', $result['action']);
        $this->assertSame([101], $result['page_ids']);
    }

    public function test_a_page_id_not_among_the_candidates_is_filtered_out(): void
    {
        $client = $this->clientReturning(['action' => 'read_pages', 'page_ids' => [101, 999], 'search_terms' => [], 'reason' => 'Relevant.']);

        $result = $client->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');

        $this->assertSame([101], $result['page_ids']);
    }

    public function test_search_terms_are_kept_only_for_the_search_more_action(): void
    {
        $client = $this->clientReturning(['action' => 'search_more', 'page_ids' => [101], 'search_terms' => ['endring'], 'reason' => 'Need more.']);

        $result = $client->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');

        $this->assertSame('search_more', $result['action']);
        $this->assertSame([], $result['page_ids']);
        $this->assertSame(['endring'], $result['search_terms']);
    }

    public function test_page_ids_are_cleared_for_non_read_pages_actions(): void
    {
        $client = $this->clientReturning(['action' => 'enough_context', 'page_ids' => [101], 'search_terms' => [], 'reason' => 'Done.']);

        $result = $client->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');

        $this->assertSame([], $result['page_ids']);
    }

    public function test_throws_when_action_is_invalid(): void
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response([
                'action' => 'read_more_maybe',
                'page_ids' => [],
                'search_terms' => [],
                'reason' => '',
            ])));

        $this->expectException(RuntimeException::class);

        app(RequirementWikiResearchAiClient::class)->selectNextAction('1.1', 'text', $this->candidates(), [], $this->budget(), 'no');
    }

    private function clientReturning(array $body): RequirementWikiResearchAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiResearchAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
