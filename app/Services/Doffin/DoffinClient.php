<?php

namespace App\Services\Doffin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DoffinClient
{
    public function search(array $query = []): array
    {
        $response = $this->request()
            ->acceptJson()
            ->retry(
                $this->searchRetryBackoffMilliseconds(),
                0,
                function (Throwable $exception): bool {
                    return $this->isTransientTransportException($exception);
                },
                false,
            )
            ->get($this->endpoint('search_endpoint'), $query);

        $this->ensureSuccessfulResponse($response, 'search');

        $data = $response->json();

        if (! is_array($data)) {
            Log::error('Doffin search returned a non-array JSON payload.');

            throw new RuntimeException('Doffin search returned invalid JSON data.');
        }

        return $data;
    }

    public function download(string $noticeId): string
    {
        $response = $this->request()
            ->accept('application/octet-stream, application/xml, text/xml')
            ->get($this->endpoint('download_endpoint').'/'.$noticeId);

        $this->ensureSuccessfulResponse($response, 'download', ['notice_id' => $noticeId]);

        $xml = $response->body();

        if ($xml === '') {
            Log::error('Doffin download returned an empty body.', ['notice_id' => $noticeId]);

            throw new RuntimeException("Doffin download returned empty content for notice {$noticeId}.");
        }

        return $xml;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl((string) config('doffin.base_url'))
            ->timeout((int) config('doffin.timeout'))
            ->connectTimeout(max(1, (int) config('doffin.connect_timeout', 10)))
            ->withUserAgent((string) config('doffin.user_agent'))
            ->withHeaders([
                'Accept-Language' => 'nb-NO,nb;q=0.9,en;q=0.8',
            ]);

        $apiKey = config('doffin.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeaders([
                'Ocp-Apim-Subscription-Key' => $apiKey,
            ]);
        }

        return $request;
    }

    /**
     * Determine whether the given exception represents a transient transport failure.
     */
    public function isTransientTransportException(Throwable $throwable): bool
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof ConnectionException) {
                continue;
            }

            $message = Str::lower($current->getMessage());

            return str_contains($message, 'curl error 28')
                || str_contains($message, 'failed to connect')
                || str_contains($message, 'could not resolve host')
                || str_contains($message, 'could not resolve proxy')
                || str_contains($message, 'name resolution')
                || str_contains($message, 'connection refused')
                || str_contains($message, 'operation timed out')
                || str_contains($message, 'timeout was reached')
                || str_contains($message, 'timed out');
        }

        return false;
    }

    private function endpoint(string $key): string
    {
        return '/'.ltrim((string) config("doffin.{$key}"), '/');
    }

    /**
     * @return array<int, int>
     */
    private function searchRetryBackoffMilliseconds(): array
    {
        $backoff = config('doffin.batch_search.retry_backoff_ms', [2000, 5000]);

        if (! is_array($backoff)) {
            $backoff = [$backoff];
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $delay): int => max(0, (int) $delay),
            $backoff,
        ), static fn (int $delay): bool => $delay >= 0));

        return $normalized !== [] ? $normalized : [2000, 5000];
    }

    private function ensureSuccessfulResponse(Response $response, string $operation, array $context = []): void
    {
        if ($response->successful()) {
            return;
        }

        $logContext = array_merge($context, [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        Log::error("Doffin {$operation} request failed.", $logContext);

        throw new RuntimeException("Doffin {$operation} request failed with status {$response->status()}.");
    }
}
