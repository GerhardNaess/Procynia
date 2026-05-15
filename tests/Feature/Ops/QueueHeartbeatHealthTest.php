<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueHeartbeatHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('supportedQueuesProvider')]
    public function test_supported_queue_endpoints_return_ok_when_heartbeat_is_fresh(string $queue): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
        Cache::put($this->heartbeatKey($queue), now()->timestamp);

        $this->getJson("/ops/health/queues/{$queue}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('queue', $queue)
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('seconds_since_last_processed', 0);
    }

    public function test_missing_heartbeat_returns_503(): void
    {
        $queue = 'supplier-harvests';

        $this->getJson("/ops/health/queues/{$queue}")
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('queue', $queue)
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('last_processed_at', null);
    }

    public function test_stale_heartbeat_returns_503(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
        $queue = 'supplier-lookups';
        Cache::put($this->heartbeatKey($queue), now()->subSeconds(301)->timestamp);

        $this->getJson("/ops/health/queues/{$queue}")
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('queue', $queue)
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('seconds_since_last_processed', 301);
    }

    public function test_unknown_queue_returns_404(): void
    {
        $this->getJson('/ops/health/queues/unknown-queue')
            ->assertStatus(404)
            ->assertJsonPath('status', 'fail');
    }

    public function test_heartbeat_for_one_queue_does_not_make_another_queue_green(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
        Cache::put($this->heartbeatKey('supplier-harvests'), now()->timestamp);

        $this->getJson('/ops/health/queues/supplier-lookups')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('queue', 'supplier-lookups');
    }

    public static function supportedQueuesProvider(): array
    {
        return [
            'supplier-harvests' => ['supplier-harvests'],
            'supplier-lookups' => ['supplier-lookups'],
            'ai-requirements' => ['ai-requirements'],
            'default' => ['default'],
        ];
    }

    private function heartbeatKey(string $queue): string
    {
        return 'ops.queue.heartbeat.'.$queue;
    }
}
