<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use Tests\TestCase;

class WikiSemanticReviserAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_payload_contract(): void
    {
        $payload = $this->capturePayload();

        $this->assertSame('gpt-5', $payload['model']);
        $this->assertSame(4000, $payload['max_output_tokens']);
        $this->assertSame('low', data_get($payload, 'reasoning.effort'));
        $this->assertFalse($payload['store']);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_returns_valid_revised_markdown(): void
    {
        $client = $this->clientReturning(['page' => ['markdown' => "# Side\n\nRevidert innhold."]]);

        $this->assertSame("# Side\n\nRevidert innhold.", $client->revise(
            'Kilde', '# Side', 'article', $this->diagnosis(), 'no',
        ));
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['page' => ['markdown' => "# Side\n\nRevidert."]]);
            });
        });
        app(WikiSemanticReviserAiClient::class)->revise('Kilde', '# Side', 'article', $this->diagnosis(), 'no');

        return $captured;
    }

    private function clientReturning(array $body): WikiSemanticReviserAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiSemanticReviserAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }

    private function diagnosis(): array
    {
        return [
            'critique' => 'Et tema mangler.',
            'missing_topics' => ['Tema'],
            'missing_key_facts' => [],
            'unsupported_claims' => [],
            'recommended_repair_action' => 'targeted_revision',
        ];
    }
}
