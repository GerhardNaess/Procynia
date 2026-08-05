<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\FinalizeEnterpriseWikiClaimVerification;
use App\Jobs\EnterpriseWiki\VerifyEnterpriseWikiClaim;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EnterpriseWikiClaimVerificationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_one_child_per_unverified_current_claim_and_skips_verified_claims(): void
    {
        Queue::fake();
        [$run, $version] = $this->runWithVersion();
        $first = $this->claim($version);
        $second = $this->claim($version);
        $this->claim($version, ['verified_at' => now()]);

        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);
        app(EnterpriseWikiDocumentFlowService::class)->continueAfterPagesGenerated($run->id);

        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, 2);
        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, fn (VerifyEnterpriseWikiClaim $job) => $job->claimId === $first->id && $job->queue === VerifyEnterpriseWikiClaim::QUEUE);
        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, fn (VerifyEnterpriseWikiClaim $job) => $job->claimId === $second->id && $job->queue === VerifyEnterpriseWikiClaim::QUEUE);
        Queue::assertPushed(FinalizeEnterpriseWikiClaimVerification::class, fn (FinalizeEnterpriseWikiClaimVerification $job) => $job->runId === $run->id && $job->queue === 'enterprise-wiki');
    }

    public function test_sentinel_waits_and_redispatches_when_a_claim_is_still_active(): void
    {
        Queue::fake();
        [$run, $version] = $this->runWithVersion();
        $this->claim($version, ['verification_claimed_at' => now(), 'verification_claim_token' => 'worker-a']);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);
        $this->mock(EnterpriseWikiAppliedRunLintService::class)->shouldNotReceive('lint');

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS, $run->fresh()->status);
        Queue::assertPushed(VerifyEnterpriseWikiClaim::class, 1);
        Queue::assertPushed(FinalizeEnterpriseWikiClaimVerification::class, fn (FinalizeEnterpriseWikiClaimVerification $job) => $job->delay !== null);
    }

    public function test_sentinel_is_a_no_op_when_run_is_waiting_on_document_owner_approval(): void
    {
        Queue::fake();
        [$run] = $this->runWithVersion();
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL]);

        $this->mock(EnterpriseWikiAppliedRunLintService::class)->shouldNotReceive('lint');

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_sentinel_claims_post_verification_continuation_only_once(): void
    {
        Queue::fake();
        [$run] = $this->runWithVersion();
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

        $this->mock(EnterpriseWikiAppliedRunLintService::class)
            ->shouldReceive('lint')
            ->once()
            ->andThrow(new RuntimeException('stop after sentinel claim'));

        try {
            app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);
        } catch (RuntimeException $exception) {
            $this->assertSame('stop after sentinel claim', $exception->getMessage());
        }

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterClaimVerification($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->failed_phase);
    }

    public function test_child_failure_marks_the_run_in_verification_linking_phase(): void
    {
        [$run] = $this->runWithVersion();
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFYING_CLAIMS]);

        app(EnterpriseWikiDocumentFlowService::class)->markClaimVerificationFailed($run->id, new RuntimeException('verification failed'));

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->failed_phase);
        $this->assertSame('verification failed', $fresh->error_message);
    }

    public function test_claim_job_is_routed_to_the_dedicated_queue_and_preserves_worker_semantics(): void
    {
        $job = new VerifyEnterpriseWikiClaim(42, 99);

        $this->assertSame('enterprise-wiki-claim-verification', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->backoff);
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    /** @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPageVersion} */
    private function runWithVersion(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create(['name' => 'Queue Test', 'slug' => 'queue-test-'.Str::lower(Str::random(8)), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'billing_interval' => Customer::BILLING_MONTHLY, 'is_active' => true]);
        $document = EnterpriseWikiDocument::query()->create(['customer_id' => $customer->id, 'original_filename' => 'source.pdf', 'file_path' => 'test/source.pdf', 'file_hash_sha256' => hash('sha256', Str::random(16)), 'extracted_text' => 'Kildetekst.', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED]);
        $run = EnterpriseWikiIngestRun::query()->create(['uuid' => Str::uuid()->toString(), 'customer_id' => $customer->id, 'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL, 'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, 'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, 'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
        $page = EnterpriseWikiPage::query()->create(['customer_id' => $customer->id, 'slug' => 'queue-page-'.Str::lower(Str::random(8)), 'title' => 'Queue page', 'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'status' => EnterpriseWikiPage::STATUS_DRAFT, 'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB, 'last_source_hash' => str_pad('hash', 64, '0')]);
        EnterpriseWikiIngestRunPage::query()->create(['enterprise_wiki_ingest_run_id' => $run->id, 'enterprise_wiki_page_id' => $page->id, 'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED]);
        $version = EnterpriseWikiPageVersion::query()->create(['enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => true, 'content_markdown' => '# Side']);

        return [$run, $version];
    }

    private function claim(EnterpriseWikiPageVersion $version, array $overrides = []): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create(array_merge(['enterprise_wiki_page_id' => $version->enterprise_wiki_page_id, 'enterprise_wiki_page_version_id' => $version->id, 'claim_text' => 'En påstand.', 'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH, 'conflict_flag' => false, 'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING], $overrides));
    }
}
