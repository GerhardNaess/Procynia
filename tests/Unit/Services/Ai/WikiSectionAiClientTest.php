<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiSectionAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiSectionAiClientTest extends TestCase
{
    // ─── isAvailable ──────────────────────────────────────────────────────────

    public function test_is_available_returns_false_by_default(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->assertFalse(WikiSectionAiClient::isAvailable());
    }

    public function test_is_available_returns_true_when_config_flag_is_enabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->assertTrue(WikiSectionAiClient::isAvailable());
    }

    // ─── Happy path ───────────────────────────────────────────────────────────

    public function test_fetch_claims_returns_parsed_claims_on_success(): void
    {
        $claims = [
            [
                'text' => 'Vi er ISO 9001-sertifisert.',
                'confidence' => 'high',
                'excerpt' => 'ISO 9001 siden 2015.',
                'conflict_note' => null,
            ],
        ];

        $client = $this->clientWithResponse(['claims' => $claims]);

        $result = $client->fetchClaims('Vi er ISO 9001-sertifisert.', null, 'no');

        $this->assertSame($claims, $result['claims']);
    }

    public function test_fetch_claims_returns_empty_claims_array_when_ai_finds_nothing(): void
    {
        $client = $this->clientWithResponse(['claims' => []]);

        $result = $client->fetchClaims('Ingen påstander her.', null, 'no');

        $this->assertSame([], $result['claims']);
    }

    // ─── Input trimming ───────────────────────────────────────────────────────

    public function test_section_text_exceeding_3000_characters_is_trimmed_in_payload(): void
    {
        $longText = str_repeat('A', 4000);
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return $this->fakeApiResponse(['claims' => []]);
            });

        $client = app(WikiSectionAiClient::class);
        $client->fetchClaims($longText, null, 'no');

        $userText = data_get($capturedPayload, 'input.1.content.0.text', '');
        $this->assertLessThanOrEqual(3000, mb_strlen($userText));
        $this->assertSame('gpt-4.1-mini', $capturedPayload['model']);
        $this->assertSame(2000, $capturedPayload['max_output_tokens']);
        $this->assertFalse($capturedPayload['store']);
        $this->assertArrayNotHasKey('reasoning', $capturedPayload);
        $this->assertSame('json_schema', data_get($capturedPayload, 'text.format.type'));
        $this->assertTrue(data_get($capturedPayload, 'text.format.strict'));
    }

    // ─── Claim count cap ─────────────────────────────────────────────────────

    public function test_at_most_15_claims_are_returned(): void
    {
        $sixteenClaims = [];

        for ($i = 0; $i < 16; $i++) {
            $sixteenClaims[] = [
                'text' => "Claim {$i}",
                'confidence' => 'medium',
                'excerpt' => "Excerpt {$i}",
                'conflict_note' => null,
            ];
        }

        $client = $this->clientWithResponse(['claims' => $sixteenClaims]);

        $result = $client->fetchClaims('Section text.', null, 'no');

        $this->assertCount(15, $result['claims']);
    }

    // ─── Excerpt trimming ────────────────────────────────────────────────────

    public function test_excerpt_exceeding_500_characters_is_trimmed(): void
    {
        $claims = [
            [
                'text' => 'Et krav.',
                'confidence' => 'high',
                'excerpt' => str_repeat('X', 600),
                'conflict_note' => null,
            ],
        ];

        $client = $this->clientWithResponse(['claims' => $claims]);

        $result = $client->fetchClaims('Et krav.', null, 'no');

        $this->assertSame(500, mb_strlen($result['claims'][0]['excerpt']));
    }

    // ─── Error handling ───────────────────────────────────────────────────────

    public function test_invalid_json_response_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/i');

        $client = $this->clientWithRawOutputText('this is not json {{');
        $client->fetchClaims('Some text.', null, 'no');
    }

    public function test_missing_claims_array_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/claims array/i');

        $client = $this->clientWithResponse(['other_key' => 'value']);
        $client->fetchClaims('Some text.', null, 'no');
    }

    public function test_api_error_propagates_as_runtime_exception(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andThrow(new RuntimeException('OpenAI API unavailable.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI API unavailable/');

        $client = app(WikiSectionAiClient::class);
        $client->fetchClaims('Some text.', null, 'no');
    }

    public function test_no_real_network_calls_are_made(): void
    {
        // If the real OpenAiClient were invoked without a configured API key it would throw.
        // A successful call here proves the mock was used instead of the real HTTP client.
        $client = $this->clientWithResponse(['claims' => []]);

        $result = $client->fetchClaims('Testsetning.', 'Overskrift', 'no');

        $this->assertArrayHasKey('claims', $result);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function clientWithResponse(array $responseBody): WikiSectionAiClient
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->fakeApiResponse($responseBody));

        return app(WikiSectionAiClient::class);
    }

    private function clientWithRawOutputText(string $rawText): WikiSectionAiClient
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn(['status' => 'completed', 'output_text' => $rawText, 'output' => [], '_meta' => []]);

        return app(WikiSectionAiClient::class);
    }

    private function fakeApiResponse(array $body): array
    {
        return [
            'id' => 'resp_test123',
            'status' => 'completed',
            'output_text' => json_encode($body),
            '_meta' => [
                'request_id' => null,
                'provider' => 'openai',
                'deployment_name' => null,
                'provider_region' => null,
            ],
        ];
    }
}
