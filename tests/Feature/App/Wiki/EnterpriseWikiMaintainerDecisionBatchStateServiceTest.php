<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiMaintainerDecisionBatch;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionBatchStateService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionBatchStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_state_is_idempotent_leased_and_ordered(): void
    {
        $run = $this->ingestRun();
        $service = app(EnterpriseWikiMaintainerDecisionBatchStateService::class);
        $service->createBatches($run->id, [['candidate' => 'first'], ['candidate' => 'second']]);
        $service->createBatches($run->id, [['candidate' => 'first'], ['candidate' => 'second']]);
        $this->assertSame(2, EnterpriseWikiMaintainerDecisionBatch::query()->count());

        $first = $service->reserve($run->id, 1);
        $this->assertNotNull($first);
        $this->assertNull($service->reserve($run->id, 1));
        $this->assertFalse($service->complete($run->id, 1, 'wrong-token', ['order' => 1]));
        $this->assertTrue($service->complete($run->id, 1, $first['token'], ['order' => 1]));
        $this->assertNull($service->reserve($run->id, 1));

        $second = $service->reserve($run->id, 2);
        $this->assertNotNull($second);
        $this->assertTrue($service->fail($run->id, 2, $second['token'], 'batch two failed'));
        $this->assertSame('batch two failed', EnterpriseWikiMaintainerDecisionBatch::query()->where('batch_number', 2)->value('error_message'));

        EnterpriseWikiMaintainerDecisionBatch::query()->where('batch_number', 2)->update(['status' => EnterpriseWikiMaintainerDecisionBatch::STATUS_COMPLETED, 'result_payload' => ['order' => 2]]);
        $this->assertSame([['order' => 1], ['order' => 2]], $service->completedResults($run->id));
    }

    public function test_unique_run_and_batch_number_is_enforced(): void
    {
        $run = $this->ingestRun();
        EnterpriseWikiMaintainerDecisionBatch::query()->create(['enterprise_wiki_ingest_run_id' => $run->id, 'batch_number' => 1, 'total_batches' => 1, 'input_payload' => [], 'status' => 'pending']);
        $this->expectException(UniqueConstraintViolationException::class);
        EnterpriseWikiMaintainerDecisionBatch::query()->create(['enterprise_wiki_ingest_run_id' => $run->id, 'batch_number' => 1, 'total_batches' => 1, 'input_payload' => [], 'status' => 'pending']);
    }

    private function ingestRun(): EnterpriseWikiIngestRun
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create(['name' => 'Batch state', 'slug' => 'batch-state-'.Str::random(8), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'billing_interval' => Customer::BILLING_MONTHLY, 'is_active' => true]);
        $document = EnterpriseWikiDocument::query()->create(['customer_id' => $customer->id, 'original_filename' => 'x.pdf', 'file_path' => 'x.pdf', 'file_hash_sha256' => hash('sha256', 'x'), 'extracted_text' => 'x', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED]);

        return EnterpriseWikiIngestRun::query()->create(['uuid' => Str::uuid()->toString(), 'customer_id' => $customer->id, 'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL, 'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION]);
    }
}
