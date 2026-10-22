<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * Finding F1c (docs/external-api-phase-0-plan.md §2): while a Wiki page is in review, only the
 * reviewer its current version was handed to may take a claim decision that changes the page's
 * text — «Rediger og godkjenn» and «Fjern teksten». A quality assurer, the page owner, the submitter
 * and a System Owner keep every decision that leaves the text as it is.
 *
 * What the reviewer changes is written into the version under review (the handover stays intact,
 * and it is what they approve), recorded with who, when and from what to what, shown to the page
 * owner on the card, and never touches the version that is already published.
 */
class WikiClaimTextDecisionDuringReviewTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    private const SUGGESTION = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';

    private const EDITED = 'Virksomheten bør gjennomføre en dokumentert tilgangsgjennomgang minst årlig.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_only_the_assigned_reviewer_may_change_text_in_review(): void
    {
        $case = $this->pageInReview();
        $systemOwner = $this->user($case['customer'], User::BID_ROLE_SYSTEM_OWNER);

        // A quality assurer, the page owner who submitted it, and a System Owner: none was handed it.
        foreach ([$case['qa'], $case['owner'], $systemOwner] as $actor) {
            $this->actingAs($actor)->get(route('app.wiki.show', ['slug' => $case['page']->slug]))
                ->assertInertia(fn ($inertia) => $inertia->where('claim_text_locked_in_review', true));

            $this->actingAs($actor)
                ->patch($this->claimUrl($case, 'approve'), ['approved_text' => self::EDITED])
                ->assertForbidden();
            $this->actingAs($actor)->patch($this->claimUrl($case, 'reject'))->assertForbidden();
        }

        // Nothing was written: not the text, not the decision, not the audit trail.
        $this->assertStringContainsString(self::SUGGESTION, $case['submitted']->fresh()->content_markdown);
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $case['claim']->fresh()->approval_status);
        $this->assertSame(0, EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $case['claim']->id)->count());
    }

    public function test_a_quality_assurer_may_still_keep_the_text_as_it_is(): void
    {
        $case = $this->pageInReview();

        // «Behold teksten»: the field is sent back unchanged, which is not an edit.
        $this->actingAs($case['qa'])
            ->patch($this->claimUrl($case, 'approve'), ['approved_text' => self::SUGGESTION, 'comment' => 'Dekkende.'])
            ->assertRedirect();

        $this->assertTrue($case['claim']->fresh()->isApproved());
        $this->assertStringContainsString(self::SUGGESTION, $case['submitted']->fresh()->content_markdown);
        $this->assertSame((int) $case['reviewer']->id, (int) $case['submitted']->fresh()->reviewer_user_id);
    }

    public function test_the_reviewer_edits_the_version_under_review_traceably_and_publishes_it(): void
    {
        $case = $this->pageInReview();

        $this->actingAs($case['reviewer'])->get(route('app.wiki.show', ['slug' => $case['page']->slug]))
            ->assertInertia(fn ($inertia) => $inertia->where('claim_text_locked_in_review', false));

        $this->actingAs($case['reviewer'])
            ->patch($this->claimUrl($case, 'approve'), ['approved_text' => self::EDITED, 'comment' => 'Presisert.'])
            ->assertRedirect();

        // Into the version under review, which stays current and stays handed to the reviewer.
        $submitted = $case['submitted']->fresh();
        $this->assertTrue((bool) $submitted->is_current);
        $this->assertSame((int) $case['reviewer']->id, (int) $submitted->reviewer_user_id);
        $this->assertStringContainsString(self::EDITED, $submitted->content_markdown);
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $case['page']->fresh()->status);

        // The published version is untouched.
        $this->assertSame((int) $case['published']->id, (int) $case['page']->fresh()->published_version_id);
        $this->assertSame($case['publishedMarkdown'], $case['published']->fresh()->content_markdown);

        // Traceable: who, when, and from what to what.
        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $case['claim']->id)->sole();
        $this->assertSame((int) $case['reviewer']->id, (int) $decision->decided_by_user_id);
        $this->assertNotNull($decision->created_at);
        $this->assertSame(self::SUGGESTION, $decision->previous_state['claim_text'] ?? null);
        $this->assertSame(self::EDITED, $decision->new_state['claim_text'] ?? null);

        // Visible to the page owner on the card.
        $this->actingAs($case['owner'])->get(route('app.wiki.show', ['slug' => $case['page']->slug]))
            ->assertInertia(fn ($inertia) => $inertia
                ->where('claims.0.approved_by_name', $case['reviewer']->name)
                ->where('claims.0.review_metadata.edited_before_approval', true));

        // And it is what the reviewer publishes.
        $this->actingAs($case['reviewer'])->patch("/app/wiki/{$case['page']->slug}/approve")->assertRedirect();
        $this->assertSame((int) $submitted->id, (int) $case['page']->fresh()->published_version_id);
    }

    public function test_the_reviewer_removes_text_traceably(): void
    {
        $case = $this->pageInReview();

        $this->actingAs($case['reviewer'])->patch($this->claimUrl($case, 'reject'))->assertRedirect();

        $this->assertStringNotContainsString(self::SUGGESTION, $case['submitted']->fresh()->content_markdown);
        $this->assertSame($case['publishedMarkdown'], $case['published']->fresh()->content_markdown);

        $decision = EnterpriseWikiClaimDecision::query()->where('enterprise_wiki_claim_id', $case['claim']->id)->sole();
        $this->assertSame((int) $case['reviewer']->id, (int) $decision->decided_by_user_id);
        $this->assertSame(self::SUGGESTION, $decision->previous_state['claim_text'] ?? null);
        $this->assertTrue($decision->new_state['text_removed'] ?? false);
        $this->assertTrue($case['claim']->fresh()->review_metadata['text_removed_by_decision'] ?? false);
    }

    public function test_outside_review_the_rule_adds_nothing(): void
    {
        $case = $this->pageInReview();
        $case['page']->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

        $this->actingAs($case['qa'])
            ->patch($this->claimUrl($case, 'approve'), ['approved_text' => self::EDITED])
            ->assertRedirect();

        $this->assertStringContainsString(self::EDITED, $case['submitted']->fresh()->content_markdown);
    }

    /** @param array<string, mixed> $case */
    private function claimUrl(array $case, string $action): string
    {
        return "/app/wiki/{$case['page']->slug}/claims/{$case['claim']->id}/{$action}";
    }

    /**
     * A published page whose newer version, carrying a best-practice suggestion, was handed to a
     * reviewer. Everyone in it may handle claims (QA), so the only thing that can refuse a text
     * change is the review rule.
     *
     * @return array<string, mixed>
     */
    private function pageInReview(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Påstand Under Gjennomgang AS',
            'slug' => 'pastand-gjennomgang-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);
        $reviewer = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true, isWikiApprover: true);
        $qa = $this->user($customer, User::BID_ROLE_CONTRIBUTOR, isQa: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'tilgang-'.Str::lower(Str::random(6)),
            'title' => 'Tilgangsstyring',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $publishedMarkdown = "# Tilgangsstyring\n\nTilgang gis etter behov.";
        $published = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => $publishedMarkdown,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Tilgangsstyring'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'Tilgang gis etter behov.'],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        $submitted = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Tilgangsstyring\n\nTilgang gis etter behov.\n\n".self::SUGGESTION,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Tilgangsstyring'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'Tilgang gis etter behov.'],
                ['block_key' => 'block-0003', 'position' => 2, 'markdown' => self::SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
            ],
            'generated_by_model' => 'gpt-5',
            'submitted_by_user_id' => $owner->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $reviewer->id,
        ]);

        $page->forceFill(['published_version_id' => $published->id])->save();

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $submitted->id,
            'claim_text' => self::SUGGESTION,
            'page_excerpt' => self::SUGGESTION,
            'content_block_key' => 'block-0003',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        return compact('customer', 'owner', 'reviewer', 'qa', 'page', 'published', 'publishedMarkdown', 'submitted', 'claim');
    }

    private function user(Customer $customer, string $bidRole, bool $isQa = false, bool $isWikiApprover = false): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
            'is_qa' => $isQa,
            'is_wiki_approver' => $isWikiApprover,
        ]));
    }
}
