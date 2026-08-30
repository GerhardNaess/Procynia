<?php

namespace App\Services\OpenAi;

use App\Services\Ai\AiUsageMeter;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Support\Ai\AiCallContextScope;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiClient
{
    public function __construct(
        private readonly AiUsageMeter $usageMeter,
        private readonly AiCostControlService $costControl,
        private readonly AiCallContextScope $contextScope,
    ) {}

    /**
     * Purpose: Send a GET request to the configured OpenAI API.
     * Inputs: The endpoint path and timeout in seconds.
     * Returns: The raw HTTP response.
     * Side effects: Issues one network request and logs failed responses.
     *
     * Deliberately outside cost control. This is only used for `GET /models` by the health check
     * and the runtime preflight: it consumes no tokens and costs nothing, and an operator has to be
     * able to verify provider connectivity during an incident — including while a global stop is
     * active. Every call that can actually spend money goes through createResponse(), post() or
     * createEmbedding(), all of which authorise first.
     */
    public function get(string $endpoint, int $timeoutSeconds = 180): Response
    {
        $response = $this->pendingRequest($timeoutSeconds)->get(ltrim($endpoint, '/'));

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $this->requestIdFrom($response), $response->body());
        }

        return $response;
    }

    /**
     * @param  ?callable(TransferStats): void  $onStats  Optional Guzzle transfer-stats
     *                                                   callback (see 'on_stats' in Guzzle's RequestOptions) — invoked for both a successful
     *                                                   and a failed transfer, so a caller can capture connect/transfer timing even when this
     *                                                   method ends up throwing (e.g. EnterpriseWikiAiCapacityRetryExecutor's structured
     *                                                   per-attempt logging, built for the Wiki run-592 incident).
     */
    public function createResponse(array $payload, int $timeoutSeconds = 120, ?callable $onStats = null): array
    {
        $model = trim((string) ($payload['model'] ?? 'unknown')) ?: 'unknown';
        $decision = $this->costControl->authorize($this->contextScope->current()->forProviderCall($model, 'responses'));

        try {
            $result = $this->usageMeter->measureResponse(
                $model,
                fn (): array => $this->send('responses', $payload, $timeoutSeconds, $onStats),
            );
            $this->costControl->finalize($decision);

            return $result;
        } catch (\Throwable $exception) {
            $this->costControl->fail($decision, $exception);

            throw $exception;
        }
    }

    public function createEmbedding(string $input): array
    {
        $model = $this->embeddingModel();
        $decision = $this->costControl->authorize($this->contextScope->current()->forProviderCall($model, 'embeddings'));

        try {
            $result = $this->usageMeter->measureResponse(
                $model,
                fn (): array => $this->send('embeddings', ['model' => $model, 'input' => $input]),
            );
            $this->costControl->finalize($decision);

            return $result;
        } catch (\Throwable $exception) {
            $this->costControl->fail($decision, $exception);

            throw $exception;
        }
    }

    public function post(string $endpoint, array $payload, int $timeoutSeconds = 180, ?callable $onStats = null): Response
    {
        $endpoint = ltrim($endpoint, '/');
        $postModel = trim((string) ($payload['model'] ?? '')) ?: $this->embeddingModel();
        $decision = $this->costControl->authorize($this->contextScope->current()->forProviderCall($postModel, $endpoint));

        try {
            $response = $endpoint === 'responses'
                ? $this->usageMeter->measureHttpResponse(
                    trim((string) ($payload['model'] ?? 'unknown')) ?: 'unknown',
                    fn (): Response => $this->postRaw($endpoint, $payload, $timeoutSeconds, $onStats),
                )
                : $this->postRaw($endpoint, $payload, $timeoutSeconds, $onStats);

            if ($response->successful()) {
                $this->costControl->finalize($decision);
            } else {
                $this->costControl->failHttp($decision, $response->status());
            }
        } catch (\Throwable $exception) {
            $this->costControl->fail($decision, $exception);

            throw $exception;
        }

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $this->requestIdFrom($response), $response->body());
        }

        return $response;
    }

    private function send(string $endpoint, array $payload, int $timeoutSeconds = 120, ?callable $onStats = null): array
    {
        // createResponse() has already created its own lifecycle attempt; using the raw transport
        // here avoids double-counting that one provider call through public post().
        $response = $this->postRaw($endpoint, $payload, $timeoutSeconds, $onStats);
        $requestId = $this->requestIdFrom($response);

        if ($response->failed()) {
            $this->logFailure($endpoint, $response->status(), $requestId, $response->body());

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

    private function postRaw(string $endpoint, array $payload, int $timeoutSeconds, ?callable $onStats): Response
    {
        return $this->pendingRequest($timeoutSeconds, $onStats)->post($endpoint, $payload);
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

    private function pendingRequest(int $timeoutSeconds = 180, ?callable $onStats = null): PendingRequest
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        $baseUrl = trim((string) config('services.openai.base_url', 'https://api.openai.com/v1'));

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        if ($baseUrl === '') {
            throw new RuntimeException('OpenAI base URL is not configured.');
        }

        $options = [
            'curl' => [
                CURLOPT_CONNECTTIMEOUT => max(1, min($timeoutSeconds, 10)),
            ],
        ];

        if ($onStats !== null) {
            $options['on_stats'] = $onStats;
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeoutSeconds)
            ->withOptions($options);
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
