<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
use App\Exceptions\HumanDecisionRefused;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPageReviewService;
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * The page owner takes a page back from review — decision B5, docs/external-api-phase-0-plan.md §4.9.
 *
 * What has to hold: the withdrawal is recorded with actor and time, the handover it ends is gone
 * (nobody has anything left to decide), the page can be edited and sent again, the published
 * version never moves, and only the submitting side may do it.
 */
class EnterpriseWikiPageWithdrawalTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use CreatesWikiManualEditFixture;
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_the_page_owner_withdraws_a_page_and_can_edit_and_send_it_again(): void
    {
        ['page' => $page, 'version' => $submitted, 'owner' => $owner, 'reviewer' => $reviewer, 'published' => $published]
            = $this->pageInReview('Trekk Tilbake AS');

        $this->actingAs($owner)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('review_assignment.can_withdraw', true));

        $this->actingAs($owner)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))
            ->assertRedirect(route('app.wiki.show', $page->slug))
            ->assertSessionHas('success');

        $page->refresh();
        $submitted->refresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame((int) $published->id, (int) $page->published_version_id, 'the published version never moves');
        $this->assertNull($submitted->reviewer_user_id, 'the handover is gone');
        $this->assertNull($submitted->submitted_by_user_id);
        $this->assertNull($submitted->submitted_at);

        // Recorded: who, when, and who it had been handed to.
        $event = EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $page->id)->sole();
        $this->assertSame(EnterpriseWikiPageReviewEvent::EVENT_TYPE_WITHDRAWN, $event->event_type);
        $this->assertSame(EnterpriseWikiPageReviewEvent::ACTOR_ROLE_PAGE_OWNER, $event->actor_role);
        $this->assertSame((int) $owner->id, $event->actor_user_id);
        $this->assertSame((int) $reviewer->id, $event->assigned_reviewer_user_id);
        $this->assertSame((int) $submitted->id, $event->enterprise_wiki_page_version_id);
        $this->assertNotNull($event->created_at);

        // The reviewer is told, and has nothing left to decide.
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $reviewer->id)
            ->where('event_type', EnterpriseWikiReviewNotificationService::EVENT_REVIEW_WITHDRAWN)
            ->exists());
        $this->actingAs($reviewer)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertStatus(422);
        $this->actingAs($reviewer)
            ->patch(route('app.wiki.reject', ['slug' => $page->slug]), ['reason' => 'For sent: siden er trukket tilbake.'])
            ->assertStatus(422);
        $this->assertSame((int) $published->id, (int) $page->fresh()->published_version_id);

        // A withdrawal is not an objection: the "sent back" history does not show it.
        $this->actingAs($owner)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia
                ->where('review_assignment.changes_requested.history', [])
                ->where('review_assignment.can_withdraw', false)
                ->where('working_version_edit.can_edit', true));

        // The owner edits and sends it again; the reviewer is asked afresh.
        $this->expectNoAiCalls();
        $this->actingAs($owner)->patch(route('app.wiki.working-version.update', ['slug' => $page->slug]), [
            'expected_page_version_id' => $submitted->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Rettet etter tilbaketrekking.']],
        ])->assertSessionHas('success');

        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertSame((int) $reviewer->id, (int) $page->currentVersion()->value('reviewer_user_id'));
        $this->assertSame((int) $published->id, (int) $page->published_version_id);
    }

    public function test_only_the_submitting_side_may_withdraw(): void
    {
        ['page' => $page, 'customer' => $customer, 'reviewer' => $reviewer, 'version' => $submitted] = $this->pageInReview('Bare Innsender AS');
        $otherApprover = $this->wikiUser($customer, isWikiApprover: true);

        $this->actingAs($reviewer)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('review_assignment.can_withdraw', false));

        // The reviewer has «Send tilbake»; withdrawing is not theirs.
        $this->actingAs($reviewer)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))->assertForbidden();
        $this->actingAs($otherApprover)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))->assertForbidden();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
        $this->assertSame((int) $reviewer->id, (int) $submitted->fresh()->reviewer_user_id);
        $this->assertSame(0, EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    public function test_the_page_owner_needs_wiki_edit(): void
    {
        ['page' => $page, 'customer' => $customer] = $this->pageInReview('Uten Redigering AS');
        $ownerWithoutEdit = $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
        ]), [CustomerPermissionCatalog::WIKI_VIEW]);
        $page->forceFill(['owner_user_id' => $ownerWithoutEdit->id])->save();

        $this->actingAs($ownerWithoutEdit)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))->assertForbidden();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    public function test_a_system_owner_may_withdraw_like_they_may_submit(): void
    {
        ['page' => $page, 'customer' => $customer] = $this->pageInReview('Systemeier Trekker AS');
        $systemOwner = $this->wikiUser($customer, bidRole: User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($systemOwner)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_a_page_that_is_not_in_review_cannot_be_withdrawn(): void
    {
        ['page' => $page, 'owner' => $owner] = $this->pageInReview('Ikke Til Gjennomgang AS');
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        $this->actingAs($owner)->patch(route('app.wiki.withdraw', ['slug' => $page->slug]))->assertStatus(409);
        $this->assertSame(0, EnterpriseWikiPageReviewEvent::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    public function test_the_service_refuses_when_the_review_ended_before_the_lock(): void
    {
        ['page' => $page, 'owner' => $owner] = $this->pageInReview('Kappløp AS');
        $stale = $page->fresh();

        // Somebody else decided in between: the instance the caller holds still says pending_review.
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_REJECTED])->save();

        $this->expectException(EnterpriseWikiReviewConflict::class);
        app(EnterpriseWikiPageReviewService::class)->withdraw($owner, $stale);
    }

    public function test_an_integration_never_withdraws(): void
    {
        ['page' => $page, 'customer' => $customer] = $this->pageInReview('Integrasjon AS');
        $integration = $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_INTEGRATION,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]));
        $page->forceFill(['owner_user_id' => $integration->id])->save();
        $service = app(EnterpriseWikiPageReviewService::class);

        $this->assertFalse($service->canWithdraw($integration, $page->fresh()));

        try {
            $service->withdraw($integration, $page->fresh());
            $this->fail('an integration must not withdraw a page from review');
        } catch (HumanDecisionRefused) {
            $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
        }
    }

    /**
     * A published page whose newer version was handed to a reviewer: the state submit() leaves.
     *
     * @return array<string, mixed>
     */
    private function pageInReview(string $customerName): array
    {
        $fixture = $this->createManualEditFixture($customerName);
        $customer = $fixture['customer'];
        $page = $fixture['page'];
        $published = $fixture['version'];

        $owner = $this->wikiUser($customer);
        $reviewer = $this->wikiUser($customer, isWikiApprover: true);

        // One current version per page (ewpv_page_single_current_unique): demote first.
        $published->forceFill(['is_current' => false])->save();

        $submitted = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'is_staged' => false,
            'content_markdown' => $published->content_markdown,
            'content_blocks_json' => $published->content_blocks_json,
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $reviewer->id,
        ]);

        $page->forceFill([
            'owner_user_id' => $owner->id,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'published_version_id' => $published->id,
        ])->save();

        return [
            'customer' => $customer,
            'page' => $page->fresh(),
            'version' => $submitted,
            'published' => $published,
            'owner' => $owner,
            'reviewer' => $reviewer,
        ];
    }

    private function wikiUser($customer, bool $isWikiApprover = false, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]));
    }

    private function expectNoAiCalls(): void
    {
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
    }
}
