<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who appears in the list of people a page can be handed to.
 *
 * The bug these tests exist for: the query built the list with an explicit column list that had
 * never been updated when is_wiki_approver was introduced. A Contributor who held the capability
 * came back with the column unset, answered "no" to canApproveWikiPages(), and vanished — and the
 * page then told the owner that nobody at the customer could approve at all. A confident wrong
 * answer, from a select list, about a permission the admin screen showed as granted.
 *
 * So most of what is asserted below is not the permission rule, which was always right, but that
 * the list and the rule agree. A capability check on a partially hydrated model must give the same
 * answer as one on a full model, or every list built that way is quietly lying.
 */
class EnterpriseWikiReviewerCandidateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * The exact reported setup: Contributor, QA on, Wiki approver on. A perfectly ordinary person
     * in a small team, and the one the list dropped.
     */
    public function test_a_contributor_who_is_a_wiki_approver_can_be_chosen(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);

        $this->assertContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    /** The capability alone is what qualifies. QA is a different job and not required here. */
    public function test_the_wiki_approver_capability_alone_is_enough(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assertContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    /**
     * The root cause, asserted directly rather than only through its symptom.
     *
     * A capability answered from a column the query forgot to select is not a missing answer; it is
     * a wrong one. Anything that hydrates part of a User and then asks what they may do has to read
     * from the same set.
     */
    public function test_a_partially_loaded_user_answers_the_same_as_a_full_one(): void
    {
        $customer = $this->customer();
        $person = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);

        $full = User::query()->with('customer')->findOrFail($person->id);
        $partial = User::query()->whereKey($person->id)->get(User::CAPABILITY_COLUMNS)->sole();

        $this->assertTrue($full->canApproveWikiPages(), 'the premise: the capability is genuinely held');
        $this->assertSame($full->canApproveWikiPages(), $partial->canApproveWikiPages());
        $this->assertSame($full->canApproveWikiClaims(), $partial->canApproveWikiClaims());
        $this->assertSame($full->canBeEnterpriseWikiDocumentOwner(), $partial->canBeEnterpriseWikiDocumentOwner());
    }

    // ── What must still be excluded ─────────────────────────────────────────

    public function test_a_plain_contributor_is_not_a_candidate(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->assertNotContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    /** QA is about claims. It has never been a mandate over the whole article, and still is not. */
    public function test_qa_alone_does_not_make_someone_a_candidate(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $this->assertFalse($candidate->canApproveWikiPages());
        $this->assertNotContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    /** A main role is not the capability. Being a bid manager confers nothing by itself. */
    public function test_a_bid_manager_without_the_capability_is_not_a_candidate(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_BID_MANAGER);

        $this->assertNotContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    public function test_an_inactive_approver_is_not_a_candidate(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $candidate = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);
        $candidate->forceFill(['is_active' => false])->save();

        $this->assertNotContains($candidate->id, $this->candidateIdsSeenBy($owner, $page));
    }

    public function test_an_approver_at_another_customer_is_never_offered(): void
    {
        [, $owner, $page] = $this->draftPage();
        $other = $this->customer('Annen Kunde AS');
        $outsider = $this->user($other, User::BID_ROLE_CONTRIBUTOR, isWikiApprover: true);

        $this->assertNotContains($outsider->id, $this->candidateIdsSeenBy($owner, $page));
    }

    /** System Owner qualifies through the permission matrix, as it always has. */
    public function test_a_system_owner_is_a_candidate(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->assertContains($systemOwner->id, $this->candidateIdsSeenBy($owner, $page));
    }

    // ── Self-review ─────────────────────────────────────────────────────────

    /**
     * submit() refuses a reviewer who is the submitter, so the list leaves the actor out. That is
     * the existing rule and it is unchanged — but it means an empty list can mean two different
     * things, and the page says which.
     */
    public function test_the_actor_is_never_offered_to_themselves(): void
    {
        [$customer, $owner, $page] = $this->draftPage();
        $owner->forceFill(['is_wiki_approver' => true])->save();

        $this->assertTrue($owner->fresh()->canApproveWikiPages(), 'even holding the capability');
        $this->assertNotContains($owner->id, $this->candidateIdsSeenBy($owner->fresh(), $page));
    }

    /**
     * The honest distinction: "nobody can approve" and "the only person who can is you" are
     * different problems with different fixes, and the second is the one a lone approver hits.
     */
    public function test_the_page_says_whether_the_only_approver_is_the_actor(): void
    {
        [, $owner, $page] = $this->draftPage();
        $owner->forceFill(['is_wiki_approver' => true])->save();

        $payload = $this->reviewAssignmentSeenBy($owner->fresh(), $page);

        $this->assertSame([], $payload['eligible_reviewers']);
        $this->assertTrue($payload['actor_can_approve_wiki_pages'], 'so the empty list can be explained honestly');
    }

    public function test_an_empty_list_with_no_approver_at_all_is_reported_as_such(): void
    {
        [, $owner, $page] = $this->draftPage();

        $payload = $this->reviewAssignmentSeenBy($owner, $page);

        $this->assertSame([], $payload['eligible_reviewers']);
        $this->assertFalse($payload['actor_can_approve_wiki_pages']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function candidateIdsSeenBy(User $actor, EnterpriseWikiPage $page): array
    {
        return array_column($this->reviewAssignmentSeenBy($actor, $page)['eligible_reviewers'], 'id');
    }

    /** @return array<string, mixed> */
    private function reviewAssignmentSeenBy(User $actor, EnterpriseWikiPage $page): array
    {
        $response = $this->actingAs($actor)->get("/app/wiki/{$page->slug}");
        $response->assertOk();

        return $response->viewData('page')['props']['review_assignment'];
    }

    /** @return array{0: Customer, 1: User, 2: EnterpriseWikiPage} */
    private function draftPage(): array
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'kandidat-'.Str::lower(Str::random(8)),
            'title' => 'Kandidatside',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
            'owner_user_id' => $owner->id,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Kandidatside',
            'generated_by_model' => 'gpt-5',
        ]);

        return [$customer, $owner, $page->fresh()];
    }

    private function user(
        Customer $customer,
        string $bidRole,
        bool $isQa = false,
        bool $isWikiApprover = false,
    ): User {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(9)).'@kandidat.test',
            'password' => bcrypt('secret'),
            'role' => $bidRole === User::BID_ROLE_SYSTEM_OWNER ? User::ROLE_CUSTOMER_ADMIN : User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ]);
    }

    private function customer(string $name = 'Kandidat AS'): Customer
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
