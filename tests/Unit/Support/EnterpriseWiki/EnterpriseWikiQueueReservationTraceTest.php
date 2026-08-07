<?php

namespace Tests\Unit\Support\EnterpriseWiki;

use App\Support\EnterpriseWiki\EnterpriseWikiQueueReservationTrace;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Wiki run status visibility fix: EnterpriseWikiQueueReservationTrace::logReservationCycle()
 * previously logged '[PROCYNIA][WIKI_QUEUE_RESERVATION_TRACE] reservation_cycle' at INFO on
 * every JobPopping event — i.e. every queue-worker poll attempt, often every ~3 seconds,
 * regardless of whether the queue held anything. This is diagnostic polling noise, not a real
 * Wiki event, and is now silent by default (config('services.enterprise_wiki.queue_reservation_trace_debug')),
 * only logging at DEBUG level when explicitly opted in. Real events — job_reserved
 * (logReservation) and dispatch_enqueued (logDispatch) — are unconditional and unaffected.
 */
class EnterpriseWikiQueueReservationTraceTest extends TestCase
{
    public function test_empty_reservation_cycle_does_not_log_by_default(): void
    {
        config(['services.enterprise_wiki.queue_reservation_trace_debug' => false]);
        Log::spy();

        EnterpriseWikiQueueReservationTrace::logReservationCycle(
            new JobPopping('redis', 'enterprise-wiki-claim-verification'),
        );

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_reservation_cycle_logs_at_debug_level_only_when_explicitly_enabled(): void
    {
        config(['services.enterprise_wiki.queue_reservation_trace_debug' => true]);
        Log::spy();

        EnterpriseWikiQueueReservationTrace::logReservationCycle(
            new JobPopping('redis', 'enterprise-wiki-claim-verification'),
        );

        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('debug')->once()->withArgs(
            fn (string $message) => str_contains($message, '[PROCYNIA][WIKI_QUEUE_RESERVATION_TRACE] reservation_cycle'),
        );
    }

    public function test_reservation_cycle_for_an_unrelated_queue_never_logs_even_when_debug_enabled(): void
    {
        config(['services.enterprise_wiki.queue_reservation_trace_debug' => true]);
        Log::spy();

        EnterpriseWikiQueueReservationTrace::logReservationCycle(
            new JobPopping('redis', 'default'),
        );

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_a_real_reservation_is_always_logged_at_info_regardless_of_the_debug_flag(): void
    {
        config(['services.enterprise_wiki.queue_reservation_trace_debug' => false]);
        Log::spy();

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn('enterprise-wiki-claim-verification');
        $job->shouldReceive('payload')->andReturn(['uuid' => 'job-uuid', 'createdAt' => time()]);
        $job->shouldReceive('getJobId')->andReturn('job-id-123');
        $job->shouldReceive('uuid')->andReturn('job-uuid');

        EnterpriseWikiQueueReservationTrace::logReservation(new JobPopped('redis', $job));

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message) => str_contains($message, '[PROCYNIA][WIKI_QUEUE_RESERVATION_TRACE] job_reserved'),
        );
        Log::shouldNotHaveReceived('debug');
    }

    public function test_a_real_dispatch_is_always_logged_at_info_regardless_of_the_debug_flag(): void
    {
        config(['services.enterprise_wiki.queue_reservation_trace_debug' => false]);
        Log::spy();

        $payload = json_encode(['uuid' => 'job-uuid', 'createdAt' => time()]);

        EnterpriseWikiQueueReservationTrace::logDispatch(
            new JobQueued('redis', 'enterprise-wiki-claim-verification', 'job-id-123', 'SomeJobClass', $payload, null),
        );

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message) => str_contains($message, '[PROCYNIA][WIKI_QUEUE_RESERVATION_TRACE] dispatch_enqueued'),
        );
        Log::shouldNotHaveReceived('debug');
    }
}
