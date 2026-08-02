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
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EnterpriseWikiExtractPageClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_CLAIMS = [
        ['text' => 'Test claim alpha', 'confidence' => 'high',   'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
        ['text' => 'Test claim beta',  'confidence' => 'medium', 'excerpt' => 'Supporting excerpt beta.',  'conflict_note' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // No real OpenAI calls in any test.
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => self::FAKE_CLAIMS])
            ->byDefault();
    }

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:extract-page-claims')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:extract-page-claims', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guard: run not applied
    // =========================================================================

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunPending($customer);

        $this->artisan('wiki:extract-page-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Successful extraction
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $this->artisan('wiki:extract-page-claims', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_creates_claims_for_article_page(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_summary_page(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_concept_page(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_claims_for_entity_page(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists()
        );
    }

    public function test_command_creates_correct_number_of_claims(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            count(self::FAKE_CLAIMS),
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->count()
        );
    }

    // =========================================================================
    // Del 4 (v0.10, docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): a run-wide cap
    // on new claims, configured via services.enterprise_wiki.max_new_claims_per_run — the run
    // still completes normally once the cap is reached, it is never a failure.
    // =========================================================================

    public function test_run_level_cap_stops_creating_new_claims_once_reached(): void
    {
        config(['services.enterprise_wiki.max_new_claims_per_run' => 3]);

        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $pages = [
            $this->createPageWithVersion($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Page One'),
            $this->createPageWithVersion($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Page Two'),
            $this->createPageWithVersion($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Page Three'),
        ];

        // FAKE_CLAIMS returns 2 claims per page: with a cap of 3, the first 2 pages processed
        // (2 + 2 = 4 claims, checked before each page's AI call) exhaust the cap and the 3rd page
        // is skipped entirely — regardless of which specific page that turns out to be.
        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $versionIds = array_map(fn (array $p) => $p['version']->id, $pages);
        $totalClaims = EnterpriseWikiClaim::query()->whereIn('enterprise_wiki_page_version_id', $versionIds)->count();
        $pagesWithNoClaims = collect($versionIds)
            ->filter(fn (int $versionId): bool => EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $versionId)->doesntExist())
            ->count();

        $this->assertSame(4, $totalClaims, 'Exactly 2 of the 3 pages (2 claims each) fit under the cap of 3.');
        $this->assertSame(1, $pagesWithNoClaims, 'Exactly one page must be fully capped out.');
    }

    public function test_run_level_cap_still_completes_the_extraction_step_for_capped_pages(): void
    {
        // A capped page must still record its claims_extracted_at checkpoint so
        // EnterpriseWikiPostIngestQaService::findIncompleteSteps() never treats it as an
        // unfinished step — the cap is never a failure state (Del 4).
        config(['services.enterprise_wiki.max_new_claims_per_run' => 1]);

        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $pageOne = $this->createPageWithVersion($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Page One');
        $pageTwo = $this->createPageWithVersion($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Page Two');

        $exitCode = Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(0, $exitCode, 'Reaching the cap must never fail the command/run.');

        $pageTwoPivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $pageTwo['page']->id)
            ->firstOrFail();

        $this->assertNotNull($pageTwoPivot->claims_extracted_at, 'A capped page must still be marked as having completed its extraction step.');
    }

    public function test_run_level_cap_default_is_configurable_via_env_pattern(): void
    {
        $this->assertSame(60, config('services.enterprise_wiki.max_new_claims_per_run'));
    }

    // =========================================================================
    // Wiki run-34 fix: exact-duplicate claims within one extraction response are deduplicated
    // =========================================================================

    public function test_identical_claims_in_one_extraction_response_are_deduplicated(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn([
                'claims' => [
                    ['text' => 'Supporting excerpt alpha.', 'confidence' => 'high', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
                    // Same fact, only cosmetic case/whitespace differences.
                    ['text' => '  supporting   EXCERPT alpha.  ', 'confidence' => 'high', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
                    ['text' => 'Supporting excerpt beta.', 'confidence' => 'medium', 'excerpt' => 'Supporting excerpt beta.', 'conflict_note' => null],
                ],
            ]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            2,
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count(),
        );
    }

    public function test_deduplication_does_not_remove_genuinely_distinct_claims(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            count(self::FAKE_CLAIMS),
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count(),
        );
    }

    // =========================================================================
    // Claim field values
    // =========================================================================

    public function test_claim_has_correct_page_and_version_ids(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->first();

        $this->assertSame($page->id, $claim->enterprise_wiki_page_id);
        $this->assertSame($version->id, $claim->enterprise_wiki_page_version_id);
    }

    public function test_claim_has_correct_claim_text(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $texts = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->pluck('claim_text')
            ->all();

        $this->assertSame('Test claim alpha', $texts[0]);
        $this->assertSame('Test claim beta', $texts[1]);
    }

    public function test_claim_has_correct_confidence(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $first = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->first();

        $this->assertSame(EnterpriseWikiClaim::CONFIDENCE_HIGH, $first->confidence);
    }

    public function test_claim_stores_page_excerpt_block_key_and_source_based_origin_when_excerpt_matches_unique_block(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->firstOrFail();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertSame('Supporting excerpt alpha.', $claim->page_excerpt);
        $this->assertSame('block-0002', $claim->content_block_key);
        $this->assertNull($claim->generation_issue);
    }

    public function test_claim_without_page_excerpt_anchor_is_internal_generation_error(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn([
                'claims' => [
                    [
                        'text' => 'Loose claim not in the page.',
                        'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                        'excerpt' => 'This excerpt is not present in the Wiki page.',
                        'conflict_note' => null,
                    ],
                ],
            ]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->firstOrFail();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $claim->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN, $claim->confidence);
        $this->assertSame('claim_excerpt_not_found_in_page_version', $claim->generation_issue);
        $this->assertFalse($claim->needsSourceWarning());
    }

    public function test_claim_approval_status_is_pending(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            0,
            EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->where('approval_status', '!=', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
                ->count()
        );
    }

    public function test_claim_position_order_is_sequential(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $orders = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->pluck('position_order')
            ->all();

        $this->assertSame([0, 1], $orders);
    }

    // =========================================================================
    // Skip: no current version
    // =========================================================================

    public function test_command_skips_page_without_current_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Version');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertStringContainsString('Pages skipped:    1', Artisan::output());
    }

    // =========================================================================
    // Idempotency: existing claims skipped
    // =========================================================================

    public function test_command_skips_page_that_already_has_claims(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        // Pre-create a claim for this version
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Existing claim',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_reports_skipped_when_claims_already_exist(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Existing claim',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Pages skipped:    1', Artisan::output());
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_pages_processed_count(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Pages processed:  1', Artisan::output());
    }

    public function test_command_outputs_claims_created_count(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Claims created:   2', Artisan::output());
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_creates_source_references_from_page_block_provenance_before_verification(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->firstOrFail();

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->firstOrFail();

        $this->assertSame(123, $reference->source_id);
        $this->assertSame('source-alpha', $reference->source_element_key);
        $this->assertSame('Supporting excerpt alpha.', $reference->excerpt);
    }

    public function test_source_based_claim_inherits_all_source_elements_from_block(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $blocks = $version->content_blocks_json;
        $blocks[1]['source_elements'][] = [
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 456,
            'source_label' => 'second-source.docx',
            'source_hash' => str_pad('b', 64, '0'),
            'document_version_hash' => str_pad('b', 64, '0'),
            'source_element_key' => 'source-alpha-supporting',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
            'source_row_key' => 'row-1',
            'source_excerpt' => 'Additional support for alpha.',
            'page_reference' => 'Tabell 1, rad 1',
        ];
        $version->update(['content_blocks_json' => $blocks]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->firstOrFail();

        $this->assertSame(2, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
        $this->assertTrue(EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('source_element_key', 'source-alpha-supporting')
            ->where('source_row_key', 'row-1')
            ->exists());
    }

    public function test_claim_from_best_practice_block_gets_review_metadata_without_source_reference(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;
        $blocks[1]['source_id'] = null;
        $blocks[1]['source_element_key'] = null;
        $blocks[1]['source_elements'] = [];
        $blocks[1]['best_practice_reason'] = 'Anbefalingen er lagt til som eksplisitt beste praksis.';
        $blocks[1]['link_intents'] = [['target_slug' => 'kontroll', 'reason' => 'Synliggjør kontrolltemaet.']];
        $version->update(['content_blocks_json' => $blocks]);

        // Realistic best-practice-flavored wording (a genuine recommendation, not a bare
        // assertion) — the extraction-time modality guard (Del 3) only preserves best_practice
        // for claim text that is actually normative, so a generic placeholder claim would no
        // longer qualify.
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => [
                ['text' => 'Det anbefales å følge opp dette punktet jevnlig.', 'confidence' => 'medium', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
            ]]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->firstOrFail();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);
        $this->assertSame('ai_block_content_origin', $claim->review_metadata['classification_basis'] ?? null);
        $this->assertSame('recommended', $claim->review_metadata['visible_wiki_link_recommendation'] ?? null);
        $this->assertFalse(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->exists());
    }

    public function test_claim_that_turns_recommendation_into_current_state_fact_is_not_best_practice(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;
        $blocks[1]['source_id'] = null;
        $blocks[1]['source_element_key'] = null;
        $blocks[1]['source_elements'] = [];
        $blocks[1]['best_practice_reason'] = 'Anbefalingen er lagt til som eksplisitt beste praksis.';
        $version->update(['content_blocks_json' => $blocks]);

        // The block is a genuine recommendation, but the extracted claim text turns it into a
        // factual assertion about the customer's current state ("Kunden har...") — Del 3 says
        // extraction must not let this ride through as best_practice just because its block was.
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => [
                ['text' => 'Kunden har allerede etablert en fast eskaleringsrutine.', 'confidence' => 'medium', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
            ]]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claim = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->firstOrFail();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('best_practice_claim_asserts_current_state', $claim->generation_issue);
        $this->assertNull($claim->review_reason);
    }

    /**
     * Regression for ingest run 486 (production document "Incident Management Illustration.docx"):
     * a genuinely best_practice-tagged block ("## Berøringspunkter mot øvrige ITIL-prosesser") was
     * a multi-sentence recommendation paragraph — one sentence carried the "bør"/"lurt å" markers,
     * but the AI extraction step split it into several separate claims, and this particular
     * sentence (general ITIL domain context, no marker of its own, no assertion about the
     * customer's current state) was wrongly downgraded to unsupported_generated_content purely
     * because it lacked its own marker word — creating a blocking claim-integrity defect for
     * content that was never presented as a customer-specific fact.
     */
    public function test_supporting_sentence_from_best_practice_block_without_its_own_marker_stays_best_practice(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        $blocks = $version->content_blocks_json;
        foreach ([1, 2] as $index) {
            $blocks[$index]['content_origin'] = EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;
            $blocks[$index]['source_id'] = null;
            $blocks[$index]['source_element_key'] = null;
            $blocks[$index]['source_elements'] = [];
            $blocks[$index]['best_practice_reason'] = 'Anbefalingen er lagt til som eksplisitt beste praksis.';
        }
        $version->update(['content_blocks_json' => $blocks]);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => [
                ['text' => 'Typiske grenseflater omfatter problemhåndtering, endringsstyring, kunnskapsforvaltning og forespørselshåndtering i ITIL.', 'confidence' => 'medium', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
                ['text' => 'Å koble prosessflyten til etablert datakatalog og systemforvaltningspraksis i masterdata-samhandling og til operasjonelle rutiner i applikasjonsdrift kan gjøre hendelseshåndteringen mer presis og sporbar.', 'confidence' => 'medium', 'excerpt' => 'Supporting excerpt beta.', 'conflict_note' => null],
            ]]);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('position_order')
            ->get();

        $this->assertCount(2, $claims);

        foreach ($claims as $claim) {
            $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);
            $this->assertNotSame('best_practice_claim_asserts_current_state', $claim->generation_issue);
        }
    }

    public function test_command_does_not_create_additional_ingest_runs(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $runsBefore = EnterpriseWikiIngestRun::query()->count();

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($runsBefore, EnterpriseWikiIngestRun::query()->count());
    }

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    public function test_command_does_not_modify_existing_page_versions(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $originalMarkdown = $version->content_markdown;

        Artisan::call('wiki:extract-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($originalMarkdown, $version->fresh()->content_markdown);
    }

    // =========================================================================
    // Manual mixed-block claim-origin extraction variant
    // =========================================================================

    public function test_manual_mixed_block_extraction_persists_explicit_claim_origins_and_claim_scoped_source_references(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = 'mixed';
        $blocks[1]['markdown'] = implode("\n\n", [
            'Kunden har en dokumentert rutine.',
            'Det anbefales å etablere månedlig kontroll.',
            'Kunden har innført døgnbemanning.',
        ]);
        $blocks[1]['source_elements'] = [
            [
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => 123,
                'source_label' => 'source.docx',
                'source_hash' => str_pad('a', 64, '0'),
                'document_version_hash' => str_pad('a', 64, '0'),
                'source_element_key' => 'source-alpha',
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_row_key' => null,
                'source_excerpt' => 'Kunden har en dokumentert rutine.',
                'page_reference' => 'Avsnitt 1',
            ],
            [
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => 456,
                'source_label' => 'source-2.docx',
                'source_hash' => str_pad('b', 64, '0'),
                'document_version_hash' => str_pad('b', 64, '0'),
                'source_element_key' => 'source-beta',
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                'source_row_key' => null,
                'source_excerpt' => 'Urelatert kildeutdrag.',
                'page_reference' => 'Avsnitt 2',
            ],
        ];
        $version->update([
            'content_markdown' => "# Test Page\n\n{$blocks[1]['markdown']}\n\nSupporting excerpt beta.",
            'content_blocks_json' => $blocks,
        ]);

        $otherBlockClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Existing claim from another block',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'page_excerpt' => 'Supporting excerpt beta.',
            'content_block_key' => 'block-0003',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->withArgs(function (string $pageTitle, string $pageType, string $blockMarkdown, string $contentBlockKey, array $sourceElements): bool {
                $this->assertSame('block-0002', $contentBlockKey);
                $this->assertSame('article', $pageType);
                $this->assertStringContainsString('Kunden har en dokumentert rutine.', $blockMarkdown);
                $this->assertSame(['source-alpha', 'source-beta'], array_column($sourceElements, 'key'));

                return $pageTitle !== '';
            })
            ->andReturn(['claims' => [
                [
                    'text' => 'Kunden har en dokumentert rutine.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'excerpt' => 'Kunden har en dokumentert rutine.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => ['source-alpha'],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ],
                [
                    'text' => 'Det anbefales å etablere månedlig kontroll.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                    'excerpt' => 'Det anbefales å etablere månedlig kontroll.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    'source_element_keys' => [],
                    'best_practice_reason' => 'Normativ anbefaling for bedre kontroll.',
                    'conflict_note' => null,
                ],
                [
                    'text' => 'Kunden har innført døgnbemanning.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_LOW,
                    'excerpt' => 'Kunden har innført døgnbemanning.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'source_element_keys' => [],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ],
            ]]);

        $result = app(EnterpriseWikiExtractPageClaimsService::class)
            ->extractClaimsForManualMixedBlock($run->fresh(), $version->fresh(), $blocks[1]);

        $this->assertSame(3, $result['claims']);

        $createdClaims = EnterpriseWikiClaim::query()
            ->whereIn('id', $result['claim_ids'])
            ->orderBy('position_order')
            ->get();

        $this->assertSame([
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        ], $createdClaims->pluck('content_origin')->all());

        $sourceBasedClaim = $createdClaims->firstWhere('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $bestPracticeClaim = $createdClaims->firstWhere('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        $unsupportedClaim = $createdClaims->firstWhere('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);

        $this->assertSame(
            ['source-alpha'],
            EnterpriseWikiSourceReference::query()
                ->where('enterprise_wiki_claim_id', $sourceBasedClaim->id)
                ->pluck('source_element_key')
                ->all(),
        );
        $this->assertFalse(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $bestPracticeClaim->id)->exists());
        $this->assertFalse(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $unsupportedClaim->id)->exists());
        $this->assertSame('ai_manual_mixed_block_claim_origin', $bestPracticeClaim->review_metadata['classification_basis'] ?? null);
        $this->assertSame('unsupported_generated_content', $unsupportedClaim->generation_issue);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->firstOrFail();

        $this->assertNull($pivot->claims_extracted_at);
        $this->assertNull($pivot->claims_claimed_at);
        $this->assertSame($blocks, $version->fresh()->content_blocks_json);
        $this->assertTrue(EnterpriseWikiClaim::query()->whereKey($otherBlockClaim->id)->exists());
    }

    public function test_manual_mixed_block_best_practice_fact_is_degraded_to_unsupported(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = 'mixed';
        $blocks[1]['markdown'] = 'Kunden har allerede etablert en fast eskaleringsrutine.';
        $blocks[1]['source_elements'] = [];
        $version->update([
            'content_markdown' => "# Test Page\n\n{$blocks[1]['markdown']}",
            'content_blocks_json' => $blocks,
        ]);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->andReturn(['claims' => [[
                'text' => 'Kunden har allerede etablert en fast eskaleringsrutine.',
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                'excerpt' => 'Kunden har allerede etablert en fast eskaleringsrutine.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'source_element_keys' => [],
                'best_practice_reason' => 'AI mente dette var normativt.',
                'conflict_note' => null,
            ]]]);

        $result = app(EnterpriseWikiExtractPageClaimsService::class)
            ->extractClaimsForManualMixedBlock($run->fresh(), $version->fresh(), $blocks[1]);

        $claim = EnterpriseWikiClaim::query()->findOrFail($result['claim_ids'][0]);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('best_practice_claim_asserts_current_state', $claim->generation_issue);
        $this->assertFalse(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->exists());
    }

    /**
     * Regression for ingest run 486 — same manual-mixed-block path, using the real production
     * wording that lacks its own recommendation marker but does not assert any current-state fact
     * either. Must stay best_practice, unlike the "Kunden har..." drift case above.
     */
    public function test_manual_mixed_block_best_practice_fact_without_own_marker_is_not_degraded(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $text = 'Typiske grenseflater omfatter problemhåndtering, endringsstyring, kunnskapsforvaltning og forespørselshåndtering i ITIL.';
        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = 'mixed';
        $blocks[1]['markdown'] = $text;
        $blocks[1]['source_elements'] = [];
        $version->update([
            'content_markdown' => "# Test Page\n\n{$blocks[1]['markdown']}",
            'content_blocks_json' => $blocks,
        ]);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->andReturn(['claims' => [[
                'text' => $text,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                'excerpt' => $text,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'source_element_keys' => [],
                'best_practice_reason' => 'AI mente dette var normativt.',
                'conflict_note' => null,
            ]]]);

        $result = app(EnterpriseWikiExtractPageClaimsService::class)
            ->extractClaimsForManualMixedBlock($run->fresh(), $version->fresh(), $blocks[1]);

        $claim = EnterpriseWikiClaim::query()->findOrFail($result['claim_ids'][0]);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);
        $this->assertNotSame('best_practice_claim_asserts_current_state', $claim->generation_issue);
    }

    public function test_manual_mixed_block_invalid_claim_rolls_back_whole_block_response(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithVersionedPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $blocks = $version->content_blocks_json;
        $blocks[1]['content_origin'] = 'mixed';
        $blocks[1]['markdown'] = 'Kunden har en dokumentert rutine. Ugyldig påstand.';
        $version->update([
            'content_markdown' => "# Test Page\n\n{$blocks[1]['markdown']}",
            'content_blocks_json' => $blocks,
        ]);
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaimsForManualMixedBlock')
            ->once()
            ->andReturn(['claims' => [
                [
                    'text' => 'Kunden har en dokumentert rutine.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'excerpt' => 'Kunden har en dokumentert rutine.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => ['source-alpha'],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ],
                [
                    'text' => 'Ugyldig påstand.',
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'excerpt' => 'Ugyldig påstand.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_element_keys' => ['source-missing'],
                    'best_practice_reason' => null,
                    'conflict_note' => null,
                ],
            ]]);

        try {
            app(EnterpriseWikiExtractPageClaimsService::class)
                ->extractClaimsForManualMixedBlock($run->fresh(), $version->fresh(), $blocks[1]);
            $this->fail('Expected manual mixed block extraction to reject the invalid response.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unknown source_element_key', $e->getMessage());
        }

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Test AS'): Customer
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for testing.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createRunPending(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createRunApplied(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * A page (of the given type) attached to an EXISTING run's pivot, with a current version
     * whose content matches FAKE_CLAIMS' excerpts closely enough for the mocked AI client's
     * default response to persist without erroring — deliberately no content_blocks_json, so
     * claims land as content_origin=internal_error (irrelevant for cap tests, which only assert
     * on claim ROW counts, never content_origin).
     *
     * @return array{page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}
     */
    private function createPageWithVersion(Customer $customer, EnterpriseWikiIngestRun $run, string $pageType, string $title): array
    {
        $page = $this->createPage($customer, $pageType, $title);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nSupporting excerpt alpha.\n\nSupporting excerpt beta.",
            'generated_by_model' => 'gpt-5',
        ]);

        return ['page' => $page, 'version' => $version];
    }

    /**
     * Applied run with one page (of the given type) in the pivot, already having a current version.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion}
     */
    private function createAppliedRunWithVersionedPage(Customer $customer, string $pageType): array
    {
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer, $pageType, 'Test Page '.Str::random(4));

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Test Page\n\nThis is test content with verifiable facts.\n\nSupporting excerpt alpha.\n\nSupporting excerpt beta.",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-0001',
                    'position' => 0,
                    'markdown' => '# Test Page',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_id' => 123,
                    'source_label' => 'source.docx',
                    'source_hash' => str_pad('a', 64, '0'),
                    'source_element_key' => 'source-heading',
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                    'source_row_key' => null,
                    'source_excerpt' => 'Test Page',
                    'page_reference' => 'Tittel',
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => 123,
                        'source_label' => 'source.docx',
                        'source_hash' => str_pad('a', 64, '0'),
                        'document_version_hash' => str_pad('a', 64, '0'),
                        'source_element_key' => 'source-heading',
                        'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                        'source_row_key' => null,
                        'source_excerpt' => 'Test Page',
                        'page_reference' => 'Tittel',
                    ]],
                ],
                [
                    'block_key' => 'block-0002',
                    'position' => 1,
                    'markdown' => 'Supporting excerpt alpha.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_id' => 123,
                    'source_label' => 'source.docx',
                    'source_hash' => str_pad('a', 64, '0'),
                    'source_element_key' => 'source-alpha',
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                    'source_row_key' => null,
                    'source_excerpt' => 'Supporting excerpt alpha.',
                    'page_reference' => 'Avsnitt 1',
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => 123,
                        'source_label' => 'source.docx',
                        'source_hash' => str_pad('a', 64, '0'),
                        'document_version_hash' => str_pad('a', 64, '0'),
                        'source_element_key' => 'source-alpha',
                        'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                        'source_row_key' => null,
                        'source_excerpt' => 'Supporting excerpt alpha.',
                        'page_reference' => 'Avsnitt 1',
                    ]],
                ],
                [
                    'block_key' => 'block-0003',
                    'position' => 2,
                    'markdown' => 'Supporting excerpt beta.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_id' => 123,
                    'source_label' => 'source.docx',
                    'source_hash' => str_pad('a', 64, '0'),
                    'source_element_key' => 'source-beta',
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                    'source_row_key' => null,
                    'source_excerpt' => 'Supporting excerpt beta.',
                    'page_reference' => 'Avsnitt 2',
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => 123,
                        'source_label' => 'source.docx',
                        'source_hash' => str_pad('a', 64, '0'),
                        'document_version_hash' => str_pad('a', 64, '0'),
                        'source_element_key' => 'source-beta',
                        'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
                        'source_row_key' => null,
                        'source_excerpt' => 'Supporting excerpt beta.',
                        'page_reference' => 'Avsnitt 2',
                    ]],
                ],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        return [$run, $page, $version];
    }
}
