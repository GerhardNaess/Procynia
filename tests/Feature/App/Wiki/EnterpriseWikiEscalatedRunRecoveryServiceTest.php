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
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiEscalatedRunRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunRecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for EnterpriseWikiEscalatedRunRecoveryService — the central decision-maker for whether an
 * escalated Enterprise Wiki ingest run (status=escalated) can be safely resumed. Built for the
 * Wiki run-585 pattern: verification_incomplete (unverified claims), no active lease, no active
 * job, interrupted by a transient OpenAI 429. All fixtures here are synthetic — run 585's real
 * data/IDs are never referenced or used as test data.
 */
class EnterpriseWikiEscalatedRunRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private const STALE_QA_ERROR = 'Run has unfinished continuation step(s) or an active reservation: verification_incomplete.';

    // =========================================================================
    // Resumable happy path (run-585 shape)
    // =========================================================================

    public function test_resumes_escalated_run_with_unverified_claims_no_lease(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 2);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);
        $this->assertContains('verification_incomplete', $result->incompleteSteps);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_resume_does_not_touch_claims_verified_at_directly(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 2, verifiedClaims: 1);

        $unverifiedBefore = EnterpriseWikiClaim::query()->whereNull('verified_at')->count();
        $verifiedBefore = EnterpriseWikiClaim::query()->whereNotNull('verified_at')->count();

        $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame($unverifiedBefore, EnterpriseWikiClaim::query()->whereNull('verified_at')->count());
        $this->assertSame($verifiedBefore, EnterpriseWikiClaim::query()->whereNotNull('verified_at')->count());
    }

    public function test_resume_dispatches_exactly_one_continuation_job(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);

        $this->service()->attempt($run->id, caller: 'test');

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    // =========================================================================
    // Active lease / active job blocks resume
    // =========================================================================

    public function test_does_not_resume_when_verification_lease_is_active(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 2, leaseActiveOnClaim: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $result->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_does_not_resume_when_run_status_shows_active_job_ownership(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 2);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $result->outcome);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Non-resumable incomplete step
    // =========================================================================

    public function test_rejects_when_incomplete_step_is_outside_resumable_scope(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createEscalatedRun($customer, unverifiedClaims: 1, pageGenerationIncomplete: true);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Permanent vs transient error classification
    // =========================================================================

    public function test_rejects_permanent_error_on_qa_status_failed(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun(
            $this->createCustomer(),
            unverifiedClaims: 1,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            qaLastError: 'TypeError: Argument #1 must be of type array, null given',
        );

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);

        Queue::assertNothingPushed();
    }

    public function test_resumes_when_qa_status_failed_error_is_a_transient_429(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun(
            $this->createCustomer(),
            unverifiedClaims: 1,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            qaLastError: 'OpenAI request failed: HTTP status [429] insufficient_quota',
        );

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    // =========================================================================
    // Stale status vs genuine defect
    // =========================================================================

    public function test_reports_stale_state_when_nothing_is_actually_incomplete(): void
    {
        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 0);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_STALE_STATE, $result->outcome);
    }

    public function test_rejects_when_a_genuine_critical_defect_exists(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 0);

        EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', EnterpriseWikiIngestRunPage::where('enterprise_wiki_ingest_run_id', $run->id)->pluck('enterprise_wiki_page_id'))
            ->where('is_current', true)
            ->limit(1)
            ->update(['content_markdown' => '']);

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Missing dependencies
    // =========================================================================

    public function test_rejects_when_source_document_no_longer_exists(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);
        EnterpriseWikiDocument::query()->where('id', $run->source_id)->delete();

        $result = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_MISSING_DEPENDENCIES, $result->outcome);

        Queue::assertNothingPushed();
    }

    public function test_rejects_when_maintainer_decision_not_applied(): void
    {
        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);
        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING]);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
    }

    public function test_not_found_run_is_not_recoverable(): void
    {
        $result = $this->service()->evaluate(999999);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $result->outcome);
    }

    // =========================================================================
    // Idempotence
    // =========================================================================

    public function test_second_attempt_on_an_already_resumed_run_does_not_dispatch_again(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);

        $first = $this->service()->attempt($run->id, caller: 'test');
        $second = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $first->outcome);
        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $second->outcome);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    // =========================================================================
    // The recorded QA verdict after a resume
    // =========================================================================

    /**
     * The verdict recorded before the resume describes work that was still unfinished. Kept as
     * qa_status, the continuation's QA claim refuses it and the flow finalizes from it unchanged —
     * the resumed run escalated again even though its verification had since completed.
     */
    public function test_a_resumed_run_is_finalized_from_a_new_qa_verdict_not_the_stale_one(): void
    {
        Queue::fake();
        $this->mockAiBackedContinuationStages();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 2, qaLastError: self::STALE_QA_ERROR);
        $run->update(['qa_result' => ['incomplete_steps' => ['verification_incomplete']]]);
        $article = $this->pageOfType($run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $publishedVersionId = $this->publish($article);

        $result = $this->service()->attempt($run->id, caller: 'test');
        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);

        $resumed = $run->fresh();
        $this->assertNull($resumed->qa_status, 'the stale verdict must not be reused by the resumed flow');
        $this->assertSame(['incomplete_steps' => ['verification_incomplete']], $resumed->qa_result, 'history stays until replaced');
        $this->assertSame(self::STALE_QA_ERROR, $resumed->qa_last_error);
        $this->assertSame(1, $resumed->qa_attempt_count);

        // The resumed continuation runs; the verification it was resumed for completes.
        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS, $run->fresh()->status);
        EnterpriseWikiClaim::query()->whereNull('verified_at')->update(['verified_at' => now()]);
        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertSame(2, $run->qa_attempt_count, 'QA ran again');
        $this->assertNotContains('verification_incomplete', (array) data_get($run->qa_result, 'incomplete_steps'));

        // The page that was already approved and published is untouched.
        $this->assertSame($publishedVersionId, $article->fresh()->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $article->fresh()->status);
    }

    public function test_a_recovery_that_resumes_nothing_keeps_the_recorded_verdict(): void
    {
        Queue::fake();

        // Nothing is incomplete any more: stale_state, no resume.
        $stale = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 0, qaLastError: self::STALE_QA_ERROR);
        // Another worker holds the verification lease: already_running, no resume.
        $leased = $this->createEscalatedRun($this->createCustomer('Lease AS'), unverifiedClaims: 1, leaseActiveOnClaim: true);
        // A permanent QA error: not_recoverable, no resume.
        $permanent = $this->createEscalatedRun(
            $this->createCustomer('Permanent AS'),
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            qaLastError: 'Schema validation failed: missing required field.',
        );

        foreach ([$stale, $leased, $permanent] as $run) {
            $before = $run->fresh();
            $result = $this->service()->attempt($run->id, caller: 'test');

            $this->assertFalse($result->isResumed());
            $after = $run->fresh();
            $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $after->status);
            $this->assertSame($before->qa_status, $after->qa_status);
            $this->assertSame($before->qa_last_error, $after->qa_last_error);
            $this->assertSame($before->qa_attempt_count, $after->qa_attempt_count);
        }

        Queue::assertNothingPushed();
    }

    public function test_a_repeated_attempt_does_not_reset_the_verdict_of_the_resumed_flow_again(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);

        $this->service()->attempt($run->id, caller: 'test');
        // The resumed flow has reached QA and claimed it.
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_QA, 'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_RUNNING]);

        $second = $this->service()->attempt($run->id, caller: 'test');

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_ALREADY_RUNNING, $second->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_RUNNING, $run->fresh()->qa_status);
        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, 1);
    }

    // =========================================================================
    // evaluate() is read-only
    // =========================================================================

    public function test_evaluate_does_not_mutate_or_dispatch(): void
    {
        Queue::fake();

        $run = $this->createEscalatedRun($this->createCustomer(), unverifiedClaims: 1);

        $result = $this->service()->evaluate($run->id);

        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_RESUMED, $result->outcome);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiEscalatedRunRecoveryService
    {
        return app(EnterpriseWikiEscalatedRunRecoveryService::class);
    }

    /**
     * The continuation's AI-backed stages. The fixture's pages already carry their claims
     * checkpoint; extraction and semantic repair are no-ops here, as they are on a real resume
     * that finds nothing new to extract or repair.
     */
    private function mockAiBackedContinuationStages(): void
    {
        $this->mock(EnterpriseWikiExtractPageClaimsService::class)
            ->shouldReceive('extract')
            ->andReturn(['pages' => 2, 'claims' => 0, 'skipped' => 0]);

        $this->mock(EnterpriseWikiLinkSemanticRepairService::class)
            ->shouldReceive('repairForRun')
            ->andReturn(['pages_reviewed' => 2, 'applied' => 0, 'skipped' => 2, 'failed' => 0]);
    }

    private function pageOfType(EnterpriseWikiIngestRun $run, string $pageType): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()
            ->whereIn('id', EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->pluck('enterprise_wiki_page_id'))
            ->where('page_type', $pageType)
            ->firstOrFail();
    }

    private function publish(EnterpriseWikiPage $page): int
    {
        $versionId = (int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('id');

        $page->update(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $versionId]);

        return $versionId;
    }

    private function createCustomer(string $name = 'Recovery Test AS'): Customer
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

    /**
     * Builds a synthetic run at status=escalated with one applied article page and one summary
     * page — the run-585 shape (extraction complete, some number of unverified claims, no active
     * lease) by default, with knobs to produce the other branches decide() must handle.
     */
    private function createEscalatedRun(
        Customer $customer,
        int $unverifiedClaims = 1,
        int $verifiedClaims = 0,
        bool $leaseActiveOnClaim = false,
        bool $pageGenerationIncomplete = false,
        ?string $qaStatus = EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ?string $qaLastError = null,
    ): EnterpriseWikiIngestRun {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for escalated recovery tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => $qaStatus,
            'qa_attempt_count' => 1,
            'qa_last_error' => $qaLastError,
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
                'generation_status' => $pageGenerationIncomplete
                    ? EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING
                    : EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
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
                'verification_claimed_at' => $leaseActiveOnClaim ? now() : null,
            ]);
        }

        for ($i = 0; $i < $verifiedClaims; $i++) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'enterprise_wiki_page_version_id' => $articleVersion->id,
                'claim_text' => 'Verifisert påstand '.$i,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
            ]);
        }

        return $run;
    }
}
