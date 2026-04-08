<?php

namespace App\Services\OpenAi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiClient
{
    public function createResponse(array $payload): array
    {
        return $this->send('responses', $payload);
    }

    public function createEmbedding(string $input): array
    {
        return $this->send('embeddings', [
            'model' => $this->embeddingModel(),
            'input' => $input,
        ]);
    }

    public function post(string $endpoint, array $payload): Response
    {
        return $this->pendingRequest()->post(ltrim($endpoint, '/'), $payload);
    }

    private function send(string $endpoint, array $payload): array
    {
        $response = $this->pendingRequest()->post(ltrim($endpoint, '/'), $payload);
        $requestId = $this->requestIdFrom($response);

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $requestId, $response->body());

            throw new RuntimeException(sprintf(
                'OpenAI request to [%s] failed with HTTP status [%d].',
                $endpoint,
                $response->status(),
            ));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->logFailure($endpoint, $response->status(), $requestId, $response->body());

            throw new RuntimeException(sprintf(
                'OpenAI request to [%s] returned an unexpected payload.',
                $endpoint,
            ));
        }

        if ($requestId !== null) {
            $decoded['_meta'] = [
                'request_id' => $requestId,
            ];
        }

        return $decoded;
    }

    private function pendingRequest(): PendingRequest
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        if ($baseUrl === '') {
            throw new RuntimeException('OpenAI base URL is not configured.');
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60);
    }

    private function embeddingModel(): string
    {
        $model = trim((string) config('services.openai.embedding_model', 'text-embedding-3-small'));

        if ($model === '') {
            throw new RuntimeException('OpenAI embedding model is not configured.');
        }

        return $model;
    }

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

    private function logFailure(string $endpoint, int $status, ?string $requestId, string $body): void
    {
        Log::warning('OpenAI request failed.', [
            'endpoint' => $endpoint,
            'status' => $status,
            'request_id' => $requestId,
            'body_excerpt' => Str::limit(trim($body), 1000),
        ]);
    }
}
