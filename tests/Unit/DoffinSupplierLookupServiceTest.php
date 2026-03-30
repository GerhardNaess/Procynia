<?php

namespace Tests\Unit;

use App\Services\Doffin\DoffinHarvestWindowService;
use App\Services\Doffin\DoffinPublicClient;
use App\Services\Doffin\DoffinSupplierLookupService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class DoffinSupplierLookupServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_returns_winner_candidates_from_the_suggest_response(): void
    {
        $client = Mockery::mock(DoffinPublicClient::class);
        $harvest = Mockery::mock(DoffinHarvestWindowService::class);

        $client->shouldReceive('suggest')
            ->once()
            ->with('renhold')
            ->andReturn([
                'winner' => [
                    'items' => [
                        [
                            'id' => 'winner-1',
                            'value' => '4Service Eir Renhold AS',
                            'total' => 77,
                            'highlight' => '4Service Eir <em>Renhold</em> AS',
                        ],
                    ],
                ],
            ]);

        $service = new DoffinSupplierLookupService($client, $harvest);
        $candidates = $service->winnerCandidates('renhold');

        $this->assertSame([
            [
                'id' => 'winner-1',
                'value' => '4Service Eir Renhold AS',
                'total' => 77,
                'highlight' => '4Service Eir <em>Renhold</em> AS',
            ],
        ], $candidates);
    }

    public function test_it_selects_the_exact_winner_candidate_and_harvests_result_notices(): void
    {
        $client = Mockery::mock(DoffinPublicClient::class);
        $harvest = Mockery::mock(DoffinHarvestWindowService::class);

        $client->shouldReceive('suggest')
            ->once()
            ->with('4Service Eir Renhold AS')
            ->andReturn([
                'winner' => [
                    'items' => [
                        [
                            'id' => 'winner-other',
                            'value' => 'Other Supplier AS',
                            'total' => 10,
                            'highlight' => 'Other Supplier AS',
                        ],
                        [
                            'id' => 'winner-exact',
                            'value' => '4Service Eir Renhold AS',
                            'total' => 77,
                            'highlight' => '4Service Eir Renhold AS',
                        ],
                    ],
                ],
            ]);

        $harvest->shouldReceive('harvest')
            ->once()
            ->withArgs(function ($from, $to, array $filters): bool {
                return $from instanceof CarbonImmutable
                    && $to instanceof CarbonImmutable
                    && $from->toDateString() === '2026-03-01'
                    && $to->toDateString() === '2026-03-29'
                    && $filters['types'] === ['RESULT']
                    && $filters['winner_ids'] === ['winner-exact'];
            })
            ->andReturn([
                'notices' => [
                    ['notice_id' => '2026-105588'],
                ],
                'records' => [
                    ['supplier_name' => '4Service Eir Renhold AS', 'notice_id' => '2026-105588'],
                ],
                'stats' => [
                    'windows_processed' => 1,
                    'windows_split' => 0,
                    'notices_seen' => 1,
                    'records_built' => 1,
                ],
            ]);

        $service = new DoffinSupplierLookupService($client, $harvest);
        $result = $service->lookup('4Service Eir Renhold AS', '2026-03-01', '2026-03-29');

        $this->assertSame('winner-exact', $result['selected_candidate']['id']);
        $this->assertCount(1, $result['notices']);
        $this->assertCount(1, $result['records']);
    }
}
