<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asking somebody to quality assure a Wiki version.
 *
 * The capability existed; the request did not. A QA user could approve and reject claims on any
 * page they could open, but nothing could say "this one is yours", so nobody was ever told there
 * was work waiting. These tests cover the sentence that was missing, and — at least as important —
 * everything it must NOT become.
 *
 * QA is contribution. It is not authority over the article, it is not a step the page waits on, and
 * it is not the document owner's job. Each of those is asserted rather than assumed, because the
 * three responsibilities are easy to collapse into one and hard to separate again afterwards.
 */
class EnterpriseWikiQaAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ── Assigning ───────────────────────────────────────────────────────────

    /** The reported setup: a Contributor whose QA capability finally means something. */
    public function test_a_contributor_with_qa_can_be_asked_to_quality_assure(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa)->assertSessionHas('success');

        $version->refresh();
        $this->assertSame((int) $qa->id, (int) $version->qa_user_id);
        $this->assertSame((int) $owner->id, (int) $version->qa_assigned_by_user_id);
        $this->assertNotNull($version->qa_assigned_at);
    }

    public function test_the_page_shows_who_was_asked_and_how_far_the_work_has_got(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims(pending: 2, approved: 1, rejected: 1);
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa);
        $payload = $this->qaPayloadSeenBy($owner, $page);

        $this->assertSame($qa->name, $payload['assignee']['name']);
        $this->assertSame($owner->name, $payload['assigned_by']['name']);
        $this->assertSame(
            ['total' => 4, 'approved' => 1, 'rejected' => 1, 'pending' => 2],
            $payload['claims'],
            'progress is derived from the claims, and a rejected claim is not hidden inside "done"',
        );
    }

    public function test_the_assignee_is_told(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa);

        $notification = $this->qaNotificationsFor($qa)->sole();

        $this->assertSame('Wiki-side til kvalitetssikring', $notification->title);
        $this->assertStringContainsString($page->title, $notification->message);
        $this->assertStringContainsString($owner->name, $notification->message);
        $this->assertSame(route('app.wiki.show', $page->slug), $notification->target_url);
        $this->assertSame((int) $version->id, (int) $notification->metadata['page_version_id']);
    }

    /** Only the page's workflow authority may ask — the same one that hands a page to a reviewer. */
    public function test_someone_without_page_authority_cannot_assign(): void
    {
        [$customer, , $page] = $this->pageWithClaims();
        $bystander = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($bystander, $page, $qa)->assertForbidden();
    }

    // ── Who may be asked ────────────────────────────────────────────────────

    public function test_the_candidate_list_offers_the_contributor_with_qa(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertContains($qa->id, $this->candidateIds($owner, $page));
    }

    public function test_a_contributor_without_qa_is_not_offered(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $plain = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertNotContains($plain->id, $this->candidateIds($owner, $page));
        $this->assign($owner, $page, $plain)->assertSessionHas('error');
    }

    /** Authority over the whole article is a different job, and does not confer the claim one. */
    public function test_a_wiki_approver_without_qa_is_not_offered(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $approver = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assertFalse($approver->canApproveWikiClaims(), 'the premise');
        $this->assertNotContains($approver->id, $this->candidateIds($owner, $page));
    }

    public function test_a_system_owner_is_offered(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->assertContains($systemOwner->id, $this->candidateIds($owner, $page));
    }

    public function test_an_inactive_qa_user_is_not_offered(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $qa->forceFill(['is_active' => false])->save();

        $this->assertNotContains($qa->id, $this->candidateIds($owner, $page));
    }

    public function test_a_qa_user_at_another_customer_is_never_offered(): void
    {
        [, $owner, $page] = $this->pageWithClaims();
        $outsider = $this->user($this->customer('Annen Kunde AS'), User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertNotContains($outsider->id, $this->candidateIds($owner, $page));
        $this->assign($owner, $page, $outsider)->assertSessionHas('error');
    }

    /**
     * The defect that hid every Wiki approver from the reviewer list, asserted for this list too:
     * a capability answered from a column the query forgot to select is a confident wrong "no".
     */
    public function test_a_partially_loaded_user_keeps_its_qa_capability(): void
    {
        $customer = $this->customer();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $full = User::query()->with('customer')->findOrFail($qa->id);
        $partial = User::query()->whereKey($qa->id)->get(User::CAPABILITY_COLUMNS)->sole();

        $this->assertTrue($full->canApproveWikiClaims(), 'the premise');
        $this->assertSame($full->canApproveWikiClaims(), $partial->canApproveWikiClaims());
    }

    // ── Reassignment and self-assignment ────────────────────────────────────

    public function test_reassigning_tells_the_new_person_and_not_the_old_one(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims();
        $first = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $second = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $first);
        $this->assign($owner, $page, $second);

        $this->assertSame((int) $second->id, (int) $version->refresh()->qa_user_id);
        $this->assertCount(1, $this->qaNotificationsFor($first));
        $this->assertCount(1, $this->qaNotificationsFor($second));
    }

    /** Handing it back is a real request, and deserves to be heard a second time. */
    public function test_handing_the_work_back_asks_again(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $first = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $second = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $first);
        $this->assign($owner, $page, $second);
        $this->travelTo(now()->addMinute());
        $this->assign($owner, $page, $first);

        $this->assertCount(2, $this->qaNotificationsFor($first));
    }

    public function test_assigning_the_same_person_again_changes_nothing(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa);
        $assignedAt = $version->refresh()->qa_assigned_at;

        $this->assign($owner, $page, $qa)->assertSessionHas('success');

        $this->assertEquals($assignedAt, $version->refresh()->qa_assigned_at, 'no second request was made');
        $this->assertCount(1, $this->qaNotificationsFor($qa));
    }

    /**
     * Allowed, unlike page review: QA is contribution, and a lone QA user recording that the work
     * is theirs is a true statement. They are simply not told what they just did.
     */
    public function test_taking_the_work_yourself_is_allowed_and_silent(): void
    {
        [$customer, , $page, $version] = $this->pageWithClaims();
        $owner = User::query()->findOrFail($page->owner_user_id);
        $owner->forceFill(['is_qa' => true])->save();

        $this->assign($owner->fresh(), $page, $owner)->assertSessionHas('success');

        $this->assertSame((int) $owner->id, (int) $version->refresh()->qa_user_id);
        $this->assertCount(0, $this->qaNotificationsFor($owner));
    }

    public function test_a_notification_never_crosses_a_customer_boundary(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $outsider = $this->user($this->customer('Tredjepart AS'), User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa);

        $this->assertCount(0, $this->qaNotificationsFor($outsider));
        $this->assertSame((int) $customer->id, (int) $this->qaNotificationsFor($qa)->sole()->customer_id);
    }

    // ── Version scope ───────────────────────────────────────────────────────

    /**
     * The reason the assignment lives on the version. An assignment that survived a regeneration
     * would say v2 had been checked when only v1 was — and nothing in the data would contradict it.
     */
    public function test_a_new_version_is_not_quality_assured_by_the_old_assignment(): void
    {
        [$customer, $owner, $page, $v1] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $this->assign($owner, $page, $qa);

        $v1->forceFill(['is_current' => false])->save();
        $v2 = $this->version($page, 2);

        $this->assertNull($v2->qa_user_id, 'v2 carries its own QA state');
        $this->assertSame((int) $qa->id, (int) $v1->refresh()->qa_user_id, 'and v1 keeps its history');
        $this->assertNull($this->qaPayloadSeenBy($owner, $page->fresh())['assignee']);
    }

    // ── What QA must never become ───────────────────────────────────────────

    /** The central guarantee: QA supports the work, it does not gate it. */
    public function test_a_page_with_no_qa_assignment_still_publishes(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims(pending: 3);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assertNull($version->qa_user_id, 'the premise: nobody was asked');

        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();
        $this->actingAs($reviewer)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertRedirect();

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $version->id, (int) $page->published_version_id);
    }

    /** Nor does an assignment with unfinished work block it. */
    public function test_pending_claims_under_an_open_qa_assignment_do_not_block_publication(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims(pending: 3);
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assign($owner, $page, $qa);

        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect();
        $this->actingAs($reviewer)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
    }

    /** QA touches nothing the review flow owns. */
    public function test_assigning_qa_leaves_the_review_assignment_alone(): void
    {
        [$customer, $owner, $page, $version] = $this->pageWithClaims();
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id]);
        $version->refresh();
        $submittedAt = $version->submitted_at;

        $this->assign($owner, $page, $qa);

        $version->refresh();
        $this->assertSame((int) $reviewer->id, (int) $version->reviewer_user_id);
        $this->assertEquals($submittedAt, $version->submitted_at);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    /** And the QA user does not become the document owner by being asked to check the claims. */
    public function test_the_qa_user_does_not_become_a_document_owner(): void
    {
        [$customer, $owner, $page] = $this->pageWithClaims();
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assign($owner, $page, $qa);

        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('document_owner_user_id', $qa->id)
                ->count(),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function assign(User $actor, EnterpriseWikiPage $page, User $assignee): TestResponse
    {
        return $this->actingAs($actor)->patch(
            route('app.wiki.qa-assignment.update', ['slug' => $page->slug]),
            ['qa_user_id' => $assignee->id],
        );
    }

    /** @return list<int> */
    private function candidateIds(User $actor, EnterpriseWikiPage $page): array
    {
        return array_column($this->qaPayloadSeenBy($actor, $page)['eligible_qa_users'], 'id');
    }

    /** @return array<string, mixed> */
    private function qaPayloadSeenBy(User $actor, EnterpriseWikiPage $page): array
    {
        $response = $this->actingAs($actor)->get("/app/wiki/{$page->slug}");
        $response->assertOk();

        return $response->viewData('page')['props']['qa_assignment'];
    }

    /** @return Collection<int, UserNotification> */
    private function qaNotificationsFor(User $user)
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('event_type', EnterpriseWikiReviewNotificationService::EVENT_QA_ASSIGNED)
            ->get();
    }

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiPage, 3: EnterpriseWikiPageVersion} */
    private function pageWithClaims(int $pending = 1, int $approved = 0, int $rejected = 0): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'kvalitet-'.Str::lower(Str::random(8)),
            'title' => 'Kvalitetssikret side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        $version = $this->version($page, 1);

        foreach ([
            EnterpriseWikiClaim::APPROVAL_STATUS_PENDING => $pending,
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED => $approved,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED => $rejected,
        ] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => 'Vi er sertifisert etter ISO 9001.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'conflict_flag' => false,
                    'approval_status' => $status,
                    'position_order' => 0,
                ]);
            }
        }

        return [$customer, $owner, $page->fresh(), $version];
    }

    private function version(EnterpriseWikiPage $page, int $number): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => true,
            'content_markdown' => "# Versjon {$number}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function user(
        Customer $customer,
        string $bidRole,
        bool $isQa = false,
        bool $isWikiApprover = false,
    ): User {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@kvalitet.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(string $name = 'Kvalitet AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
