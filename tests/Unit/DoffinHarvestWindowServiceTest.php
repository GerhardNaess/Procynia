<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinHarvestWindowService;
use App\Services\Doffin\DoffinNoticeParser;
use App\Services\Doffin\DoffinPublicClient;
use Mockery;
use Tests\TestCase;

class DoffinHarvestWindowServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_iterates_date_windows_and_builds_records(): void
    {
        config([
            'doffin.public_client.per_page' => 50,
            'doffin.public_client.throttle_ms' => 0,
        ]);

        $searchCalls = [];
        $client = Mockery::mock(DoffinPublicClient::class);
        $parser = Mockery::mock(DoffinNoticeParser::class);

        $client->shouldReceive('search')
            ->twice()
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use (&$searchCalls): array {
                $searchCalls[] = compact('filters', 'page', 'perPage');

                if ($filters['publication_from'] === '2026-03-01') {
                    return [
                        'numHitsTotal' => 1,
                        'numHitsAccessible' => 1,
                        'hits' => [
                            ['id' => '2026-300001'],
                        ],
                    ];
                }

                return [
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'hits' => [],
                ];
            });

        $client->shouldReceive('noticeDetail')
            ->once()
            ->with('2026-300001')
            ->andReturn(['id' => '2026-300001']);

        $parsedNotice = [
            'notice_id' => '2026-300001',
            'suppliers' => [
                [
                    'supplier_name' => 'Supplier One AS',
                    'organization_number' => '123456789',
                    'winner_lots' => ['LOT-0001'],
                    'source' => 'eform',
                ],
            ],
        ];

        $parser->shouldReceive('parse')
            ->once()
            ->with(['id' => '2026-300001'])
            ->andReturn($parsedNotice);

        $parser->shouldReceive('supplierRecords')
            ->once()
            ->with($parsedNotice)
            ->andReturn([
                [
                    'supplier_name' => 'Supplier One AS',
                    'notice_id' => '2026-300001',
                ],
            ]);

        $service = new DoffinHarvestWindowService($client, $parser);
        $result = $service->harvest('2026-03-01', '2026-03-10', [], 7);

        $this->assertCount(1, $result['notices']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('2026-03-01', $searchCalls[0]['filters']['publication_from']);
        $this->assertSame('2026-03-07', $searchCalls[0]['filters']['publication_to']);
        $this->assertSame('2026-03-08', $searchCalls[1]['filters']['publication_from']);
        $this->assertSame('2026-03-10', $searchCalls[1]['filters']['publication_to']);
    }

    public function test_it_splits_capped_windows_before_harvesting_details(): void
    {
        config([
            'doffin.public_client.per_page' => 50,
            'doffin.public_client.throttle_ms' => 0,
        ]);

        $searchCalls = [];
        $client = Mockery::mock(DoffinPublicClient::class);
        $parser = Mockery::mock(DoffinNoticeParser::class);

        $client->shouldReceive('search')
            ->times(3)
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use (&$searchCalls): array {
                $searchCalls[] = compact('filters', 'page', 'perPage');

                if ($filters['publication_from'] === '2026-03-01' && $filters['publication_to'] === '2026-03-07') {
                    return [
                        'numHitsTotal' => 1200,
                        'numHitsAccessible' => 1000,
                        'hits' => [],
                    ];
                }

                return [
                    'numHitsTotal' => 0,
                    'numHitsAccessible' => 0,
                    'hits' => [],
                ];
            });

        $service = new DoffinHarvestWindowService($client, $parser);
        $result = $service->harvest('2026-03-01', '2026-03-07', [], 7);

        $this->assertSame(3, $result['stats']['windows_processed']);
        $this->assertSame(1, $result['stats']['windows_split']);
        $this->assertSame('2026-03-01', $searchCalls[1]['filters']['publication_from']);
        $this->assertSame('2026-03-04', $searchCalls[1]['filters']['publication_to']);
        $this->assertSame('2026-03-05', $searchCalls[2]['filters']['publication_from']);
        $this->assertSame('2026-03-07', $searchCalls[2]['filters']['publication_to']);
    }

    public function test_it_paginates_until_the_accessible_last_page(): void
    {
        config([
            'doffin.public_client.per_page' => 2,
            'doffin.public_client.throttle_ms' => 0,
        ]);

        $searchCalls = [];
        $detailCalls = [];
        $client = Mockery::mock(DoffinPublicClient::class);
        $parser = Mockery::mock(DoffinNoticeParser::class);

        $client->shouldReceive('search')
            ->twice()
            ->andReturnUsing(function (array $filters, int $page, int $perPage) use (&$searchCalls): array {
                $searchCalls[] = compact('filters', 'page', 'perPage');

                if ($page === 1) {
                    return [
                        'numHitsTotal' => 3,
                        'numHitsAccessible' => 3,
                        'hits' => [
                            ['id' => '2026-400001'],
                            ['id' => '2026-400002'],
                        ],
                    ];
                }

                return [
                    'numHitsTotal' => 3,
                    'numHitsAccessible' => 3,
                    'hits' => [
                        ['id' => '2026-400003'],
                    ],
                ];
            });

        $client->shouldReceive('noticeDetail')
            ->times(3)
            ->andReturnUsing(function (string $noticeId) use (&$detailCalls): array {
                $detailCalls[] = $noticeId;

                return ['id' => $noticeId];
            });

        $parser->shouldReceive('parse')
            ->times(3)
            ->andReturnUsing(fn (array $detail): array => [
                'notice_id' => $detail['id'],
                'suppliers' => [
                    [
                        'supplier_name' => 'Supplier '.$detail['id'],
                        'organization_number' => null,
                        'winner_lots' => [],
                        'source' => 'awardedNames fallback',
                    ],
                ],
            ]);

        $parser->shouldReceive('supplierRecords')
            ->times(3)
            ->andReturnUsing(fn (array $parsedNotice): array => [[
                'supplier_name' => 'Supplier '.$parsedNotice['notice_id'],
                'notice_id' => $parsedNotice['notice_id'],
            ]]);

        $service = new DoffinHarvestWindowService($client, $parser);
        $result = $service->harvest('2026-03-01', '2026-03-01', [], 1);

        $this->assertSame([1, 2], array_column($searchCalls, 'page'));
        $this->assertSame(['2026-400001', '2026-400002', '2026-400003'], $detailCalls);
        $this->assertCount(3, $result['records']);
        $this->assertSame(3, $result['stats']['notices_seen']);
    }

    public function test_it_throws_for_a_capped_single_day_window(): void
    {
        config([
            'doffin.public_client.per_page' => 15,
            'doffin.public_client.throttle_ms' => 0,
        ]);

        $client = Mockery::mock(DoffinPublicClient::class);
        $parser = Mockery::mock(DoffinNoticeParser::class);

        $client->shouldReceive('search')
            ->once()
            ->andReturn([
                'numHitsTotal' => 1500,
                'numHitsAccessible' => 1000,
                'hits' => [],
            ]);

        $service = new DoffinHarvestWindowService($client, $parser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Doffin harvest window exceeded the accessible result cap for a single day.');

        $service->harvest('2026-03-01', '2026-03-01', [], 1);
    }
}
