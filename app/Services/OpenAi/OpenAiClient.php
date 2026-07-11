<?php

namespace App\Services\OpenAi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiClient
{
    /**
     * Purpose: Send a GET request to the configured OpenAI API.
     * Inputs: The endpoint path and timeout in seconds.
     * Returns: The raw HTTP response.
     * Side effects: Issues one network request and logs failed responses.
     */
    public function get(string $endpoint, int $timeoutSeconds = 180): Response
    {
        $response = $this->pendingRequest($timeoutSeconds)->get(ltrim($endpoint, '/'));

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $this->requestIdFrom($response), $response->body());
        }

        return $response;
    }

    public function createResponse(array $payload, int $timeoutSeconds = 120): array
    {
        return $this->send('responses', $payload, $timeoutSeconds);
    }

    public function createEmbedding(string $input): array
    {
        return $this->send('embeddings', [
            'model' => $this->embeddingModel(),
            'input' => $input,
        ]);
    }

    public function post(string $endpoint, array $payload, int $timeoutSeconds = 180): Response
    {
        $response = $this->pendingRequest($timeoutSeconds)->post(ltrim($endpoint, '/'), $payload);

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $this->requestIdFrom($response), $response->body());
        }

        return $response;
    }

    private function send(string $endpoint, array $payload, int $timeoutSeconds = 120): array
    {
        $response = $this->post($endpoint, $payload, $timeoutSeconds);
        $requestId = $this->requestIdFrom($response);

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessageFromResponse($endpoint, $response));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->logFailure($endpoint, $response->status(), $requestId, $response->body());

            throw new RuntimeException(sprintf(
                'OpenAI request to [%s] returned an unexpected payload.',
                $endpoint,
            ));
        }

        $decoded['_meta'] = [
            'request_id' => $requestId,
            'http_status' => $response->status(),
            'provider' => $this->providerKey(),
            'deployment_name' => $this->deploymentName(),
            'provider_region' => $this->providerRegion(),
        ];

        return $decoded;
    }

    /**
     * Purpose: Return the canonical provider key for the configured AI endpoint.
     * Inputs: None.
     * Returns: A stable provider identifier read from config — never guessed from model name.
     * Side effects: None.
     */
    public function providerKey(): string
    {
        return trim((string) config('services.openai.provider_key', 'openai')) ?: 'openai';
    }

    /**
     * Purpose: Return the deployment name for providers that require it (Azure, Advania LLM, etc.).
     * Inputs: None.
     * Returns: The deployment name from config, or null when not applicable.
     * Side effects: None.
     */
    public function deploymentName(): ?string
    {
        $value = trim((string) (config('services.openai.deployment_name') ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Purpose: Return the provider region for region-aware pricing or routing.
     * Inputs: None.
     * Returns: The provider region from config, or null when not configured.
     * Side effects: None.
     */
    public function providerRegion(): ?string
    {
        $value = trim((string) (config('services.openai.provider_region') ?? ''));

        return $value !== '' ? $value : null;
    }

    private function failureMessageFromResponse(string $endpoint, Response $response): string
    {
        $status = $response->status();
        $details = $this->errorDetailsFromBody($response->body());
        $fragments = array_values(array_filter([
            $details['type'] ?? null,
            $details['code'] ?? null,
            $details['param'] ? 'param='.$details['param'] : null,
            $details['message'] ?? null,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        if ($fragments === []) {
            return sprintf('OpenAI request to [%s] failed with HTTP status [%d].', $endpoint, $status);
        }

        return sprintf(
            'OpenAI request to [%s] failed with HTTP status [%d]: %s',
            $endpoint,
            $status,
            implode(' | ', $fragments),
        );
    }

    private function pendingRequest(int $timeoutSeconds = 180): PendingRequest
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
            ->timeout($timeoutSeconds)
            ->withOptions([
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => max(1, min($timeoutSeconds, 10)),
                ],
            ]);
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
        $body = trim($body);
        $error = $this->errorDetailsFromBody($body);

        Log::warning('OpenAI request failed.', [
            'endpoint' => $endpoint,
            'status' => $status,
            'request_id' => $requestId,
            'error_message' => $error['message'],
            'error_type' => $error['type'],
            'error_code' => $error['code'],
            'error_param' => $error['param'],
            'raw_body_length' => mb_strlen($body, 'UTF-8'),
        ]);
    }

    private function errorDetailsFromBody(string $body): array
    {
        if ($body === '') {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        $error = data_get($decoded, 'error');

        if (! is_array($error)) {
            return [
                'message' => null,
                'type' => null,
                'code' => null,
                'param' => null,
            ];
        }

        return [
            'message' => is_string(data_get($error, 'message')) ? trim((string) data_get($error, 'message')) : null,
            'type' => is_string(data_get($error, 'type')) ? trim((string) data_get($error, 'type')) : null,
            'code' => is_scalar(data_get($error, 'code')) ? trim((string) data_get($error, 'code')) : null,
            'param' => is_scalar(data_get($error, 'param')) ? trim((string) data_get($error, 'param')) : null,
        ];
    }
}
