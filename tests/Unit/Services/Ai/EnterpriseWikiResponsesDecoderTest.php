<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseEmptyException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseFailedException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseIncompleteException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseInvalidJsonException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseMalformedException;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseRefusedException;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnterpriseWikiResponsesDecoderTest extends TestCase
{
    public function test_decodes_completed_nested_output_after_reasoning_item(): void
    {
        $response = $this->response(output: [
            ['type' => 'reasoning', 'summary' => []],
            $this->message([['type' => 'output_text', 'text' => '{"ok":true}']]),
        ]);

        $this->assertSame(['ok' => true], $this->decoder()->decode($response, 'test-operation'));
    }

    public function test_decodes_completed_top_level_compatibility_output(): void
    {
        $response = $this->response(output: [], outputText: '{"ok":true}');

        $this->assertSame(['ok' => true], $this->decoder()->decode($response, 'test-operation'));
    }

    public function test_concatenates_multiple_nested_text_segments_in_order(): void
    {
        $response = $this->response(output: [
            $this->message([
                ['type' => 'output_text', 'text' => '{"ok":'],
                ['type' => 'output_text', 'text' => 'true}'],
            ]),
        ]);

        $this->assertSame(['ok' => true], $this->decoder()->decode($response, 'test-operation'));
    }

    public function test_incomplete_max_tokens_is_distinguishable(): void
    {
        try {
            $this->decoder()->decode($this->response(
                status: 'incomplete',
                outputText: '{"partial":',
                extra: ['incomplete_details' => ['reason' => 'max_output_tokens']],
            ), 'test-operation');
            $this->fail('Expected incomplete response exception.');
        } catch (EnterpriseWikiResponseIncompleteException $exception) {
            $this->assertTrue($exception->reachedMaxOutputTokens());
            $this->assertSame('max_output_tokens', $exception->incompleteReason());
            $this->assertStringNotContainsString('{"partial":', $exception->getMessage());
        }
    }

    public function test_incomplete_other_reason_is_preserved(): void
    {
        try {
            $this->decoder()->decode($this->response(
                status: 'incomplete',
                extra: ['incomplete_details' => ['reason' => 'content_filter']],
            ), 'test-operation');
            $this->fail('Expected incomplete response exception.');
        } catch (EnterpriseWikiResponseIncompleteException $exception) {
            $this->assertFalse($exception->reachedMaxOutputTokens());
            $this->assertSame('content_filter', $exception->incompleteReason());
        }
    }

    public function test_failed_response_exposes_only_safe_error_fields(): void
    {
        $this->expectException(EnterpriseWikiResponseFailedException::class);
        $this->expectExceptionMessage('Error type: server_error.');

        $this->decoder()->decode($this->response(status: 'failed', extra: [
            'error' => ['type' => 'server_error', 'code' => 'internal', 'message' => 'sensitive'],
        ]), 'test-operation');
    }

    #[DataProvider('malformedStatusProvider')]
    public function test_rejects_missing_or_unknown_status(array $response): void
    {
        $this->expectException(EnterpriseWikiResponseMalformedException::class);

        $this->decoder()->decode($response, 'test-operation');
    }

    public static function malformedStatusProvider(): array
    {
        return [
            'missing' => [['id' => 'resp_test', 'output' => []]],
            'unknown' => [['id' => 'resp_test', 'status' => 'queued', 'output' => []]],
            'malformed' => [['id' => 'resp_test', 'status' => ['completed'], 'output' => []]],
        ];
    }

    public function test_refusal_is_rejected_before_accompanying_text_is_decoded(): void
    {
        $response = $this->response(output: [
            $this->message([
                ['type' => 'output_text', 'text' => '{"would":"otherwise decode"}'],
                ['type' => 'refusal', 'refusal' => 'sensitive refusal text'],
            ]),
        ]);

        try {
            $this->decoder()->decode($response, 'test-operation');
            $this->fail('Expected refusal exception.');
        } catch (EnterpriseWikiResponseRefusedException $exception) {
            $this->assertStringNotContainsString('sensitive refusal text', $exception->getMessage());
            $this->assertStringNotContainsString('would', $exception->getMessage());
        }
    }

    #[DataProvider('emptyOutputProvider')]
    public function test_rejects_empty_or_missing_output(array $response): void
    {
        $this->expectException(EnterpriseWikiResponseEmptyException::class);

        $this->decoder()->decode($response, 'test-operation');
    }

    public static function emptyOutputProvider(): array
    {
        return [
            'empty nested' => [[
                'id' => 'resp_test', 'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '   ']]]],
            ]],
            'missing output' => [['id' => 'resp_test', 'status' => 'completed']],
        ];
    }

    public function test_rejects_invalid_json_without_exposing_output(): void
    {
        try {
            $this->decoder()->decode($this->response(outputText: 'sensitive invalid output'), 'test-operation');
            $this->fail('Expected invalid JSON exception.');
        } catch (EnterpriseWikiResponseInvalidJsonException $exception) {
            $this->assertStringNotContainsString('sensitive invalid output', $exception->getMessage());
        }
    }

    #[DataProvider('malformedOutputProvider')]
    public function test_rejects_malformed_output_and_content(array $response): void
    {
        $this->expectException(EnterpriseWikiResponseMalformedException::class);

        $this->decoder()->decode($response, 'test-operation');
    }

    public static function malformedOutputProvider(): array
    {
        return [
            'output scalar' => [['id' => 'resp_test', 'status' => 'completed', 'output' => 'bad']],
            'output item scalar' => [['id' => 'resp_test', 'status' => 'completed', 'output' => ['bad']]],
            'content scalar' => [['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => 'bad']]]],
            'text scalar' => [['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => []]]]]]],
        ];
    }

    public function test_diagnostics_are_allowlisted_and_include_usage_and_shapes(): void
    {
        $response = $this->response(output: [
            ['type' => 'reasoning'],
            $this->message([['type' => 'output_text', 'text' => 'sensitive output']]),
        ], extra: [
            'prompt' => 'sensitive prompt',
            'raw_body' => 'sensitive raw body',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 40,
                'output_tokens_details' => ['reasoning_tokens' => 12],
                'total_tokens' => 140,
            ],
        ]);

        $diagnostics = $this->decoder()->diagnostics($response, 'test-operation');
        $serialized = json_encode($diagnostics);

        $this->assertSame(['reasoning', 'message'], $diagnostics['output_item_types']);
        $this->assertSame(['output_text'], $diagnostics['content_item_types']);
        $this->assertSame(1, $diagnostics['output_text_segments']);
        $this->assertSame(12, $diagnostics['reasoning_tokens']);
        $this->assertStringNotContainsString('sensitive output', $serialized);
        $this->assertStringNotContainsString('sensitive prompt', $serialized);
        $this->assertStringNotContainsString('sensitive raw body', $serialized);
    }

    public function test_rejection_log_does_not_contain_prompt_output_refusal_or_raw_body(): void
    {
        Log::spy();
        $response = $this->response(output: [
            $this->message([['type' => 'refusal', 'refusal' => 'secret refusal']]),
        ], extra: ['prompt' => 'secret prompt', 'raw_body' => 'secret body']);

        try {
            $this->decoder()->decode($response, 'test-operation');
        } catch (EnterpriseWikiResponseRefusedException) {
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $serialized = json_encode($context);

            return $message === '[PROCYNIA][ENTERPRISE_WIKI_RESPONSES] Response rejected.'
                && ! str_contains($serialized, 'secret refusal')
                && ! str_contains($serialized, 'secret prompt')
                && ! str_contains($serialized, 'secret body');
        });
    }

    private function decoder(): EnterpriseWikiResponsesDecoder
    {
        return app(EnterpriseWikiResponsesDecoder::class);
    }

    private function response(string $status = 'completed', array $output = [], string $outputText = '', array $extra = []): array
    {
        return array_replace_recursive([
            'id' => 'resp_test',
            'status' => $status,
            'output' => $output,
            'output_text' => $outputText,
            '_meta' => ['http_status' => 200],
        ], $extra);
    }

    private function message(array $content): array
    {
        return ['type' => 'message', 'role' => 'assistant', 'content' => $content];
    }
}
