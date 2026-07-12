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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `wiki:recover-document-flow` recovers a run stuck at status=failed by computing a
 * deterministic resume plan from existing checkpoints and artifacts:
 *
 *   - qa_status already terminal (passed/escalated/failed) + its snapshot and required
 *     artifacts validate → finalize directly from that result (no stages re-run, no dispatch).
 *   - qa_status never reached a terminal state → restore to verification_linking and dispatch
 *     a fresh continuation job (safe because every stage is independently idempotent — see
 *     EnterpriseWikiDocumentFlowService::continueAfterPagesGenerated()).
 *
 * Background: this replaces an earlier, narrower version of this command that was scoped to
 * one specific incident (run 24 — a race between continuation and the scheduled QA sweep,
 * fixed separately in EnterpriseWikiDocumentFlowService::markRunFailed()). Run 24's data no
 * longer exists; its shape is reproduced here only as a regression fixture, not as a run this
 * command still needs to recover.
 */
class EnterpriseWikiRecoverDocumentFlowCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Guards
    // =========================================================================

    public function test_refuses_when_run_not_found(): void
    {
        $this->artisan('wiki:recover-document-flow', ['--run-id' => 999999])
            ->assertExitCode(1);
    }

    public function test_refuses_when_run_already_completed(): void
    {
        $run = $this->createStuckRun($this->createCustomer());
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_run_already_escalated(): void
    {
        $run = $this->createStuckRun($this->createCustomer());
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_ESCALATED]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_status_is_not_failed(): void
    {
        $run = $this->createStuckRun($this->createCustomer());
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->status);
    }

    public function test_refuses_when_maintainer_decision_not_applied(): void
    {
        $run = $this->createStuckRun($this->createCustomer());
        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_run_has_no_applied_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => null,
            'qa_attempt_count' => 0,
            'error_message' => 'Some failure before any pages existed.',
            'finished_at' => now(),
        ]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_qa_terminal_but_no_snapshot_exists(): void
    {
        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withSnapshot: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_snapshot_qa_status_does_not_match_run_qa_status(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createStuckRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withSnapshot: false);
        EnterpriseWikiQaSnapshot::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $customer->id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_attempt_count' => $run->qa_attempt_count,
            'snapshotted_at' => now(),
        ]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_refuses_when_snapshot_belongs_to_a_different_customer(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Other Customer');
        $run = $this->createStuckRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withSnapshot: false);
        EnterpriseWikiQaSnapshot::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $otherCustomer->id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => $run->qa_attempt_count,
            'snapshotted_at' => now(),
        ]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_a_passed_run_when_no_current_page_version_exists(): void
    {
        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withPageVersion: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_a_passed_run_when_no_page_links_exist(): void
    {
        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withLink: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_a_passed_run_when_no_claims_exist(): void
    {
        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    // =========================================================================
    // Direct finalize — qa_status already terminal, snapshot + artifacts validate
    // =========================================================================

    public function test_direct_finalizes_a_passed_run_without_dispatching_a_job(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertSame(1, $fresh->qa_attempt_count);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertNothingPushed();
    }

    public function test_direct_finalizes_an_escalated_run_without_dispatching_a_job(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $fresh->qa_status);
        $this->assertNotNull($fresh->finished_at);

        Queue::assertNothingPushed();
    }

    public function test_direct_finalizes_a_failed_qa_run_without_promoting_it_to_completed(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $run->update(['qa_last_error' => 'Semantic QA rejected the generated content.']);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $fresh->qa_status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);

        Queue::assertNothingPushed();
    }

    public function test_direct_finalize_does_not_create_new_page_versions_links_claims_or_snapshots(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $linksBefore = EnterpriseWikiPageLink::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $snapshotsBefore = EnterpriseWikiQaSnapshot::query()->count();

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($linksBefore, EnterpriseWikiPageLink::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($snapshotsBefore, EnterpriseWikiQaSnapshot::query()->count());
    }

    // =========================================================================
    // Resume continuation — qa_status never reached a terminal state
    // =========================================================================

    public function test_resumes_via_fresh_continuation_when_qa_never_started(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: null, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertNull($fresh->qa_status);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_resumes_via_fresh_continuation_when_qa_is_repair_required(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $fresh->qa_status);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    /**
     * qa_status=running with the owning run already marked failed means the worker that
     * claimed QA died before reaching a terminal state (a genuine worker-restart mid-QA) —
     * that claim is stale and must be released to pending so a fresh continuation can
     * reclaim it, instead of looping forever on the QA-busy retry path.
     */
    public function test_releases_a_stale_running_qa_claim_before_resuming(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PENDING, $fresh->qa_status);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_resume_continuation_does_not_require_links_or_claims_to_already_exist(): void
    {
        Queue::fake();

        // A technical failure that happened before claim extraction/linking ever ran must
        // still be resumable — requiring links/claims up front would wrongly block recovery
        // for exactly the runs that most need to resume.
        $run = $this->createStuckRun($this->createCustomer(), qaStatus: null, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    // =========================================================================
    // Dry-run
    // =========================================================================

    public function test_dry_run_reports_direct_finalize_plan_without_changes(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->expectsOutputToContain('direct_finalize')
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertNotNull($fresh->finished_at);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_resume_continuation_plan_without_changes(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: null, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->expectsOutputToContain('resume_continuation')
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_release_a_stale_running_qa_claim(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING, withSnapshot: false, withLink: false, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_RUNNING, $run->fresh()->qa_status);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Legacy guard
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiRecoverDocumentFlow', $source);
        $this->assertStringNotContainsString('recover-document-flow', $source);
    }

    // =========================================================================
    // Helpers
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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for recovery command tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * Builds a run stuck at status=failed with one applied article page, matching the shape
     * continueAfterPagesGenerated() would have produced by the time a technical failure could
     * occur at any point in its sequence. $qaStatus controls which resume-plan branch the
     * command should choose; a passed/escalated/failed qa_status additionally gets a matching
     * snapshot (unless withSnapshot=false) since real QA execution always writes one alongside
     * qa_status.
     */
    private function createStuckRun(
        Customer $customer,
        ?string $qaStatus = EnterpriseWikiIngestRun::QA_STATUS_PASSED,
        bool $withSnapshot = true,
        bool $withPageVersion = true,
        bool $withLink = true,
        bool $withClaim = true,
    ): EnterpriseWikiIngestRun {
        $document = $this->createDocument($customer);

        $qaAttemptCount = $qaStatus === null ? 0 : 1;

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => $qaStatus,
            'qa_attempt_count' => $qaAttemptCount,
            'error_message' => 'Technical failure while the run was in flight.',
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

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = null;

        if ($withPageVersion) {
            $version = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => "# Artikkel\n\nInnhold.",
            ]);
        }

        if ($withLink) {
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
        }

        if ($withClaim && $version !== null) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => 'En påstand fra artikkelen.',
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
            ]);
        }

        if ($withSnapshot && in_array($qaStatus, [
            EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            EnterpriseWikiIngestRun::QA_STATUS_FAILED,
        ], true)) {
            EnterpriseWikiQaSnapshot::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'customer_id' => $customer->id,
                'qa_status' => $qaStatus,
                'qa_attempt_count' => $qaAttemptCount,
                'snapshotted_at' => now(),
                'technical_qa_passed' => true,
                'structural_qa_passed' => true,
                'semantic_qa_ran' => true,
                'semantic_pass' => $qaStatus === EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            ]);
        }

        return $run;
    }
}
