<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Document-owner approval is a quality gate before final Wiki review, not a form of publication.
 *
 * Each active row is one owner saying "the content drawn from MY documents is represented
 * correctly" — nothing about the page as a whole. That judgement stays with the assigned reviewer,
 * who still has to act; clearing the gate never publishes anything by itself.
 *
 * Three rights are involved and none implies another: approve_wiki_claims (one statement against its
 * source), document-owner approval (my documents are represented correctly), and approve_wiki_pages
 * (publish the page). See docs/enterprise-wiki-approval-model.md §10.
 */
class EnterpriseWikiSourceOwnerGateTest extends TestCase
{
    use DatabaseTransactions;

    private EnterpriseWikiDocumentOwnerApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->approvals = app(EnterpriseWikiDocumentOwnerApprovalService::class);
    }

    // A. one document, one owner
    public function test_one_source_document_produces_one_requirement(): void
    {
        [, , $version, $owners] = $this->pageWithSources(1);

        $this->approvals->syncForPageVersion($version);

        $active = $this->activeRequirements($version);
        $this->assertCount(1, $active);
        $this->assertSame($owners[0]->id, (int) $active->first()->document_owner_user_id);
    }

    // B. two documents, two owners
    public function test_two_owners_produce_two_requirements(): void
    {
        [, , $version] = $this->pageWithSources(2);

        $this->approvals->syncForPageVersion($version);

        $this->assertCount(2, $this->activeRequirements($version));
    }

    // C. two documents, same owner
    public function test_two_documents_with_one_owner_produce_a_single_requirement(): void
    {
        [$customer, , $version, $owners, $documents] = $this->pageWithSources(2);
        $documents[1]->forceFill(['owner_user_id' => $owners[0]->id])->save();

        $this->approvals->syncForPageVersion($version);

        $active = $this->activeRequirements($version);
        $this->assertCount(1, $active);
        $this->assertEqualsCanonicalizing(
            collect($documents)->pluck('id')->all(),
            $active->first()->source_document_ids,
        );
    }

    // D + G. the owner decides their own requirement, and it is recorded
    public function test_the_document_owner_can_decide_their_own_requirement(): void
    {
        [, $page, $version, $owners] = $this->pageWithSources(1);
        $this->approvals->syncForPageVersion($version);
        $requirement = $this->activeRequirements($version)->first();

        $this->actingAs($owners[0])
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $requirement->refresh();
        $this->assertSame(EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED, $requirement->approval_status);
        $this->assertNotNull($requirement->decided_at);
        $this->assertSame($owners[0]->id, (int) $requirement->decided_by_user_id);
        $this->assertNotEmpty($requirement->source_document_ids, 'the documents it covers are kept');
    }

    // E. somebody else's requirement is not theirs to decide
    public function test_another_users_requirement_cannot_be_decided(): void
    {
        [$customer, $page, $version, $owners] = $this->pageWithSources(2);
        $this->approvals->syncForPageVersion($version);
        $requirement = $this->activeRequirements($version)
            ->firstWhere('document_owner_user_id', $owners[0]->id);

        $this->actingAs($owners[1])
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve")
            ->assertForbidden();

        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
            $requirement->fresh()->approval_status,
        );
    }

    // F. a retired requirement is history
    public function test_a_superseded_requirement_cannot_be_decided(): void
    {
        [$customer, , $version, $owners, $documents] = $this->pageWithSources(1);
        $this->approvals->syncForPageVersion($version);

        $documents[0]->forceFill(['owner_user_id' => $this->user($customer)->id])->save();
        $this->approvals->syncForDocument($documents[0]->fresh());

        $stale = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNotNull('superseded_at')
            ->firstOrFail();

        $this->assertFalse($this->approvals->canDecide($stale, $owners[0]));
    }

    // I. a pending requirement blocks final approval
    public function test_a_pending_requirement_blocks_final_approval(): void
    {
        [$customer, $page, $version] = $this->submittedPage(1);

        $this->actingAs($this->reviewerOf($page))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertStatus(409);

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_version_id);
    }

    // H. a rejection blocks it outright
    public function test_a_rejected_requirement_blocks_final_approval(): void
    {
        [$customer, $page, $version, $owners] = $this->submittedPage(1);
        $requirement = $this->activeRequirements($version)->first();

        $this->actingAs($owners[0])
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/reject", ['comment' => 'Kildegrunnlaget stemmer ikke med innholdet.']);

        // Since step 8 a refusal also sends the version back to its owner, so the page is no longer
        // in review at all — 422 rather than 409. Either way final approval is impossible.
        $this->actingAs($this->reviewerOf($page))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertStatus(422);

        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->fresh()->status);
        $this->assertNull($page->fresh()->published_version_id);
    }

    // J + K. cleared gate lets the reviewer act — and only the reviewer publishes
    public function test_the_reviewer_can_approve_once_every_owner_has_signed_off(): void
    {
        [$customer, $page, $version, $owners] = $this->submittedPage(2);
        $this->clearGate($page, $version, $owners);

        $this->assertSame(
            EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            $page->fresh()->status,
            'clearing the gate does not approve the page by itself',
        );
        $this->assertNull($page->fresh()->published_version_id, 'nor does it publish anything');

        $this->actingAs($this->reviewerOf($page))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
        $this->assertSame($version->id, (int) $page->fresh()->published_version_id);
    }

    // O. deciding a requirement never publishes
    public function test_deciding_a_requirement_does_not_touch_the_published_version(): void
    {
        [$customer, $page, $version, $owners] = $this->submittedPage(1);
        $earlier = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 99,
            'is_current' => false,
            'content_markdown' => '# Tidligere',
            'generated_by_model' => 'gpt-5',
        ]);
        $page->forceFill(['published_version_id' => $earlier->id])->save();

        $requirement = $this->activeRequirements($version)->first();
        $this->actingAs($owners[0])
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve");

        $this->assertSame($earlier->id, (int) $page->fresh()->published_version_id);
    }

    // L. no bypass of the gate
    public function test_a_system_owner_cannot_skip_the_gate(): void
    {
        [$customer, $page] = $this->submittedPage(1);
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertStatus(409);

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_a_system_owner_may_decide_an_owners_requirement_and_it_is_marked_as_an_override(): void
    {
        // The override exists on the row itself, so it is visible rather than a silent bypass.
        [$customer, $page, $version] = $this->submittedPage(1);
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $requirement = $this->activeRequirements($version)->first();

        $this->actingAs($systemOwner)
            ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve");

        $requirement->refresh();
        $this->assertTrue((bool) $requirement->is_override);
        $this->assertSame($systemOwner->id, (int) $requirement->overridden_by_user_id);
    }

    // M + N. neither of the other two rights satisfies this gate
    public function test_claim_approval_does_not_satisfy_the_source_owner_gate(): void
    {
        [$customer, $page, $version] = $this->submittedPage(1);
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_CLAIMS, ['contributor']);
        $claimApprover = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $requirement = $this->activeRequirements($version)->first();

        $this->assertTrue($claimApprover->canApproveWikiClaims());
        $this->assertFalse($this->approvals->canDecide($requirement, $claimApprover));
    }

    public function test_page_review_capability_does_not_satisfy_the_source_owner_gate(): void
    {
        [$customer, $page, $version] = $this->submittedPage(1);
        $reviewer = $this->reviewerOf($page);
        $requirement = $this->activeRequirements($version)->first();

        $this->assertTrue($reviewer->canApproveWikiPages());
        $this->assertFalse(
            $this->approvals->canDecide($requirement, $reviewer),
            'publishing the page is a different right from vouching for another person\'s document',
        );
    }

    // P. submit derives the requirement set
    public function test_submit_syncs_the_requirement_set(): void
    {
        [$customer, $page, $version, $owners, $documents] = $this->pageWithSources(1);
        $this->assertCount(0, $this->activeRequirements($version), 'nothing exists before submit');

        $this->submit($page, $customer);

        $this->assertCount(1, $this->activeRequirements($version));
    }

    public function test_submit_asks_the_current_owner_not_the_previous_one(): void
    {
        [$customer, $page, $version, $owners, $documents] = $this->pageWithSources(1);
        $newOwner = $this->user($customer);
        $documents[0]->forceFill(['owner_user_id' => $newOwner->id])->save();

        $this->submit($page, $customer);

        $active = $this->activeRequirements($version);
        $this->assertCount(1, $active);
        $this->assertSame($newOwner->id, (int) $active->first()->document_owner_user_id);
    }

    // Q. nothing to ask is a valid state
    public function test_a_version_with_no_source_content_does_not_block_final_review(): void
    {
        [$customer, $page, $version] = $this->pageWithSources(0);
        $this->submit($page, $customer);

        $this->assertCount(0, $this->activeRequirements($version));

        $this->actingAs($this->reviewerOf($page))
            ->patch("/app/wiki/{$page->slug}/approve")
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    public function test_the_payload_explains_why_final_approval_is_blocked(): void
    {
        [$customer, $page] = $this->submittedPage(1);

        $this->actingAs($this->reviewerOf($page))
            ->get("/app/wiki/{$page->slug}")
            ->assertSuccessful()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.source_owner_gate.ready', false)
                ->where('review_assignment.source_owner_gate.blocking_reason', 'pending')
                ->where(
                    'review_assignment.source_owner_gate.requirements',
                    fn ($rows) => count($rows) === 1 && $rows[0]['status'] === 'pending',
                ));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array{0: Customer, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: list<User>, 4: list<EnterpriseWikiDocument>} */
    private function pageWithSources(int $documentCount): array
    {
        $customer = $this->customer();
        $pageOwner = $this->user($customer);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $pageOwner->id,
            'slug' => 'kildeport-'.Str::lower(Str::random(6)),
            'title' => 'Kildeport Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Kildeport Side',
            'generated_by_model' => 'gpt-5',
        ]);

        $owners = [];
        $documents = [];

        for ($i = 0; $i < $documentCount; $i++) {
            $owner = $this->user($customer);
            $document = $this->document($customer, $owner);
            $claim = EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => "Påstand {$i}.",
                'position_order' => $i,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            ]);
            EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'excerpt' => 'Utdrag',
            ]);

            $owners[] = $owner;
            $documents[] = $document;
        }

        return [$customer, $page, $version, $owners, $documents];
    }

    /** A page already submitted to a valid reviewer. */
    private function submittedPage(int $documentCount): array
    {
        [$customer, $page, $version, $owners, $documents] = $this->pageWithSources($documentCount);
        $this->submit($page, $customer);

        return [$customer, $page->fresh(), $version, $owners, $documents];
    }

    private function submit(EnterpriseWikiPage $page, Customer $customer): void
    {
        $owner = User::query()->findOrFail($page->owner_user_id);

        $this->actingAs($owner)->patch("/app/wiki/{$page->slug}/submit", [
            'reviewer_user_id' => $this->reviewerOf($page)->id,
        ])->assertRedirect(route('app.wiki.show', $page->slug));
    }

    /** The single user holding approve_wiki_pages for this page's customer. */
    private function reviewerOf(EnterpriseWikiPage $page): User
    {
        $customer = Customer::query()->findOrFail($page->customer_id);
        $this->grant($customer, Customer::PERMISSION_APPROVE_WIKI_PAGES, ['bid_manager']);

        return User::query()
            ->where('customer_id', $customer->id)
            ->where('bid_role', User::BID_ROLE_BID_MANAGER)
            ->first() ?? $this->user($customer, User::BID_ROLE_BID_MANAGER);
    }

    /** @param list<User> $owners */
    private function clearGate(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, array $owners): void
    {
        foreach ($this->activeRequirements($version) as $requirement) {
            $owner = collect($owners)->firstWhere('id', $requirement->document_owner_user_id);

            $this->actingAs($owner)
                ->patch("/app/wiki/{$page->slug}/document-owner-approvals/{$requirement->id}/approve")
                ->assertRedirect(route('app.wiki.show', $page->slug));
        }
    }

    private function activeRequirements(EnterpriseWikiPageVersion $version)
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->get();
    }

    /** @param list<string> $roles */
    private function grant(Customer $customer, string $permission, array $roles): void
    {
        $settings = $customer->resolvedPermissionSettings();
        $settings[$permission] = $roles;
        $customer->forceFill(['permission_settings' => $settings])->save();
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Kildeport Test AS',
            'slug' => 'kildeport-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@kildeport.test',
            'password' => bcrypt('secret'),
            'role' => in_array($bidRole, [User::BID_ROLE_SYSTEM_OWNER, User::BID_ROLE_BID_MANAGER], true)
                ? User::ROLE_CUSTOMER_ADMIN
                : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
