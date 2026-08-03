<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunRecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-592: a run that failed transiently during maintainer_decision never persisted a
 * decision or any page, so "resuming" it is functionally identical to a fresh queued run — this
 * service's whole job is deciding WHEN that is actually safe, then doing exactly that (same
 * run_id, same document, no re-extraction, no new import).
 */
class EnterpriseWikiMaintainerDecisionFailureRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EnterpriseWikiMaintainerDecisionFailureRecoveryService
    {
        return app(EnterpriseWikiMaintainerDecisionFailureRecoveryService::class);
    }

    public function test_resumable_run_is_resumed_and_job_is_dispatched(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);
        $this->assertTrue($result->isResumed());

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->status);
        $this->assertNull($run->failed_phase);
        $this->assertNull($run->transient_failure);
        $this->assertNull($run->error_message);
        $this->assertNull($run->finished_at);

        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, fn ($job) => $job->runId === $run->id);
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);
    }

    public function test_resume_reuses_the_same_run_id_and_document_without_re_extraction(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $originalDocumentId = $run->source_id;
        $originalExtractedText = EnterpriseWikiDocument::query()->find($originalDocumentId)->extracted_text;

        $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, EnterpriseWikiIngestRun::query()->where('customer_id', $customer->id)->count());

        $document = EnterpriseWikiDocument::query()->find($originalDocumentId);
        $this->assertNotNull($document);
        $this->assertSame($originalExtractedText, $document->extracted_text);
        $this->assertSame($originalDocumentId, $run->fresh()->source_id);
    }

    public function test_double_recovery_call_dispatches_only_one_job(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);

        $first = $this->service()->attempt($run->id, caller: 'test-first');
        $second = $this->service()->attempt($run->id, caller: 'test-second');

        $this->assertTrue($first->isResumed());
        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $second->outcome);

        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);
    }

    public function test_non_transient_failure_is_not_recoverable(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['transient_failure' => false]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_unclassified_failure_is_not_recoverable(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['transient_failure' => null]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_failure_in_a_different_phase_is_not_recoverable(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['failed_phase' => EnterpriseWikiIngestRun::STATUS_QA]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_run_with_already_applied_decision_is_not_wrongly_resumed(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_run_with_an_existing_applied_page_is_not_wrongly_resumed(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'artikkel-'.Str::lower(Str::random(6)),
            'title' => 'Artikkel',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => 'created',
        ]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_missing_document_is_reported_as_missing_dependencies(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        EnterpriseWikiDocument::query()->find($run->source_id)->delete();

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_MISSING_DEPENDENCIES, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_document_with_no_extracted_text_is_reported_as_missing_dependencies(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        EnterpriseWikiDocument::query()->find($run->source_id)->update(['extracted_text' => '']);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_MISSING_DEPENDENCIES, $result->outcome);
        Queue::assertNothingPushed();
    }

    public function test_run_not_found_is_not_recoverable(): void
    {
        $result = $this->service()->evaluate(999999);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
    }

    public function test_run_still_actively_processing_is_already_running(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION]);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $result->outcome);
    }

    public function test_completed_run_is_already_complete(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED]);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_COMPLETE, $result->outcome);
    }

    public function test_evaluate_is_read_only_and_never_dispatches(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createTransientlyFailedRun($customer);

        $result = $this->service()->evaluate($run->id);

        $this->assertTrue($result->isResumed());
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'run-592-source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Extracted source text for the run-592 recovery fixture.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * Builds a run-592-shaped fixture: status=failed, failed_phase=maintainer_decision,
     * transient_failure=true, no maintainer_decision_status, no applied pages — the exact state
     * EnterpriseWikiDocumentFlowService::markRunFailed() leaves behind after a documented
     * transient HTTP/network failure on the very first maintainer_decision AI call.
     */
    private function createTransientlyFailedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'failed_phase' => EnterpriseWikiIngestRun::STATUS_MAINTAINER_DECISION,
            'transient_failure' => true,
            'maintainer_decision_attempt_count' => 1,
            'error_message' => 'cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received',
            'finished_at' => now(),
        ]);
    }
}
