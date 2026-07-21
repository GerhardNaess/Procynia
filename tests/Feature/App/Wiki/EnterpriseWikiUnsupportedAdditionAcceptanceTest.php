<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v0.7 binding quality-strategy acceptance test (docs/enterprise-llm-wiki-plan.md,
 * "Arkitekturnotat — v0.7"): a targeted new-document-import scenario proving every acceptance
 * criterion for the rule — source-supported text produces no user finding, a best-practice
 * paragraph is kept and marked directly on the page, several claims anchored to that SAME
 * paragraph collapse into exactly one user case, "Gå til tekst" navigates to the right block, no
 * internal verification category (negation/modality/actor/scope) is ever user-facing, and
 * approve/edit/remove all work and close the case without creating duplicates.
 */
class EnterpriseWikiUnsupportedAdditionAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_source_supported_text_creates_no_user_finding(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");

        $response->assertOk();
        $this->assertNull(collect($response->json('findings'))->firstWhere('claim_id', $scenario['sourceClaim']->id));
    }

    public function test_best_practice_paragraph_with_multiple_claims_is_exactly_one_finding(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $response->assertOk();

        $additionFindings = collect($response->json('findings'))->where('category', 'best_practice_suggestion')->values();

        $this->assertCount(1, $additionFindings);
        $finding = $additionFindings->first();
        $this->assertSame(2, $finding['claim_count']);
        $this->assertSame('pending_review', $finding['status']);
        $this->assertFalse($finding['blocks_run']);
    }

    public function test_no_internal_verification_category_is_ever_user_facing(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $response->assertOk();

        $this->assertNull(collect($response->json('findings'))->firstWhere('claim_id', $scenario['negationClaim']->id));

        $categories = collect($response->json('findings'))->pluck('category')->all();
        foreach (['negation_mismatch', 'modality_mismatch', 'actor_mismatch', 'scope_mismatch', 'subject_mismatch', 'technical_uncertainty'] as $forbidden) {
            $this->assertNotContains($forbidden, $categories);
        }

        $rawText = $response->getContent();
        $this->assertStringNotContainsString('negation_mismatch', $rawText);
    }

    public function test_go_to_text_action_opens_the_right_page_and_highlights_the_marked_block(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $finding = collect($response->json('findings'))->firstWhere('category', 'best_practice_suggestion');

        $this->assertSame('open_and_review', $finding['action']);
        $this->assertNotNull($finding['url']);

        $path = parse_url($finding['url'], PHP_URL_PATH).'?'.parse_url($finding['url'], PHP_URL_QUERY);
        $pageResponse = $this->actingAs($scenario['user'])->get($path);

        $pageResponse->assertOk();
        $pageResponse->assertInertia(fn ($page) => $page
            ->where('review_reference.status', 'ready')
            ->where('review_reference.block_key', 'block-best-practice'));
    }

    public function test_best_practice_text_is_marked_directly_on_the_page(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['user'])->get("/app/wiki/{$scenario['page']->slug}");

        $response->assertOk();
        $bestPracticeClaims = collect($response->getOriginalContent()->getData()['page']['props']['claims'])
            ->where('content_block_key', 'block-best-practice');

        $this->assertCount(2, $bestPracticeClaims);
        $this->assertTrue($bestPracticeClaims->every(fn (array $c) => $c['content_origin'] === 'best_practice'));
    }

    public function test_approving_the_case_closes_it_and_decides_every_claim_in_the_block(): void
    {
        $scenario = $this->createScenario();
        $primary = $scenario['bestPracticeClaims'][0];
        $sibling = $scenario['bestPracticeClaims'][1];

        $this->actingAs($scenario['user'])->patch(
            "/app/wiki/{$scenario['page']->slug}/claims/{$primary->id}/approve",
            ['comment' => 'Nyttig tillegg, beholdes.'],
        )->assertRedirect();

        $this->assertTrue($primary->fresh()->isApproved());
        $this->assertTrue($sibling->fresh()->isApproved(), 'Sibling claim in the same block must be cascaded to approved.');

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $finding = collect($response->json('findings'))->firstWhere('category', 'best_practice_suggestion');

        $this->assertSame('approved', $finding['status']);
        $this->assertNotSame('pending_review', $finding['status']);
    }

    public function test_editing_and_approving_replaces_the_block_text_and_closes_the_case(): void
    {
        $scenario = $this->createScenario();
        $primary = $scenario['bestPracticeClaims'][0];
        $editedText = 'Redigert tillegg: gjennomgå tilgangsrettigheter kvartalsvis som beste praksis.';

        $this->actingAs($scenario['user'])->patch(
            "/app/wiki/{$scenario['page']->slug}/claims/{$primary->id}/approve",
            ['approved_text' => $editedText],
        )->assertRedirect();

        $version = $scenario['version']->fresh();
        $blocks = collect($version->content_blocks_json);
        $editedBlock = $blocks->firstWhere('block_key', 'block-best-practice');

        $this->assertSame($editedText, $editedBlock['markdown']);
        $this->assertStringContainsString($editedText, $version->content_markdown);

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $finding = collect($response->json('findings'))->firstWhere('category', 'best_practice_suggestion');
        $this->assertSame('approved_edited', $finding['status']);
    }

    public function test_removing_the_text_blanks_the_block_and_closes_the_case(): void
    {
        $scenario = $this->createScenario();
        $primary = $scenario['bestPracticeClaims'][0];

        $this->actingAs($scenario['user'])->patch(
            "/app/wiki/{$scenario['page']->slug}/claims/{$primary->id}/reject",
            ['comment' => 'Ikke ønsket, fjernes.'],
        )->assertRedirect();

        $version = $scenario['version']->fresh();
        $blocks = collect($version->content_blocks_json);
        $removedBlock = $blocks->firstWhere('block_key', 'block-best-practice');

        $this->assertSame('', $removedBlock['markdown']);

        $response = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $finding = collect($response->json('findings'))->firstWhere('category', 'best_practice_suggestion');
        $this->assertSame('rejected', $finding['status']);

        $this->assertTrue($scenario['bestPracticeClaims'][1]->fresh()->isRejected());
    }

    public function test_repeated_processing_does_not_create_duplicate_findings(): void
    {
        $scenario = $this->createScenario();

        $first = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $second = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");

        $firstCount = collect($first->json('findings'))->where('category', 'best_practice_suggestion')->count();
        $secondCount = collect($second->json('findings'))->where('category', 'best_practice_suggestion')->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount);

        $this->actingAs($scenario['user'])->patch(
            "/app/wiki/{$scenario['page']->slug}/claims/{$scenario['bestPracticeClaims'][0]->id}/approve",
        )->assertRedirect();

        $after = $this->actingAs($scenario['user'])->getJson("/app/wiki/runs/{$scenario['run']->id}/findings");
        $this->assertSame(1, collect($after->json('findings'))->where('category', 'best_practice_suggestion')->count());
    }

    public function test_other_customers_and_documents_are_not_affected(): void
    {
        $scenarioA = $this->createScenario('Tillegg AS A');
        $scenarioB = $this->createScenario('Tillegg AS B');

        $this->actingAs($scenarioA['user'])->patch(
            "/app/wiki/{$scenarioA['page']->slug}/claims/{$scenarioA['bestPracticeClaims'][0]->id}/approve",
        )->assertRedirect();

        $this->assertTrue($scenarioA['bestPracticeClaims'][0]->fresh()->isApproved());
        $this->assertTrue($scenarioB['bestPracticeClaims'][0]->fresh()->isPending());

        $responseB = $this->actingAs($scenarioB['user'])->getJson("/app/wiki/runs/{$scenarioB['run']->id}/findings");
        $findingB = collect($responseB->json('findings'))->firstWhere('category', 'best_practice_suggestion');
        $this->assertSame('pending_review', $findingB['status']);
    }

    // =========================================================================
    // Scenario builder
    // =========================================================================

    /**
     * @return array{
     *     customer: Customer, user: User, run: EnterpriseWikiIngestRun, page: EnterpriseWikiPage,
     *     version: EnterpriseWikiPageVersion, sourceClaim: EnterpriseWikiClaim,
     *     bestPracticeClaims: list<EnterpriseWikiClaim>, negationClaim: EnterpriseWikiClaim,
     * }
     */
    private function createScenario(string $customerName = 'Tillegg-strategi AS'): array
    {
        $customer = $this->createCustomer($customerName);
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createIngestRun($customer, $document);
        $page = $this->createPage($customer, 'Tilgangsstyring');

        $sourceMarkdown = 'Tilgangsrettigheter tildeles basert på den ansattes rolle og behov for tilgang.';
        $bestPracticeMarkdown = 'Som beste praksis anbefales det å gjennomgå tilgangsrettigheter kvartalsvis for å redusere risiko for utdaterte rettigheter.';
        $negationMarkdown = 'Systemet støtter ikke automatisk utløp av tilgangsrettigheter.';

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Tilgangsstyring\n\n{$sourceMarkdown}\n\n{$bestPracticeMarkdown}\n\n{$negationMarkdown}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-source',
                    'position' => 0,
                    'markdown' => $sourceMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                ],
                [
                    'block_key' => 'block-best-practice',
                    'position' => 1,
                    'markdown' => $bestPracticeMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                ],
                [
                    'block_key' => 'block-negation',
                    'position' => 2,
                    'markdown' => $negationMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                ],
            ],
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $version->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        $sourceClaim = $this->createClaim($page, $version, $sourceMarkdown, 0, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'content_block_key' => 'block-source',
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $sourceClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'excerpt' => $sourceMarkdown,
        ]);

        // Two DISTINCT internal claims anchored to the SAME best-practice paragraph — must
        // collapse into exactly one user-facing case (v0.7 rule #4), never two.
        $bestPracticeClaims = [
            $this->createClaim($page, $version, 'Kvartalsvis gjennomgang av tilgangsrettigheter anbefales.', 1, [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'content_block_key' => 'block-best-practice',
                'review_reason' => 'Reduserer risiko for utdaterte tilgangsrettigheter.',
            ]),
            $this->createClaim($page, $version, 'Formålet er å redusere risiko for utdaterte rettigheter.', 2, [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'content_block_key' => 'block-best-practice',
                'review_reason' => 'Reduserer risiko for utdaterte tilgangsrettigheter.',
            ]),
        ];

        // A claim flagged only by an internal comparison-mechanism signal — must never surface as
        // a user-facing finding (v0.7 rule #3), regardless of how the block itself is labeled.
        $negationClaim = $this->createClaim($page, $version, $negationMarkdown, 3, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'content_block_key' => 'block-negation',
            'generation_issue' => 'unsupported_generated_content',
            'review_metadata' => [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => 'negation_mismatch',
            ],
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
            'run' => $run,
            'page' => $page,
            'version' => $version,
            'sourceClaim' => $sourceClaim,
            'bestPracticeClaims' => $bestPracticeClaims,
            'negationClaim' => $negationClaim,
        ];
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

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
            'name' => 'Addition Strategy Tester',
            'email' => Str::lower(Str::random(8)).'@addition-strategy-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'tilgangsstyring.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Tilgangsrettigheter tildeles basert på den ansattes rolle og behov for tilgang.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createIngestRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
    }

    private function createPage(Customer $customer, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $text,
        int $positionOrder,
        array $overrides = [],
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => $positionOrder,
        ], $overrides));
    }
}
