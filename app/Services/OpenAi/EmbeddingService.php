<?php

namespace App\Services\OpenAi;

use App\Services\Ai\AiUsageMeter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class EmbeddingService
{
    private const MAX_EMBEDDING_INPUT_CHARS = 6000;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly AiUsageMeter $usageMeter,
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

        return $this->usageMeter->measureEmbedding(
            $model,
            fn (): array => $this->tryEmbedTextForConfiguredModel($text, $model),
        );
    }

    /** @return array<string, mixed> */
    private function tryEmbedTextForConfiguredModel(string $text, string $model): array
    {
        $embeddingInputs = $this->splitEmbeddingInput($text);

        if ($embeddingInputs === []) {
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
                'input' => count($embeddingInputs) === 1 ? $embeddingInputs[0] : $embeddingInputs,
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

        $data = data_get($decoded, 'data', []);

        if (! is_array($data) || $data === []) {
            return $this->failureResult(
                'unexpected_response',
                'OpenAI embedding response did not include embedding data.',
                $status,
                $requestId,
                $bodyExcerpt,
                $model,
                null,
            );
        }

        usort(
            $data,
            static function (mixed $left, mixed $right): int {
                return (int) data_get($left, 'index', 0) <=> (int) data_get($right, 'index', 0);
            },
        );

        $normalizedEmbeddings = [];
        $weights = [];

        foreach ($data as $index => $item) {
            $embedding = $this->normalizeEmbedding(data_get($item, 'embedding'));

            if ($embedding === null) {
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

            $normalizedEmbeddings[] = $embedding;
            $weights[] = max(1, mb_strlen((string) ($embeddingInputs[$index] ?? ''), 'UTF-8'));
        }

        $aggregatedEmbedding = count($normalizedEmbeddings) === 1
            ? $normalizedEmbeddings[0]
            : $this->aggregateEmbeddings($normalizedEmbeddings, $weights);

        if ($aggregatedEmbedding === null) {
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
            'embedding' => $aggregatedEmbedding,
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
     * Purpose: Split long embedding text into deterministic parts that stay well below the model limit.
     * Inputs: A trimmed piece of text to embed.
     * Returns: One or more text parts that can be embedded safely.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function splitEmbeddingInput(string $text): array
    {
        $normalizedText = trim(preg_replace('/\r\n|\r/u', "\n", $text) ?? '');

        if ($normalizedText === '') {
            return [];
        }

        if (mb_strlen($normalizedText, 'UTF-8') <= self::MAX_EMBEDDING_INPUT_CHARS) {
            return [$normalizedText];
        }

        $paragraphs = preg_split('/\n{2,}/u', $normalizedText) ?: [];
        $parts = [];
        $currentPart = '';

        foreach ($paragraphs as $paragraph) {
            $paragraphParts = $this->splitEmbeddingParagraph((string) $paragraph);

            foreach ($paragraphParts as $paragraphPart) {
                $paragraphPart = trim((string) $paragraphPart);

                if ($paragraphPart === '') {
                    continue;
                }

                if ($currentPart === '') {
                    $currentPart = $paragraphPart;

                    continue;
                }

                $candidatePart = $currentPart."\n\n".$paragraphPart;

                if (mb_strlen($candidatePart, 'UTF-8') > self::MAX_EMBEDDING_INPUT_CHARS) {
                    $parts[] = $currentPart;
                    $currentPart = $paragraphPart;

                    continue;
                }

                $currentPart = $candidatePart;
            }
        }

        if ($currentPart !== '') {
            $parts[] = $currentPart;
        }

        return $parts === [] ? [$normalizedText] : $parts;
    }

    /**
     * Purpose: Split one oversized paragraph into smaller, stable embedding parts.
     * Inputs: A normalized paragraph of text.
     * Returns: Embedding-safe paragraph parts.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function splitEmbeddingParagraph(string $paragraph): array
    {
        $normalizedParagraph = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? '');

        if ($normalizedParagraph === '') {
            return [];
        }

        if (mb_strlen($normalizedParagraph, 'UTF-8') <= self::MAX_EMBEDDING_INPUT_CHARS) {
            return [$normalizedParagraph];
        }

        $tokens = preg_split('/\s+/u', $normalizedParagraph, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return [$normalizedParagraph];
        }

        $parts = [];
        $currentPart = '';

        foreach ($tokens as $token) {
            $token = trim((string) $token);

            if ($token === '') {
                continue;
            }

            if (mb_strlen($token, 'UTF-8') > self::MAX_EMBEDDING_INPUT_CHARS) {
                foreach ($this->splitLongEmbeddingToken($token) as $fragment) {
                    $fragment = trim((string) $fragment);

                    if ($fragment === '') {
                        continue;
                    }

                    $candidatePart = $currentPart === '' ? $fragment : $currentPart.' '.$fragment;

                    if (mb_strlen($candidatePart, 'UTF-8') > self::MAX_EMBEDDING_INPUT_CHARS) {
                        if ($currentPart !== '') {
                            $parts[] = $currentPart;
                        }

                        $currentPart = $fragment;

                        continue;
                    }

                    $currentPart = $candidatePart;
                }

                continue;
            }

            $candidatePart = $currentPart === '' ? $token : $currentPart.' '.$token;

            if (mb_strlen($candidatePart, 'UTF-8') > self::MAX_EMBEDDING_INPUT_CHARS) {
                if ($currentPart !== '') {
                    $parts[] = $currentPart;
                }

                $currentPart = $token;

                continue;
            }

            $currentPart = $candidatePart;
        }

        if ($currentPart !== '') {
            $parts[] = $currentPart;
        }

        return $parts === [] ? [$normalizedParagraph] : $parts;
    }

    /**
     * Purpose: Split one excessively long token into deterministic fragments.
     * Inputs: A token that still exceeds the embedding safety limit.
     * Returns: A list of token fragments below the embedding safety limit.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function splitLongEmbeddingToken(string $token): array
    {
        $fragments = [];
        $remaining = $token;

        while ($remaining !== '') {
            $fragment = mb_substr($remaining, 0, self::MAX_EMBEDDING_INPUT_CHARS, 'UTF-8');
            $fragments[] = $fragment;
            $remaining = mb_substr($remaining, self::MAX_EMBEDDING_INPUT_CHARS, null, 'UTF-8');
        }

        return $fragments;
    }

    /**
     * Purpose: Aggregate multiple embedding vectors into one stable vector.
     * Inputs: The vectors to combine and their relative weights.
     * Returns: A single normalized embedding vector or null when the input is invalid.
     * Side effects: None.
     *
     * @param array<int, array<int, float>> $embeddings
     * @param array<int, int> $weights
     * @return array<int, float>|null
     */
    private function aggregateEmbeddings(array $embeddings, array $weights): ?array
    {
        if ($embeddings === []) {
            return null;
        }

        $dimension = count($embeddings[0]);

        if ($dimension === 0) {
            return null;
        }

        $weightedSums = array_fill(0, $dimension, 0.0);
        $totalWeight = 0.0;

        foreach ($embeddings as $index => $embedding) {
            if (count($embedding) !== $dimension) {
                return null;
            }

            $weight = (float) max(1, (int) ($weights[$index] ?? 1));
            $totalWeight += $weight;

            foreach ($embedding as $dimensionIndex => $value) {
                $weightedSums[$dimensionIndex] += $weight * (float) $value;
            }
        }

        if ($totalWeight <= 0.0) {
            return null;
        }

        $combined = [];
        $magnitude = 0.0;

        foreach ($weightedSums as $value) {
            $averageValue = $value / $totalWeight;
            $combined[] = $averageValue;
            $magnitude += $averageValue * $averageValue;
        }

        $magnitude = sqrt($magnitude);

        if ($magnitude <= 0.0) {
            return null;
        }

        return array_map(
            static fn (float $value): float => $value / $magnitude,
            $combined,
        );
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
