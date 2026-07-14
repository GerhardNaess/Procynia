<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RequirementWikiAnswerAiClientTest extends TestCase
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
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
        $this->assertStringContainsString('REQUIREMENT TEXT: The vendor shall provide documentation.', data_get($payload, 'input.1.content.0.text'));
        $this->assertStringContainsString('[claim-1] Documentation is delivered within 10 days.', data_get($payload, 'input.1.content.0.text'));
    }

    public function test_throws_when_ai_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', [], 'no');
    }

    public function test_full_coverage_is_returned_verbatim(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_text' => 'Dokumentasjon leveres innen 10 dager.',
            'missing_summary' => null,
            'used_claim_keys' => ['claim-1'],
        ]);

        $result = $client->generateAnswer('1.1', 'text', [['claim_key' => 'claim-1', 'claim_text' => 'x']], 'no');

        $this->assertSame('full', $result['coverage_status']);
        $this->assertSame('Dokumentasjon leveres innen 10 dager.', $result['answer_text']);
        $this->assertNull($result['missing_summary']);
        $this->assertSame(['claim-1'], $result['used_claim_keys']);
    }

    /**
     * Never trust the model's own answer_text when it reports coverage_status 'none' — this is
     * the core anti-fabrication guarantee (task requirement 6).
     */
    public function test_none_coverage_forces_answer_text_to_null_even_if_the_model_provided_one(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'none',
            'answer_text' => 'Jeg gjetter at svaret er ja.',
            'missing_summary' => null,
            'used_claim_keys' => [],
        ]);

        $result = $client->generateAnswer('1.1', 'text', [['claim_key' => 'claim-1', 'claim_text' => 'x']], 'no');

        $this->assertSame('none', $result['coverage_status']);
        $this->assertNull($result['answer_text']);
    }

    public function test_used_claim_keys_are_filtered_to_known_candidates_only(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'partial',
            'answer_text' => 'Delvis svar.',
            'missing_summary' => 'Mangler frist.',
            'used_claim_keys' => ['claim-1', 'claim-999-hallucinated'],
        ]);

        $result = $client->generateAnswer('1.1', 'text', [['claim_key' => 'claim-1', 'claim_text' => 'x']], 'no');

        $this->assertSame(['claim-1'], $result['used_claim_keys']);
    }

    public function test_throws_when_coverage_status_is_invalid(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'mostly',
            'answer_text' => null,
            'missing_summary' => null,
            'used_claim_keys' => [],
        ]);

        $this->expectException(RuntimeException::class);

        $client->generateAnswer('1.1', 'text', [], 'no');
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response([
                    'coverage_status' => 'none',
                    'answer_text' => null,
                    'missing_summary' => null,
                    'used_claim_keys' => [],
                ]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer(
            '1.1',
            'The vendor shall provide documentation.',
            [['claim_key' => 'claim-1', 'claim_text' => 'Documentation is delivered within 10 days.']],
            'no',
        );

        return $captured;
    }

    private function clientReturning(array $body): RequirementWikiAnswerAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiAnswerAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
