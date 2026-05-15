<?php

namespace Tests\Feature\Ops;

use App\Jobs\OpsQueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueueSchedulerHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_returns_200_when_both_heartbeats_are_fresh(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'scheduler' => 'ok', 'queue' => 'ok']);
    }

    public function test_returns_503_when_scheduler_heartbeat_is_missing(): void
    {
        Cache::forget('ops.scheduler.heartbeat');
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'ok']);
    }

    public function test_returns_503_when_queue_heartbeat_is_missing(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::forget('ops.queue.heartbeat');

        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'ok', 'queue' => 'stale']);
    }

    public function test_returns_503_when_both_heartbeats_are_missing(): void
    {
        Cache::forget('ops.scheduler.heartbeat');
        Cache::forget('ops.queue.heartbeat');

        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'stale']);
    }

    public function test_returns_503_when_scheduler_heartbeat_is_stale(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->subMinutes(10)->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'ok']);
    }

    public function test_response_contains_only_expected_keys(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $body = $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(200)
            ->json();

        $this->assertEqualsCanonicalizing(['ok', 'scheduler', 'queue'], array_keys($body));
    }

    public function test_scheduler_heartbeat_command_writes_to_cache(): void
    {
        Cache::forget('ops.scheduler.heartbeat');

        $this->artisan('ops:scheduler-heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get('ops.scheduler.heartbeat'));
    }

    public function test_queue_heartbeat_job_writes_to_cache(): void
    {
        Cache::forget('ops.queue.heartbeat');

        (new OpsQueueHeartbeatJob)->handle();

        $this->assertNotNull(Cache::get('ops.queue.heartbeat'));
        $this->assertNotNull(Cache::get('ops.queue.heartbeat.default'));
    }

    public function test_non_default_queue_heartbeat_does_not_touch_aggregate_cache_key(): void
    {
        Cache::forget('ops.queue.heartbeat');
        Cache::forget('ops.queue.heartbeat.supplier-harvests');

        (new OpsQueueHeartbeatJob('supplier-harvests'))->handle();

        $this->assertNull(Cache::get('ops.queue.heartbeat'));
        $this->assertNotNull(Cache::get('ops.queue.heartbeat.supplier-harvests'));
    }

    public function test_scheduler_registers_four_queue_heartbeat_jobs(): void
    {
        Artisan::call('schedule:list', ['--json' => true, '--next' => true]);
        $tasks = json_decode(Artisan::output(), true);

        $heartbeatJobs = array_values(array_filter($tasks, static fn ($task): bool => ($task['command'] ?? null) === 'App\\Jobs\\OpsQueueHeartbeatJob'));

        $this->assertCount(4, $heartbeatJobs);
    }

    public function test_endpoint_is_accessible_without_authentication(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->getJson('/ops/health/queue-scheduler')->assertStatus(200);
    }
}
