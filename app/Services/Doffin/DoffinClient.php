<?php

namespace App\Services\Doffin;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DoffinClient
{
    public function search(array $query = []): array
    {
        $response = $this->request()
            ->acceptJson()
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

    private function endpoint(string $key): string
    {
        return '/'.ltrim((string) config("doffin.{$key}"), '/');
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
