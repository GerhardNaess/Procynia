<?php

namespace Tests\Feature\App;

use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiPage;
use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewSnapshotSection;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Lag kunnskapsartikkel» from Ledelsens gjennomgåelse. The shared matrix (permissions, 404 for a
 * foreign review, ordinary source + origin + run, AI attribution) runs in WikiKnowledgeHandoffTest;
 * this file pins what is particular to a review:
 *
 *  - only a finalized review hands over;
 *  - a module section's judgement and comment follow that section's basis gate, as on the page;
 *  - what goes over is the management's own word, always dated — never the basis, the people, the
 *    live follow-up or the unverified framework mapping;
 *  - handing the same choice over twice is one source with one origin.
 */
class ManagementReviewWikiKnowledgeTest extends TestCase
{
    use CreatesManagementReviewScenarios;
    use UsesProjectPostgresConnection;

    private const CONCLUSION = 'Styringssystemet er egnet og virker.';

    private const IMPROVEMENT_COMMENT = 'Avvikshåndteringen fungerer, men lukketiden er for lang.';

    private const RESOURCES_NOTES = 'Kompetanseplanen dekker behovet for neste periode.';

    private const CASE_TITLE = 'Dobbel lønnsutbetaling i mars';

    private const PARTICIPANT = 'Kari Nordmann';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Storage::fake('local');
        Queue::fake();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_draft_review_cannot_be_handed_over(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);
        $review = $this->mrReview($manager);

