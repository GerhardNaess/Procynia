<?php

namespace Tests\Unit\Services;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    public function test_it_posts_to_the_responses_endpoint_with_the_configured_base_url(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.model' => 'gpt-4.1-mini',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_123',
                'object' => 'response',
                'output_text' => '{"ok":true}',
            ], 200),
        ]);

        $response = app(OpenAiClient::class)->createResponse([
            'model' => 'gpt-4.1-mini',
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'Hello',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('resp_123', $response['id']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->method() === 'POST'
                && $request['model'] === 'gpt-4.1-mini'
                && data_get($request['input'], '0.role') === 'user';
        });
    }

    public function test_it_throws_a_runtime_exception_when_the_api_key_is_missing(): void
    {
        config([
            'services.openai.api_key' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured.');

        app(OpenAiClient::class)->createResponse([
            'model' => 'gpt-4.1-mini',
            'input' => [],
        ]);
    }
}
