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
 * Runtime fix (run 24, corrected): `wiki:recover-document-flow` is a narrow, single-purpose
 * recovery for the known post-ingest QA claim race — a run stuck at status=failed with
 * error_message="Post-ingest QA did not claim run [id]." while its qa_status/snapshot
 * already reflect a legitimate passed QA result. Every guard must pass, using the QA
 * snapshot as the source of truth, or the command refuses outright.
 */
class EnterpriseWikiRecoverDocumentFlowCommandTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_ERROR_MESSAGE = 'Post-ingest QA did not claim run [24].';

    // =========================================================================
    // Guards — each must independently refuse recovery
    // =========================================================================

    public function test_refuses_when_run_not_found(): void
    {
        $this->artisan('wiki:recover-document-flow', ['--run-id' => 999999])
            ->assertExitCode(1);
    }

    public function test_refuses_when_run_already_completed(): void
    {
        $run = $this->createCorruptedRun($this->createCustomer());
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_status_is_not_failed(): void
    {
        $run = $this->createCorruptedRun($this->createCustomer());
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $run->fresh()->status);
    }

    public function test_refuses_when_error_message_does_not_match_known_race(): void
    {
        $run = $this->createCorruptedRun($this->createCustomer());
        $run->update(['error_message' => 'Some unrelated failure.']);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_refuses_when_no_snapshot_exists(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer, withSnapshot: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_snapshot_qa_status_is_not_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer, snapshotQaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_snapshot_belongs_to_a_different_customer(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer('Other Customer');
        $run = $this->createCorruptedRun($customer, withSnapshot: false);
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
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            'qa_attempt_count' => 1,
            'error_message' => self::KNOWN_ERROR_MESSAGE,
            'finished_at' => now(),
        ]);
        EnterpriseWikiQaSnapshot::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'customer_id' => $customer->id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_attempt_count' => 1,
            'snapshotted_at' => now(),
        ]);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_no_current_page_version_exists(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer, withPageVersion: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_no_page_links_exist(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer, withLink: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    public function test_refuses_when_no_claims_exist(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer, withClaim: false);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(1);
    }

    // =========================================================================
    // Success path
    // =========================================================================

    public function test_recovers_a_valid_corrupted_run(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $fresh->qa_status);
        $this->assertSame(1, $fresh->qa_attempt_count);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(
            ContinueEnterpriseWikiDocumentFlowAfterPages::class,
            fn (ContinueEnterpriseWikiDocumentFlowAfterPages $job) => $job->runId === $run->id,
        );
    }

    public function test_dry_run_makes_no_changes_and_dispatches_no_job(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer);

        $this->artisan('wiki:recover-document-flow', ['--run-id' => $run->id, '--dry-run' => true])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $fresh->qa_status);

        Queue::assertNothingPushed();
    }

    public function test_does_not_create_new_page_versions_links_claims_or_snapshots(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createCorruptedRun($customer);

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
     * Builds the exact run-24 corrupted shape: status=failed, qa_status=failed (clobbered),
     * the known race error_message, plus a passed snapshot and real artifacts (pages, a
     * current page version, a canonical link, and a claim) — everything the command's
     * guards must be able to confirm before it will act.
     */
    private function createCorruptedRun(
        Customer $customer,
        bool $withSnapshot = true,
        string $snapshotQaStatus = EnterpriseWikiIngestRun::QA_STATUS_PASSED,
        bool $withPageVersion = true,
        bool $withLink = true,
        bool $withClaim = true,
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
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            'qa_attempt_count' => 1,
            'error_message' => self::KNOWN_ERROR_MESSAGE,
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
            ]);
        }

        if ($withSnapshot) {
            EnterpriseWikiQaSnapshot::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'customer_id' => $customer->id,
                'qa_status' => $snapshotQaStatus,
                'qa_attempt_count' => 1,
                'snapshotted_at' => now(),
                'technical_qa_passed' => true,
                'structural_qa_passed' => true,
                'semantic_qa_ran' => true,
                'semantic_pass' => $snapshotQaStatus === EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            ]);
        }

        return $run;
    }
}
