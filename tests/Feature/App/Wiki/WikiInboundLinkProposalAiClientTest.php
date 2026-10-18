<?php

namespace Tests\Feature\App\Wiki;

use App\Services\Ai\Wiki\WikiInboundLinkProposalAiClient;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The relink v2 AI contract (D8): the model is shown whole blocks and returns a placement only.
 */
class WikiInboundLinkProposalAiClientTest extends TestCase
{
    public function test_the_request_carries_whole_blocks_and_a_strict_placement_only_schema(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $longBlock = str_repeat('Lang tekst om leverandører. ', 600).'Slutten av blokken.';
        $captured = null;
        $operation = null;

        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->once()
            ->andReturnUsing(function (array $payload, int $timeoutSeconds = 120, ?callable $onStats = null, ?string $op = null) use (&$captured, &$operation): array {
                $captured = $payload;
                $operation = $op;

                return $this->response(['decision' => 'link', 'block_key' => 'block-0002', 'anchor_text' => 'leverandører', 'reason' => 'Relevant.']);
            });

        $result = app(WikiInboundLinkProposalAiClient::class)->propose(
            ['title' => 'Leverandørvurdering', 'page_type' => 'article'],
            [['block_key' => 'block-0001', 'markdown' => '# Leverandørvurdering'], ['block_key' => 'block-0002', 'markdown' => $longBlock]],
            ['page_id' => 5, 'title' => 'Leverandørkontroll', 'page_type' => 'article', 'excerpt' => 'Årlig kontroll.'],
            'no',
        );

        $this->assertSame(['decision' => 'link', 'block_key' => 'block-0002', 'anchor_text' => 'leverandører', 'reason' => 'Relevant.'], $result);
        $this->assertSame(WikiInboundLinkProposalAiClient::OPERATION, $operation);
        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertTrue($captured['text']['format']['strict']);
        $schema = $captured['text']['format']['schema'];
        $this->assertSame(['decision', 'block_key', 'anchor_text', 'reason'], $schema['required']);
        $this->assertSame(['block-0001', 'block-0002', null], $schema['properties']['block_key']['enum']);
        $this->assertArrayNotHasKey('markdown', $schema['properties'], 'the model never returns page content');

        $userPrompt = $captured['input'][1]['content'][0]['text'];
        $this->assertStringContainsString($longBlock, $userPrompt, 'blocks are sent whole, never truncated');
        $this->assertStringContainsString('Årlig kontroll.', $userPrompt);
        $this->assertStringContainsString('Never write link syntax', $captured['input'][0]['content'][0]['text']);
    }

    public function test_a_response_without_a_valid_decision_is_a_real_error(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->once()->andReturn($this->response(['decision' => 'maybe', 'block_key' => null, 'anchor_text' => null, 'reason' => null]));

        $this->expectException(RuntimeException::class);

        app(WikiInboundLinkProposalAiClient::class)->propose(['title' => 'A', 'page_type' => 'article'], [['block_key' => 'b', 'markdown' => 'x']], ['page_id' => 1, 'title' => 'B', 'page_type' => 'article', 'excerpt' => null], 'no');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function response(array $body): array
    {
        return [
            'id' => 'resp_'.Str::lower(Str::random(6)),
            'status' => 'completed',
            'output_text' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'output' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            '_meta' => ['request_id' => 'req_test', 'http_status' => 200, 'provider' => 'openai'],
        ];
    }
}
