<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiDocumentOwnerApprovalFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_pending_page_is_visible_to_required_document_owner_and_collapses_multiple_documents_from_same_owner(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $documentA = $this->createDocument($customer, $owner, 'alpha.docx');
        $documentB = $this->createDocument($customer, $owner, 'beta.docx');
        $page = $this->createPendingPage($customer, 'same-owner-page');
        $version = $this->createCurrentVersion($page);
        $claimA = $this->createClaim($page, $version, 'Krav fra alpha.');
        $claimB = $this->createClaim($page, $version, 'Krav fra beta.', 1);
        $this->createSourceReference($claimA, $documentA);
        $this->createSourceReference($claimB, $documentB);

        $response = $this->actingAs($owner)->get('/app/wiki/'.$page->slug);
        $response->assertOk();

        $response->assertViewHas('page', function (array $inertia) use ($documentA, $documentB): bool {
            $props = data_get($inertia, 'props');
            $approvals = collect(data_get($props, 'document_owner_approvals', []));
            $summary = data_get($props, 'document_owner_approval_summary', []);
            $approval = $approvals->first();

            return $approvals->count() === 1
                && ($summary['total'] ?? null) === 1
                && ($summary['pending'] ?? null) === 1
                && ($summary['ready'] ?? null) === false
                && ($approval['approval_status'] ?? null) === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING
                && count($approval['source_document_ids'] ?? []) === 2
                && in_array($documentA->id, $approval['source_document_ids'] ?? [], true)
                && in_array($documentB->id, $approval['source_document_ids'] ?? [], true)
                && ($approval['can_decide'] ?? null) === true;
        });

        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );

        $this->actingAs($owner)->get('/app/wiki/'.$page->slug);

        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    public function test_run_stays_awaiting_document_owner_approval_until_all_required_owners_have_approved(): void
    {
        $customer = $this->createCustomer();
        $ownerA = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $ownerB = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $documentA = $this->createDocument($customer, $ownerA, 'owner-a.docx');
        $documentB = $this->createDocument($customer, $ownerB, 'owner-b.docx');
        $page = $this->createPendingPage($customer, 'two-owner-page');
        $version = $this->createCurrentVersion($page);
        $claimA = $this->createClaim($page, $version, 'Påstand A.');
        $claimB = $this->createClaim($page, $version, 'Påstand B.', 1);
        $this->createSourceReference($claimA, $documentA);
        $this->createSourceReference($claimB, $documentB);
        $run = $this->createPassedQaRun($customer, $page, $version, $documentA->id);

        $service = app(EnterpriseWikiDocumentFlowService::class);
        $service->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);
        $this->assertStringContainsString('Dokumenteier', (string) $run->error_message);

        $approvalA = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('document_owner_user_id', $ownerA->id)
            ->firstOrFail();
        $approvalB = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('document_owner_user_id', $ownerB->id)
            ->firstOrFail();

        $this->actingAs($ownerA)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalA->id}/approve", [
            'comment' => 'Godkjent av dokumenteier A.',
        ])->assertRedirect(route('app.wiki.show', $page->slug));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        $this->actingAs($ownerB)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalB->id}/approve", [
            'comment' => 'Godkjent av dokumenteier B.',
        ])->assertRedirect(route('app.wiki.show', $page->slug));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
    }

    public function test_system_owner_can_override_missing_document_owner_and_complete_run(): void
    {
        $customer = $this->createCustomer();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $document = $this->createDocument($customer, null, 'orphaned.docx');
        $page = $this->createPendingPage($customer, 'missing-owner-page');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, 'Orphaned claim.');
        $this->createSourceReference($claim, $document);
        $run = $this->createPassedQaRun($customer, $page, $version, $document->id);

        $service = app(EnterpriseWikiDocumentFlowService::class);
        $service->finalizeFromExistingQaResult($run);

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->firstOrFail();

        $this->assertNull($approval->document_owner_user_id);
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->approval_status);

        $this->actingAs($systemOwner)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve", [
            'comment' => 'Overstyrt av System Owner.',
        ])->assertRedirect(route('app.wiki.show', $page->slug));

        $approval->refresh();
        $run->refresh();

        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $approval->approval_status);
        $this->assertTrue($approval->is_override);
        $this->assertSame($systemOwner->id, $approval->decided_by_user_id);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
    }

    private function createCustomer(string $name = 'Document Owner Test AS'): Customer
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

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'name' => 'Owner '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, ?User $owner, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => $filename,
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst for '.$filename,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPendingPage(Customer $customer, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'title' => Str::headline($slug),
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createCurrentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.e($page->title),
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function createClaim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text, int $order = 0): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => $order,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function createSourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag for '.$document->original_filename,
        ]);
    }

    private function createPassedQaRun(Customer $customer, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, int $sourceId): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $sourceId,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED,
            'qa_started_at' => now()->subMinute(),
            'qa_completed_at' => now(),
            'qa_attempt_count' => 1,
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'generated_page_version_id' => $version->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'generation_started_at' => now()->subMinute(),
            'generation_completed_at' => now(),
        ]);

        return $run;
    }
}
