<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiClaimVerificationAiClientTest extends TestCase
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
        $this->assertSame(500, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('reasoning', $payload);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_requires_actual_boolean_supported(): void
    {
        $this->expectException(RuntimeException::class);

        $this->clientReturning(['supported' => 1, 'excerpt' => 'Kilde'])
            ->verifyClaim('Påstand', 'Kildetekst', 'no');
    }

    public function test_accepts_null_excerpt_and_normalizes_it_to_empty_string(): void
    {
        $result = $this->clientReturning(['supported' => false, 'excerpt' => null])
            ->verifyClaim('Påstand', 'Kildetekst', 'no');

        $this->assertSame(['supported' => false, 'excerpt' => ''], $result);
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['supported' => true, 'excerpt' => 'Kilde']);
            });
        });
        app(WikiClaimVerificationAiClient::class)->verifyClaim('Påstand', 'Kilde', 'no');

        return $captured;
    }

    private function clientReturning(array $body): WikiClaimVerificationAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiClaimVerificationAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
