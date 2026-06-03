<?php

namespace Tests\Unit\Currency;

use App\Services\Currency\NorgesBankExchangeRateClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NorgesBankExchangeRateClientTest extends TestCase
{
    private NorgesBankExchangeRateClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new NorgesBankExchangeRateClient();
    }

    public function test_parses_normalised_response(): void
    {
        Http::fake([
            '*norges-bank*' => Http::response($this->fakeNorgesBankResponse(10.8523, '2026-06-03'), 200),
        ]);

        $result = $this->client->fetch('USD', 'NOK');

        $this->assertNotNull($result);
        $this->assertSame('USD', $result['base_currency']);
        $this->assertSame('NOK', $result['quote_currency']);
        $this->assertEqualsWithDelta(10.8523, $result['rate'], 0.0001);
        $this->assertSame('2026-06-03', $result['rate_date']);
        $this->assertSame('norges_bank', $result['source']);
        $this->assertNotNull($result['raw_payload_hash']);
    }

    public function test_returns_null_on_api_error(): void
    {
        Http::fake(['*norges-bank*' => Http::response('', 500)]);

        $result = $this->client->fetch('USD', 'NOK');

        $this->assertNull($result);
    }

    public function test_returns_null_when_response_has_no_observations(): void
    {
        Http::fake(['*norges-bank*' => Http::response([
            'data' => ['dataSets' => [['series' => ['0:0:0:0' => ['observations' => []]]]], 'structure' => ['dimensions' => ['observation' => [['values' => []]]]]],
        ], 200)]);

        $result = $this->client->fetch('USD', 'NOK');

        $this->assertNull($result);
    }

    public function test_falls_back_to_earlier_observation_when_last_is_null(): void
    {
        Http::fake([
            '*norges-bank*' => Http::response($this->fakeNorgesBankResponse(10.75, '2026-06-02'), 200),
        ]);

        $result = $this->client->fetch('USD', 'NOK', '2026-06-03');

        $this->assertNotNull($result);
        $this->assertSame('2026-06-02', $result['rate_date']);
    }

    private function fakeNorgesBankResponse(float $rate, string $date): array
    {
        return [
            'data' => [
                'dataSets' => [[
                    'series' => [
                        '0:0:0:0' => ['observations' => ['0' => [$rate, 0]]],
                    ],
                ]],
                'structure' => [
                    'dimensions' => [
                        'observation' => [[
                            'values' => [['id' => $date, 'name' => $date]],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
