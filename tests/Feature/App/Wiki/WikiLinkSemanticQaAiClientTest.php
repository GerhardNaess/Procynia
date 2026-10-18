<?php

namespace Tests\Feature\App\Wiki;

use App\Services\Ai\Wiki\WikiLinkSemanticQaAiClient;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Plan §18 (P0-3): run 962 lost the semantic link review of two pages because the model suggested
 * "deteksjonsgap" and "responsgap" for a page whose slug is "deteksjonsgap-og-responsgap", and the
 * client rejected the whole answer. A target outside the catalog is now dropped on its own.
 */
class WikiLinkSemanticQaAiClientTest extends TestCase
{
    private const CATALOG = [
        ['slug' => 'deteksjonsgap-og-responsgap', 'title' => 'Deteksjonsgap og responsgap', 'page_type' => 'concept'],
        ['slug' => 'mtta-mean-time-to-acknowledge', 'title' => 'MTTA', 'page_type' => 'concept'],
    ];

    public function test_an_invented_slug_is_dropped_and_the_valid_suggestions_are_kept(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->once()->andReturn($this->response([
            'assessment' => 'repair_recommended',
            'missing_link_slugs' => ['deteksjonsgap', 'mtta-mean-time-to-acknowledge', 'responsgap'],
            'remove_link_slugs' => [],
            'critique' => 'Lenk til MTTA.',
        ]));

        $result = app(WikiLinkSemanticQaAiClient::class)->review('# Side', 'concept', self::CATALOG, 'no');

        $this->assertSame('repair_recommended', $result['assessment']);
        $this->assertSame(['mtta-mean-time-to-acknowledge'], $result['missing_link_slugs']);
        $this->assertSame(['deteksjonsgap', 'responsgap'], $result['rejected_missing_link_slugs']);
    }

    public function test_only_invented_slugs_leave_nothing_to_change_rather_than_an_error(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->once()->andReturn($this->response([
            'assessment' => 'repair_recommended',
            'missing_link_slugs' => ['hendelsesrapport'],
            'remove_link_slugs' => [],
            'critique' => 'Lenk til hendelsesrapport.',
        ]));

        $result = app(WikiLinkSemanticQaAiClient::class)->review('# Side', 'concept', self::CATALOG, 'no');

        $this->assertSame([], $result['missing_link_slugs']);
        $this->assertSame(['hendelsesrapport'], $result['rejected_missing_link_slugs']);
    }

    public function test_a_malformed_answer_is_still_a_real_error(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        $this->mock(OpenAiClient::class)->shouldReceive('createResponse')->once()->andReturn($this->response([
            'assessment' => 'maybe',
            'missing_link_slugs' => [],
            'remove_link_slugs' => [],
            'critique' => '',
        ]));

        $this->expectException(RuntimeException::class);

        app(WikiLinkSemanticQaAiClient::class)->review('# Side', 'concept', self::CATALOG, 'no');
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
