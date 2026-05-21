<?php

namespace Tests\Unit\Services;

use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\Ai\Providers\OpenAiTextGenerationClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery;
use Tests\TestCase;

class OpenAiTextGenerationClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_binds_the_ai_text_generation_client_contract_to_the_openai_adapter(): void
    {
        /** @var AiTextGenerationClient $client */
        $client = $this->app->make(AiTextGenerationClient::class);

        $this->assertInstanceOf(OpenAiTextGenerationClient::class, $client);
    }

    public function test_it_delegates_text_generation_requests_to_the_openai_client(): void
    {
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->with([
                'model' => 'gpt-4.1-mini',
                'input' => [],
            ], 90)
            ->andReturn([
                'id' => 'resp_123',
            ]);

        $adapter = new OpenAiTextGenerationClient($openAiClient);

        $this->assertSame([
            'id' => 'resp_123',
        ], $adapter->createResponse([
            'model' => 'gpt-4.1-mini',
            'input' => [],
        ], 90));
    }
}
