<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiBestPracticeReviewReconciler;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * The observable best-practice contract.
 *
 * Runs 56–58 generated 21 pages and produced 0, 0 and 1 best_practice blocks. The AI-side origin
 * counters proved the model returned none and that nothing downstream dropped any — so "assessed,
 * nothing worth adding" and "never assessed" were indistinguishable in the data. Everything else in
 * the generation contract is structurally required and deterministically verified; the best-practice
 * synthesis was the one part that was only instructed.
 *
 * This makes the assessment itself an output, and holds three lines while doing it:
 *  - it never forces a recommendation (gap_found=false is a complete answer),
 *  - it never fails a run over an inconsistency (that would make false the safe answer),
 *  - it never deletes generated content on the strength of metadata.
 */
class EnterpriseWikiBestPracticeReviewContractTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // The contract the model answers
    // =========================================================================

    public function test_every_planned_topic_gets_exactly_one_review_entry(): void
    {
        $schema = $this->schemaFor(EnterpriseWikiPage::PAGE_TYPE_CONCEPT, [
            ['planned_topic' => 'Risikostyring'],
            ['planned_topic' => 'Endringshåndtering'],
        ]);
        $review = $schema['properties']['best_practice_review'];

        $this->assertContains('best_practice_review', $schema['required']);
        $this->assertSame(2, $review['minItems']);
        $this->assertSame(2, $review['maxItems'], 'fixed cardinality is what makes a missing review impossible');
        $this->assertSame(
            ['Risikostyring', 'Endringshåndtering'],
            $review['items']['properties']['planned_topic']['enum'],
        );
        $this->assertSame(
            ['planned_topic', 'gap_found', 'assessment'],
            $review['items']['required'],
            'no separate "reviewed" flag — the entry IS the assessment',
        );
    }

    public function test_a_summary_page_reviews_itself_as_a_whole(): void
    {
        // Summary pages are explicitly instructed to have no headings, so they are not section-
        // mapped (EnterpriseWikiPlannedSectionCoverageValidator::CHECKED_PAGE_TYPES leaves them out)
        // and there is no per-topic unit to report against.
        $review = $this->schemaFor(EnterpriseWikiPage::PAGE_TYPE_SUMMARY, [
            ['planned_topic' => 'Ignorert for sammendrag'],
        ])['properties']['best_practice_review'];

        $this->assertSame(1, $review['maxItems']);
        $this->assertSame([WikiPageContentAiClient::REVIEW_TOPIC_WHOLE_PAGE], $review['items']['properties']['planned_topic']['enum']);
    }

    public function test_a_page_without_a_planned_section_contract_reviews_itself_as_a_whole(): void
    {
        $review = $this->schemaFor(EnterpriseWikiPage::PAGE_TYPE_CONCEPT, [])['properties']['best_practice_review'];

        $this->assertSame([WikiPageContentAiClient::REVIEW_TOPIC_WHOLE_PAGE], $review['items']['properties']['planned_topic']['enum']);
    }

    public function test_the_prompt_states_that_finding_nothing_is_a_complete_answer(): void
    {
        $client = app(WikiPageContentAiClient::class);
        $prompt = (new ReflectionClass($client))->getMethod('developerPrompt')->invoke(
            $client,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'Norwegian',
            [['planned_topic' => 'Risikostyring', 'section_index' => 0]],
        );

        $this->assertStringContainsString('gap_found=false is a legitimate, expected and complete answer', $prompt);
        $this->assertStringContainsString('gap_found=true means you also wrote at least one concrete best_practice block', $prompt);
        $this->assertStringContainsString('never rendered as page text', $prompt);
        $this->assertStringContainsString('Risikostyring', $prompt, 'the reviewable topics are named');

        // The existing threshold is untouched — this task adds observability, not pressure.
        $this->assertStringContainsString('No quota, no minimum, no padding', $prompt);
        $this->assertStringContainsString('Zero best_practice blocks is the correct outcome', $prompt);
    }

    // =========================================================================
    // Reconciliation against what was actually written
    // =========================================================================

    public function test_no_gap_and_no_clause_is_a_clean_assessment(): void
    {
        $result = $this->reconcile(
            [['planned_topic' => 'Risikostyring', 'gap_found' => false, 'assessment' => 'Kilden dekker roller og eskalering.']],
            [$this->block('## Risikostyring', 'structural'), $this->block('Prosjektet skal ha risikoregister.', 'source_based')],
        );

        $this->assertFalse($result[0]['gap_found']);
        $this->assertNull($result[0]['inconsistency']);
        $this->assertSame(0, $result[0]['best_practice_blocks']);
        $this->assertSame('Kilden dekker roller og eskalering.', $result[0]['assessment']);
    }

    public function test_a_gap_backed_by_a_clause_is_a_clean_assessment(): void
    {
        $result = $this->reconcile(
            [['planned_topic' => 'Risikostyring', 'gap_found' => true, 'assessment' => 'Kilden mangler eskaleringsterskel.']],
            [
                $this->block('## Risikostyring', 'structural'),
                $this->block('Prosjektet skal ha risikoregister.', 'source_based'),
                $this->block('Risiko med konsekvensgrad høy skal eskaleres til styringsgruppen.', 'best_practice'),
            ],
        );

        $this->assertTrue($result[0]['gap_found']);
        $this->assertNull($result[0]['inconsistency']);
        $this->assertSame(1, $result[0]['best_practice_blocks']);
    }

    public function test_a_claimed_gap_without_a_clause_is_downgraded_not_failed(): void
    {
        $result = $this->reconcile(
            [['planned_topic' => 'Risikostyring', 'gap_found' => true, 'assessment' => 'Mangler terskel.']],
            [$this->block('## Risikostyring', 'structural'), $this->block('Prosjektet skal ha risikoregister.', 'source_based')],
        );

        $this->assertFalse($result[0]['gap_found'], 'a gap that never reached the page is not a gap this run produced');
        $this->assertSame(
            EnterpriseWikiBestPracticeReviewReconciler::INCONSISTENCY_CLAIMED_GAP_WITHOUT_CLAUSE,
            $result[0]['inconsistency'],
        );
    }

    public function test_a_clause_without_a_claimed_gap_keeps_the_clause(): void
    {
        $blocks = [
            $this->block('## Risikostyring', 'structural'),
            $this->block('Risiko med konsekvensgrad høy skal eskaleres til styringsgruppen.', 'best_practice'),
        ];

        $result = $this->reconcile(
            [['planned_topic' => 'Risikostyring', 'gap_found' => false, 'assessment' => 'Kilden dekker dette.']],
            $blocks,
        );

        $this->assertSame(
            EnterpriseWikiBestPracticeReviewReconciler::INCONSISTENCY_CLAUSE_WITHOUT_CLAIMED_GAP,
            $result[0]['inconsistency'],
        );
        $this->assertTrue($result[0]['gap_found'], 'the content is the stronger signal, so it is read back from the page');
        $this->assertSame(1, $result[0]['best_practice_blocks']);
        // The block itself is untouched — metadata never deletes generated substance.
        $this->assertCount(2, $blocks);
        $this->assertSame('best_practice', $blocks[1]['content_origin']);
    }

    public function test_a_clause_is_attributed_to_its_own_section_only(): void
    {
        $result = $this->reconcile(
            [
                ['planned_topic' => 'Risikostyring', 'gap_found' => false, 'assessment' => 'Dekket.'],
                ['planned_topic' => 'Endringshåndtering', 'gap_found' => true, 'assessment' => 'Mangler CAB.'],
            ],
            [
                $this->block('## Risikostyring', 'structural'),
                $this->block('Risikoregister føres.', 'source_based'),
                $this->block('## Endringshåndtering', 'structural'),
                $this->block('Endringer skal godkjennes av et endringsråd.', 'best_practice'),
            ],
        );

        $this->assertFalse($result[0]['gap_found']);
        $this->assertSame(0, $result[0]['best_practice_blocks']);
        $this->assertTrue($result[1]['gap_found']);
        $this->assertSame(1, $result[1]['best_practice_blocks']);
        $this->assertNull($result[1]['inconsistency']);
    }

    public function test_a_whole_page_review_sees_every_clause_on_the_page(): void
    {
        $result = $this->reconcile(
            [['planned_topic' => WikiPageContentAiClient::REVIEW_TOPIC_WHOLE_PAGE, 'gap_found' => true, 'assessment' => 'Mangler måling.']],
            [$this->block('Tjenestenivå skal måles månedlig.', 'best_practice')],
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        );

        $this->assertTrue($result[0]['gap_found']);
        $this->assertNull($result[0]['inconsistency']);
    }

    public function test_a_response_without_any_review_is_not_read_as_no_gap(): void
    {
        // A stored/legacy version predating the contract. "Not assessed" must stay distinguishable
        // from "assessed, found nothing" — that distinction is the entire point.
        $this->assertSame([], $this->reconcile([], [$this->block('Tekst.', 'source_based')]));
    }

    // =========================================================================
    // The review never becomes page text
    // =========================================================================

    public function test_the_assessment_is_never_part_of_the_page_markdown(): void
    {
        $client = app(WikiPageContentAiClient::class);
        $parsed = (new ReflectionClass($client))->getMethod('parseBlocksResponse')->invoke(
            $client,
            [
                'page' => ['blocks' => [[
                    'markdown' => 'Prosjektet skal ha risikoregister.',
                    'content_origin' => 'source_based',
                    'source_element_keys' => ['paragraph-0'],
                    'source_element_types' => ['paragraph'],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ]]],
                'best_practice_review' => [[
                    'planned_topic' => 'Risikostyring',
                    'gap_found' => false,
                    'assessment' => 'DENNE-TEKSTEN-SKAL-ALDRI-VISES',
                ]],
            ],
            'generation',
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            [['planned_topic' => 'Risikostyring']],
        );

        $this->assertStringNotContainsString('DENNE-TEKSTEN-SKAL-ALDRI-VISES', $parsed['markdown']);
        $this->assertSame('Prosjektet skal ha risikoregister.', $parsed['markdown']);
        $this->assertCount(1, $parsed['blocks']);
        $this->assertArrayNotHasKey('assessment', $parsed['blocks'][0]);
        $this->assertSame('Risikostyring', $parsed['best_practice_review'][0]['planned_topic']);
    }

    // =========================================================================
    // Persistence and the metadata-loss invariant
    // =========================================================================

    public function test_the_review_is_stored_with_the_version_it_assessed(): void
    {
        $page = $this->createPage();
        $review = [['planned_topic' => 'Risikostyring', 'gap_found' => false, 'assessment' => 'Dekket.', 'best_practice_blocks' => 0, 'inconsistency' => null]];

        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => '## Risikostyring',
            'content_blocks_json' => [$this->block('## Risikostyring', 'structural')],
            'best_practice_review_json' => $review,
        ]);

        $this->assertSame($review, $version->fresh()->best_practice_review_json);
    }

    public function test_a_rewrite_that_does_not_reassess_inherits_the_assessment(): void
    {
        // Link/semantic repair, incremental relink and claim repair all write markdown only. They
        // re-assess nothing, so dropping the record there would turn "assessed" back into
        // "never assessed" — the exact state this contract exists to end.
        $page = $this->createPage();
        $writer = app(EnterpriseWikiPageVersionWriter::class);
        $review = [['planned_topic' => 'Risikostyring', 'gap_found' => true, 'assessment' => 'Mangler terskel.', 'best_practice_blocks' => 1, 'inconsistency' => null]];

        $writer->writeNewCurrentVersion($page->id, [
            'content_markdown' => '## Risikostyring',
            'content_blocks_json' => [$this->block('## Risikostyring', 'structural')],
            'best_practice_review_json' => $review,
        ]);

        $repaired = $writer->writeNewCurrentVersion($page->id, [
            'content_markdown' => '## Risikostyring (rettet lenke)',
            'content_blocks_json' => [$this->block('## Risikostyring (rettet lenke)', 'structural')],
        ]);

        $this->assertSame($review, $repaired->fresh()->best_practice_review_json);
    }

    public function test_a_caller_that_brings_a_new_assessment_replaces_the_old_one(): void
    {
        $page = $this->createPage();
        $writer = app(EnterpriseWikiPageVersionWriter::class);

        $writer->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'v1',
            'content_blocks_json' => [$this->block('v1', 'source_based')],
            'best_practice_review_json' => [['planned_topic' => 'A', 'gap_found' => false, 'assessment' => 'Gammel.']],
        ]);

        $regenerated = $writer->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'v2',
            'content_blocks_json' => [$this->block('v2', 'source_based')],
            'best_practice_review_json' => [['planned_topic' => 'A', 'gap_found' => true, 'assessment' => 'Ny.']],
        ]);

        $this->assertSame('Ny.', $regenerated->fresh()->best_practice_review_json[0]['assessment']);
    }

    public function test_a_version_that_never_had_an_assessment_stays_null(): void
    {
        $page = $this->createPage();
        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, [
            'content_markdown' => 'Tekst.',
            'content_blocks_json' => [$this->block('Tekst.', 'source_based')],
        ]);

        $this->assertNull($version->fresh()->best_practice_review_json);
    }

    // =========================================================================
    // Findings and summary
    // =========================================================================

    public function test_an_assessment_without_gaps_is_counted_but_is_never_a_finding(): void
    {
        [$run, $user] = $this->runWithAssessedPage([
            ['planned_topic' => 'Risikostyring', 'gap_found' => false, 'assessment' => 'Dekket.', 'best_practice_blocks' => 0, 'inconsistency' => null],
            ['planned_topic' => 'Endringshåndtering', 'gap_found' => true, 'assessment' => 'Mangler CAB.', 'best_practice_blocks' => 1, 'inconsistency' => null],
        ]);

        $result = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false);

        $this->assertSame(
            ['reviewed' => 2, 'gaps_found' => 1, 'pages_assessed' => 1, 'pages_without_assessment' => 0],
            $result['summary']['best_practice_review'],
        );

        foreach ($result['findings'] as $finding) {
            $this->assertStringNotContainsString('Dekket.', json_encode($finding, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_technical_inconsistencies_are_never_user_facing(): void
    {
        [$run, $user] = $this->runWithAssessedPage([[
            'planned_topic' => 'Risikostyring',
            'gap_found' => false,
            'assessment' => 'Dekket.',
            'best_practice_blocks' => 0,
            'inconsistency' => EnterpriseWikiBestPracticeReviewReconciler::INCONSISTENCY_CLAIMED_GAP_WITHOUT_CLAUSE,
        ]]);

        $result = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, true);

        $this->assertStringNotContainsString(
            EnterpriseWikiBestPracticeReviewReconciler::INCONSISTENCY_CLAIMED_GAP_WITHOUT_CLAUSE,
            json_encode($result['findings'], JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame(1, $result['summary']['best_practice_review']['reviewed']);
    }

    public function test_a_page_generated_before_the_contract_is_reported_as_unassessed(): void
    {
        [$run, $user] = $this->runWithAssessedPage(null);

        $summary = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false)['summary']['best_practice_review'];

        $this->assertSame(0, $summary['reviewed']);
        $this->assertSame(1, $summary['pages_without_assessment']);
    }

    public function test_best_practice_claims_and_approval_are_untouched(): void
    {
        [$run, $user, $page, $version] = $this->runWithAssessedPage(
            [['planned_topic' => 'Risikostyring', 'gap_found' => true, 'assessment' => 'Mangler terskel.', 'best_practice_blocks' => 1, 'inconsistency' => null]],
            withBestPracticeClaim: true,
        );

        $result = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run, $user, false);
        $statuses = array_column($result['findings'], 'status');

        $this->assertContains('pending_review', $statuses, 'a pending suggestion is still a decision a human owes');
        $this->assertSame(1, $result['summary']['best_practice_pending']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string, mixed> */
    private function schemaFor(string $pageType, array $plannedSections): array
    {
        $method = (new ReflectionClass(WikiPageContentAiClient::class))->getMethod('schema');

        return $method->invoke(null, [], $plannedSections, $pageType);
    }

    /**
     * @param  list<array<string, mixed>>  $review
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function reconcile(array $review, array $blocks, string $pageType = EnterpriseWikiPage::PAGE_TYPE_CONCEPT): array
    {
        return app(EnterpriseWikiBestPracticeReviewReconciler::class)->reconcile($review, $blocks, $pageType);
    }

    /** @return array<string, mixed> */
    private function block(string $markdown, string $origin): array
    {
        return [
            'block_key' => 'block-'.substr(md5($markdown), 0, 8),
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => $origin,
            'best_practice_reason' => $origin === 'best_practice' ? 'Kilden mangler dette.' : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $review  null = a version predating the contract
     * @return array{0: EnterpriseWikiIngestRun, 1: User, 2: EnterpriseWikiPage, 3: EnterpriseWikiPageVersion}
     */
    private function runWithAssessedPage(?array $review, bool $withBestPracticeClaim = false): array
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createRun($customer, $document);
        $page = $this->createPage($customer);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        $blocks = [$this->block('## Risikostyring', 'structural')];

        if ($withBestPracticeClaim) {
            $blocks[] = $this->block('Risiko skal eskaleres til styringsgruppen.', 'best_practice');
        }

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'best_practice_review_json' => $review,
            'generated_by_model' => 'gpt-5',
        ]);

        if ($withBestPracticeClaim) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => 'Risiko skal eskaleres til styringsgruppen.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'content_block_key' => $blocks[1]['block_key'],
                'position_order' => 1,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => now(),
            ]);
        }

        return [$run, $user, $page, $version];
    }

    private function createPage(?Customer $customer = null): EnterpriseWikiPage
    {
        $customer ??= $this->createCustomer();

        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'risikostyring-'.Str::lower(Str::random(6)),
            'title' => 'Risikostyring',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Review Contract AS',
            'slug' => 'review-contract-as-'.Str::lower(Str::random(6)),
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
            'email' => Str::lower(Str::random(8)).'@review-contract-test.invalid',
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
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst for vurderingskontrakten.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
        ]);
    }
}
