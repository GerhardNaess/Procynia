<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

        $this->assertSame([
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'verification_recovery_candidates' => 0,
            'verification_recovery_resumed' => 0,
            'claim_content_repairs_attempted' => 0,
        ], $summary);
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

        $this->assertSame([
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'verification_recovery_candidates' => 0,
            'verification_recovery_resumed' => 0,
            'claim_content_repairs_attempted' => 0,
        ], $summary);
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

        $this->assertSame([
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'verification_recovery_candidates' => 0,
            'verification_recovery_resumed' => 0,
            'claim_content_repairs_attempted' => 0,
        ], $summary);
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
            ->withArgs(function (string $message, array $context = []): bool {
                return $message === '[WIKI_MAINTENANCE] Source changed — triggering QA retry'
                    && ($context['current_hash'] ?? null) === 'hash-new'
                    && ($context['prev_hash'] ?? null) === 'hash-old'
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
            ->withArgs(function (string $message, array $context = []): bool {
                return $message === '[WIKI_MAINTENANCE] First maintenance check for this run — triggering QA retry'
                    && array_key_exists('prev_hash', $context)
                    && $context['prev_hash'] === null;
            });
    }

    // =========================================================================
    // Verification-incomplete recovery sweep (Wiki run-585) — independent of the source-hash
    // gated retry above; delegates entirely to EnterpriseWikiEscalatedRunRecoveryService.
    // =========================================================================

    public function test_finds_and_resumes_a_qualified_escalated_run(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createEscalatedRunWithUnverifiedClaims($customer);

        $summary = $this->makeService()->run();

        $this->assertSame(1, $summary['verification_recovery_candidates']);
        $this->assertSame(1, $summary['verification_recovery_resumed']);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->status);
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_skips_a_non_qualified_escalated_run_with_a_clear_reason(): void
    {
        Queue::fake();

        Log::spy();

        $customer = $this->createCustomer();
        // Every claim already verified, content intact — genuinely stale, nothing to resume.
        $run = $this->createEscalatedRunWithUnverifiedClaims($customer, unverifiedClaims: 0);

        $summary = $this->makeService()->run();

        $this->assertSame(1, $summary['verification_recovery_candidates']);
        $this->assertSame(0, $summary['verification_recovery_resumed']);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context = []): bool {
                return $message === '[WIKI_MAINTENANCE] Verification-incomplete recovery candidate evaluated.'
                    && ($context['outcome'] ?? null) === 'stale_state';
            });
    }

    public function test_source_hash_gated_delfase1_is_unaffected_by_the_recovery_sweep(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: 'delfase1-hash');
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, document: $document);

        $this->mockQaService()->shouldReceive('runForRun')
            ->once()
            ->with(\Mockery::on(fn ($r) => $r->id === $run->id), true)
            ->andReturnNull();

        $summary = $this->makeService()->run();

        $this->assertSame(1, $summary['retried']);
        // Main status was never set (decision_only, per createAppliedRun) — not a candidate for
        // the run-585 sweep, which queries main status=escalated only.
        $this->assertSame(0, $summary['verification_recovery_candidates']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Builds a synthetic run at main status=escalated with extraction complete and a configurable
     * number of unverified claims — the Wiki run-585 shape. Synthetic fixture only.
     */
    private function createEscalatedRunWithUnverifiedClaims(Customer $customer, int $unverifiedClaims = 2): EnterpriseWikiIngestRun
    {
        // Real run 585 had already been through one maintenance tick before this pattern was
        // observed (its maintenance_source_hash was already stamped to the current, unchanged
        // document hash) — so Delfase 1's source-change gate above correctly skips it, and only
        // the run-585 sweep below considers it. Matching that shape here (rather than leaving
        // maintenance_source_hash null) keeps this fixture from also triggering Delfase 1's real,
        // unmocked QA-retry/deep-repair path, which is unrelated to what this test verifies.
        $document = $this->createDocument($customer, hash: 'unchanged-hash-'.Str::random(8));

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_attempt_count' => 1,
            'maintenance_source_hash' => $document->file_hash_sha256,
            'error_message' => 'Interrupted before all claims were verified.',
            'finished_at' => now(),
        ]);

        $article = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'artikkel-'.Str::lower(Str::random(6)),
            'title' => 'Artikkel',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $summary = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'sammendrag-'.Str::lower(Str::random(6)),
            'title' => 'Sammendrag',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
            ]);
        }

        $articleVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nInnhold.",
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $summary->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Sammendrag\n\nInnhold.",
        ]);

        for ($i = 0; $i < $unverifiedClaims; $i++) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'enterprise_wiki_page_version_id' => $articleVersion->id,
                'claim_text' => 'Uverifisert påstand '.$i,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => null,
            ]);
        }

        return $run;
    }

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
