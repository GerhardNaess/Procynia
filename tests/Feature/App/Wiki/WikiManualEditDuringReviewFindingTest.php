<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Characterisation of a known gap — docs/external-api-phase-0-plan.md, finding F1a.
 *
 * The review assignment (submitted_by, submitted_at, reviewer) lives on the submitted VERSION.
 * EnterpriseWikiPageVersionWriter refuses automated writes to a page in pending_review for exactly
 * that reason, and lets a person's own edit through (allowDuringReview). Nothing then deals with the
 * consequence: the page owner can still press «Rediger» on a page they sent for review, and the
 * edit becomes the current version with no assignment.
 *
 * What that does, pinned below:
 * - nothing unreviewed is published, and the assigned reviewer can no longer publish (409);
 * - the version under review is now unassigned, so ANY Wiki approver — not only the person it was
 *   handed to — may send it back (WikiController::mayActOnCurrentVersion() falls through to true);
 * - the only way forward is send back, then submit again.
 *
 * Publication integrity holds; the four-eyes handover does not. When the rule is fixed (refuse the
 * edit, or end the review explicitly) this test fails and should be rewritten to that rule.
 */
class WikiManualEditDuringReviewFindingTest extends TestCase
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

    public function test_editing_a_page_under_review_detaches_it_from_its_reviewer(): void
    {
        $fixture = $this->createManualEditFixture('Strandet Gjennomgang AS');
        $owner = $this->wikiUser($fixture);
        $reviewer = $this->wikiUser($fixture, isWikiApprover: true);
        $page = $fixture['page'];
        $submitted = $fixture['version'];

        // The state submit() leaves behind.
        $page->forceFill([
            'owner_user_id' => $owner->id,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
        ])->save();
        $submitted->forceFill([
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $reviewer->id,
        ])->save();

        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        // «Rediger» is offered and accepted while the review is open.
        $this->actingAs($owner)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('working_version_edit.can_edit', true));

        $this->actingAs($owner)->patch(route('app.wiki.working-version.update', ['slug' => $page->slug]), [
            'expected_page_version_id' => $submitted->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Endret etter innsending.']],
        ])->assertSessionHas('success');

        $page->refresh();
        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->sole();

        $this->assertNotSame((int) $submitted->id, (int) $current->id);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status, 'still in review');
        $this->assertNull($current->reviewer_user_id, 'the review assignment stayed on the old version');
        $this->assertNull($page->published_version_id, 'nothing unreviewed was published');

        // The assigned reviewer cannot publish what they were handed: the version changed.
        $this->actingAs($reviewer)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertStatus(409);
        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertStatus(422);

        // And somebody it was never handed to can now decide it.
        $bystander = $this->wikiUser($fixture, isWikiApprover: true);
        $this->actingAs($bystander)
            ->patch(route('app.wiki.reject', ['slug' => $page->slug]), ['reason' => 'Sendes tilbake av en som ikke var tildelt.'])
            ->assertRedirect();

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_REJECTED, $page->status);
        $this->assertSame((int) $bystander->id, (int) $page->reviewed_by_user_id, 'F1a: decided by an unassigned approver');
        $this->assertNull($page->published_version_id);
    }

    /** @param array<string, mixed> $fixture */
    private function wikiUser(array $fixture, bool $isWikiApprover = false): User
    {
        return $this->grantWikiPermissions($fixture['customer'], User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
        ]));
    }
}
