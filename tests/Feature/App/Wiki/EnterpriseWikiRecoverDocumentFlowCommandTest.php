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
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `wiki:recover-document-flow` recovers a run stuck at status=failed by re-evaluating QA
 * deterministically against existing artifacts (no AI, no stage re-execution) and finalizing
 * from whatever verdict that produces, or — when some continuation step genuinely isn't
 * finished yet — resuming via a fresh continuation job.
 *
 * Background: post-ingest QA used to be AI-driven (semantic review + repair), so recovery
 * had to trust a possibly-stale existing qa_status/snapshot rather than re-run anything
 * expensive. QA is now a pure, deterministic end check (see EnterpriseWikiPostIngestQaService),
 * so recovery re-evaluates directly instead — this is what let run 28 (an OpenAI technical
 * failure recorded as qa_status=failed during the old AI-based repair step) be recovered
 * correctly. Run 28's data is used here only as the shape for a regression fixture.
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

    // =========================================================================
    // Revalidate and finalize — every continuation step is complete, so QA can be
    // re-evaluated right now from existing artifacts.
    // =========================================================================

    public function test_revalidates_and_completes_a_run_whose_artifacts_are_actually_fine(): void
    {
        Queue::fake();

        // The run 28 shape: a technical failure (e.g. during the old AI-based QA step) left
        // status=failed and a stale qa_status=failed, even though every artifact is genuinely
        // complete and defect-free.
        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertNothingPushed();
    }

    public function test_revalidation_gives_failed_when_a_real_critical_defect_exists(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        // Introduce a genuine critical defect: the article's current version is empty.
        $article = EnterpriseWikiPage::query()
            ->whereIn('id', EnterpriseWikiIngestRunPage::where('enterprise_wiki_ingest_run_id', $run->id)->pluck('enterprise_wiki_page_id'))
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)
            ->firstOrFail();
        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->update(['content_markdown' => '']);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $fresh->qa_status);
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);

        Queue::assertNothingPushed();
    }

    public function test_releases_a_stale_running_qa_claim_before_revalidating(): void
    {
        Queue::fake();

        // qa_status=running with the owning run already marked failed proves the worker that
        // held the claim died without reaching a terminal state.
        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);

        Queue::assertNothingPushed();
    }

    public function test_revalidate_and_finalize_does_not_create_new_page_versions_links_or_claims(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    // =========================================================================
    // Resume via continuation — some continuation step is not finished yet, so QA cannot
    // be safely judged.
    // =========================================================================

    public function test_resumes_via_fresh_continuation_when_extraction_is_not_complete(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: false, qaStatus: null);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);
    }

    public function test_resume_continuation_does_not_call_qa_or_touch_qa_status(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: false, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED, $fresh->qa_status);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class);
    }

    // =========================================================================
    // Dry-run
    // =========================================================================

    public function test_dry_run_predicts_revalidate_and_finalize_without_changes(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->expectsOutputToContain('revalidate_and_finalize')
            ->expectsOutputToContain('predicted verdict=passed')
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $fresh->qa_status);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_predicts_resume_continuation_without_changes(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: false, qaStatus: null);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->expectsOutputToContain('resume_continuation')
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_release_a_stale_running_qa_claim(): void
    {
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_RUNNING, $run->fresh()->qa_status);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_increase_qa_attempt_count(): void
    {
        // evaluate() is pure and read-only — no AI client bindings are mocked here at all, and
        // dry-run must never claim the run via runForRun(), so qa_attempt_count is untouched.
        Queue::fake();

        $run = $this->createStuckRun($this->createCustomer(), stepsComplete: true, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $attemptCountBefore = $run->qa_attempt_count;

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame($attemptCountBefore, $run->fresh()->qa_attempt_count);
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
     * Builds a run stuck at status=failed with one applied article page and one summary page.
     *
     * $stepsComplete=true marks generation/extraction/verification complete for every page and
     * claim, matching a run whose pipeline genuinely finished (the run 28 shape — QA can be
     * safely re-evaluated). $stepsComplete=false leaves extraction unfinished, matching a run
     * that failed before the pipeline actually completed (recovery must resume, not finalize).
     */
    private function createStuckRun(
        Customer $customer,
        bool $stepsComplete = true,
        ?string $qaStatus = EnterpriseWikiIngestRun::QA_STATUS_FAILED,
    ): EnterpriseWikiIngestRun {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_FAILED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => $qaStatus,
            'qa_attempt_count' => $qaStatus === null ? 0 : 1,
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
        $summary = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'sammendrag-'.Str::lower(Str::random(6)),
            'title' => 'Sammendrag',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $articleRow = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => $stepsComplete ? now() : null,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $summary->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => $stepsComplete ? now() : null,
        ]);

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

        if ($stepsComplete) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'enterprise_wiki_page_version_id' => $articleVersion->id,
                'claim_text' => 'En påstand fra artikkelen.',
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
            ]);
        }

        return $run;
    }
}
