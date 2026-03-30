<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinPublicClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DoffinPublicClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doffin.public_client.retry_times' => 1,
            'doffin.public_client.retry_sleep_ms' => 1,
        ]);
    }

    public function test_it_builds_the_search_payload_for_result_notices(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'numHitsTotal' => 1,
                'numHitsAccessible' => 1,
                'hits' => [],
            ], 200),
        ]);

        $result = app(DoffinPublicClient::class)->search([
            'q' => '',
            'types' => ['RESULT'],
            'winner_ids' => ['winner-123'],
            'publication_from' => '2026-03-01',
            'publication_to' => '2026-03-29',
        ], 2, 25);

        $this->assertSame(1, $result['numHitsTotal']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.doffin.no/webclient/api/v2/search-api/search'
                && $request->method() === 'POST'
                && $request['numHitsPerPage'] === 25
                && $request['page'] === 2
                && $request['facets']['type']['checkedItems'] === ['RESULT']
                && $request['facets']['winner']['checkedItems'] === ['winner-123']
                && $request['facets']['publicationDate']['from'] === '2026-03-01'
                && $request['facets']['publicationDate']['to'] === '2026-03-29';
        });
    }

    public function test_it_builds_the_suggest_payload_for_supplier_lookup(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search/suggest' => Http::response([
                'winner' => [
                    'items' => [
                        ['id' => 'winner-123', 'value' => '4Service Eir Renhold AS', 'total' => 77],
                    ],
                ],
            ], 200),
        ]);

        $result = app(DoffinPublicClient::class)->suggest('renhold');

        $this->assertSame('winner-123', $result['winner']['items'][0]['id']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.doffin.no/webclient/api/v2/search-api/search/suggest'
                && $request->method() === 'POST'
                && $request['searchString'] === 'renhold'
                && $request['facets']['winner']['checkedItems'] === [];
        });
    }

    public function test_it_fetches_notice_detail_from_the_public_notice_endpoint(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/notices-api/notices/2026-105588' => Http::response([
                'id' => '2026-105588',
                'heading' => 'Renholdstjenester',
            ], 200),
        ]);

        $detail = app(DoffinPublicClient::class)->noticeDetail('2026-105588');

        $this->assertSame('2026-105588', $detail['id']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.doffin.no/webclient/api/v2/notices-api/notices/2026-105588'
                && $request->method() === 'GET';
        });
    }

    public function test_it_throws_a_runtime_exception_for_non_successful_search_responses(): void
    {
        Http::fake([
            'https://api.doffin.no/webclient/api/v2/search-api/search' => Http::response([
                'message' => 'Internal error',
            ], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Doffin public search failed with status 500.');

        app(DoffinPublicClient::class)->search([
            'types' => ['RESULT'],
            'publication_from' => '2026-03-01',
            'publication_to' => '2026-03-29',
        ]);
    }
}
