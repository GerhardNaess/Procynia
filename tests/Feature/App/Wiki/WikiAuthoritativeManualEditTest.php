<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\CreatesWikiManualEditFixture;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * What a manual edit does to an already-published Wiki article.
 *
 * The rule: saving is never approving. Every editor — System Owner and Wiki approver included —
 * produces a working version, the previously published one keeps serving, and the page goes back to
 * draft so it can be sent for review. Somebody other than the editor then publishes it through the
 * ordinary submit/approve flow; the four-eyes rule has no exception for whoever wrote the change.
 *
 * Shares CreatesWikiManualEditFixture with WikiWorkingVersionEditControllerTest: both exercise the
 * same endpoint, and a fixture that drifted between them would let one pass on a shape the other
 * can no longer produce.
 */
class WikiAuthoritativeManualEditTest extends TestCase
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

    // ── Nobody publishes by saving ──────────────────────────────────────────

    public function test_a_wiki_approver_editing_a_published_article_does_not_publish_it(): void
    {
        $fixture = $this->publishedFixture('Godkjenner Redigerer AS');
        $approver = $this->pageOwnerWith($fixture, isWikiApprover: true);

        $this->assertEditLeavesThePublishedVersionServing($approver, $fixture);
    }

    public function test_a_system_owner_editing_a_published_article_does_not_publish_it(): void
    {
        $fixture = $this->publishedFixture('Systemeier Redigerer AS');
        // The fixture's own actor is a System Owner; owning the page is what lets them edit it.
        $systemOwner = $fixture['actor'];
        $fixture['page']->forceFill(['owner_user_id' => $systemOwner->id])->save();

        $this->assertEditLeavesThePublishedVersionServing($systemOwner, $fixture);
    }

    /**
     * The whole route, end to end: the System Owner's edit goes to somebody else, the System Owner
     * cannot sign it off themselves, and the person it was sent to publishes it.
     */
    public function test_the_edit_is_published_by_somebody_other_than_the_editor(): void
    {
        $fixture = $this->publishedFixture('Fire Øyne AS');
        $systemOwner = $fixture['actor'];
        $fixture['page']->forceFill(['owner_user_id' => $systemOwner->id])->save();
        $reviewer = $this->userWith($fixture, isWikiApprover: true);

        $this->edit($systemOwner, $fixture)->assertSessionHas('success');
        $page = $fixture['page']->fresh();
        $edited = $this->currentVersion($page);

        $this->actingAs($systemOwner)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertStatus(422);

        $this->actingAs($systemOwner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->actingAs($systemOwner)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertForbidden();
        $this->assertSame((int) $fixture['version']->id, (int) $page->fresh()->published_version_id);

        $this->actingAs($reviewer)->patch(route('app.wiki.approve', ['slug' => $page->slug]))->assertRedirect();

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $edited->id, (int) $page->published_version_id);
        $this->assertSame((int) $reviewer->id, (int) $page->reviewed_by_user_id);
    }

    /** The previous version is history, not something publication overwrites. */
    public function test_the_superseded_version_stays_in_the_history(): void
    {
        $fixture = $this->publishedFixture('Historikk AS');
        $approver = $this->pageOwnerWith($fixture, isWikiApprover: true);
        $v1Id = (int) $fixture['version']->id;

        $this->edit($approver, $fixture);

        $v1 = EnterpriseWikiPageVersion::query()->find($v1Id);

        $this->assertNotNull($v1, 'the previously published version must still exist');
        $this->assertFalse((bool) $v1->is_current);
        $this->assertSame(2, EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $fixture['page']->id)
            ->count());
    }

    // ── Everyone else: a working version that still needs approving ─────────

    public function test_an_ordinary_editor_leaves_the_published_version_serving(): void
    {
        $fixture = $this->publishedFixture('Vanlig Redaktør AS');
        $owner = $this->pageOwnerWith($fixture);
        $publishedId = (int) $fixture['version']->id;

        $this->edit($owner, $fixture)->assertSessionHas('success');

        $page = $fixture['page']->fresh();
        $new = $this->currentVersion($page);

        $this->assertNotSame((int) $new->id, $publishedId, 'a new working version exists');
        $this->assertSame($publishedId, (int) $page->published_version_id, 'readers still get the approved text');
    }

    /**
     * The gap this change also closes: submit() only accepts a draft page, so an approved page left
     * as-is could be edited but never sent for review — the working version had no route forward.
     */
    public function test_the_new_working_version_can_actually_be_sent_for_review(): void
    {
        $fixture = $this->publishedFixture('Til Gjennomgang AS');
        $owner = $this->pageOwnerWith($fixture);
        $reviewer = $this->userWith($fixture, isWikiApprover: true);

        $this->edit($owner, $fixture);

        $page = $fixture['page']->fresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);

        $this->actingAs($owner)
            ->patch(route('app.wiki.submit', ['slug' => $page->slug]), ['reviewer_user_id' => $reviewer->id])
            ->assertRedirect(route('app.wiki.show', $page->slug));

        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->fresh()->status);
    }

    // ── What does not confer authority ──────────────────────────────────────

    /** QA is about claims. It is not a mandate over the finished article. */
    public function test_qa_alone_does_not_publish(): void
    {
        $fixture = $this->publishedFixture('QA Uten Myndighet AS');
        $qa = $this->pageOwnerWith($fixture, isQa: true);
        $publishedId = (int) $fixture['version']->id;

        $this->assertFalse($qa->fresh()->canApproveWikiPages(), 'the premise: QA does not approve pages');

        $this->edit($qa, $fixture);

        $this->assertSame($publishedId, (int) $fixture['page']->fresh()->published_version_id);
    }

    /** Document ownership answers for one source document, not for the whole article. */
    public function test_a_document_owner_alone_does_not_publish(): void
    {
        $fixture = $this->publishedFixture('Dokumenteier AS');
        $owner = $this->pageOwnerWith($fixture);
        $fixture['document']->forceFill(['owner_user_id' => $owner->id])->save();
        $publishedId = (int) $fixture['version']->id;

        $this->assertFalse($owner->fresh()->canApproveWikiPages());

        $this->edit($owner, $fixture);

        $this->assertSame($publishedId, (int) $fixture['page']->fresh()->published_version_id);
    }

    // ── Boundaries ──────────────────────────────────────────────────────────

    /** A page that was never published stays a draft; manual editing is no way to a first publication. */
    public function test_a_page_that_was_never_published_is_not_fast_tracked(): void
    {
        $fixture = $this->createManualEditFixture('Aldri Publisert AS');
        $approver = $this->pageOwnerWith($fixture, isWikiApprover: true);

        $this->assertNull($fixture['page']->fresh()->published_version_id, 'the premise');

        $this->edit($approver, $fixture);

        $page = $fixture['page']->fresh();
        $this->assertNull($page->published_version_id, 'first publication still goes through review');
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
    }

    /**
     * No writer outside review moves the published pointer. Asserted structurally because the
     * settlement service is the one place every non-review writer goes through, and a direct
     * publication would have to be written there.
     */
    public function test_the_settlement_service_never_publishes(): void
    {
        $settlement = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiPublicationSettlementService.php'));

        $this->assertStringNotContainsString("'published_version_id' =>", $settlement);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function publishedFixture(string $customerName): array
    {
        $fixture = $this->createManualEditFixture($customerName);

        // The state approve() leaves behind: the current version is the published one.
        $fixture['page']->forceFill([
            'published_version_id' => $fixture['version']->id,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
        ])->save();

        return $fixture;
    }

    /** @param array<string, mixed> $fixture */
    private function assertEditLeavesThePublishedVersionServing(User $editor, array $fixture): void
    {
        $publishedId = (int) $fixture['version']->id;

        $this->edit($editor, $fixture)->assertSessionHas('success', 'Endringene er lagret i en ny arbeidsversjon.');

        $page = $fixture['page']->fresh();
        $new = $this->currentVersion($page);

        $this->assertSame(2, (int) $new->version_number, 'the edit is a new working version');
        $this->assertSame($publishedId, (int) $page->published_version_id, 'readers keep the approved text');
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status, 'so it can be sent for review');
    }

    /** @param array<string, mixed> $fixture */
    private function edit(User $actor, array $fixture): TestResponse
    {
        $this->expectNoAiCalls();

        return $this->actingAs($actor)->patch(
            route('app.wiki.working-version.update', ['slug' => $fixture['page']->slug]),
            [
                'expected_page_version_id' => $fixture['version']->id,
                'blocks' => [['block_key' => 'block-0001', 'markdown' => 'Teksten slik den skal stå nå.']],
            ],
        );
    }

    /**
     * A page owner with the given supplemental capabilities. Owning the page is what makes editing
     * possible at all (canSubmitEnterpriseWikiPage), so every actor here has to hold it.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function pageOwnerWith(array $fixture, bool $isWikiApprover = false, bool $isQa = false): User
    {
        $user = $this->userWith($fixture, $isWikiApprover, $isQa);
        $fixture['page']->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
    }

    /** @param array<string, mixed> $fixture */
    private function userWith(array $fixture, bool $isWikiApprover = false, bool $isQa = false): User
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

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    /** Manual editing reaches no AI; asserting it here keeps that true for the published path too. */
    private function expectNoAiCalls(): void
    {
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldNotReceive('extractClaimsForManualMixedBlock');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
    }
}
