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
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    /**
     * v0.10 architecture fix: ordinary source-attributed content (content_origin = source_based
     * on a page version's own content_blocks_json) must generate a document owner approval
     * requirement even when zero claims exist for the page — claims are Procynia's own
     * assertions (best practice, recommendation, interpretation, ...), never the entry ticket for
     * document owner approval. Covers scenario A + B from the fix ticket: requirement exists, and
     * the run cannot reach completed without an explicit human decision.
     */
    public function test_source_based_content_blocks_create_requirement_and_await_approval_with_zero_claims(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'zero-claim-source.docx');
        $page = $this->createPendingPage($customer, 'zero-claim-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);
        $run = $this->createPassedQaRun($customer, $page, $version, $document->id);

        $this->assertSame(0, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count());

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->firstOrFail();
        $this->assertSame($owner->id, $approval->document_owner_user_id);
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->approval_status);
        $this->assertContains($document->id, $approval->source_document_ids);
    }

    /**
     * Scenario C: once the real document owner records an actual approve decision (traceable to
     * user, decision and timestamp via the existing approval model), the run can complete — with
     * no claims involved anywhere in the flow.
     */
    public function test_real_owner_approval_completes_zero_claim_source_based_run(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'zero-claim-source.docx');
        $page = $this->createPendingPage($customer, 'zero-claim-approve-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);
        $run = $this->createPassedQaRun($customer, $page, $version, $document->id);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->firstOrFail();

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve", [
            'comment' => 'Godkjent uten claims.',
        ])->assertRedirect(route('app.wiki.show', $page->slug));

        $approval->refresh();
        $run->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $approval->approval_status);
        $this->assertSame($owner->id, $approval->decided_by_user_id);
        $this->assertNotNull($approval->decided_at);
        $this->assertFalse($approval->is_override);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
    }

    /**
     * Scenario D: a page whose content_blocks_json cites two different source documents owned by
     * two different users — no claims involved — must require both owners' approval before the
     * run can complete, and neither owner can satisfy the other's requirement.
     */
    public function test_two_source_documents_with_two_owners_both_required_via_block_provenance(): void
    {
        $customer = $this->createCustomer();
        $ownerX = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $ownerY = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $documentA = $this->createDocument($customer, $ownerX, 'doc-a.docx');
        $documentB = $this->createDocument($customer, $ownerY, 'doc-b.docx');
        $page = $this->createPendingPage($customer, 'two-doc-block-page');
        $version = $this->createCurrentVersionWithBlocks($page, [
            $this->sourceBasedBlock($documentA, 'block-0001'),
            $this->sourceBasedBlock($documentB, 'block-0002'),
        ]);
        $run = $this->createPassedQaRun($customer, $page, $version, $documentA->id);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        $approvalX = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('document_owner_user_id', $ownerX->id)
            ->firstOrFail();
        $approvalY = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('document_owner_user_id', $ownerY->id)
            ->firstOrFail();

        // X cannot satisfy Y's requirement.
        $this->actingAs($ownerX)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalY->id}/approve")
            ->assertForbidden();

        $this->actingAs($ownerX)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalX->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        $this->actingAs($ownerY)->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalY->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
    }

    /**
     * Scenario E: block-level provenance and a claim's own source reference both point at the same
     * document — requirement identity must stay deterministic, so no duplicate approval row is
     * created just because two provenance paths agree on the same document.
     */
    public function test_provenance_and_claim_to_same_document_does_not_duplicate_requirement(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'shared-doc.docx');
        $page = $this->createPendingPage($customer, 'shared-doc-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);
        $claim = $this->createClaim($page, $version, 'Krav fra delt dokument.');
        $this->createSourceReference($claim, $document);

        $approvals = app(EnterpriseWikiDocumentOwnerApprovalService::class)->syncForPageVersion($version);

        $this->assertCount(1, $approvals);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    /**
     * Scenario F: running the requirement builder twice must be idempotent — no duplicate rows,
     * and an already-recorded decision must not be overwritten by the second pass.
     */
    public function test_requirement_builder_is_idempotent_across_repeated_runs(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'idempotent-doc.docx');
        $page = $this->createPendingPage($customer, 'idempotent-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);

        $service = app(EnterpriseWikiDocumentOwnerApprovalService::class);
        $first = $service->syncForPageVersion($version);
        $this->assertCount(1, $first);

        $second = $service->syncForPageVersion($version);
        $this->assertCount(1, $second);
        $this->assertSame($first->first()->id, $second->first()->id);

        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    /**
     * Scenario H, combined with F: an existing decision on a requirement survives a repeated
     * requirement-building pass — approving, then re-syncing, must not reset the decision back to
     * pending.
     */
    public function test_existing_approval_decision_is_preserved_across_re_evaluation(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'preserved-decision-doc.docx');
        $page = $this->createPendingPage($customer, 'preserved-decision-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);

        $service = app(EnterpriseWikiDocumentOwnerApprovalService::class);
        $approval = $service->syncForPageVersion($version)->first();
        $service->decide($approval, $owner, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, 'Godkjent.');

        $resynced = $service->syncForPageVersion($version)->first();

        $resynced->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $resynced->approval_status);
        $this->assertSame($owner->id, $resynced->decided_by_user_id);
        $this->assertNotNull($resynced->decided_at);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    /**
     * Scenario G: a terminal run must not gain new approval requirements from a stale/late
     * re-evaluation call — the pre-existing terminal guard in
     * EnterpriseWikiDocumentFlowService::reconcileRunDocumentOwnerApprovalState() must still hold
     * for the new block-based provenance path, exactly as it already did for claims.
     */
    public function test_terminal_run_gets_no_new_requirements_from_block_provenance(): void
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'terminal-doc.docx');
        $page = $this->createPendingPage($customer, 'terminal-run-page');
        $version = $this->createCurrentVersionWithBlocks($page, [$this->sourceBasedBlock($document)]);
        $run = $this->createPassedQaRun($customer, $page, $version, $document->id);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED, 'finished_at' => now()]);

        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
        );
    }

    private function createCurrentVersionWithBlocks(EnterpriseWikiPage $page, array $blocks): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.e($page->title),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function sourceBasedBlock(EnterpriseWikiDocument $document, string $blockKey = 'block-0001'): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => 'Kildebasert innhold for '.$document->original_filename,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_elements' => [],
        ];
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
