<?php

namespace Tests\Unit\Services;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_it_logs_the_full_raw_error_body_for_non_success_responses(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.model' => 'gpt-4.1-mini',
        ]);

        $errorBody = [
            'error' => [
                'message' => 'Invalid schema for response format: all properties must be required when strict is true.',
                'type' => 'invalid_request_error',
                'code' => 'invalid_json_schema',
                'param' => 'text.format.schema',
            ],
        ];

        Log::spy();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($errorBody, 400, [
                'x-request-id' => 'req_error_logging_test',
            ]),
        ]);

        $response = app(OpenAiClient::class)->post('responses', [
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

        $this->assertTrue($response->failed());

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($errorBody): bool {
            return $message === 'OpenAI request failed.'
                && ($context['endpoint'] ?? null) === 'responses'
                && ($context['status'] ?? null) === 400
                && ($context['request_id'] ?? null) === 'req_error_logging_test'
                && ($context['error_message'] ?? null) === data_get($errorBody, 'error.message')
                && ($context['error_type'] ?? null) === data_get($errorBody, 'error.type')
                && ($context['error_code'] ?? null) === data_get($errorBody, 'error.code')
                && ($context['error_param'] ?? null) === data_get($errorBody, 'error.param')
                && ($context['raw_body'] ?? null) === json_encode($errorBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }
}
