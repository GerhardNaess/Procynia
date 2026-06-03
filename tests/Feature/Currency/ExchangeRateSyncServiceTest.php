<?php

namespace Tests\Feature\Currency;

use App\Models\AdminNotification;
use App\Models\ExchangeRate;
use App\Services\Admin\AdminNotificationService;
use App\Services\Currency\ExchangeRateSyncService;
use App\Services\Currency\NorgesBankExchangeRateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ExchangeRateSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(NorgesBankExchangeRateClient $client): ExchangeRateSyncService
    {
        return new ExchangeRateSyncService($client, new AdminNotificationService());
    }

    private function fakeDto(float $rate = 10.75, string $date = '2026-06-03'): array
    {
        return [
            'base_currency'    => 'USD',
            'quote_currency'   => 'NOK',
            'rate'             => $rate,
            'rate_date'        => $date,
            'source'           => 'norges_bank',
            'raw_payload_hash' => hash('sha256', (string) $rate.$date),
        ];
    }

    public function test_new_rate_is_created(): void
    {
        Carbon::setTestNow('2026-06-03 17:00:00');

        $client = Mockery::mock(NorgesBankExchangeRateClient::class);
        $client->shouldReceive('fetch')->andReturn($this->fakeDto(10.75));

        $this->makeService($client)->sync('USD', 'NOK');

        $rate = ExchangeRate::query()->where('rate_date', '2026-06-03')->first();
        $this->assertNotNull($rate);
        $this->assertSame('10.75000000', (string) $rate->rate);

        Carbon::setTestNow();
    }

    public function test_same_rate_does_not_create_duplicate(): void
    {
        Carbon::setTestNow('2026-06-03 17:00:00');

        $dto    = $this->fakeDto(10.75);
        $client = Mockery::mock(NorgesBankExchangeRateClient::class);
        $client->shouldReceive('fetch')->twice()->andReturn($dto);

        $svc = $this->makeService($client);
        $svc->sync('USD', 'NOK');
        $svc->sync('USD', 'NOK');

        $this->assertSame(1, ExchangeRate::query()->where('rate_date', '2026-06-03')->count());

        Carbon::setTestNow();
    }

    public function test_changed_rate_for_same_date_creates_admin_notification(): void
    {
        Carbon::setTestNow('2026-06-03 17:00:00');

        $client = Mockery::mock(NorgesBankExchangeRateClient::class);
        $client->shouldReceive('fetch')
            ->once()->andReturn($this->fakeDto(10.75))
            ->getMock()
            ->shouldReceive('fetch')
            ->once()->andReturn($this->fakeDto(10.99));

        $svc = $this->makeService($client);
        $svc->sync('USD', 'NOK');
        $svc->sync('USD', 'NOK');

        $this->assertNotNull(
            AdminNotification::query()->where('type', 'exchange_rate_changed')->first(),
        );

        Carbon::setTestNow();
    }

    public function test_sync_failure_creates_admin_notification(): void
    {
        Carbon::setTestNow('2026-06-03 17:00:00');

        $client = Mockery::mock(NorgesBankExchangeRateClient::class);
        $client->shouldReceive('fetch')->andReturn(null);

        $result = $this->makeService($client)->sync('USD', 'NOK');

        $this->assertNull($result);
        $this->assertNotNull(
            AdminNotification::query()->where('type', AdminNotification::TYPE_AI_PRICE_SYNC_FAILED)->first(),
        );

        Carbon::setTestNow();
    }

    public function test_nearest_earlier_rate_can_be_fetched(): void
    {
        Carbon::setTestNow('2026-06-05 17:00:00');

        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK',
            'rate' => 10.80, 'rate_date' => '2026-06-03',
            'source' => 'norges_bank', 'fetched_at' => now(),
        ]);

        $found = ExchangeRate::findForDate('USD', 'NOK', Carbon::parse('2026-06-05'));

        $this->assertNotNull($found);
        $this->assertSame('2026-06-03', $found->rate_date->toDateString());

        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
