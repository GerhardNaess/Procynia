<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WikiSemanticQaAiClientTest extends TestCase
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
        $this->assertSame(1500, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('reasoning', $payload);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_parses_valid_semantic_result(): void
    {
        $result = $this->clientReturning($this->validResult())->review('Kilde', '# Side', 'no');

        $this->assertTrue($result['pass']);
        $this->assertSame(0.9, $result['quality_score']);
    }

    #[DataProvider('invalidDomainProvider')]
    public function test_rejects_invalid_domain_types(array $overrides): void
    {
        $this->expectException(RuntimeException::class);

        $this->clientReturning(array_replace($this->validResult(), $overrides))->review('Kilde', '# Side', 'no');
    }

    public static function invalidDomainProvider(): array
    {
        return [
            'non boolean pass' => [['pass' => 1]],
            'score below zero' => [['quality_score' => -0.1]],
            'score above one' => [['confidence' => 1.1]],
            'list is scalar' => [['missing_topics' => 'none']],
            'list has non-string' => [['unsupported_claims' => [123]]],
            'critique is not string' => [['critique' => []]],
        ];
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response($this->validResult());
            });
        });
        app(WikiSemanticQaAiClient::class)->review('Kilde', '# Side', 'no');

        return $captured;
    }

    private function clientReturning(array $body): WikiSemanticQaAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiSemanticQaAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }

    private function validResult(): array
    {
        return [
            'pass' => true,
            'quality_score' => 0.9,
            'coverage_score' => 0.8,
            'factual_consistency_score' => 1.0,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => 'God dekning.',
            'recommended_repair_action' => 'none',
            'confidence' => 0.9,
        ];
    }
}
