<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-3: a run's own `status` was previously trusted as blanket proof that every timeline
 * step — including Dokumenteiergodkjenning — had actually happened once `status === 'completed'`
 * (runFindingsLogic.js::getRunTimelineState()). In reality, run 3 reached `completed` with zero
 * human document-owner approval ever having taken place. These tests cover
 * WikiController::documentOwnerApprovalCountsForRun(), the new evidence the frontend now uses
 * instead — sourced from the exact same EnterpriseWikiDocumentOwnerApprovalService the page-detail
 * approval panel and runPages() already rely on, never a second hand-rolled approximation.
 */
class WikiRunDocumentOwnerApprovalTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_with_an_actually_approved_requirement_reports_approved_count(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);
        [$version] = $this->createVersionedPageWithSourceBlock($customer, $run, $document);

        $approval = app(EnterpriseWikiDocumentOwnerApprovalService::class)->syncForPageVersion($version, $run)->first();
        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($approval, $owner, 'approved');

        $counts = $this->fetchDocumentOwnerApprovalCounts($user, $run);

        $this->assertSame(1, $counts['required_count']);
        $this->assertSame(1, $counts['approved_count']);
        $this->assertSame(0, $counts['rejected_count']);
        $this->assertSame(0, $counts['pending_count']);
    }

    public function test_run_awaiting_approval_with_an_undecided_requirement_reports_pending_count(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        [$version] = $this->createVersionedPageWithSourceBlock($customer, $run, $document);

        app(EnterpriseWikiDocumentOwnerApprovalService::class)->syncForPageVersion($version, $run);

        $counts = $this->fetchDocumentOwnerApprovalCounts($user, $run);

        $this->assertSame(1, $counts['required_count']);
        $this->assertSame(0, $counts['approved_count']);
        $this->assertSame(0, $counts['rejected_count']);
        $this->assertSame(1, $counts['pending_count']);
    }

    public function test_rejected_requirement_reports_rejected_count(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $owner = $this->createUser($customer);
        $document = $this->createDocument($customer, $owner);
        $run = $this->createRun($customer, $document, EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL);
        [$version] = $this->createVersionedPageWithSourceBlock($customer, $run, $document);

        $approval = app(EnterpriseWikiDocumentOwnerApprovalService::class)->syncForPageVersion($version, $run)->first();
        app(EnterpriseWikiDocumentOwnerApprovalService::class)->decide($approval, $owner, 'rejected');

        $counts = $this->fetchDocumentOwnerApprovalCounts($user, $run);

        $this->assertSame(1, $counts['required_count']);
        $this->assertSame(0, $counts['approved_count']);
        $this->assertSame(1, $counts['rejected_count']);
        $this->assertSame(0, $counts['pending_count']);
    }

    /**
     * A page generated purely from best-practice/AI content with an open, non-blocking claim QA
     * signal never produces a Document Owner requirement at all — documentOwnerSummaryQaReviewOpen()
     * — so a completed run built only from such pages must report required_count = 0, never as if
     * a human approved something.
     */
    public function test_completed_run_with_no_live_approval_requirement_reports_zero_required_count(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createRun($customer, $this->createDocument($customer, null), EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'no-approval-page-'.Str::lower(Str::random(6)),
            'title' => 'No Approval Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        // No content_blocks_json at all -> buildRequirementGroups() finds zero source document
        // ids and returns no requirements at all (WikiController::documentOwnerSummaryForVersion()).
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# No Approval Page',
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // A source-based claim with no source reference at all is an open, non-blocking claim QA
        // signal (missing provenance) — WikiController::documentOwnerSummaryForVersion() reports
        // this specific combination (empty requirements + an open claim QA signal) as
        // 'qa_review_open', never as a pending/awaiting-sync approval requirement.
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim without source reference.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $counts = $this->fetchDocumentOwnerApprovalCounts($user, $run);

        $this->assertSame(0, $counts['required_count']);
        $this->assertSame(0, $counts['approved_count']);
        $this->assertSame(0, $counts['rejected_count']);
        $this->assertSame(0, $counts['pending_count']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{required_count: int, approved_count: int, rejected_count: int, pending_count: int}
     */
    private function fetchDocumentOwnerApprovalCounts(User $user, EnterpriseWikiIngestRun $run): array
    {
        $counts = null;
        $this->actingAs($user)->get('/app/wiki?tab=runs')->assertViewHas('page', function (array $inertia) use ($run, &$counts): bool {
            $found = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id);
            $counts = $found['document_owner_approval'] ?? null;

            return true;
        });

        $this->assertIsArray($counts, 'document_owner_approval must be present on the run payload');

        return $counts;
    }

    private function createCustomer(string $name = 'Approval Timeline Test AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'User '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@approval-timeline-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, ?User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => 'source.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source document text for approval timeline tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'finished_at' => $status === EnterpriseWikiIngestRun::STATUS_COMPLETED ? now() : null,
        ]);
    }

    /**
     * @return array{0: EnterpriseWikiPageVersion, 1: EnterpriseWikiPage}
     */
    private function createVersionedPageWithSourceBlock(Customer $customer, EnterpriseWikiIngestRun $run, EnterpriseWikiDocument $document): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'approval-page-'.Str::lower(Str::random(6)),
            'title' => 'Approval Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Approval Page',
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Kildebasert innhold for '.$document->original_filename,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_elements' => [],
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        return [$version, $page];
    }
}
