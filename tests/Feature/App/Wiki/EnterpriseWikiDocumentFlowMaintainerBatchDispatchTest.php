<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiMaintainerDecisionBatches;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiMaintainerDecisionBatch;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiIncrementalRelinkService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class EnterpriseWikiDocumentFlowMaintainerBatchDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_persists_and_dispatches_candidate_batches_once(): void
    {
        Queue::fake();
        $run = $this->ingestRun();
        $decision = Mockery::mock(EnterpriseWikiMaintainerDecisionService::class);
        $decision->shouldReceive('preparePersistedCandidateBatchesForDocument')->once()->andReturn([
            'global_plan' => ['concept_candidate_mentions' => [['name' => 'A']]],
            'batches' => [
                ['global_plan' => ['concept_candidate_mentions' => [['name' => 'A']]], 'mentions' => [['name' => 'A']], 'batch_number' => 1, 'total_batches' => 2],
                ['global_plan' => ['concept_candidate_mentions' => [['name' => 'A']]], 'mentions' => [['name' => 'B']], 'batch_number' => 2, 'total_batches' => 2],
            ],
        ]);

        $this->flow($decision)->run($run->id);

        Queue::assertPushed(RunEnterpriseWikiMaintainerDecisionBatch::class, 2);
        Queue::assertPushed(FinalizeEnterpriseWikiMaintainerDecisionBatches::class, 1);
        $this->assertSame(2, app(EnterpriseWikiMaintainerDecisionBatchStateService::class)->summary($run->id)['total']);
    }

    public function test_existing_batch_state_is_reentered_without_new_global_plan(): void
    {
        Queue::fake();
        $run = $this->ingestRun();
        app(EnterpriseWikiMaintainerDecisionBatchStateService::class)->createBatches($run->id, [[
            'global_plan' => ['concept_candidate_mentions' => []], 'mentions' => [], 'batch_number' => 1, 'total_batches' => 1,
        ]]);
        $decision = Mockery::mock(EnterpriseWikiMaintainerDecisionService::class);
        $decision->shouldNotReceive('preparePersistedCandidateBatchesForDocument');

        $this->flow($decision)->run($run->id);

        Queue::assertPushed(RunEnterpriseWikiMaintainerDecisionBatch::class, 1);
        Queue::assertPushed(FinalizeEnterpriseWikiMaintainerDecisionBatches::class, 1);
    }

    private function flow(EnterpriseWikiMaintainerDecisionService $decision): EnterpriseWikiDocumentFlowService
    {
        return new EnterpriseWikiDocumentFlowService(
            Mockery::mock(EnterpriseWikiIngestService::class), $decision, app(EnterpriseWikiMaintainerDecisionBatchStateService::class),
            Mockery::mock(EnterpriseWikiMaintainerDecisionApplyService::class), Mockery::mock(EnterpriseWikiExtractPageClaimsService::class), Mockery::mock(EnterpriseWikiVerifyPageClaimsService::class), Mockery::mock(EnterpriseWikiBuildPageLinksService::class), Mockery::mock(EnterpriseWikiIncrementalRelinkService::class), Mockery::mock(EnterpriseWikiAppliedRunLintService::class), Mockery::mock(EnterpriseWikiLinkSemanticRepairService::class), Mockery::mock(EnterpriseWikiPostIngestQaService::class), Mockery::mock(EnterpriseWikiDocumentOwnerApprovalService::class),
        );
    }

    private function ingestRun(): EnterpriseWikiIngestRun
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create(['name' => 'Flow batches', 'slug' => 'flow-batches-'.Str::random(8), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'billing_interval' => Customer::BILLING_MONTHLY, 'is_active' => true]);
        $document = EnterpriseWikiDocument::query()->create(['customer_id' => $customer->id, 'original_filename' => 'x.pdf', 'file_path' => 'x.pdf', 'file_hash_sha256' => hash('sha256', Str::random()), 'extracted_text' => 'x', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED]);

        return EnterpriseWikiIngestRun::query()->create(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL, 'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_QUEUED]);
    }
}
