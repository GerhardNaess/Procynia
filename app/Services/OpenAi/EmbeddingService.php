<?php

namespace App\Services\OpenAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class EmbeddingService
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Generate one embedding vector for a piece of text.
     * Inputs: The source text to embed.
     * Returns: A compact embedding payload with the vector, model, and usage data.
     * Side effects: Calls OpenAI over HTTP.
     */
    public function embedText(string $text): array
    {
        $result = $this->tryEmbedText($text);

        if (! $result['ok']) {
            throw new RuntimeException((string) ($result['error_message'] ?? 'OpenAI embedding request failed.'));
        }

        return [
            'embedding' => $result['embedding'],
            'model' => $result['model'],
            'usage' => $result['usage'],
        ];
    }

    /**
     * Purpose: Generate one embedding vector and retain structured failure details.
     * Inputs: The source text to embed.
     * Returns: A controlled success/failure payload for retrieval and persistence flows.
     * Side effects: Calls OpenAI over HTTP.
     */
    public function tryEmbedText(string $text): array
    {
        $model = $this->embeddingModel();

        if ($model === '') {
            return $this->failureResult(
                'connection_error',
                'OpenAI embedding model is not configured.',
                null,
                null,
                null,
                $model,
                null,
            );
        }

        if (trim($text) === '') {
            return $this->failureResult(
                'invalid_request',
                'Embedding text is empty.',
                null,
                null,
                null,
                $model,
                null,
            );
        }

        try {
            $response = $this->openAiClient->post('embeddings', [
                'model' => $model,
                'input' => $text,
            ]);
        } catch (ConnectionException $exception) {
            $errorType = str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out')
                ? 'timeout'
                : 'connection_error';

            return $this->failureResult(
                $errorType,
                $exception->getMessage(),
                null,
                null,
                null,
                $model,
                null,
            );
        } catch (RuntimeException $exception) {
            return $this->failureResult(
                'connection_error',
                $exception->getMessage(),
                null,
                null,
                null,
                $model,
                null,
            );
        }

        $requestId = $this->requestIdFrom($response);
        $status = $response->status();
        $bodyExcerpt = Str::limit(trim($response->body()), 1000);

        if (! $response->successful()) {
            $errorType = $status >= 500 || $status === 429
                ? 'upstream_unavailable'
                : 'invalid_request';

            if ($status === 408) {
                $errorType = 'timeout';
            }

            return $this->failureResult(
                $errorType,
                sprintf('OpenAI embedding request failed with HTTP status [%d].', $status),
                $status,
                $requestId,
                $bodyExcerpt,
                $model,
                null,
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return $this->failureResult(
                'unexpected_response',
                'OpenAI embedding response was not valid JSON.',
                $status,
                $requestId,
                $bodyExcerpt,
                $model,
                null,
            );
        }

        $embedding = data_get($decoded, 'data.0.embedding');
        $normalizedEmbedding = $this->normalizeEmbedding($embedding);

        if ($normalizedEmbedding === null) {
            return $this->failureResult(
                'unexpected_response',
                'OpenAI embedding response did not include a valid vector.',
                $status,
                $requestId,
                $bodyExcerpt,
                $model,
                null,
            );
        }

        $usage = data_get($decoded, 'usage', []);

        if (! is_array($usage)) {
            $usage = [];
        }

        return [
            'ok' => true,
            'embedding' => $normalizedEmbedding,
            'model' => $model,
            'usage' => $usage,
            'error_type' => null,
            'error_message' => null,
            'upstream_status' => $status,
            'request_id' => $requestId,
            'response_body_excerpt' => null,
        ];
    }

    /**
     * Purpose: Normalize an embedding vector returned by the upstream API.
     * Inputs: The raw embedding payload from OpenAI.
     * Returns: A float vector or null when the payload is malformed.
     * Side effects: None.
     */
    private function normalizeEmbedding(mixed $embedding): ?array
    {
        if (! is_array($embedding) || $embedding === []) {
            return null;
        }

        $normalized = [];

        foreach ($embedding as $value) {
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                return null;
            }

            if (! is_numeric($value)) {
                return null;
            }

            $floatValue = (float) $value;

            if (! is_finite($floatValue)) {
                return null;
            }

            $normalized[] = $floatValue;
        }

        return $normalized === [] ? null : array_values($normalized);
    }

    /**
     * Purpose: Resolve the configured embedding model for outbound requests.
     * Inputs: None.
     * Returns: The configured model name or an empty string when unavailable.
     * Side effects: None.
     */
    private function embeddingModel(): string
    {
        return trim((string) config('services.openai.embedding_model', 'text-embedding-3-small'));
    }

    /**
     * Purpose: Extract a request id from the upstream response headers.
     * Inputs: A successful or failed HTTP response.
     * Returns: The upstream request id when available.
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
     * Purpose: Build a controlled embedding failure payload.
     * Inputs: Failure metadata from the upstream request.
     * Returns: A consistent structure for callers that need fallback behavior.
     * Side effects: None.
     */
    private function failureResult(
        string $errorType,
        string $errorMessage,
        ?int $upstreamStatus,
        ?string $requestId,
        ?string $responseBodyExcerpt,
        string $model,
        ?array $embedding,
    ): array {
        return [
            'ok' => false,
            'embedding' => $embedding,
            'model' => $model,
            'usage' => [],
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'upstream_status' => $upstreamStatus,
            'request_id' => $requestId,
            'response_body_excerpt' => $responseBodyExcerpt,
        ];
    }
}
