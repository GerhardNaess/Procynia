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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A completed run must not keep looking finished once a document owner change creates a NEW,
 * still-undecided approval requirement on one of its current page versions.
 *
 * Before the fix, three layered terminal guards made completed -> awaiting impossible, so the Wiki
 * showed a finished run next to an approval nobody had made. The fix opens exactly one transition —
 * `completed` -> `awaiting_document_owner_approval`, and only when the existing completion gate says
 * the run is genuinely not ready.
 *
 * The second half of this file closes the override-test gap the mapping found: System Owner deciding
 * a NAMED other owner's requirement was never covered (only the missing-owner case), and the reject
 * route had no test at all. Nothing about the authorization model is changed here — these tests only
 * pin the behaviour that already exists.
 */
class EnterpriseWikiDocumentOwnerReassignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // The main scenario: owner change after a completed run
    // =========================================================================

    public function test_owner_change_after_a_completed_run_reopens_it_and_the_new_owner_can_complete_it_again(): void
    {
        [$customer, $ownerA, $document, $page, $version, $run] = $this->completedRunApprovedBy();
        $ownerB = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $approvalA = $this->approvalFor($version, $ownerA);
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $approvalA->approval_status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);

        // 4-8: ownership moves to B.
        $document->forceFill(['owner_user_id' => $ownerB->id])->save();
        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document->fresh());

        $run->refresh();
        $this->assertSame(
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            $run->status,
            'a new outstanding requirement must reopen the run',
        );
        $this->assertNull($run->finished_at);
        $this->assertStringContainsString('Dokumenteier', (string) $run->error_message);

        // 9: the historical decision is untouched and still attributed to A.
        $approvalA->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $approvalA->approval_status);
        $this->assertSame($ownerA->id, $approvalA->document_owner_user_id);
        $this->assertSame($ownerA->id, $approvalA->decided_by_user_id);

        $approvalB = $this->approvalFor($version, $ownerB);
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approvalB->approval_status);
        $this->assertNotSame($approvalA->id, $approvalB->id);

        // 10-11: B approves through the ordinary route and the run completes again.
        $this->actingAs($ownerB)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalB->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($ownerB->id, $approvalB->fresh()->decided_by_user_id);
        $this->assertFalse((bool) $approvalB->fresh()->is_override);
    }

    public function test_a_system_owner_override_can_complete_the_reopened_run(): void
    {
        [$customer, $ownerA, $document, $page, $version, $run] = $this->completedRunApprovedBy();
        $ownerB = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $document->forceFill(['owner_user_id' => $ownerB->id])->save();
        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document->fresh());

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->fresh()->status);

        $approvalB = $this->approvalFor($version, $ownerB);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approvalB->id}/approve", [
                'comment' => 'B er utilgjengelig, overstyrt.',
            ])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $approvalB->refresh();
        $run->refresh();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertTrue((bool) $approvalB->is_override);
        $this->assertSame($systemOwner->id, $approvalB->decided_by_user_id);
        $this->assertSame($ownerB->id, $approvalB->document_owner_user_id);
    }

    // =========================================================================
    // Negative: an owner change that creates nothing outstanding
    // =========================================================================

    public function test_a_document_no_current_page_version_uses_never_reopens_a_completed_run(): void
    {
        [$customer, , , , , $run] = $this->completedRunApprovedBy();

        // A second document of the same customer that no page version references at all.
        $unrelated = $this->createDocument($customer, $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR), 'ubrukt.docx');
        $newOwner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $unrelated->forceFill(['owner_user_id' => $newOwner->id])->save();
        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($unrelated->fresh());

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_a_sync_that_creates_no_new_requirement_leaves_the_run_completed(): void
    {
        [, , $document, , $version, $run] = $this->completedRunApprovedBy();

        // Same owner, same documents: the gate stays ready, so nothing may change.
        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document->fresh());

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count(),
            'a no-op sync must not create a second approval row',
        );
    }

    // =========================================================================
    // Only `completed` reopens — other terminal statuses stay terminal
    // =========================================================================

    #[DataProvider('protectedTerminalStatuses')]
    public function test_an_owner_change_never_revives_a_run_that_ended_for_a_technical_reason(string $terminalStatus): void
    {
        [$customer, , $document, , $version, $run] = $this->completedRunApprovedBy();
        $ownerB = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);

        $run->forceFill(['status' => $terminalStatus, 'finished_at' => now()])->save();

        $document->forceFill(['owner_user_id' => $ownerB->id])->save();
        app(EnterpriseWikiDocumentFlowService::class)->syncDocumentOwnerApprovals($document->fresh());

        $this->assertSame($terminalStatus, $run->fresh()->status, "a {$terminalStatus} run must stay terminal");

        // The requirement itself is still recorded — only the run status is protected.
        $this->assertNotNull($this->approvalFor($version, $ownerB));
    }

    /** @return array<string, array{0: string}> */
    public static function protectedTerminalStatuses(): array
    {
        return [
            'failed' => [EnterpriseWikiIngestRun::STATUS_FAILED],
            'cancelled' => [EnterpriseWikiIngestRun::STATUS_CANCELLED],
            'escalated' => [EnterpriseWikiIngestRun::STATUS_ESCALATED],
        ];
    }

    // =========================================================================
    // System Owner override on a NAMED other owner (the mapped test gap)
    // =========================================================================

    public function test_a_system_owner_approves_a_named_other_owners_requirement_and_is_recorded_as_the_actor(): void
    {
        [$customer, $owner, , $page, $version, $run] = $this->awaitingRunOwnedBy();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve", [
                'comment' => 'Godkjent på vegne av eier.',
            ])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $approval->refresh();

        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $approval->approval_status);
        $this->assertSame($owner->id, $approval->document_owner_user_id, 'the requirement still belongs to the real owner');
        $this->assertSame($systemOwner->id, $approval->decided_by_user_id, 'the decision is attributed to who actually made it');
        $this->assertTrue((bool) $approval->is_override);
        $this->assertSame($systemOwner->id, $approval->overridden_by_user_id);
        $this->assertNotNull($approval->overridden_at);
        $this->assertSame('Godkjent på vegne av eier.', $approval->override_reason);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_a_system_owner_rejects_a_named_other_owners_requirement(): void
    {
        [$customer, $owner, , $page, $version, $run] = $this->awaitingRunOwnedBy();
        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/reject", [
                'comment' => 'Innholdet er ikke dekkende.',
            ])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $approval->refresh();

        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED, $approval->approval_status);
        $this->assertSame($owner->id, $approval->document_owner_user_id);
        $this->assertSame($systemOwner->id, $approval->decided_by_user_id, 'a rejection must never be attributed to the document owner');
        $this->assertTrue((bool) $approval->is_override);
        $this->assertSame($systemOwner->id, $approval->overridden_by_user_id);
        $this->assertSame('Innholdet er ikke dekkende.', $approval->override_reason);

        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status, 'a rejected requirement cannot complete the run');
    }

    public function test_the_document_owners_own_decision_is_not_marked_as_an_override(): void
    {
        [, $owner, , $page, $version] = $this->awaitingRunOwnedBy();
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $approval->refresh();

        $this->assertSame($owner->id, $approval->decided_by_user_id);
        $this->assertFalse((bool) $approval->is_override);
        $this->assertNull($approval->overridden_by_user_id);
        $this->assertNull($approval->overridden_at);
    }

    // =========================================================================
    // Who may NOT decide
    // =========================================================================

    public function test_an_ordinary_user_who_is_not_the_owner_cannot_decide(): void
    {
        [$customer, $owner, , $page, $version] = $this->awaitingRunOwnedBy();
        $bystander = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $approval = $this->approvalFor($version, $owner);

        foreach (['approve', 'reject'] as $action) {
            $this->actingAs($bystander)
                ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/{$action}")
                ->assertForbidden();
        }

        $approval->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->approval_status);
        $this->assertNull($approval->decided_by_user_id);
    }

    public function test_a_bid_manager_who_is_not_the_owner_cannot_decide(): void
    {
        [$customer, $owner, , $page, $version] = $this->awaitingRunOwnedBy();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($bidManager)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->fresh()->approval_status);
    }

    public function test_a_user_from_another_customer_cannot_reach_the_approval_route(): void
    {
        [, $owner, , $page, $version] = $this->awaitingRunOwnedBy();
        $approval = $this->approvalFor($version, $owner);

        $foreignCustomer = $this->createCustomer('Fremmed Kunde AS');
        $foreignSystemOwner = $this->createUser($foreignCustomer, User::BID_ROLE_SYSTEM_OWNER);

        foreach (['approve', 'reject'] as $action) {
            $this->actingAs($foreignSystemOwner)
                ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/{$action}")
                ->assertNotFound();
        }

        $approval->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->approval_status);
        $this->assertNull($approval->decided_by_user_id);
        $this->assertNull($approval->decided_at);
    }

    // =========================================================================
    // The authorization rule itself is untouched
    // =========================================================================

    public function test_the_can_decide_contract_is_unchanged(): void
    {
        [$customer, $owner, , , $version] = $this->awaitingRunOwnedBy();
        $service = app(EnterpriseWikiDocumentOwnerApprovalService::class);
        $approval = $this->approvalFor($version, $owner);

        $systemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $inactiveSystemOwner = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);
        $inactiveSystemOwner->forceFill(['is_active' => false])->save();

        $this->assertTrue($service->canDecide($approval, $owner));
        $this->assertTrue($service->canDecide($approval, $systemOwner));
        $this->assertFalse($service->canDecide($approval, $contributor));
        $this->assertFalse($service->canDecide($approval, $inactiveSystemOwner->fresh()));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A run that reached `completed` because its real document owner approved it.
     *
     * @return array{0: Customer, 1: User, 2: EnterpriseWikiDocument, 3: EnterpriseWikiPage, 4: EnterpriseWikiPageVersion, 5: EnterpriseWikiIngestRun}
     */
    private function completedRunApprovedBy(): array
    {
        [$customer, $owner, $document, $page, $version, $run] = $this->awaitingRunOwnedBy();
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);

        return [$customer, $owner, $document, $page, $version, $run];
    }

    /**
     * A QA-passed run parked at `awaiting_document_owner_approval` with one pending requirement.
     *
     * @return array{0: Customer, 1: User, 2: EnterpriseWikiDocument, 3: EnterpriseWikiPage, 4: EnterpriseWikiPageVersion, 5: EnterpriseWikiIngestRun}
     */
    private function awaitingRunOwnedBy(): array
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer, User::BID_ROLE_CONTRIBUTOR);
        $document = $this->createDocument($customer, $owner, 'kilde.docx');
        $page = $this->createPendingPage($customer, 'eierbytte-side');
        $version = $this->createCurrentVersion($page);
        $claim = $this->createClaim($page, $version, 'Påstand fra kilden.');
        $this->createSourceReference($claim, $document);
        $run = $this->createPassedQaRun($customer, $page, $version, $document->id);

        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL, $run->status);

        return [$customer, $owner, $document, $page, $version, $run];
    }

    private function approvalFor(EnterpriseWikiPageVersion $version, User $owner): EnterpriseWikiPageVersionDocumentOwnerApproval
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('document_owner_user_id', $owner->id)
            ->firstOrFail();
    }

    private function createCustomer(string $name = 'Eierbytte Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

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
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@eierbytte.test',
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

    private function createClaim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => 0,
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

    private function createPassedQaRun(
        Customer $customer,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        int $sourceId,
    ): EnterpriseWikiIngestRun {
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
