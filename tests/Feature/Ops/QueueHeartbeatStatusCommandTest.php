<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the two read-only artisan commands used as the Docker Compose `healthcheck:` for the
 * queue worker and scheduler containers (see docker-compose.yml). Neither command writes data or
 * dispatches a job — they only read the existing heartbeat cache keys already exercised by
 * QueueHeartbeatHealthTest/QueueSchedulerHealthTest.
 */
class QueueHeartbeatStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    public function test_ops_queue_heartbeat_status_exits_zero_when_fresh(): void
    {
        Cache::put('ops.queue.heartbeat.enterprise-wiki', now()->timestamp);

        $this->artisan('ops:queue-heartbeat-status enterprise-wiki')
            ->assertExitCode(0);
    }

    public function test_ops_queue_heartbeat_status_exits_one_when_missing(): void
    {
        Cache::forget('ops.queue.heartbeat.enterprise-wiki-pages');

        $this->artisan('ops:queue-heartbeat-status enterprise-wiki-pages')
            ->assertExitCode(1);
    }

    public function test_ops_queue_heartbeat_status_exits_one_when_stale(): void
    {
        Cache::put('ops.queue.heartbeat.ai-requirements', now()->subSeconds(301)->timestamp);

        $this->artisan('ops:queue-heartbeat-status ai-requirements')
            ->assertExitCode(1);
    }

    public function test_ops_queue_heartbeat_status_exits_one_for_unknown_queue(): void
    {
        $this->artisan('ops:queue-heartbeat-status unknown-queue')
            ->assertExitCode(1);
    }

    public function test_ops_scheduler_heartbeat_status_exits_zero_when_fresh(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp);

        $this->artisan('ops:scheduler-heartbeat-status')
            ->assertExitCode(0);
    }

    public function test_ops_scheduler_heartbeat_status_exits_one_when_missing(): void
    {
        Cache::forget('ops.scheduler.heartbeat');

        $this->artisan('ops:scheduler-heartbeat-status')
            ->assertExitCode(1);
    }

    public function test_ops_scheduler_heartbeat_status_exits_one_when_stale(): void
    {
        Cache::put('ops.scheduler.heartbeat', now()->subSeconds(301)->timestamp);

        $this->artisan('ops:scheduler-heartbeat-status')
            ->assertExitCode(1);
    }
}
