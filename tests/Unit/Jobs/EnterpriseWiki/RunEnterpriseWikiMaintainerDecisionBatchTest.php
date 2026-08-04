<?php

namespace Tests\Unit\Jobs\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiMaintainerDecisionBatch;
use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchEvaluator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunEnterpriseWikiMaintainerDecisionBatchTest extends TestCase
{
    public function test_job_uses_dedicated_queue_and_completes_reserved_batch(): void
    {
        Queue::fake();
        $job = new RunEnterpriseWikiMaintainerDecisionBatch(12, 3);
        $batch = new EnterpriseWikiMaintainerDecisionBatch(['batch_number' => 3, 'input_payload' => ['candidates' => ['A']]]);
        $state = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $state->shouldReceive('reserve')->once()->with(12, 3)->andReturn(['batch' => $batch, 'token' => 'lease']);
        $state->shouldReceive('complete')->once()->with(12, 3, 'lease', ['decision' => 'create'])->andReturnTrue();
        $evaluator = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchEvaluator::class);
        $evaluator->shouldReceive('evaluate')->once()->with(12, $batch)->andReturn(['decision' => 'create']);
        $job->handle($state, $evaluator);
        $this->assertSame('enterprise-wiki-maintainer-batches', $job->queue);
    }

    public function test_completed_or_leased_batch_is_skipped(): void
    {
        $job = new RunEnterpriseWikiMaintainerDecisionBatch(12, 4);
        $state = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $evaluator = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchEvaluator::class);
        $state->shouldReceive('reserve')->once()->andReturnNull();
        $evaluator->shouldNotReceive('evaluate');
        $job->handle($state, $evaluator);
        $this->assertTrue(true);
    }

    public function test_evaluator_failure_is_persisted_with_batch_number_and_rethrown(): void
    {
        $job = new RunEnterpriseWikiMaintainerDecisionBatch(12, 4);
        $batch = new EnterpriseWikiMaintainerDecisionBatch(['batch_number' => 4]);
        $state = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $evaluator = Mockery::mock(EnterpriseWikiMaintainerDecisionBatchEvaluator::class);
        $state->shouldReceive('reserve')->once()->andReturn(['batch' => $batch, 'token' => 'lease']);
        $evaluator->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('original failure'));
        $state->shouldReceive('fail')->once()->with(12, 4, 'lease', Mockery::on(fn ($message) => str_contains($message, 'batch [4]') && str_contains($message, 'original failure')))->andReturnTrue();
        $this->expectExceptionMessage('original failure');
        $job->handle($state, $evaluator);
    }
}
