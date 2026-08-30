<?php

namespace App\Services\Ai;

use App\Data\Ai\AiCallContext;
use App\Models\AiUsageAttempt;
use App\Models\EnterpriseWikiIngestRun;
use App\Support\Ai\AiCallContextScope;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one append-only operational record for every provider attempt. It never stores prompt
 * text or model output and never makes a telemetry outage block the underlying AI operation.
 */
class AiUsageMeter
{
    public function __construct(private readonly AiCallContextScope $contextScope) {}

    public function within(AiCallContext $context, Closure $callback): mixed
    {
        return $this->contextScope->within($context, $callback);
    }

    /** Mark the most recent scoped provider response as unusable after transport succeeded. */
    /** @param array<string, mixed>|null $response */
    public function markCurrentAttemptInvalid(?array $response = null): void
    {
        $attemptId = $this->contextScope->latestAttemptId();

        if ($attemptId === null) {
            $requestId = data_get($response, '_meta.request_id');
            $attemptId = is_string($requestId) && $requestId !== ''
                ? AiUsageAttempt::query()->where('provider_request_id', $requestId)->latest('id')->value('id')
                : null;
        }

        if ($attemptId === null) {
            return;
        }

        try {
            AiUsageAttempt::query()->whereKey($attemptId)->update([
                'status' => AiUsageAttempt::STATUS_FAILED,
                'failure_type' => 'invalid_response',
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_USAGE_METER] Could not mark AI response as invalid.', [
                'attempt_id' => $attemptId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function measureResponse(string $model, Closure $providerCall): array
    {
        return $this->measure($model, 'responses', $providerCall);
    }

    /** Measure a raw Responses API transport call used by legacy clients that need the HTTP object. */
    public function measureHttpResponse(string $model, Closure $providerCall): Response
    {
        $context = $this->contextScope->current();
        $attempt = $this->start($context, $model, 'responses');
        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $providerCall();
            $decoded = $response->json();
            $decoded = is_array($decoded) ? $decoded : [];
            $decoded['_meta'] = [
                'request_id' => $this->requestIdFromResponse($response),
            ];
            $this->finish(
                $attempt,
                $response->successful() ? AiUsageAttempt::STATUS_SUCCESS : AiUsageAttempt::STATUS_FAILED,
                (int) round((microtime(true) - $startedAt) * 1000),
                $decoded,
                $response->successful() ? null : 'http_'.$response->status(),
            );

            return $response;
        } catch (Throwable $exception) {
            $this->finish(
                $attempt,
                $exception instanceof ConnectionException && str_contains(mb_strtolower($exception->getMessage()), 'timeout')
                    ? AiUsageAttempt::STATUS_UNCERTAIN
                    : AiUsageAttempt::STATUS_FAILED,
                (int) round((microtime(true) - $startedAt) * 1000),
                [],
                $this->failureType($exception),
            );

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function measureEmbedding(string $model, Closure $providerCall): array
    {
        return $this->measure($model, 'embeddings', $providerCall, true);
    }

    /** @return array<string, mixed> */
    private function measure(string $model, string $endpoint, Closure $providerCall, bool $embedding = false): array
    {
        $context = $this->contextScope->current();
        $attempt = $this->start($context, $model, $endpoint);
        $startedAt = microtime(true);

        try {
            $result = $providerCall();
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($embedding && is_array($result) && ! ($result['ok'] ?? false)) {
                $this->finish($attempt, $result['error_type'] === 'timeout' ? AiUsageAttempt::STATUS_UNCERTAIN : AiUsageAttempt::STATUS_FAILED, $elapsedMs, $result, (string) ($result['error_type'] ?? 'embedding_failed'));
            } else {
                $this->finish($attempt, AiUsageAttempt::STATUS_SUCCESS, $elapsedMs, is_array($result) ? $result : []);
            }

            return $result;
        } catch (Throwable $exception) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->finish(
                $attempt,
                $exception instanceof ConnectionException && str_contains(mb_strtolower($exception->getMessage()), 'timeout')
                    ? AiUsageAttempt::STATUS_UNCERTAIN
                    : AiUsageAttempt::STATUS_FAILED,
                $elapsedMs,
                [],
                $this->failureType($exception),
            );

            throw $exception;
        }
    }

    private function start(AiCallContext $context, string $model, string $endpoint): ?AiUsageAttempt
    {
        try {
            $attempt = AiUsageAttempt::query()->create([
                'customer_id' => $context->customerId,
                'user_id' => $context->userId,
                'feature' => $context->feature ?? 'unclassified',
                'operation_key' => $context->operation ?? 'unclassified',
                'resource_type' => $context->resourceType,
                'resource_id' => $context->resourceId,
                'enterprise_wiki_ingest_run_id' => $context->runId,
                'job_id' => $context->jobId,
                'request_correlation_id' => $context->requestCorrelationId,
                'provider' => $context->provider ?? config('services.openai.provider_key', 'openai'),
                'deployment_name' => config('services.openai.deployment_name'),
                'provider_region' => config('services.openai.provider_region'),
                'endpoint' => $endpoint,
                'model' => $model,
                'status' => AiUsageAttempt::STATUS_STARTED,
                'started_at' => now(),
            ]);

            $this->contextScope->rememberAttempt($attempt->id);

            return $attempt;
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_USAGE_METER] Could not start AI usage attempt.', [
                'customer_id' => $context->customerId,
                'operation' => $context->operation,
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array<string, mixed> $result */
    private function finish(?AiUsageAttempt $attempt, string $status, int $elapsedMs, array $result, ?string $failureType = null): void
    {
        if (! $attempt instanceof AiUsageAttempt) {
            return;
        }

        try {
            $usage = (array) ($result['usage'] ?? []);
            $meta = (array) ($result['_meta'] ?? []);

            $attempt->update([
                'status' => $status,
                'failure_type' => $failureType,
                'provider_request_id' => $meta['request_id'] ?? $result['request_id'] ?? null,
                'input_tokens' => $this->integer($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null),
                'output_tokens' => $this->integer($usage['output_tokens'] ?? null),
                'total_tokens' => $this->integer($usage['total_tokens'] ?? null),
                'elapsed_ms' => $elapsedMs,
                'finished_at' => now(),
            ]);

            if ($status === AiUsageAttempt::STATUS_SUCCESS && $attempt->enterprise_wiki_ingest_run_id !== null) {
                $inputTokens = $this->integer($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null) ?? 0;
                $outputTokens = $this->integer($usage['output_tokens'] ?? null) ?? 0;

                EnterpriseWikiIngestRun::query()->whereKey($attempt->enterprise_wiki_ingest_run_id)->update([
                    'input_tokens' => DB::raw('COALESCE(input_tokens, 0) + '.$inputTokens),
                    'output_tokens' => DB::raw('COALESCE(output_tokens, 0) + '.$outputTokens),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_USAGE_METER] Could not finish AI usage attempt.', [
                'attempt_id' => $attempt->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function failureType(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'invalid') || str_contains($message, 'malformed') => 'invalid_response',
            default => 'provider_or_transport_error',
        };
    }

    private function requestIdFromResponse(Response $response): ?string
    {
        foreach (['x-request-id', 'x-openai-request-id', 'openai-request-id'] as $header) {
            $requestId = trim((string) $response->header($header));

            if ($requestId !== '') {
                return $requestId;
            }
        }

        return null;
    }
}
