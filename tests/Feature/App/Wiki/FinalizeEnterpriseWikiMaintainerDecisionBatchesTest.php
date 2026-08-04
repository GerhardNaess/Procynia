<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiMaintainerDecisionBatches;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FinalizeEnterpriseWikiMaintainerDecisionBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_batches_wait_failed_batches_throw_and_completed_batches_finalize_once(): void
    {
        Queue::fake();
        $run = $this->ingestRun();
        $state = app(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $state->createBatches($run->id, [['global_plan' => ['plan' => true]], ['global_plan' => ['plan' => true]]]);
        $job = new FinalizeEnterpriseWikiMaintainerDecisionBatches($run->id);
        $coordinator = Mockery::mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $decision = Mockery::mock(EnterpriseWikiMaintainerDecisionService::class);
        $flow = Mockery::mock(EnterpriseWikiDocumentFlowService::class);
        $coordinator->shouldNotReceive('mergePersistedBatchResults');
        $decision->shouldNotReceive('validateAndRepairForDocument');
        $flow->shouldNotReceive('persistMaintainerDecision');
        $job->handle($state, $coordinator, $decision, $flow);

        $first = $state->reserve($run->id, 1);
        $second = $state->reserve($run->id, 2);
        $state->complete($run->id, 2, $second['token'], ['batch' => 2]);
        $state->fail($run->id, 1, $first['token'], 'original failure');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('batch [1] failed: original failure');
        $job->handle($state, $coordinator, $decision, $flow);
    }

    public function test_completed_batches_merge_in_order_validate_and_persist_once(): void
    {
        Queue::fake();
        $run = $this->ingestRun();
        $state = app(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $state->createBatches($run->id, [['global_plan' => ['plan' => true]], ['global_plan' => ['plan' => true]]]);
        foreach ([1 => ['batch' => 1], 2 => ['batch' => 2]] as $number => $result) {
            $reserved = $state->reserve($run->id, $number);
            $state->complete($run->id, $number, $reserved['token'], $result);
        }
        $coordinator = Mockery::mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $decision = Mockery::mock(EnterpriseWikiMaintainerDecisionService::class);
        $flow = Mockery::mock(EnterpriseWikiDocumentFlowService::class);
        $coordinator->shouldReceive('mergePersistedBatchResults')->once()->with(['plan' => true], [['batch' => 1], ['batch' => 2]])->andReturn(['merged' => true]);
        $decision->shouldReceive('validateAndRepairForDocument')->once()->andReturn(['final' => true]);
        $flow->shouldReceive('persistMaintainerDecision')->once()->andReturnTrue();
        $flow->shouldReceive('continueAfterMaintainerDecisionBatches')->once()->with($run->id);
        (new FinalizeEnterpriseWikiMaintainerDecisionBatches($run->id))->handle($state, $coordinator, $decision, $flow);
        $run->update(['maintainer_decision_generated_at' => now()]);
        $coordinator->shouldNotReceive('mergePersistedBatchResults');
        $decision->shouldNotReceive('validateAndRepairForDocument');
        $flow->shouldNotReceive('persistMaintainerDecision');
        $flow->shouldNotReceive('continueAfterMaintainerDecisionBatches');
        (new FinalizeEnterpriseWikiMaintainerDecisionBatches($run->id))->handle($state, $coordinator, $decision, $flow);
        $this->assertSame('enterprise-wiki-maintainer-batches', (new FinalizeEnterpriseWikiMaintainerDecisionBatches($run->id))->queue);
    }

    private function ingestRun(): EnterpriseWikiIngestRun
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create(['name' => 'Fan in', 'slug' => 'fanin-'.Str::random(8), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'billing_interval' => Customer::BILLING_MONTHLY, 'is_active' => true]);
        $document = EnterpriseWikiDocument::query()->create(['customer_id' => $customer->id, 'original_filename' => 'x.pdf', 'file_path' => 'x.pdf', 'file_hash_sha256' => hash('sha256', Str::random()), 'extracted_text' => 'x', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED]);

        return EnterpriseWikiIngestRun::query()->create(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL, 'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION]);
    }
}
