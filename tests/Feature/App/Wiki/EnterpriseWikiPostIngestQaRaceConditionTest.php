<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Runtime fix (run 24): race condition between ContinueEnterpriseWikiDocumentFlowAfterPages
 * and the scheduled `wiki:run-post-ingest-qa --all-pending` sweep. The scheduler claimed a
 * run that was still mid verification_linking, set qa_status=passed, and the continuation
 * job's own later claim then failed with "Post-ingest QA did not claim run [id]" — leaving
 * the run stuck at status=verification_linking with qa_status=passed, finished_at=null.
 */
class EnterpriseWikiPostIngestQaRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1-5: scheduler QA gating excludes active document-flow statuses
    // =========================================================================

    public function test_scheduler_qa_excludes_queued(): void
    {
        $this->assertStatusExcludedFromPending(EnterpriseWikiIngestRun::STATUS_QUEUED);
    }

    public function test_scheduler_qa_excludes_running(): void
    {
        $this->assertStatusExcludedFromPending(EnterpriseWikiIngestRun::STATUS_RUNNING);
    }

    public function test_scheduler_qa_excludes_generating_pages(): void
    {
        $this->assertStatusExcludedFromPending(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
    }

    public function test_scheduler_qa_excludes_generating_concept_entity_pages(): void
    {
        $this->assertStatusExcludedFromPending(EnterpriseWikiIngestRun::STATUS_GENERATING_CONCEPT_ENTITY_PAGES);
    }

    public function test_scheduler_qa_excludes_verification_linking(): void
    {
        $this->assertStatusExcludedFromPending(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);
    }

    private function assertStatusExcludedFromPending(string $activeStatus): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRun($customer, status: $activeStatus, qaStatus: null);

        $pending = $this->qaService()->findPendingRuns();
        $retryable = $this->qaService()->findRetryableRuns();

        $this->assertFalse($pending->pluck('id')->contains($run->id));
        $this->assertFalse($retryable->pluck('id')->contains($run->id));
    }

    // =========================================================================
    // 6-10: idempotent continuation QA claim
    // =========================================================================

    public function test_continuation_can_claim_and_run_first_qa_normally(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaClaims($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_continuation_completes_run_after_qa_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaClaims($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);
    }

    public function test_continuation_finding_already_passed_qa_does_not_throw(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();

        // Simulates the scheduler having already won the claim and set qa_status=passed
        // before the continuation job's own call reaches performPostIngestQa().
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_already_passed_qa_creates_no_new_snapshot(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $snapshotsBefore = EnterpriseWikiQaSnapshot::query()->count();

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame($snapshotsBefore, EnterpriseWikiQaSnapshot::query()->count());
    }

    public function test_already_passed_qa_does_not_increase_qa_attempt_count(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update(['qa_attempt_count' => 1]);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(1, $run->fresh()->qa_attempt_count);
    }

    // =========================================================================
    // 11-12: terminal completion, including the exact run-24 shape
    // =========================================================================

    public function test_verification_linking_with_passed_qa_and_null_finished_at_becomes_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->assertNull($run->finished_at);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
    }

    // =========================================================================
    // Runtime fix (real run 24 state): a queue-level exception must never overwrite an
    // already-legitimate passed/escalated/failed QA result. Run execution status (`status`)
    // and semantic QA result (`qa_status`/snapshot) are distinct states — the bug was
    // markRunFailed(qaContext: true) unconditionally setting qa_status=failed whenever the
    // exception happened to occur while $currentStage === STATUS_QA, even when qa_status was
    // already a real, completed 'passed' result recorded by a different (scheduler) worker.
    // =========================================================================

    public function test_failed_continuation_preserves_a_legitimate_passed_qa_result(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => 1,
            'qa_completed_at' => now(),
        ]);
        $snapshot = $this->createPassedSnapshot($run);

        $this->configureUpstreamMocks();

        // A genuinely unexpected exception while performPostIngestQa() is still trying to
        // claim/execute QA — regardless of the reason, it must not clobber the qa_status the
        // run already legitimately has.
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->once()
            ->andThrow(new RuntimeException("Post-ingest QA did not claim run [{$run->id}]."));

        try {
            $this->flowService()->continueAfterPagesGenerated($run->id);
            $this->fail('Expected the exception to propagate to the queue worker.');
        } catch (RuntimeException) {
            // expected — a real unexpected exception must still surface (so the operator/queue
            // sees it), it just must not corrupt the already-completed QA result.
        }

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertSame(1, $fresh->qa_attempt_count);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNotNull($fresh->error_message);

        $snapshot->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $snapshot->qa_status);
    }

    /**
     * The real end-to-end sequence: verification_linking with an already-passed QA result
     * and snapshot → the old race exception is thrown → the job's failed() hook runs (after
     * the service's own catch already ran markRunFailed()) → the run is failed but qa_status
     * is preserved as passed → wiki:recover-document-flow finalizes it directly from that
     * already-recorded QA result (no stage re-execution, no job dispatch) → no duplicates
     * anywhere.
     */
    public function test_full_sequence_from_verification_linking_through_recovery_to_completed(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => 1,
            'qa_completed_at' => now(),
        ]);
        $this->createPassedSnapshot($run);

        $article = EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->first()->page;
        $target = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'target-'.Str::lower(Str::random(6)),
            'title' => 'Target',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $target->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->firstOrFail()->id,
            'claim_text' => 'En påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $linksBefore = EnterpriseWikiPageLink::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $snapshotsBefore = EnterpriseWikiQaSnapshot::query()->count();

        // Step 3: the old race exception, thrown from inside performPostIngestQa()'s claim.
        // The whole QA service is mocked here (this test is about the continuation/recovery
        // race, not QA's own logic) — recovery's revalidate_and_finalize path also calls
        // evaluate() (read-only prediction) and a second runForRun() (a no-op against a real
        // service, since qa_status is already the terminal 'passed'), so both must be stubbed
        // on the same mock instance alongside the original throwing expectation.
        $this->configureUpstreamMocks();
        $this->mock(EnterpriseWikiPostIngestQaService::class, function ($mock) use ($run): void {
            $mock->shouldReceive('runForRun')
                ->once()
                ->andThrow(new RuntimeException("Post-ingest QA did not claim run [{$run->id}]."));

            $mock->shouldReceive('runForRun')
                ->andReturnNull();

            $mock->shouldReceive('evaluate')
                ->andReturn([
                    'verdict' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
                    'reason' => null,
                    'incomplete_steps' => [],
                    'critical_defects' => [],
                    'checks' => [
                        'article_exists' => true,
                        'summary_exists' => true,
                        'article_has_content' => true,
                        'summary_has_content' => true,
                    ],
                ]);
        });

        $job = new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id);

        try {
            $job->handle(app(EnterpriseWikiDocumentFlowService::class));
            $this->fail('Expected the exception to propagate.');
        } catch (\Throwable $e) {
            // Step 4: what Laravel's worker does after handle() throws for a tries=1 job.
            $job->failed($e);
        }

        // Step 5: failed, but qa_status/snapshot untouched.
        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertSame(1, $fresh->qa_attempt_count);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNotNull($fresh->error_message);

        // Step 6+7: controlled recovery — qa_status is already terminal (passed) and its
        // snapshot + artifacts validate, so the command finalizes directly. No stage
        // re-execution, no job dispatch.
        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertSame(1, $fresh->qa_attempt_count);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertNothingPushed();

        // No duplicates anywhere.
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($snapshotsBefore, EnterpriseWikiQaSnapshot::query()->count());
    }

    /**
     * A technical failure during or before QA — qa_status never reached a terminal state —
     * must resume via a fresh continuation rather than being finalized directly, and must not
     * repeat the stages (materialize wikilinks, incremental relink, lint) that already ran
     * successfully before the failure.
     */
    public function test_recovery_resumes_a_technical_failure_before_qa_via_fresh_continuation(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'qa_status' => null,
            'qa_attempt_count' => 0,
            'error_message' => 'Worker crashed mid-flight.',
            'finished_at' => now(),
        ]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_completed_run_is_idempotent_on_new_continuation_call(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'finished_at' => now(),
        ]);

        $this->mock(EnterpriseWikiBuildPageLinksService::class)->shouldNotReceive('materializeWikilinksForRun');
        $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldNotReceive('runForRun');

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    // =========================================================================
    // 13-16: escalated/failed/busy are never turned into completed
    // =========================================================================

    public function test_escalated_qa_is_not_marked_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->fresh()->status);
    }

    public function test_failed_qa_is_not_marked_completed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        try {
            $this->flowService()->continueAfterPagesGenerated($run->id);
        } catch (\Throwable) {
            // finalizeFromQaResult() routes qa_status=failed through markRunFailed(), which
            // re-throws — this is existing, unchanged terminal semantics.
        }

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_busy_claim_with_null_qa_status_is_not_marked_completed(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldReceive('runForRun')->once()->andReturnNull();

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QA, $fresh->status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertNull($fresh->finished_at);
    }

    public function test_busy_state_dispatches_a_deferred_retry_job(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldReceive('runForRun')->once()->andReturnNull();

        $this->flowService()->continueAfterPagesGenerated($run->id);

        Queue::assertPushed(
            ContinueEnterpriseWikiDocumentFlowAfterPages::class,
            fn (ContinueEnterpriseWikiDocumentFlowAfterPages $job) => $job->runId === $run->id,
        );
    }

    // =========================================================================
    // 17-18: duplicate/racing invocations end deterministically
    // =========================================================================

    public function test_duplicate_continuation_jobs_do_not_duplicate_qa(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaClaims($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        // First call claims and runs QA; second call must recognize the terminal result
        // instead of re-running the pipeline or re-claiming QA.
        $this->flowService()->continueAfterPagesGenerated($run->id);
        $firstStatus = $run->fresh()->status;

        $this->flowService()->continueAfterPagesGenerated($run->id);

        // The run reached a terminal status on the first call (isTerminal() guard at the top
        // of continueAfterPagesGenerated() then makes the second call a pure no-op).
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $firstStatus);
        $this->assertSame($firstStatus, $run->fresh()->status);
    }

    public function test_scheduler_and_continuation_race_ends_deterministically(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();

        // The scheduler "wins" the race: it claims and completes QA before the continuation
        // job's own call reaches performPostIngestQa().
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    // =========================================================================
    // 19-21: job-level failed() hardening
    // =========================================================================

    public function test_job_failed_marks_a_real_continuation_failure_as_failed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->failed(new RuntimeException('worker crashed mid-flight'));

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
    }

    public function test_job_failed_sets_finished_at_and_error_message(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);

        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->failed(new RuntimeException('worker crashed mid-flight'));

        $fresh = $run->fresh();
        $this->assertNotNull($fresh->finished_at);
        $this->assertSame('worker crashed mid-flight', $fresh->error_message);
    }

    public function test_job_failed_does_not_overwrite_a_completed_run(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'error_message' => null,
        ]);

        (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->failed(new RuntimeException('should not apply'));

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    // =========================================================================
    // 22-25: retry does not regenerate/duplicate anything
    // =========================================================================

    public function test_retry_does_not_regenerate_pages(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        $this->flowService()->continueAfterPagesGenerated($run->id);
        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_retry_does_not_duplicate_page_links(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $article = EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->first()->page;
        $target = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'target-'.Str::lower(Str::random(6)),
            'title' => 'Target',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $target->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
        $linksBefore = EnterpriseWikiPageLink::query()->count();

        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);
        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
    }

    public function test_retry_does_not_duplicate_claims_or_source_references(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        $this->flowService()->continueAfterPagesGenerated($run->id);
        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_retry_does_not_duplicate_qa_snapshots(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);
        $this->configureUpstreamMocks();
        $this->mockQaBusyThenAlreadyCompleted($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->flowService()->continueAfterPagesGenerated($run->id);
        $snapshotsAfterFirst = EnterpriseWikiQaSnapshot::query()->count();

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $this->assertSame($snapshotsAfterFirst, EnterpriseWikiQaSnapshot::query()->count());
    }

    // =========================================================================
    // 30: legacy ProcessEnterpriseWikiIngest is untouched
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('ACTIVE_DOCUMENT_FLOW_STATUSES', $source);
        $this->assertStringNotContainsString('QA_BUSY_RETRY_DELAY_SECONDS', $source);
    }

    // =========================================================================
    // Run-24-shaped end-to-end integration test
    // =========================================================================

    public function test_run_24_shaped_run_is_completed_by_a_fresh_continuation_call_without_duplicates(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);

        // Exact run-24 shape: verification_linking, qa_status already passed by the
        // scheduler, qa_attempt_count=1, finished_at still null.
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => 1,
            'finished_at' => null,
            'error_message' => null,
        ]);

        $pageVersionsBefore = EnterpriseWikiPageVersion::query()->count();
        $pageLinksBefore = EnterpriseWikiPageLink::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $snapshotsBefore = EnterpriseWikiQaSnapshot::query()->count();

        $this->configureUpstreamMocks();
        $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldReceive('runForRun')->once()->andReturnNull();

        $this->flowService()->continueAfterPagesGenerated($run->id);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);
        $this->assertSame(1, $fresh->qa_attempt_count);

        $this->assertSame($pageVersionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($pageLinksBefore, EnterpriseWikiPageLink::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($snapshotsBefore, EnterpriseWikiQaSnapshot::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function flowService(): EnterpriseWikiDocumentFlowService
    {
        return app(EnterpriseWikiDocumentFlowService::class);
    }

    private function qaService(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    /**
     * Mocks the QA service so the FIRST claim attempt fails (as if another worker had
     * already claimed it), and the run's qa_status already reflects a terminal outcome by
     * the time performPostIngestQa() inspects it.
     */
    private function mockQaBusyThenAlreadyCompleted(EnterpriseWikiIngestRun $run, string $qaStatus): void
    {
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->once()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $r) use ($run, $qaStatus) {
                $run->update([
                    'qa_status' => $qaStatus,
                    'qa_completed_at' => now(),
                    'qa_attempt_count' => 1,
                ]);

                return null;
            });
    }

    private function mockQaClaims(EnterpriseWikiIngestRun $run, string $qaStatus): void
    {
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->once()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $r) use ($run, $qaStatus) {
                $run->update([
                    'qa_status' => $qaStatus,
                    'qa_completed_at' => now(),
                    'qa_attempt_count' => 1,
                ]);

                return ['pass' => true];
            });
    }

    private function configureUpstreamMocks(): void
    {
        $this->mock(EnterpriseWikiBuildPageLinksService::class)
            ->shouldReceive('materializeWikilinksForRun')
            ->once()
            ->andReturn([
                'pages_processed' => 1, 'occurrences_found' => 0, 'valid_links' => 0,
                'broken_slugs' => 0, 'self_links' => 0, 'created' => 0, 'updated' => 0,
                'stale_links_removed' => 0,
            ]);

        $this->mock(EnterpriseWikiExtractPageClaimsService::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['pages' => 1, 'claims' => 0, 'skipped' => 0]);

        $this->mock(EnterpriseWikiVerifyPageClaimsService::class)
            ->shouldReceive('verify')
            ->once()
            ->andReturn(['pages' => 1, 'claims' => 0, 'references' => 0, 'skipped' => 0, 'no_support' => 0]);

        $this->mock(EnterpriseWikiAppliedRunLintService::class)
            ->shouldReceive('lint')
            ->once()
            ->andReturn([
                'pages_checked' => 1, 'claims_checked' => 0, 'source_refs_checked' => 0,
                'links_checked' => 0, 'findings_created' => 0, 'findings_skipped' => 0,
                'findings_resolved' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0,
            ]);
    }

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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for QA race-condition tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, string $status, ?string $qaStatus): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'qa_status' => $qaStatus,
        ]);
    }

    /**
     * A run with one article page (current version present) attached, positioned exactly
     * where FinalizeEnterpriseWikiPageGeneration would hand off to
     * ContinueEnterpriseWikiDocumentFlowAfterPages.
     */
    private function createRunAwaitingContinuation(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
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

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => null,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nInnhold.",
        ]);

        return $run;
    }

    private function createPassedSnapshot(EnterpriseWikiIngestRun $run, int $qaAttemptCount = 1): EnterpriseWikiQaSnapshot
    {
        return EnterpriseWikiQaSnapshot::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $run->customer_id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => $qaAttemptCount,
            'snapshotted_at' => now(),
            'technical_qa_passed' => true,
            'structural_qa_passed' => true,
            'semantic_qa_ran' => true,
            'semantic_pass' => true,
        ]);
    }
}
