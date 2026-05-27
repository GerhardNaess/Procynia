<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DoffinClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doffin.base_url' => 'https://betaapi.doffin.no',
            'doffin.search_endpoint' => '/public/v2/search',
            'doffin.connect_timeout' => 1,
            'doffin.batch_search.retry_backoff_ms' => [0, 0],
            'doffin.api_key' => null,
        ]);
    }

    public function test_it_retries_transient_connection_failures_before_succeeding(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new ConnectionException('cURL error 28: Failed to connect to betaapi.doffin.no port 443 after 10001 ms: Timeout was reached.');
            }

            return Http::response([
                'hits' => [],
            ], 200);
        });

        $result = app(DoffinClient::class)->search();

        $this->assertSame(['hits' => []], $result);
        $this->assertSame(3, $attempts);
    }

    public function test_it_throws_the_last_connection_exception_after_max_attempts(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Failed to connect to betaapi.doffin.no port 443 after 10001 ms: Timeout was reached.');
        });

        try {
            app(DoffinClient::class)->search();

            $this->fail('The Doffin search request should have thrown a connection exception.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());
        }

        $this->assertSame(3, $attempts);
    }

    public function test_it_does_not_retry_non_transient_http_errors(): void
    {
        Http::fake([
            'https://betaapi.doffin.no/public/v2/search' => Http::response([
                'message' => 'Bad request',
            ], 400),
        ]);

        try {
            app(DoffinClient::class)->search();

            $this->fail('The Doffin search request should have thrown a runtime exception for HTTP 400.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Doffin search request failed with status 400.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }
}
