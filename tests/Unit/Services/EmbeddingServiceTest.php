<?php

namespace Tests\Unit\Services;

use App\Services\OpenAi\EmbeddingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function test_it_returns_embedding_vectors_and_usage_metadata(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'object' => 'embedding',
                        'index' => 0,
                        'embedding' => [0.1, 0.2, 0.3],
                    ],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => [
                    'input_tokens' => 5,
                    'total_tokens' => 5,
                ],
            ], 200),
        ]);

        $result = app(EmbeddingService::class)->embedText('Procynia');

        $this->assertSame([0.1, 0.2, 0.3], $result['embedding']);
        $this->assertSame('text-embedding-3-small', $result['model']);
        $this->assertSame([
            'input_tokens' => 5,
            'total_tokens' => 5,
        ], $result['usage']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/embeddings'
                && $request->method() === 'POST'
                && $request['model'] === 'text-embedding-3-small'
                && $request['input'] === 'Procynia';
        });
    }

    public function test_it_splits_large_embedding_inputs_into_multiple_parts_before_sending(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        $firstParagraph = str_repeat('Procynia embedding input paragraph alpha beta gamma delta epsilon ', 80);
        $secondParagraph = str_repeat('Procynia embedding input paragraph zeta eta theta iota kappa lambda ', 80);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'object' => 'embedding',
                        'index' => 0,
                        'embedding' => [1.0, 0.0, 0.0],
                    ],
                    [
                        'object' => 'embedding',
                        'index' => 1,
                        'embedding' => [0.0, 1.0, 0.0],
                    ],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => [
                    'input_tokens' => 10,
                    'total_tokens' => 10,
                ],
            ], 200),
        ]);

        $result = app(EmbeddingService::class)->tryEmbedText($firstParagraph."\n\n".$secondParagraph);

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $result['embedding']);
        $this->assertGreaterThan(0.0, $result['embedding'][0]);
        $this->assertGreaterThan(0.0, $result['embedding'][1]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($firstParagraph, $secondParagraph): bool {
            return $request->url() === 'https://api.openai.com/v1/embeddings'
                && $request->method() === 'POST'
                && $request['model'] === 'text-embedding-3-small'
                && is_array($request['input'])
                && count($request['input']) === 2
                && $request['input'][0] === trim($firstParagraph)
                && $request['input'][1] === trim($secondParagraph);
        });
    }

    public function test_it_returns_controlled_invalid_request_results_for_4xx_responses(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'message' => 'Bad request',
                ],
            ], 400, [
                'x-request-id' => 'req_400',
            ]),
        ]);

        $result = app(EmbeddingService::class)->tryEmbedText('Procynia');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_request', $result['error_type']);
        $this->assertSame(400, $result['upstream_status']);
        $this->assertSame('req_400', $result['request_id']);
        $this->assertStringContainsString('400', (string) $result['error_message']);
        $this->assertNotEmpty((string) $result['response_body_excerpt']);
    }

    public function test_it_returns_controlled_upstream_unavailable_results_for_5xx_responses(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'message' => 'Upstream unavailable',
                ],
            ], 503, [
                'x-request-id' => 'req_503',
            ]),
        ]);

        $result = app(EmbeddingService::class)->tryEmbedText('Procynia');

        $this->assertFalse($result['ok']);
        $this->assertSame('upstream_unavailable', $result['error_type']);
        $this->assertSame(503, $result['upstream_status']);
        $this->assertSame('req_503', $result['request_id']);
    }

    public function test_it_returns_controlled_timeout_results_for_connection_timeouts(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake(function (Request $request) {
            throw new ConnectionException('cURL error 28: Operation timed out after 60 seconds.');
        });

        $result = app(EmbeddingService::class)->tryEmbedText('Procynia');

        $this->assertFalse($result['ok']);
        $this->assertSame('timeout', $result['error_type']);
        $this->assertSame('cURL error 28: Operation timed out after 60 seconds.', $result['error_message']);
        $this->assertNull($result['upstream_status']);
        $this->assertNull($result['request_id']);
    }

    public function test_it_returns_controlled_connection_error_results_for_connection_failures(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake(function (Request $request) {
            throw new ConnectionException('cURL error 7: Failed to connect to api.openai.com port 443.');
        });

        $result = app(EmbeddingService::class)->tryEmbedText('Procynia');

        $this->assertFalse($result['ok']);
        $this->assertSame('connection_error', $result['error_type']);
        $this->assertSame('cURL error 7: Failed to connect to api.openai.com port 443.', $result['error_message']);
        $this->assertNull($result['upstream_status']);
        $this->assertNull($result['request_id']);
    }

    public function test_it_returns_controlled_unexpected_response_results_for_invalid_payloads(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'object' => 'list',
                'data' => [],
            ], 200, [
                'x-request-id' => 'req_200',
            ]),
        ]);

        $result = app(EmbeddingService::class)->tryEmbedText('Procynia');

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_response', $result['error_type']);
        $this->assertSame(200, $result['upstream_status']);
        $this->assertSame('req_200', $result['request_id']);
        $this->assertStringContainsString('embedding data', (string) $result['error_message']);
    }
}