        $this->assertNull($this->actingAs($manager)->get("/app/management-reviews/{$review->id}")->assertOk()->viewData('page')['props']['knowledge_handoff']);

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload([]))->assertForbidden();

        $this->assertNothingHandedOver($customer);
    }

    public function test_a_module_section_is_offered_only_to_someone_who_may_read_its_basis(): void
    {
        ['customer' => $customer, 'review' => $review] = $this->finalizedReview();
        $wiki = [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE];

        $withoutCases = $this->mrManager($customer, $wiki);
        $draft = $this->draftFor($withoutCases, $review);
        $this->assertNotContains('assessment_improvements', array_column($draft['sections'], 'key'));
        $this->assertStringNotContainsString(self::IMPROVEMENT_COMMENT, json_encode($draft, JSON_UNESCAPED_UNICODE));
        // Own and manual sections follow the review, not a module.
        $this->assertContains('assessment_resources', array_column($draft['sections'], 'key'));

        // Naming the section anyway is refused, and nothing is handed over.
        $this->actingAs($withoutCases)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload(['assessment_improvements']))
            ->assertSessionHasErrors('sections.0');
        $this->assertNothingHandedOver($customer);

        $withCases = $this->mrManager($customer, [...$wiki, CustomerPermissionCatalog::IMPROVEMENT_VIEW], true);
        $this->assertContains('assessment_improvements', array_column($this->draftFor($withCases, $review)['sections'], 'key'));
    }

    public function test_only_the_managements_own_dated_word_goes_over(): void
    {
        ['customer' => $customer, 'review' => $review, 'finalizer' => $finalizer] = $this->finalizedReview();
        $user = $this->mrManager($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE, CustomerPermissionCatalog::IMPROVEMENT_VIEW], true);

        // The basis really did hold the register entry — leaving it out below is a choice, not luck.
        $this->assertStringContainsString(self::CASE_TITLE, json_encode(
            ManagementReviewSnapshotSection::query()->where('management_review_id', $review->id)->where('section_key', 'improvements')->sole()->payload,
            JSON_UNESCAPED_UNICODE,
        ));

        $offered = array_column($this->draftFor($user, $review)['sections'], 'key');
        foreach (['conclusion', 'assessment_improvements', 'assessment_resources', 'decisions', 'planned_improvements', 'amendments'] as $key) {
            $this->assertContains($key, $offered);
        }

        $this->actingAs($user)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload($offered))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $text = (string) EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole()->extracted_text;
        $finalizedOn = $review->finalized_at->toDateString();

        // Dated and framed: the period, the finalization date, and what kind of statement each part is.
        $this->assertStringContainsString('2026-01-01–2026-06-30', $text);
        $this->assertStringContainsString(__('procynia.knowledge_handoff.sources.management_review.as_of', ['date' => $finalizedOn]), $text);
        $this->assertStringContainsString(__('procynia.knowledge_handoff.sources.management_review.kinds'), $text);
        $this->assertStringContainsString(__('procynia.knowledge_handoff.sources.management_review.conclusion_heading', ['date' => $finalizedOn]), $text);

        // The management's own word.
        $this->assertStringContainsString('Slik følger ledelsen opp styringssystemet.', $text);
        $this->assertStringContainsString(self::CONCLUSION, $text);
        $this->assertStringContainsString(self::IMPROVEMENT_COMMENT, $text);
        $this->assertStringContainsString(self::RESOURCES_NOTES, $text);
        $this->assertStringContainsString(__('procynia.management_review.judgements.needs_improvement'), $text);
        $this->assertStringContainsString('Avvik skal lukkes innen 30 dager.', $text);
        $this->assertStringContainsString('Innføre ukentlig gjennomgang av åpne avvik.', $text);
        $this->assertStringContainsString('Konklusjonen gjelder også Bergen.', $text);

        // Decisions and planned improvements stay apart.
        $this->assertLessThan(
            strpos($text, '## '.__('procynia.knowledge_handoff.sources.management_review.planned_improvements_heading')),
            strpos($text, 'Avvik skal lukkes innen 30 dager.'),
        );
        $this->assertGreaterThan(
            strpos($text, '## '.__('procynia.knowledge_handoff.sources.management_review.planned_improvements_heading')),
            strpos($text, 'Innføre ukentlig gjennomgang av åpne avvik.'),
        );

        // Never the register, the people, the live follow-up or the unverified framework mapping.
        $this->assertStringNotContainsString(self::CASE_TITLE, $text);
        $this->assertStringNotContainsString(self::PARTICIPANT, $text);
        $this->assertStringNotContainsString($finalizer->name, $text);
        $this->assertStringNotContainsString('2026-12-31', $text);
        $this->assertStringNotContainsString('ISO', $text);
        $this->assertStringNotContainsString('Feil i rettelse', $text);

        // Provenance back to the review; an ordinary run, nothing published.
        $origin = EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(['management_review', 'management_review', (int) $review->id], [$origin->source_module, $origin->source_type, (int) $origin->source_id]);
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);
        $this->assertSame(0, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_dating_goes_over_even_when_no_section_is_chosen(): void
    {
        ['customer' => $customer, 'review' => $review] = $this->finalizedReview();
        $user = $this->mrManager($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);

        $draft = $this->draftFor($user, $review);
        $this->assertSame('context', $draft['context']['key']);
        $this->assertNotContains('context', array_column($draft['sections'], 'key'));
        $this->assertSame(__('procynia.knowledge_handoff.sources.management_review.notice'), $draft['notice']);

        $this->actingAs($user)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload([]))->assertSessionHasNoErrors();

        $text = (string) EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->sole()->extracted_text;
        $this->assertStringContainsString('2026-01-01–2026-06-30', $text);
        $this->assertStringNotContainsString(self::CONCLUSION, $text);
        // The context frames the lesson: it comes first.
        $this->assertLessThan(strpos($text, 'Slik følger ledelsen opp styringssystemet.'), strpos($text, '2026-01-01–2026-06-30'));
    }

    public function test_handing_the_same_choice_over_twice_is_one_source_with_one_origin(): void
    {
        ['customer' => $customer, 'review' => $review] = $this->finalizedReview();
        $user = $this->mrManager($customer, [CustomerPermissionCatalog::WIKI_VIEW, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE]);

        $this->actingAs($user)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload(['conclusion']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/management-reviews/{$review->id}/knowledge-handoff", $this->payload(['conclusion']))->assertSessionHasNoErrors();

        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->count());
        Queue::assertPushed(RunEnterpriseWikiDocumentFlow::class, 1);

        $entries = $this->actingAs($user)->get("/app/management-reviews/{$review->id}")->viewData('page')['props']['knowledge_handoff']['entries'];
        $this->assertCount(1, $entries);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * A finalized review with something in every part that may — or must not — go over.
     *
     * @return array{customer: Customer, review: ManagementReview, finalizer: User}
     */
    private function finalizedReview(): array
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Bergen');
        $finalizer = $this->mrManager($customer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], true);

        $case = ImprovementCase::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'type' => ImprovementCase::TYPE_DEVIATION,
            'title' => self::CASE_TITLE, 'description' => 'Beskrivelse',
        ]);
        $case->forceFill(['created_at' => '2026-03-01 10:00:00'])->saveQuietly();

        $review = $this->mrReview($finalizer, ['purpose' => 'Årlig gjennomgang av styringssystemet', 'frameworks' => ['iso9001']]);
        $base = "/app/management-reviews/{$review->id}";

        $this->actingAs($finalizer)->post("{$base}/participants", ['name' => self::PARTICIPANT, 'role_label' => 'Daglig leder'])->assertSessionHasNoErrors();
        $this->actingAs($finalizer)->put("{$base}/conclusion", ['conclusion' => self::CONCLUSION])->assertSessionHasNoErrors();

        foreach ($this->actingAs($finalizer)->get($base)->viewData('page')['props']['sections'] as $section) {
            if ($section['can_assess']) {
                $this->actingAs($finalizer)->put("{$base}/sections/{$section['key']}", ['judgement' => 'satisfactory'])->assertSessionHasNoErrors();
            }
        }

        $this->actingAs($finalizer)->put("{$base}/sections/improvements", ['judgement' => 'needs_improvement', 'comment' => self::IMPROVEMENT_COMMENT])->assertSessionHasNoErrors();
        $this->actingAs($finalizer)->put("{$base}/sections/resources", ['judgement' => 'satisfactory', 'notes' => self::RESOURCES_NOTES])->assertSessionHasNoErrors();
        $this->actingAs($finalizer)->post("{$base}/decisions", ['kind' => 'decision', 'text' => 'Avvik skal lukkes innen 30 dager.', 'section_key' => 'improvements'])->assertSessionHasNoErrors();
        $this->actingAs($finalizer)->post("{$base}/decisions", [
            'kind' => 'action', 'text' => 'Innføre ukentlig gjennomgang av åpne avvik.', 'section_key' => 'improvements',
            'owner_user_id' => $finalizer->id, 'due_date' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $this->actingAs($finalizer)->post("{$base}/finalize")->assertSessionHasNoErrors();
        $this->actingAs($finalizer)->post("{$base}/amendments", ['text' => 'Konklusjonen gjelder også Bergen.', 'reason' => 'Feil i rettelse'])->assertSessionHasNoErrors();

        return ['customer' => $customer, 'review' => $review->fresh(), 'finalizer' => $finalizer];
    }

    /** @return array<string, mixed> */
    private function draftFor(User $user, ManagementReview $review): array
    {
        $panel = $this->actingAs($user)->get("/app/management-reviews/{$review->id}")->assertOk()->viewData('page')['props']['knowledge_handoff'];
        $this->assertTrue($panel['can_create']);

        return $panel['draft'];
    }

    /** @param  list<string>  $sections */
    private function payload(array $sections): array
    {
        return ['title' => 'Slik styrer vi virksomheten', 'learning' => 'Slik følger ledelsen opp styringssystemet.', 'sections' => $sections];
    }

    private function assertNothingHandedOver(Customer $customer): void
    {
        $this->assertSame(0, EnterpriseWikiDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, EnterpriseWikiDocumentOrigin::query()->where('customer_id', $customer->id)->count());
        Queue::assertNotPushed(RunEnterpriseWikiDocumentFlow::class);
    }
}
