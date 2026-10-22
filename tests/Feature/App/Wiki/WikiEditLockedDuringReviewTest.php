<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
use App\Exceptions\HumanDecisionRefused;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Content out for review is not edited directly — decision B5, docs/external-api-phase-0-plan.md §4.9.
 *
 * Finding F1a was that the page owner could press «Rediger» on a page they had sent for review: the
 * edit became the current version with no assignment, the assigned reviewer could no longer publish,
 * and any approver could decide it. Now the edit is refused on every path — the article editor and
 * claim repair, in the controller and again in the service under the page lock — the review keeps
 * its version and its reviewer, and the way to change something is «Trekk tilbake».
 */
class WikiEditLockedDuringReviewTest extends TestCase
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

    public function test_the_article_editor_is_locked_while_the_page_is_in_review(): void
    {
        ['page' => $page, 'version' => $submitted, 'owner' => $owner, 'reviewer' => $reviewer] = $this->pageInReview('Låst Under Gjennomgang AS');
        $this->expectNoAiCalls();

        // Offered disabled, with its reason, rather than hidden.
        $this->actingAs($owner)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia
                ->where('working_version_edit.can_edit', false)
                ->where('working_version_edit.unavailable_reason', 'in_review')
                ->where('review_assignment.can_withdraw', true));

        $this->actingAs($owner)->patch(route('app.wiki.working-version.update', ['slug' => $page->slug]), [
            'expected_page_version_id' => $submitted->id,
            'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Endret etter innsending.']],
        ])->assertSessionHas('error', __('procynia.wiki.edit_locked_in_review'));

        $this->assertTheReviewIsIntact($page, $submitted, $reviewer);
    }

    public function test_claim_repair_is_locked_while_the_page_is_in_review(): void
    {
        ['page' => $page, 'version' => $submitted, 'reviewer' => $reviewer, 'fixture' => $fixture] = $this->pageInReview('Reparasjon Låst AS');
        $this->expectNoAiCalls();
        $qa = $this->wikiUser($fixture, isQa: true);

        $this->actingAs($qa)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('manual_block_edit.locked_in_review', true));

        $this->actingAs($qa)->patch(route('app.wiki.claims.manual-block-edit.update', [
            'slug' => $page->slug,
            'claim' => $fixture['reviewClaim']->id,
        ]), [
            'run_id' => $fixture['run']->id,
            'expected_page_version_id' => $submitted->id,
            'blocks' => [['block_key' => 'block-0002', 'markdown' => 'Kunden bidrar til dokumentert kontroll.']],
        ])->assertSessionHas('error', __('procynia.wiki.edit_locked_in_review'));

        $this->assertTheReviewIsIntact($page, $submitted, $reviewer);
    }

    /** The controller's check is for the answer; the service refuses on its own, under the lock. */
    public function test_the_services_refuse_an_edit_in_review_on_their_own(): void
    {
        ['page' => $page, 'version' => $submitted, 'owner' => $owner, 'reviewer' => $reviewer, 'fixture' => $fixture] = $this->pageInReview('Tjenesten Nekter AS');
        $this->expectNoAiCalls();
        $service = app(EnterpriseWikiClaimContentRepairService::class);

        try {
            $service->applyWorkingVersionBlockEdits($page, $submitted, ['block-0001' => 'Endret.'], $owner);
            $this->fail('an edit of a page in review must be refused');
        } catch (EnterpriseWikiReviewConflict) {
        }

        try {
            $service->applyManualMixedBlockEdit(
                $fixture['run']->fresh(),
                $page->fresh(),
                $submitted->fresh(),
                $fixture['reviewClaim']->fresh(),
                'block-0002',
                'Kunden bidrar til dokumentert kontroll.',
                $fixture['actor']->fresh(),
            );
            $this->fail('a claim repair of a page in review must be refused');
        } catch (EnterpriseWikiReviewConflict) {
        }

        $this->assertTheReviewIsIntact($page, $submitted, $reviewer);
    }

    public function test_an_integration_never_edits_wiki_content(): void
    {
        $fixture = $this->createManualEditFixture('Integrasjon Redigerer AS');
        $integration = $this->grantWikiPermissions($fixture['customer'], User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_INTEGRATION,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]));
        $fixture['page']->forceFill(['owner_user_id' => $integration->id])->save();
        $this->expectNoAiCalls();

        $this->expectException(HumanDecisionRefused::class);
        app(EnterpriseWikiClaimContentRepairService::class)
            ->applyWorkingVersionBlockEdits($fixture['page']->fresh(), $fixture['version']->fresh(), ['block-0001' => 'Maskintekst.'], $integration);
    }

    /** Nothing written, the version under review is still current, and it still belongs to its reviewer. */
    private function assertTheReviewIsIntact(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $submitted, User $reviewer): void
    {
        $page->refresh();
        $submitted->refresh();

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
        $this->assertTrue((bool) $submitted->is_current);
        $this->assertSame((int) $reviewer->id, (int) $submitted->reviewer_user_id);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());

        $this->actingAs($reviewer)->get(route('app.wiki.show', ['slug' => $page->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('review_assignment.can_send_back', true));
    }

    /** @return array<string, mixed> */
    private function pageInReview(string $customerName): array
    {
        $fixture = $this->createManualEditFixture($customerName);
        $owner = $this->wikiUser($fixture);
        $reviewer = $this->wikiUser($fixture, isWikiApprover: true);

        // The state submit() leaves behind.
        $fixture['page']->forceFill([
            'owner_user_id' => $owner->id,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
        ])->save();
        $fixture['version']->forceFill([
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $reviewer->id,
        ])->save();

        return [
            'fixture' => $fixture,
            'page' => $fixture['page']->fresh(),
            'version' => $fixture['version']->fresh(),
            'owner' => $owner,
            'reviewer' => $reviewer,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function wikiUser(array $fixture, bool $isWikiApprover = false, bool $isQa = false): User
    {
        return $this->grantWikiPermissions($fixture['customer'], User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
            'is_qa' => $isQa,
        ]));
    }

    private function expectNoAiCalls(): void
    {
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
    }
}
