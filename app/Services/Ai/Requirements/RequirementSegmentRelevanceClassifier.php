<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Data\Ai\Requirements\DocumentRequirementSegmentRelevanceData;
use App\Models\SavedNoticeAiDocument;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementSegmentRelevanceClassifier
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly RequirementSegmentRelevancePromptBuilder $promptBuilder,
    ) {
    }

    /**
     * Purpose: Classify whether one source-preserving segment is relevant for requirement extraction.
     * Inputs: The source AI document, the segment, and an optional run id for tracing.
     * Returns: A structured relevance result with explicit success or failure metadata.
     * Side effects: Calls OpenAI once and emits observability logs.
     */
    public function classify(SavedNoticeAiDocument $document, DocumentRequirementSegmentData $segment, ?string $runId = null): DocumentRequirementSegmentRelevanceData
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $payload = $this->promptBuilder->buildRequestPayload($document, $segment);
        $model = (string) ($payload['model'] ?? '');

        Log::info('[PROCYNIA][AI_COST][RELEVANCE][PRE_OPENAI] Segment relevance OpenAI call starting.', $this->logContext(
            $document,
            $segment,
            $runId,
            [
                'openai_call_count' => 1,
                'model' => $model,
                'segment_text_length' => mb_strlen($segment->text, 'UTF-8'),
                'input_text_length' => mb_strlen($this->segmentInputText($payload), 'UTF-8'),
                'max_output_tokens' => $payload['max_output_tokens'] ?? null,
            ],
        ));

        try {
            $response = $this->openAiClient->post('responses', $payload, 90);
        } catch (ConnectionException $exception) {
            Log::warning('[PROCYNIA][AI_COST][RELEVANCE][POST_OPENAI] Segment relevance OpenAI call failed before a response was returned.', $this->logContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                        ? 'timeout'
                        : 'connection_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            return $this->failedResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                    ? 'timeout'
                    : 'connection_error',
                errorMessage: $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
            Log::warning('[PROCYNIA][AI_COST][RELEVANCE][POST_OPENAI] Segment relevance OpenAI call failed before a response was returned.', $this->logContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => 'connection_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            return $this->failedResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'connection_error',
                errorMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_COST][RELEVANCE][POST_OPENAI] Segment relevance OpenAI call failed before a response was returned.', $this->logContext(
                $document,
                $segment,
                $runId,
                [
                    'openai_call_count' => 1,
                    'model' => $model,
                    'request_id' => null,
                    'response_id' => null,
                    'status' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                    'error_type' => 'openai_error',
                    'error_message' => $exception->getMessage(),
                ],
            ));

            return $this->failedResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'openai_error',
                errorMessage: $exception->getMessage(),
            );
        }

        $requestId = $this->requestIdFrom($response);
        $responseId = $this->responseIdFrom($response);
        $status = $response->status();
        $rawResponseBody = trim($response->body());
        $tokenUsage = $this->tokenUsageFromResponse($response);

        Log::info('[PROCYNIA][AI_COST][RELEVANCE][POST_OPENAI] Segment relevance OpenAI call completed.', $this->logContext(
            $document,
            $segment,
            $runId,
            [
                'openai_call_count' => 1,
                'model' => $model,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'status' => $status,
                'input_tokens' => $tokenUsage['input_tokens'],
                'output_tokens' => $tokenUsage['output_tokens'],
                'total_tokens' => $tokenUsage['total_tokens'],
                'elapsed_ms' => $this->elapsedMs($startedAt),
            ],
        ));

        if (! $response->successful()) {
            return $this->failedResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                requestId: $requestId,
                responseId: $responseId,
                upstreamStatus: $status,
                rawOutput: $rawResponseBody,
                tokenUsage: $tokenUsage,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: $this->errorTypeForStatus($status),
                errorMessage: sprintf('OpenAI segment relevance request failed with HTTP status [%d].', $status),
            );
        }

        try {
            $decoded = $this->decodePayload($response->json());
            $validated = $this->validatePayload($decoded);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI][RELEVANCE] Segment relevance parsing failed.', [
                'run_id' => $runId,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
                'segment_id' => $segment->segmentId,
                'segment_index' => $segment->segmentIndex,
                'request_id' => $requestId,
                'response_id' => $responseId,
                'error' => $exception->getMessage(),
                'raw_response' => $rawResponseBody,
            ]);

            return $this->failedResult(
                document: $document,
                segment: $segment,
                runId: $runId,
                model: $model,
                requestId: $requestId,
                responseId: $responseId,
                upstreamStatus: $status,
                rawOutput: $rawResponseBody,
                tokenUsage: $tokenUsage,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'unexpected_response',
                errorMessage: $exception->getMessage(),
            );
        }

        return new DocumentRequirementSegmentRelevanceData(
            ok: true,
            savedNoticeId: $segment->savedNoticeId,
            savedNoticeAiDocumentId: $segment->savedNoticeAiDocumentId,
            savedNoticeAiDocumentChunkId: $segment->savedNoticeAiDocumentChunkId,
            documentTitle: $segment->documentTitle,
            documentFilename: $segment->documentFilename,
            segmentId: $segment->segmentId,
            segmentIndex: $segment->segmentIndex,
            isRelevant: (bool) $validated['is_relevant'],
            confidence: (float) $validated['confidence'],
            reason: trim((string) $validated['reason']),
            model: $model,
            requestId: $requestId,
            responseId: $responseId,
            upstreamStatus: $status,
            openAiCallCount: 1,
            elapsedMs: $this->elapsedMs($startedAt),
            inputTokens: $tokenUsage['input_tokens'],
            outputTokens: $tokenUsage['output_tokens'],
            totalTokens: $tokenUsage['total_tokens'],
            parseStrategy: 'json',
            rawOutput: $rawResponseBody,
            errorType: null,
            errorMessage: null,
            metadata: [
                'prompt_version' => $this->promptBuilder->promptVersion(),
            ],
        );
    }

    /**
     * Purpose: Build the failure payload for a relevance classification request.
     * Inputs: The source document, the segment, tracing metadata, and the error details.
     * Returns: A deterministic failure result that preserves the segment provenance.
     * Side effects: Emits a warning log.
     */
    private function failedResult(
        SavedNoticeAiDocument $document,
        DocumentRequirementSegmentData $segment,
        string $runId,
        string $model,
        ?string $requestId = null,
        ?string $responseId = null,
        ?int $upstreamStatus = null,
        ?string $rawOutput = null,
        array $tokenUsage = [],
        ?int $elapsedMs = null,
        string $errorType = 'unexpected_error',
        string $errorMessage = 'Segment relevance classification failed.',
    ): DocumentRequirementSegmentRelevanceData {
        Log::warning('[PROCYNIA][AI][RELEVANCE] Segment relevance classification failed.', [
            'run_id' => $runId,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
            'segment_id' => $segment->segmentId,
            'segment_index' => $segment->segmentIndex,
            'model' => $model,
            'request_id' => $requestId,
            'response_id' => $responseId,
            'upstream_status' => $upstreamStatus,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'raw_response' => $rawOutput,
        ]);

        return new DocumentRequirementSegmentRelevanceData(
            ok: false,
            savedNoticeId: $segment->savedNoticeId,
            savedNoticeAiDocumentId: $segment->savedNoticeAiDocumentId,
            savedNoticeAiDocumentChunkId: $segment->savedNoticeAiDocumentChunkId,
            documentTitle: $segment->documentTitle,
            documentFilename: $segment->documentFilename,
            segmentId: $segment->segmentId,
            segmentIndex: $segment->segmentIndex,
            isRelevant: false,
            confidence: 0.0,
            reason: $errorMessage,
            model: $model,
            requestId: $requestId,
            responseId: $responseId,
            upstreamStatus: $upstreamStatus,
            openAiCallCount: 1,
            elapsedMs: $elapsedMs,
            inputTokens: $tokenUsage['input_tokens'] ?? null,
            outputTokens: $tokenUsage['output_tokens'] ?? null,
            totalTokens: $tokenUsage['total_tokens'] ?? null,
            parseStrategy: 'error',
            rawOutput: $rawOutput,
            errorType: $errorType,
            errorMessage: $errorMessage,
            metadata: [
                'prompt_version' => $this->promptBuilder->promptVersion(),
            ],
        );
    }

    /**
     * Purpose: Build a consistent log context for relevance tracing.
     * Inputs: The AI document, the segment, the run id, and additional context fields.
     * Returns: A structured log context with stable identifiers.
     * Side effects: None.
     */
    private function logContext(
        SavedNoticeAiDocument $document,
        DocumentRequirementSegmentData $segment,
        string $runId,
        array $extra = [],
    ): array {
        return array_merge([
            'run_id' => $runId,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_id' => $document->id,
            'document_title' => $segment->documentTitle,
            'document_filename' => $segment->documentFilename,
            'saved_notice_ai_document_chunk_id' => $segment->savedNoticeAiDocumentChunkId,
            'segment_id' => $segment->segmentId,
            'segment_index' => $segment->segmentIndex,
        ], $extra);
    }

    /**
     * Purpose: Extract the request id from a response payload.
     * Inputs: The raw HTTP response from OpenAI.
     * Returns: The request id or null when absent.
     * Side effects: None.
     */
    private function requestIdFrom(Response $response): ?string
    {
        foreach (['x-request-id', 'x-openai-request-id', 'openai-request-id'] as $header) {
            $requestId = trim((string) $response->header($header));

            if ($requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }

    /**
     * Purpose: Extract the response id from a response payload.
     * Inputs: The raw HTTP response from OpenAI.
     * Returns: The response id or null when absent.
     * Side effects: None.
     */
    private function responseIdFrom(Response $response): ?string
    {
        $responseId = trim((string) data_get($response->json(), 'id', ''));

        return $responseId !== '' ? $responseId : null;
    }

    /**
     * Purpose: Extract token usage fields from an OpenAI response payload.
     * Inputs: The raw HTTP response from the OpenAI API.
     * Returns: Normalised input, output, and total token counts or null when absent.
     * Side effects: None.
     */
    private function tokenUsageFromResponse(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return $this->emptyTokenUsage();
        }

        return [
            'input_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.input_tokens')),
            'output_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.output_tokens')),
            'total_tokens' => $this->normalizeTokenCount(data_get($decoded, 'usage.total_tokens')),
        ];
    }

    /**
     * Purpose: Return the absence of token usage for error paths.
     * Inputs: None.
     * Returns: A token usage structure with null counts.
     * Side effects: None.
     */
    private function emptyTokenUsage(): array
    {
        return [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ];
    }

    /**
     * Purpose: Convert a raw usage count into a normalised integer or null.
     * Inputs: A token count from the OpenAI payload.
     * Returns: A bounded integer value or null when unusable.
     * Side effects: None.
     */
    private function normalizeTokenCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value) || is_string($value)) {
            $numeric = (int) $value;

            return $numeric >= 0 ? $numeric : null;
        }

        return null;
    }

    /**
     * Purpose: Parse the assistant text payload from a Responses API result.
     * Inputs: The raw OpenAI response payload.
     * Returns: The concatenated response text.
     * Side effects: None.
     */
    private function responseTextFromOpenAi(array $response): string
    {
        $topLevelText = trim((string) data_get($response, 'output_text', ''));

        if ($topLevelText !== '') {
            return $topLevelText;
        }

        $segments = [];
        $outputItems = data_get($response, 'output', []);

        if (! is_array($outputItems)) {
            return '';
        }

        foreach ($outputItems as $outputItem) {
            if (data_get($outputItem, 'type') !== 'message' || data_get($outputItem, 'role') !== 'assistant') {
                continue;
            }

            $contentItems = data_get($outputItem, 'content', []);

            if (! is_array($contentItems)) {
                continue;
            }

            foreach ($contentItems as $contentItem) {
                $contentType = data_get($contentItem, 'type');

                if ($contentType === 'refusal') {
                    throw new RuntimeException('OpenAI refused to return a relevance result.');
                }

                if (in_array($contentType, ['output_text', 'text'], true)) {
                    $segment = trim((string) data_get($contentItem, 'text', ''));

                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                }
            }
        }

        return trim(implode('', $segments));
    }

    /**
     * Purpose: Remove Markdown code fences if the model wrapped the JSON payload.
     * Inputs: Raw model text.
     * Returns: The cleaned text.
     * Side effects: None.
     */
    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Decode the JSON payload returned by OpenAI.
     * Inputs: The raw OpenAI response payload.
     * Returns: A decoded JSON object for the classifier.
     * Side effects: Throws when no usable JSON payload is present.
     */
    private function decodePayload(array $response): array
    {
        $text = $this->stripCodeFences($this->responseTextFromOpenAi($response));

        if ($text === '') {
            throw new RuntimeException('OpenAI relevance response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI relevance response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI relevance response did not decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * Purpose: Validate the classifier payload before the result is returned.
     * Inputs: The decoded OpenAI output.
     * Returns: A validated payload with the canonical relevance fields.
     * Side effects: Throws when the payload violates the contract.
     */
    private function validatePayload(array $payload): array
    {
        $validated = validator($payload, [
            'is_relevant' => ['required', 'boolean'],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ])->validate();

        return [
            'is_relevant' => (bool) $validated['is_relevant'],
            'confidence' => (float) $validated['confidence'],
            'reason' => trim((string) $validated['reason']),
        ];
    }

    /**
     * Purpose: Convert an HTTP failure status into a stable error type label.
     * Inputs: The upstream HTTP status code.
     * Returns: A short machine-readable error type.
     * Side effects: None.
     */
    private function errorTypeForStatus(int $status): string
    {
        return match (true) {
            $status === 408 => 'timeout',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'upstream_error',
            $status >= 400 => 'request_error',
            default => 'http_error',
        };
    }

    /**
     * Purpose: Derive the elapsed time in milliseconds from the given start time.
     * Inputs: The floating-point timestamp captured before the call started.
     * Returns: The elapsed time rounded to the nearest millisecond.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Purpose: Extract the exact user prompt text from a payload for logging calculations.
     * Inputs: The OpenAI request payload.
     * Returns: The first user text block when present, otherwise an empty string.
     * Side effects: None.
     */
    private function segmentInputText(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', data_get($payload, 'input.0.content.0.text', ''));
    }
}
