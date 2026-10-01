<?php

namespace App\Services\Ted;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TED's Search API, and nothing else.
 *
 * The counterpart to DoffinLiveSearchService: one register's own client, speaking that register's
 * own wire format. Everything above it works in OpportunitySearchCriteria and NormalizedNotice, so
 * this is the only class that knows TED sends a POST with an expert-search string in it.
 *
 * The contract, verified against the live API on 27 September 2026:
 *
 *   POST https://api.ted.europa.eu/v3/notices/search
 *   {"query": "<expert search>", "fields": [...], "page": 1, "limit": 15}
 *
 * Both `query` and `fields` are required — an empty either way is a 400 with a validation body —
 * and one unrecognised field name rejects the whole request. A success is
 * {"notices": [...], "totalNoticeCount": int, "iterationNextToken": ?string, "timedOut": bool}.
 *
 * Failures are returned rather than thrown, in the same shape DoffinLiveSearchService uses, so the
 * adapter above can turn either register's failure into the same OpportunitySourceSearchResult
 * without a second vocabulary of error types.
 *
 * @return array{ok: bool, ...}
 */
class TedSearchClient
{
    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function search(string $query, array $fields, int $page = 1, int $perPage = 15): array
    {
        $url = (string) config('ted.search_url');
        $payload = [
            'query' => $query,
            'fields' => array_values($fields),
            'page' => max(1, $page),
            'limit' => max(1, $perPage),
        ];

        try {
            $response = Http::timeout((int) config('ted.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            // Distinguished from a generic failure because the two mean different things to the
            // person searching: "TED did not answer in time" is worth retrying now, and the user
            // message says so.
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');

            return $this->failure($timedOut ? 'timeout' : 'connection_error', $exception->getMessage(), $page, $perPage);
        } catch (Throwable $exception) {
            return $this->failure('connection_error', $exception->getMessage(), $page, $perPage);
        }

        if ($response->failed()) {
            $status = $response->status();
            // 400 is TED rejecting the query itself — a malformed expert search or an unknown
            // field. That is ours to fix, not a reason to tell the user to try again later.
            $errorType = $status === 400 || $status === 422 ? 'invalid_request' : 'upstream_unavailable';

            Log::warning('[TED][service] Search request failed.', [
                'url' => $url,
                'status' => $status,
                'error_type' => $errorType,
                'query' => $query,
                'body' => mb_substr((string) $response->body(), 0, 500),
                'live_search' => true,
            ]);

            return $this->failure($errorType, (string) $response->body(), $page, $perPage, $status);
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('notices', $decoded)) {
            Log::warning('[TED][service] Search returned an unexpected body.', [
                'url' => $url,
                'keys' => is_array($decoded) ? array_keys($decoded) : null,
                'live_search' => true,
            ]);

            return $this->failure('unexpected_response', 'The response had no notices key.', $page, $perPage, $response->status());
        }

        $total = (int) ($decoded['totalNoticeCount'] ?? 0);

        return [
            'ok' => true,
            'items' => is_array($decoded['notices']) ? $decoded['notices'] : [],
            'page' => max(1, $page),
            'perPage' => max(1, $perPage),
            'numHitsTotal' => $total,
            'numHitsAccessible' => $total,
            // TED answers a partial result rather than failing when its own search runs long. The
            // caller is told, the way a Doffin buyer-lookup fallback is told, so a short page of
            // results is not silently presented as the whole answer.
            'fallback_used' => (bool) ($decoded['timedOut'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function failure(string $errorType, string $message, int $page, int $perPage, mixed $upstreamStatus = null): array
    {
        return [
            'ok' => false,
            'items' => [],
            'page' => max(1, $page),
            'perPage' => max(1, $perPage),
            'numHitsTotal' => 0,
            'numHitsAccessible' => 0,
            'fallback_used' => false,
            'error_type' => $errorType,
            'error_message' => $message,
            'upstream_status' => $upstreamStatus,
        ];
    }
}
