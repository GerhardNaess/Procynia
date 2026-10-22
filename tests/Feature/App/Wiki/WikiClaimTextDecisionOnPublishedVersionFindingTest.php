<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
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
 * Characterisation of a known gap — docs/external-api-phase-0-plan.md, finding F1d.
 *
 * Text-changing claim decisions («Rediger og godkjenn», «Fjern teksten») write into the claim's page
 * version IN PLACE (EnterpriseWikiPageContentBlockService). On a published page whose current
 * version IS the published one, that rewrites published content: what readers and grounding rely on
 * changes with no new version, no draft and no review. Quality assurance may continue after
 * publication, and best-practice suggestions do not block it, so the state is ordinary.
 *
 * This test pins today's behaviour. It fails the day the rule changes, and should then be rewritten
 * to it.
 */
class WikiClaimTextDecisionOnPublishedVersionFindingTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    private const SUGGESTION = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_editing_a_best_practice_suggestion_rewrites_the_published_version_in_place(): void
    {
        [$page, $published, $claim, $qa] = $this->publishedPageWithPendingSuggestion();

        $this->actingAs($qa)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/approve", [
            'approved_text' => 'Virksomheten skal gjennomføre tilgangsgjennomgang hver måned.',
        ])->assertRedirect();

        $page->refresh();
        $this->assertSame((int) $published->id, (int) $page->published_version_id, 'still the same published version');
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status, 'and still approved — nobody reviewed the change');
        $this->assertStringContainsString('hver måned', $published->fresh()->content_markdown, 'F1d: published content changed in place');
    }

    public function test_removing_a_best_practice_suggestion_removes_it_from_the_published_version_in_place(): void
    {
        [$page, $published, $claim, $qa] = $this->publishedPageWithPendingSuggestion();

        $this->actingAs($qa)->patch("/app/wiki/{$page->slug}/claims/{$claim->id}/reject")->assertRedirect();

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->fresh()->status);
        $this->assertStringNotContainsString(self::SUGGESTION, $published->fresh()->content_markdown, 'F1d: published content changed in place');
    }

    /** @return array{0: EnterpriseWikiPage, 1: EnterpriseWikiPageVersion, 2: EnterpriseWikiClaim, 3: User} */
    private function publishedPageWithPendingSuggestion(): array
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

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
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
            'content_markdown' => "# Tilgangsstyring\n\n".self::SUGGESTION,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Tilgangsstyring'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => self::SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        // The state approve() leaves behind: the current version is the published one.
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $published->id])->save();

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $published->id,
            'claim_text' => self::SUGGESTION,
            'page_excerpt' => self::SUGGESTION,
            'content_block_key' => 'block-0002',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $qa = $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
            'is_qa' => true,
        ]));

        return [$page->fresh(), $published, $claim, $qa];
    }
}
