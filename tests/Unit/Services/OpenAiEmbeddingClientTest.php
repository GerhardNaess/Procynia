<?php

namespace Tests\Unit\Services;

use App\Services\Ai\Contracts\AiEmbeddingClient;
use App\Services\Ai\Providers\OpenAiEmbeddingClient;
use App\Services\OpenAi\EmbeddingService;
use Mockery;
use Tests\TestCase;

class OpenAiEmbeddingClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_binds_the_ai_embedding_client_contract_to_the_openai_adapter(): void
    {
        /** @var AiEmbeddingClient $client */
        $client = $this->app->make(AiEmbeddingClient::class);

        $this->assertInstanceOf(OpenAiEmbeddingClient::class, $client);
    }

    public function test_it_delegates_embedding_requests_to_the_openai_embedding_service(): void
    {
        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('tryEmbedText')
            ->once()
            ->with('Procynia')
            ->andReturn([
                'ok' => true,
                'embedding' => [0.11, 0.22, 0.33],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'req_123',
                'response_body_excerpt' => null,
            ]);

        $adapter = new OpenAiEmbeddingClient($embeddingService);

        $this->assertSame([
            'ok' => true,
            'embedding' => [0.11, 0.22, 0.33],
            'model' => 'text-embedding-3-small',
            'usage' => [],
            'error_type' => null,
            'error_message' => null,
            'upstream_status' => 200,
            'request_id' => 'req_123',
            'response_body_excerpt' => null,
        ], $adapter->tryEmbedText('Procynia'));
    }
}
