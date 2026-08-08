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
use Tests\TestCase;

/**
 * One visible "Beste praksis" section is one decision unit.
 *
 * A section spans several content blocks — a heading block plus its paragraphs — but
 * WikiClaimController::cascadeBlockDecision() used to cascade only within a single
 * content_block_key, so approving what the reviewer saw as one section settled just one of its
 * blocks and left the rest pending as new open review cards. The decision now spans every block of
 * the section, resolved through EnterpriseWikiBestPracticeSectionService::mapBlocksToSections() —
 * the same grouping the Wiki page itself renders from, which derives its boundaries from block
 * order, each block's leading heading and content_origin rather than any stored section_key.
 */
class WikiBestPracticeSectionDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_approving_one_claim_approves_every_claim_in_the_same_visible_section(): void
    {
        [$owner, $page, $version, $claims] = $this->createSectionScenario();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claims['a']->id}/approve")
            ->assertRedirect();

        foreach (['a', 'b', 'c'] as $key) {
            $this->assertSame(
                EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
                $claims[$key]->fresh()->approval_status,
                "claim {$key} (same section) must be approved by the one decision",
            );
        }

        // Audit trail per claim survives: each decided claim records who and when.
        foreach (['a', 'b', 'c'] as $key) {
            $fresh = $claims[$key]->fresh();
            $this->assertSame($owner->id, (int) $fresh->approved_by_user_id);
            $this->assertNotNull($fresh->approved_at);
        }

        // Append-only decision log keeps one row per claim decision.
        $this->assertSame(3, \DB::table('enterprise_wiki_claim_decisions')
            ->whereIn('enterprise_wiki_claim_id', [$claims['a']->id, $claims['b']->id, $claims['c']->id])
            ->count());
    }

    public function test_rejecting_one_claim_rejects_the_whole_section_and_removes_its_text(): void
    {
        [$owner, $page, $version, $claims] = $this->createSectionScenario();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claims['b']->id}/reject")
            ->assertRedirect();

        foreach (['a', 'b', 'c'] as $key) {
            $this->assertSame(
                EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
                $claims[$key]->fresh()->approval_status,
                "claim {$key} (same section) must be rejected by the one decision",
            );
        }

        // "Fjern teksten" applies to the whole section — no paragraph is left standing.
        $markdownByKey = collect($version->fresh()->content_blocks_json)
            ->keyBy('block_key')
            ->map(fn (array $block): string => trim((string) ($block['markdown'] ?? '')));

        foreach (['block-0013', 'block-0014', 'block-0015'] as $blockKey) {
            $this->assertSame('', $markdownByKey[$blockKey], "{$blockKey} must be cleared");
        }

        // A best-practice block in a DIFFERENT section is untouched.
        $this->assertNotSame('', $markdownByKey['block-0020']);
    }

    public function test_two_separate_best_practice_sections_do_not_affect_each_other(): void
    {
        [$owner, $page, , $claims] = $this->createSectionScenario();

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claims['a']->id}/approve")
            ->assertRedirect();

        $this->assertSame(
            EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            $claims['other']->fresh()->approval_status,
            'a claim in another section must stay untouched',
        );
    }

    public function test_editing_and_approving_replaces_the_whole_section_text(): void
    {
        [$owner, $page, $version, $claims] = $this->createSectionScenario();

        $sectionText = "## Styring\n\nLeverandøren skal etablere rutiner.\n\nLeverandøren skal dokumentere avvik og følge opp disse kvartalsvis.";

        $this->actingAs($owner)
            ->patch("/app/wiki/{$page->slug}/claims/{$claims['a']->id}/approve", ['approved_text' => $sectionText])
            ->assertRedirect();

        $markdownByKey = collect($version->fresh()->content_blocks_json)
            ->keyBy('block_key')
            ->map(fn (array $block): string => trim((string) ($block['markdown'] ?? '')));

        // The whole edited section lands on the section's first block; the rest are cleared, so
        // the section's other paragraphs are not duplicated alongside the edited text.
        $this->assertSame($sectionText, $markdownByKey['block-0013']);
        $this->assertSame('', $markdownByKey['block-0014']);
        $this->assertSame('', $markdownByKey['block-0015']);
        $this->assertNotSame('', $markdownByKey['block-0020'], 'the other section is untouched');
    }

    /**
     * Three consecutive best_practice blocks forming ONE section (heading + two paragraphs), plus
     * a separate best_practice section after a source_based block, which is a hard boundary.
     * content_blocks_json deliberately carries no section_key — it is never stored, only derived.
     *
     * @return array{0: User, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: array<string, EnterpriseWikiClaim>}
     */
    private function createSectionScenario(): array
    {
        $customer = $this->createCustomer();
        $owner = $this->createUser($customer);
        $page = $this->createPage($customer);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            // replaceBlockMarkdown() keeps content_markdown and content_blocks_json in sync, so
            // the markdown must genuinely contain each block's own text.
            'content_markdown' => implode("\n\n", [
                '## Styring',
                'Leverandøren skal etablere rutiner.',
                'Leverandøren skal dokumentere avvik.',
                'Kildebasert innhold som avslutter seksjonen.',
                '## Annen anbefaling',
            ]),
            'content_blocks_json' => [
                $this->bestPracticeBlock('block-0013', 0, '## Styring'),
                $this->bestPracticeBlock('block-0014', 1, 'Leverandøren skal etablere rutiner.'),
                $this->bestPracticeBlock('block-0015', 2, 'Leverandøren skal dokumentere avvik.'),
                [
                    'block_key' => 'block-0019',
                    'position' => 3,
                    'markdown' => 'Kildebasert innhold som avslutter seksjonen.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'best_practice_reason' => null,
                    'source_elements' => [],
                ],
                $this->bestPracticeBlock('block-0020', 4, '## Annen anbefaling'),
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        return [$owner, $page, $version, [
            'a' => $this->createClaim($page, $version, 'block-0013', 0),
            'b' => $this->createClaim($page, $version, 'block-0014', 1),
            'c' => $this->createClaim($page, $version, 'block-0015', 2),
            'other' => $this->createClaim($page, $version, 'block-0020', 3),
        ]];
    }

    private function bestPracticeBlock(string $blockKey, int $position, string $markdown): array
    {
        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'best_practice_reason' => 'Identifisert svakhet: uklart prosesseierskap.',
            'source_elements' => [],
        ];
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $blockKey,
        int $positionOrder,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Påstand for '.$blockKey,
            'page_excerpt' => 'Påstand for '.$blockKey,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'content_block_key' => $blockKey,
            'review_reason' => 'Identifisert svakhet: uklart prosesseierskap.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => $positionOrder,
        ]);
    }

    private function createCustomer(string $name = 'Seksjonsbeslutning AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@seksjonsbeslutning.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createPage(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'seksjonsbeslutning-'.Str::lower(Str::random(6)),
            'title' => 'Seksjonsbeslutning',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }
}
