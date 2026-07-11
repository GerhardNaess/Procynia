<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use Tests\TestCase;

class WikiPageClaimExtractionAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_payload_contract(): void
    {
        $payload = $this->capturePayload();

        $this->assertSame('gpt-4.1-mini', $payload['model']);
        $this->assertSame(2000, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('reasoning', $payload);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_returns_valid_claims_and_filters_malformed_elements(): void
    {
        $client = $this->clientReturning(['claims' => [
            ['text' => 'Gyldig.', 'confidence' => 'high', 'excerpt' => 'Gyldig.', 'conflict_note' => null],
            'not-an-array',
            ['text' => '', 'confidence' => 'high', 'excerpt' => '', 'conflict_note' => null],
            ['text' => 'Feil confidence.', 'confidence' => 'certain', 'excerpt' => 'x', 'conflict_note' => null],
        ]]);

        $result = $client->extractClaims('Side', 'article', 'Innhold', 'no');

        $this->assertCount(1, $result['claims']);
        $this->assertSame('Gyldig.', $result['claims'][0]['text']);
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['claims' => []]);
            });
        });

        app(WikiPageClaimExtractionAiClient::class)->extractClaims('Side', 'article', 'Innhold', 'no');

        return $captured;
    }

    private function clientReturning(array $body): WikiPageClaimExtractionAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiPageClaimExtractionAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
