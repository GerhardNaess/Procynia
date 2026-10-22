<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * A text-changing claim decision on a published page never changes the published version —
 * finding F1d (docs/external-api-phase-0-plan.md §2).
 *
 * «Rediger og godkjenn» and «Fjern teksten» edit their version in place. When that version is the
 * published one, the decision first gets a new working version (an exact copy written through
 * EnterpriseWikiPageVersionWriter, with every claim copied), runs on the claim's copy there, and the
 * page returns to draft. The published version, its claims and what grounding reads stay exactly
 * as they were until a reviewer publishes the new version through the ordinary review.
 */
class WikiClaimTextDecisionOnPublishedVersionTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    private const SUGGESTION = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';

    private const SECOND_SUGGESTION = 'Virksomheten bør loggføre alle administrative tilganger.';

    private const EDITED = 'Virksomheten skal gjennomføre tilgangsgjennomgang hver måned.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_editing_keeps_the_published_version_and_moves_the_change_into_a_new_draft_version(): void
    {
        $case = $this->publishedPage();
        $before = $this->snapshot($case['published']);

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), [
            'approved_text' => self::EDITED,
            'comment' => 'Strengere krav.',
        ])->assertRedirect()->assertSessionHas('success');

        // 1. The published version — its text, blocks and claims — is exactly as it was.
        $this->assertSame($before, $this->snapshot($case['published']));
        $this->assertSame(0, EnterpriseWikiClaimDecision::query()->whereIn('enterprise_wiki_claim_id', $before['claim_ids'])->count());

        // 2. A new working version carries the change, and the page is a draft.
        $page = $case['page']->fresh();
        $working = $this->currentVersion($page);
        $this->assertNotSame((int) $case['published']->id, (int) $working->id);
        $this->assertSame((int) $case['published']->version_number + 1, (int) $working->version_number);
        $this->assertStringContainsString(self::EDITED, $working->content_markdown);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame((int) $case['published']->id, (int) $page->published_version_id);
        $this->assertSame((int) $case['qa']->id, (int) $working->created_by_user_id);

        // Every claim and its source references went along; the decision is on the copy.
        $this->assertSame(count($before['claim_ids']), EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->count());
        $copy = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->where('content_block_key', 'block-0003')->sole();
        $this->assertTrue($copy->isApproved());
        $this->assertSame(self::EDITED, $copy->claim_text);
        $this->assertSame(1, EnterpriseWikiSourceReference::query()
            ->whereIn('enterprise_wiki_claim_id', EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->select('id'))
            ->count());

        // 3. Who, when, and from what to what.
        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $copy->id)->sole();
        $this->assertSame((int) $case['qa']->id, (int) $decision->decided_by_user_id);
        $this->assertNotNull($decision->created_at);
        $this->assertSame(self::SUGGESTION, $decision->previous_state['claim_text'] ?? null);
        $this->assertSame(self::EDITED, $decision->new_state['claim_text'] ?? null);
    }

    public function test_removing_keeps_the_published_version_and_removes_the_text_from_a_new_draft_version(): void
    {
        $case = $this->publishedPage();
        $before = $this->snapshot($case['published']);

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'reject'))->assertRedirect();

        $this->assertSame($before, $this->snapshot($case['published']));

        $working = $this->currentVersion($case['page']->fresh());
        $this->assertStringNotContainsString(self::SUGGESTION, $working->content_markdown);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $case['page']->fresh()->status);

        $copy = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->where('content_block_key', 'block-0003')->sole();
        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $copy->id)->sole();
        $this->assertTrue($decision->new_state['text_removed'] ?? false);
    }

    /** 6. AI answers and grounding keep reading the published version until a new one is published. */
    public function test_grounding_keeps_the_published_text_until_the_change_is_published(): void
    {
        $case = $this->publishedPage();

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['approved_text' => self::EDITED])->assertRedirect();

        $catalog = collect(app(RequirementWikiCatalogBuilder::class)->build((int) $case['customer']->id))->firstWhere('page_id', $case['page']->id);
        $this->assertNotNull($catalog, 'the page still answers from its published version');
        $this->assertStringContainsString(self::SUGGESTION, $catalog['content_markdown']);
        $this->assertStringNotContainsString(self::EDITED, $catalog['content_markdown']);

        // The claims grounding cites are the published version's (RequirementWikiResearchService).
        $this->assertSame([self::SUGGESTION, self::SECOND_SUGGESTION], $this->publishedClaimTexts($case['page']));
    }

    /** 4–5. The change goes through the ordinary review: submitted to someone else, and published by them. */
    public function test_the_change_is_published_only_through_a_new_review_round(): void
    {
        $case = $this->publishedPage();

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['approved_text' => self::EDITED])->assertRedirect();
        $working = $this->currentVersion($case['page']->fresh());

        // Nobody publishes it without a handover — not even an approver: the page is a draft.
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertStatus(422);

        $this->actingAs($case['owner'])
            ->patch("/app/wiki/{$case['page']->slug}/submit", ['reviewer_user_id' => $case['reviewer']->id])
            ->assertRedirect();
        $this->actingAs($case['owner'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertForbidden();
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();

        $page = $case['page']->fresh();
        $this->assertSame((int) $working->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame([self::EDITED, self::SECOND_SUGGESTION], $this->publishedClaimTexts($page));

        // The previous published version is history, untouched.
        $this->assertStringContainsString(self::SUGGESTION, $case['published']->fresh()->content_markdown);
    }

    /**
     * 8. Concurrent decisions: the first moves the page to a new working version; the second, made
     * from a view of the published version, finds its claim no longer current and is refused —
     * nothing lost, no second fork, no decision written against the published version.
     */
    public function test_a_decision_made_against_a_version_that_moved_on_is_refused(): void
    {
        $case = $this->publishedPage();

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['approved_text' => self::EDITED])->assertRedirect();
        $working = $this->currentVersion($case['page']->fresh());

        // The second person still has the published version's claim on screen.
        $this->actingAs($case['qa'])
            ->patch($this->url($case, $case['secondClaim'], 'reject'))
            ->assertStatus(409);

        $this->assertSame(2, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $case['page']->id)->count());
        $this->assertSame((int) $working->id, (int) $this->currentVersion($case['page']->fresh())->id);
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $case['secondClaim']->fresh()->approval_status);
        $this->assertStringContainsString(self::SECOND_SUGGESTION, $working->fresh()->content_markdown);
    }

    /** The fork re-checks under the page lock, so two forks from the same published version cannot both happen. */
    public function test_the_fork_refuses_a_version_that_is_no_longer_current_and_published(): void
    {
        $case = $this->publishedPage();
        $fork = app(EnterpriseWikiClaimContentRepairService::class);

        $fork->forkPublishedVersionForClaimDecision($case['page'], $case['claim'], $case['qa']);

        $this->expectException(EnterpriseWikiReviewConflict::class);
        $fork->forkPublishedVersionForClaimDecision($case['page']->fresh(), $case['claim']->fresh(), $case['qa']);
    }

    /** A refused decision takes its fork with it: one transaction. */
    public function test_a_decision_that_fails_after_the_fork_leaves_no_working_version_behind(): void
    {
        $case = $this->publishedPage();
        // A best-practice edit needs its block; without it the decision is refused (422) after the fork.
        $case['claim']->forceFill(['content_block_key' => 'block-missing'])->save();
        $before = $this->snapshot($case['published']);

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['approved_text' => self::EDITED])->assertStatus(422);

        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $case['page']->id)->count());
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
        $this->assertSame($before, $this->snapshot($case['published']));
    }

    /** Keeping the text as it stands changes nothing, so it stays on the published claim and forks nothing. */
    public function test_keeping_the_text_creates_no_working_version(): void
    {
        $case = $this->publishedPage();

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['approved_text' => self::SUGGESTION])->assertRedirect();

        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $case['page']->id)->count());
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $case['page']->fresh()->status);
        $this->assertTrue($case['claim']->fresh()->isApproved());
    }

    /** @return array<string, mixed> */
    private function snapshot(EnterpriseWikiPageVersion $version): array
    {
        $version = $version->fresh();
        $claims = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->orderBy('id')->get();

        return [
            'content_markdown' => $version->content_markdown,
            'content_blocks_json' => $version->content_blocks_json,
            'claim_ids' => $claims->pluck('id')->all(),
            'claims' => $claims->map(fn (EnterpriseWikiClaim $claim): array => [
                $claim->claim_text, $claim->page_excerpt, $claim->approval_status, $claim->approved_by_user_id, $claim->review_metadata,
            ])->all(),
        ];
    }

    /** @return list<string> */
    private function publishedClaimTexts(EnterpriseWikiPage $page): array
    {
        return EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $page->fresh()->published_version_id)
            ->orderBy('position_order')
            ->pluck('claim_text')
            ->all();
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->sole();
    }

    /** @param array<string, mixed> $case */
    private function url(array $case, EnterpriseWikiClaim $claim, string $action): string
    {
        return "/app/wiki/{$case['page']->slug}/claims/{$claim->id}/{$action}";
    }

    /**
     * A published page — the state approve() leaves — with two pending best-practice suggestions, a
     * quality assurer who may handle them, the page owner and an eligible reviewer.
     *
     * @return array<string, mixed>
     */
    private function publishedPage(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Publisert Påstand AS',
            'slug' => 'publisert-pastand-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer);
        $qa = $this->user($customer, isQa: true);
        $reviewer = $this->user($customer, isWikiApprover: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'publisert-'.Str::lower(Str::random(6)),
            'title' => 'Tilgangsstyring',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $published = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Tilgangsstyring\n\nTilgang gis etter behov.\n\n".self::SUGGESTION."\n\n".self::SECOND_SUGGESTION,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Tilgangsstyring'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'Tilgang gis etter behov.'],
                ['block_key' => 'block-0003', 'position' => 2, 'markdown' => self::SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
                ['block_key' => 'block-0004', 'position' => 3, 'markdown' => '## Logging'],
                ['block_key' => 'block-0005', 'position' => 4, 'markdown' => self::SECOND_SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $published->id])->save();

        $claim = $this->suggestion($page, $published, self::SUGGESTION, 'block-0003', 0);
        $secondClaim = $this->suggestion($page, $published, self::SECOND_SUGGESTION, 'block-0005', 1);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'tilgangspolicy.pdf',
            'excerpt' => 'Tilgang skal gjennomgås.',
            'source_hash' => str_pad('kilde', 64, '0'),
        ]);

        return compact('customer', 'owner', 'qa', 'reviewer', 'page', 'published', 'claim', 'secondClaim');
    }

    private function suggestion(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text, string $blockKey, int $position): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => $text,
            'content_block_key' => $blockKey,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => $position,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function user(Customer $customer, bool $isQa = false, bool $isWikiApprover = false): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ]));
    }
}
