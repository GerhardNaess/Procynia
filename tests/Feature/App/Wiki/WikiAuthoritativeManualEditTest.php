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
use Tests\TestCase;

/**
 * What a manual edit does to an already-published Wiki article.
 *
 * The rule: authority over the whole article is canApproveWikiPages(), the same check approve()
 * uses. Someone holding it has nobody above them to hand the change to — and could not submit it to
 * themselves anyway, since submit() refuses a reviewer who is the submitter — so their save is the
 * approval, and readers keep getting current text. Everyone else produces a working version, the
 * previously published one keeps serving, and a Wiki approver still has to approve it.
 *
 * Three boundaries are deliberate and each has a test: a page that was never published is not
 * fast-tracked to a first publication however senior the editor, QA and document ownership answer
 * different questions and confer nothing here, and automated paths never reach this code at all.
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // ── Authoritative: saving is approving ──────────────────────────────────

    public function test_a_wiki_approver_editing_a_published_article_publishes_it_directly(): void
    {
        $fixture = $this->publishedFixture('Autoritativ Godkjenner AS');
        $approver = $this->pageOwnerWith($fixture, isWikiApprover: true);

        $this->edit($approver, $fixture)->assertSessionHas('success');

        $page = $fixture['page']->fresh();
        $new = $this->currentVersion($page);

        $this->assertSame(2, (int) $new->version_number);
        $this->assertSame((int) $new->id, (int) $page->published_version_id, 'the edit is what readers now get');
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame((int) $approver->id, (int) $page->reviewed_by_user_id);
        $this->assertNotNull($page->reviewed_at);
    }

    public function test_a_system_owner_editing_a_published_article_publishes_it_directly(): void
    {
        $fixture = $this->publishedFixture('Autoritativ Systemeier AS');
        // The fixture's own actor is a System Owner; owning the page is what lets them edit it.
        $systemOwner = $fixture['actor'];
        $fixture['page']->forceFill(['owner_user_id' => $systemOwner->id])->save();

        $this->edit($systemOwner, $fixture)->assertSessionHas('success');

        $page = $fixture['page']->fresh();

        $this->assertSame((int) $this->currentVersion($page)->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
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

    /**
     * "Saving is approval" only makes sense for an article that has already been through approval
     * once. A first publication is a different decision, and manual editing must not become a way
     * around it.
     */
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
     * Publishing by saving is the manual path's privilege alone.
     *
     * Asserted structurally because the alternative — running an ingest here — would test the
     * pipeline rather than this rule. afterManualEdit() is the only method that can move
     * published_version_id without a review, so what matters is that exactly one caller reaches it
     * and that caller is the manual working-version edit.
     */
    public function test_only_the_manual_edit_path_can_publish_directly(): void
    {
        $settlement = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiPublicationSettlementService.php'));

        $this->assertStringContainsString(
            "'published_version_id' => \$newVersion->id,",
            $settlement,
            'afterManualEdit() is where publication moves without a review',
        );

        $callers = [];

        foreach (glob(app_path('Services/EnterpriseWiki/*.php')) as $path) {
            if (str_contains(file_get_contents($path), 'afterManualEdit(')
                && ! str_ends_with($path, 'EnterpriseWikiPublicationSettlementService.php')) {
                $callers[] = basename($path);
            }
        }

        $this->assertSame(['EnterpriseWikiClaimContentRepairService.php'], $callers);

        $service = file_get_contents(app_path('Services/EnterpriseWiki/EnterpriseWikiClaimContentRepairService.php'));
        $callSite = substr($service, 0, strpos($service, '$this->publicationSettlement->afterManualEdit('));

        $this->assertStringContainsString(
            'public function applyWorkingVersionBlockEdits(',
            $callSite,
            'and it sits inside the manual working-version edit',
        );
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
        return User::factory()->create([
            'customer_id' => $fixture['customer']->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
            'is_wiki_approver' => $isWikiApprover,
            'is_qa' => $isQa,
        ]);
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
