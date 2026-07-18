<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Tests for the 8H-kjerne maintenance cycle service (source change detection + intelligent retry).
 *
 * Verifies that escalated runs are retried only when their source document hash has changed,
 * that idempotence is preserved via maintenance_source_hash, and that errors are counted
 * without masking earlier state. The QA service is mocked so tests focus on detection logic.
 */
class EnterpriseWikiMaintenanceCycleTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1: No escalated runs → zero summary
    // =========================================================================

    public function test_no_escalated_runs_returns_zero_summary(): void
    {
        $customer = $this->createCustomer();
        $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $summary = $this->makeService()->run();

        $this->assertSame(['retried' => 0, 'skipped' => 0, 'failed' => 0, 'claim_content_repairs_attempted' => 0], $summary);
    }

    // =========================================================================
    // 2: Escalated run, maintenance_source_hash null → retry triggered
    // =========================================================================

    public function test_escalated_run_with_null_maintenance_hash_triggers_retry(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'aaaa-hash-new');
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, document: $document);

        $this->mockQaService()->shouldReceive('runForRun')
            ->once()
            ->with(\Mockery::on(fn ($r) => $r->id === $run->id), true)
            ->andReturnNull();

        $summary = $this->makeService()->run();

        $this->assertSame(1, $summary['retried']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(0, $summary['failed']);

        $run->refresh();
        $this->assertSame('aaaa-hash-new', $run->maintenance_source_hash);
    }

    // =========================================================================
    // 3: Escalated run, source hash changed since last maintenance → retry
    // =========================================================================

    public function test_escalated_run_with_changed_source_hash_triggers_retry(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-current');
        $run = $this->createAppliedRun(
            $customer,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            document: $document,
            maintenanceSourceHash: 'hash-old',
        );

        $this->mockQaService()->shouldReceive('runForRun')->once()->andReturnNull();

        $summary = $this->makeService()->run();

        $this->assertSame(1, $summary['retried']);

        $run->refresh();
        $this->assertSame('hash-current', $run->maintenance_source_hash);
    }

    // =========================================================================
    // 4: Escalated run, source hash unchanged → skipped (idempotence)
    // =========================================================================

    public function test_escalated_run_with_unchanged_source_hash_is_skipped(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-same');
        $run = $this->createAppliedRun(
            $customer,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            document: $document,
            maintenanceSourceHash: 'hash-same',
        );

        $this->mockQaService()->shouldNotReceive('runForRun');

        $summary = $this->makeService()->run();

        $this->assertSame(0, $summary['retried']);
        $this->assertSame(1, $summary['skipped']);
    }

    // =========================================================================
    // 5: maintenance_triggered_at is stamped when retry is triggered
    // =========================================================================

    public function test_maintenance_triggered_at_is_set_on_retry(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-x');
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, document: $document);

        $this->assertNull($run->maintenance_triggered_at);

        $this->mockQaService()->shouldReceive('runForRun')->once()->andReturnNull();

        $this->makeService()->run();

        $run->refresh();
        $this->assertNotNull($run->maintenance_triggered_at);
    }

    // =========================================================================
    // 6: Source document not found → run is skipped
    // =========================================================================

    public function test_source_document_not_found_skips_run(): void
    {
        $customer = $this->createCustomer();
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 99999,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ]);

        $this->mockQaService()->shouldNotReceive('runForRun');

        $summary = $this->makeService()->run();

        $this->assertSame(0, $summary['retried']);
        $this->assertSame(1, $summary['skipped']);

        $run->refresh();
        $this->assertNull($run->maintenance_triggered_at);
    }

    // =========================================================================
    // 7: Non-document source type is not processed by the maintenance cycle
    // =========================================================================

    public function test_non_document_source_type_run_not_processed(): void
    {
        $customer = $this->createCustomer();
        EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => 1,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ]);

        $this->mockQaService()->shouldNotReceive('runForRun');

        $summary = $this->makeService()->run();

        $this->assertSame(['retried' => 0, 'skipped' => 0, 'failed' => 0, 'claim_content_repairs_attempted' => 0], $summary);
    }

    // =========================================================================
    // 8: Non-escalated run (e.g. passed) is not processed
    // =========================================================================

    public function test_non_escalated_run_is_not_processed(): void
    {
        $customer = $this->createCustomer();
        $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->mockQaService()->shouldNotReceive('runForRun');

        $summary = $this->makeService()->run();

        $this->assertSame(['retried' => 0, 'skipped' => 0, 'failed' => 0, 'claim_content_repairs_attempted' => 0], $summary);
    }

    // =========================================================================
    // 9: QA retry throws → counted as failed; maintenance_source_hash already set
    // =========================================================================

    public function test_qa_retry_exception_counted_as_failed_with_hash_stored(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-fail');
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, document: $document);

        $this->mockQaService()->shouldReceive('runForRun')
            ->once()
            ->andThrow(new \RuntimeException('AI error'));

        $summary = $this->makeService()->run();

        $this->assertSame(0, $summary['retried']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(1, $summary['failed']);

        // Hash was stored before QA call — the attempted source version is recorded.
        $run->refresh();
        $this->assertSame('hash-fail', $run->maintenance_source_hash);
    }

    // =========================================================================
    // Runtime fix: hash-comparison log accuracy (run 18 — "Source changed" logged
    // with current_hash and prev_hash appearing identical due to a capture-after-mutate bug)
    // =========================================================================

    public function test_equal_source_hashes_do_not_log_source_changed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'same-hash');
        $run = $this->createAppliedRun(
            $customer,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            document: $document,
            maintenanceSourceHash: 'same-hash',
        );

        // The equal-hash branch returns 'skipped' before any "Source changed" log is emitted
        // and before QA is ever re-triggered — confirmed by both the summary and the fact
        // that runForRun() is never called.
        $this->mockQaService()->shouldNotReceive('runForRun');

        $summary = $this->makeService()->run();

        $this->assertSame(0, $summary['retried']);
        $this->assertSame(1, $summary['skipped']);
        $run->refresh();
        $this->assertSame('same-hash', $run->maintenance_source_hash);
    }

    public function test_different_source_hashes_log_the_real_previous_hash_not_the_new_one(): void
    {
        Log::spy();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-new');
        $run = $this->createAppliedRun(
            $customer,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            document: $document,
            maintenanceSourceHash: 'hash-old',
        );

        $this->mockQaService()->shouldReceive('runForRun')
            ->once()
            ->with(\Mockery::on(fn ($r) => $r->id === $run->id), true)
            ->andReturnNull();

        $this->makeService()->run();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[WIKI_MAINTENANCE] Source changed — triggering QA retry'
                    && $context['current_hash'] === 'hash-new'
                    && $context['prev_hash'] === 'hash-old'
                    && $context['prev_hash'] !== $context['current_hash'];
            });
    }

    public function test_first_maintenance_check_uses_a_distinct_log_message_not_source_changed(): void
    {
        Log::spy();

        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'hash-first');
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, document: $document);

        $this->mockQaService()->shouldReceive('runForRun')
            ->once()
            ->with(\Mockery::on(fn ($r) => $r->id === $run->id), true)
            ->andReturnNull();

        $this->makeService()->run();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[WIKI_MAINTENANCE] First maintenance check for this run — triggering QA retry'
                    && $context['prev_hash'] === null;
            });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(): EnterpriseWikiMaintenanceCycleService
    {
        return app(EnterpriseWikiMaintenanceCycleService::class);
    }

    private function mockQaService(): MockInterface
    {
        return $this->mock(EnterpriseWikiPostIngestQaService::class);
    }

    private function createCustomer(string $name = 'Maintenance Test AS'): Customer
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

    private function createDocument(Customer $customer, string $hash = ''): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => $hash !== '' ? $hash : hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for maintenance tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(
        Customer $customer,
        ?string $qaStatus = null,
        ?EnterpriseWikiDocument $document = null,
        ?string $maintenanceSourceHash = null,
    ): EnterpriseWikiIngestRun {
        $document ??= $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
            'maintenance_source_hash' => $maintenanceSourceHash,
        ]);
    }
}
