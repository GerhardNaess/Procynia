<?php

namespace Tests\Feature\Ai\Pricing;

use App\Models\AdminNotification;
use App\Models\AiModelPrice;
use App\Models\AiModelPriceSyncRun;
use App\Services\Admin\AdminNotificationService;
use App\Services\Ai\Pricing\AiModelPriceProviderInterface;
use App\Services\Ai\Pricing\AiModelPriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiModelPriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiModelPriceSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiModelPriceSyncService(new AdminNotificationService());
    }

    public function test_new_model_price_creates_active_row(): void
    {
        Carbon::setTestNow('2026-06-03');

        $provider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => 0.50, 'source_url' => null, 'raw_payload_hash' => 'abc123'],
        ]);

        $this->service->sync($provider);

        $price = AiModelPrice::query()->where('model', 'gpt-4.1')->first();
        $this->assertNotNull($price);
        $this->assertTrue($price->is_active);
        $this->assertSame('2026-06-03', $price->valid_from->toDateString());
        $this->assertNull($price->valid_to);
        $this->assertSame('openai', $price->provider);

        Carbon::setTestNow();
    }

    public function test_unchanged_price_only_updates_last_seen_at(): void
    {
        Carbon::setTestNow('2026-06-03');

        $provider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'xyz999'],
        ]);

        $this->service->sync($provider);
        $firstCount = AiModelPrice::query()->count();

        $this->service->sync($provider);
        $this->assertSame($firstCount, AiModelPrice::query()->count(), 'No new row on unchanged price.');

        Carbon::setTestNow();
    }

    public function test_changed_price_closes_old_row_and_creates_new_active_row(): void
    {
        Carbon::setTestNow('2026-06-01');

        $oldProvider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'old-hash'],
        ]);
        $this->service->sync($oldProvider);

        Carbon::setTestNow('2026-06-03');

        $newProvider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 3.00, 'output_price_per_1m_tokens' => 10.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'new-hash'],
        ]);
        $this->service->sync($newProvider);

        $all = AiModelPrice::query()->where('model', 'gpt-4.1')->orderBy('id')->get();
        $this->assertCount(2, $all);

        $old = $all->first();
        $this->assertFalse($old->is_active);
        $this->assertSame('2026-06-03', $old->valid_to->toDateString());

        $new = $all->last();
        $this->assertTrue($new->is_active);
        $this->assertNull($new->valid_to);
        $this->assertSame('3.000000', (string) $new->input_price_per_1m_tokens);

        Carbon::setTestNow();
    }

    public function test_missing_model_from_feed_is_not_deleted(): void
    {
        Carbon::setTestNow('2026-06-01');

        $firstProvider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'h1'],
        ]);
        $this->service->sync($firstProvider);

        Carbon::setTestNow('2026-06-03');

        $secondProvider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1-mini', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 0.40, 'output_price_per_1m_tokens' => 1.60,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'h2'],
        ]);
        $this->service->sync($secondProvider);

        $this->assertSame(1, AiModelPrice::query()->where('model', 'gpt-4.1')->count(),
            'gpt-4.1 must still exist even though it was absent from the second feed.');

        Carbon::setTestNow();
    }

    public function test_price_change_creates_admin_notification(): void
    {
        Carbon::setTestNow('2026-06-01');

        $this->service->sync($this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'old'],
        ]));

        Carbon::setTestNow('2026-06-03');

        $this->service->sync($this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 3.00, 'output_price_per_1m_tokens' => 12.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'new'],
        ]));

        $notification = AdminNotification::query()
            ->where('type', AdminNotification::TYPE_AI_PRICE_CHANGED)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(AdminNotification::SEVERITY_WARNING, $notification->severity);

        Carbon::setTestNow();
    }

    public function test_new_price_creates_admin_notification(): void
    {
        Carbon::setTestNow('2026-06-03');

        $this->service->sync($this->fakeProvider('openai', [
            ['model' => 'gpt-5', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 15.00, 'output_price_per_1m_tokens' => 75.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'h1'],
        ]));

        $notification = AdminNotification::query()
            ->where('type', AdminNotification::TYPE_AI_PRICE_CREATED)
            ->first();

        $this->assertNotNull($notification);

        Carbon::setTestNow();
    }

    public function test_duplicate_price_change_notification_is_not_created(): void
    {
        Carbon::setTestNow('2026-06-01');
        $this->service->sync($this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 2.00, 'output_price_per_1m_tokens' => 8.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'old'],
        ]));

        Carbon::setTestNow('2026-06-03');
        $newProvider = $this->fakeProvider('openai', [
            ['model' => 'gpt-4.1', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 3.00, 'output_price_per_1m_tokens' => 12.00,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => 'new'],
        ]);

        $this->service->sync($newProvider);
        $countAfterFirst = AdminNotification::query()->where('type', AdminNotification::TYPE_AI_PRICE_CHANGED)->count();

        $this->service->sync($newProvider);
        $countAfterSecond = AdminNotification::query()->where('type', AdminNotification::TYPE_AI_PRICE_CHANGED)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Duplicate price-change notification must not be created.');

        Carbon::setTestNow();
    }

    public function test_sync_run_is_logged(): void
    {
        Carbon::setTestNow('2026-06-03');

        $this->service->sync($this->fakeProvider('openai', [
            ['model' => 'gpt-4.1-mini', 'currency' => 'usd',
             'input_price_per_1m_tokens' => 0.40, 'output_price_per_1m_tokens' => 1.60,
             'cached_input_price_per_1m_tokens' => null, 'source_url' => null, 'raw_payload_hash' => null],
        ]));

        $run = AiModelPriceSyncRun::query()->where('provider', 'openai')->first();
        $this->assertNotNull($run);
        $this->assertSame(AiModelPriceSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->models_seen);
        $this->assertNotNull($run->finished_at);

        Carbon::setTestNow();
    }

    private function fakeProvider(string $providerKey, array $prices): AiModelPriceProviderInterface
    {
        return new class($providerKey, $prices) implements AiModelPriceProviderInterface {
            public function __construct(
                private readonly string $key,
                private readonly array $prices,
            ) {}

            public function providerKey(): string { return $this->key; }
            public function fetchPrices(): array  { return $this->prices; }
        };
    }
}
