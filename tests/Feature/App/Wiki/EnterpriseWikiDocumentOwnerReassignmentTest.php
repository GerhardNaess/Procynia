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

/**
 * Who may decide a document-owner requirement, and how that decision is recorded.
 *
 * This file once opened with the opposite guarantee: that a finished run reopened when an owner
 * change created a new, undecided requirement. That mattered while the sign-off gated publishing —
 * a run looking finished next to an approval nobody had made was a lie. It gates nothing now, so a
 * finished run stays finished and those tests went with the rule (see the reassignment test in
 * EnterpriseWikiSourceDocumentLifecycleTest for what replaced them).
 *
 * What remains is the authorization model, which is unchanged: a System Owner may decide a NAMED
 * other owner's requirement and is recorded as having overridden it, an owner's own decision is not
 * an override, and nobody else may decide at all.
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

    public function test_a_system_owner_approves_a_named_other_owners_requirement_and_is_recorded_as_the_actor(): void
    {
        [$customer, $owner, , $page, $version, $run] = $this->finishedRunOwnedBy();
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
        [$customer, $owner, , $page, $version, $run] = $this->finishedRunOwnedBy();
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

        // The run's own status is about its processing and is not revisited. What a refusal does
        // is send the page back to its owner, which is the consequence that still means something.
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
    }

    public function test_the_document_owners_own_decision_is_not_marked_as_an_override(): void
    {
        [, $owner, , $page, $version] = $this->finishedRunOwnedBy();
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
        [$customer, $owner, , $page, $version] = $this->finishedRunOwnedBy();
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
        [$customer, $owner, , $page, $version] = $this->finishedRunOwnedBy();
        $bidManager = $this->createUser($customer, User::BID_ROLE_BID_MANAGER);
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($bidManager)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve")
            ->assertForbidden();

        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING, $approval->fresh()->approval_status);
    }

    public function test_a_user_from_another_customer_cannot_reach_the_approval_route(): void
    {
        [, $owner, , $page, $version] = $this->finishedRunOwnedBy();
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
        [$customer, $owner, , , $version] = $this->finishedRunOwnedBy();
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
        [$customer, $owner, $document, $page, $version, $run] = $this->finishedRunOwnedBy();
        $approval = $this->approvalFor($version, $owner);

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$approval->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);

        return [$customer, $owner, $document, $page, $version, $run];
    }

    /**
     * A QA-passed run that has finished, with one pending requirement recorded against it.
     *
     * The run completing while the requirement is undecided is the point: the sign-off is
     * provenance, and the run was never waiting on it.
     *
     * @return array{0: Customer, 1: User, 2: EnterpriseWikiDocument, 3: EnterpriseWikiPage, 4: EnterpriseWikiPageVersion, 5: EnterpriseWikiIngestRun}
     */
    private function finishedRunOwnedBy(): array
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
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);

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
