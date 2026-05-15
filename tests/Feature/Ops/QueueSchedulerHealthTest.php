<?php

namespace Tests\Feature\Ops;

use App\Jobs\OpsQueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueueSchedulerHealthTest extends TestCase
{
    private const TEST_TOKEN = 'test-health-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['procynia.health_token' => self::TEST_TOKEN]);
    }

    public function test_returns_200_when_both_heartbeats_are_fresh(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(200)
            ->assertExactJson(['ok' => true, 'scheduler' => 'ok', 'queue' => 'ok']);
    }

    public function test_returns_503_when_scheduler_heartbeat_is_missing(): void
    {
        Cache::forget('ops.scheduler.heartbeat');
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'ok']);
    }

    public function test_returns_503_when_queue_heartbeat_is_missing(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::forget('ops.queue.heartbeat');

        $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'ok', 'queue' => 'stale']);
    }

    public function test_returns_503_when_both_heartbeats_are_missing(): void
    {
        Cache::forget('ops.scheduler.heartbeat');
        Cache::forget('ops.queue.heartbeat');

        $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'stale']);
    }

    public function test_returns_503_when_scheduler_heartbeat_is_stale(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->subMinutes(10)->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['ok' => false, 'scheduler' => 'stale', 'queue' => 'ok']);
    }

    public function test_response_contains_only_expected_keys(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);
        Cache::put('ops.queue.heartbeat', now()->timestamp);

        $body = $this->withHealthToken(self::TEST_TOKEN)
            ->getJson('/ops/health/queue-scheduler')
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

    public function test_endpoint_requires_token_and_returns_403_without_it(): void
    {
        $this->getJson('/ops/health/queue-scheduler')
            ->assertStatus(403)
            ->assertExactJson(['status' => 'fail', 'message' => 'Forbidden']);
    }

    public function test_endpoint_returns_403_with_wrong_token(): void
    {
        $this->withHeaders(['X-Procynia-Health-Token' => 'wrong-token'])
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(403)
            ->assertExactJson(['status' => 'fail', 'message' => 'Forbidden']);
    }

    public function test_endpoint_returns_503_when_token_is_not_configured(): void
    {
        config(['procynia.health_token' => '']);

        $this->withHeaders(['X-Procynia-Health-Token' => self::TEST_TOKEN])
            ->getJson('/ops/health/queue-scheduler')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'message' => 'Health token is not configured']);
    }

    private function withHealthToken(string $token): static
    {
        return $this->withHeaders(['X-Procynia-Health-Token' => $token]);
    }
}
