<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Customer;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify RequirementWikiAnswerService's own orchestration — combining a (mocked)
 * research context with a (mocked) final-answer result, assembling answer_text from validated
 * sections, building the sources/research_trace/engine_version persistence payload, and never
 * touching the existing answer-draft flow. Research (page discovery) and answer-writing behavior
 * themselves are covered by RequirementWikiResearchServiceTest/RequirementWikiAnswerAiClientTest.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_it_persists_research_trace_and_engine_version(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svaret.', 'page_ids' => [$page->id]]],
            'missing_summary' => null,
            'used_page_ids' => [$page->id],
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertIsArray($answer->research_trace);
        $this->assertArrayHasKey('research', $answer->research_trace);
        $this->assertArrayHasKey('answer', $answer->research_trace);
        $this->assertSame(RequirementWikiAnswerService::ENGINE_VERSION, $answer->engine_version);
    }

    public function test_old_answers_without_the_new_fields_can_still_be_loaded(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');

        DB::table('saved_notice_ai_requirement_wiki_answers')->insert([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => 'full',
            'answer_text' => 'Gammelt svar fra før research_trace fantes.',
            'sources' => json_encode([['enterprise_wiki_page_id' => 1, 'page_title' => 'Gammel side', 'page_slug' => 'gammel', 'page_type' => 'article', 'claim_ids' => [1]]]),
            'research_trace' => null,
            'engine_version' => null,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loaded = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->firstOrFail();

        $this->assertSame('Gammelt svar fra før research_trace fantes.', $loaded->answer_text);
        $this->assertNull($loaded->research_trace);
        $this->assertNull($loaded->engine_version);
        $this->assertIsArray($loaded->sources);
    }

    public function test_full_coverage_assembles_the_answer_text_from_validated_sections(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            'coverage_status' => 'full',
            'answer_sections' => [
                ['text' => 'Første avsnitt.', 'page_ids' => [$page->id]],
                ['text' => 'Andre avsnitt.', 'page_ids' => [$page->id]],
            ],
            'missing_summary' => null,
            'used_page_ids' => [$page->id],
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('full', $answer->coverage_status);
        $this->assertSame("Første avsnitt.\n\nAndre avsnitt.", $answer->answer_text);
        $this->assertCount(1, $answer->sources);
        $this->assertSame($page->id, $answer->sources[0]['enterprise_wiki_page_id']);
    }

    public function test_partial_coverage_keeps_answer_text_and_missing_summary(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            'coverage_status' => 'partial',
            'answer_sections' => [['text' => 'Delvis svar.', 'page_ids' => [$page->id]]],
            'missing_summary' => 'Wiki-en dekker ikke responstider.',
            'used_page_ids' => [$page->id],
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('partial', $answer->coverage_status);
        $this->assertSame('Delvis svar.', $answer->answer_text);
        $this->assertSame('Wiki-en dekker ikke responstider.', $answer->missing_summary);
    }

    public function test_none_coverage_from_zero_pages_read_never_calls_the_answer_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [], 'no_relevant_candidates'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('none', $answer->coverage_status);
        $this->assertNull($answer->answer_text);
        $this->assertSame([], $answer->sources);
    }

    public function test_none_coverage_from_the_answer_client_forces_null_answer_text(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            'coverage_status' => 'none',
            'answer_sections' => [],
            'missing_summary' => null,
            'used_page_ids' => [],
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('none', $answer->coverage_status);
        $this->assertNull($answer->answer_text);
        $this->assertSame([], $answer->sources);
    }

    public function test_it_never_reads_or_writes_the_existing_answer_draft_columns(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $requirement->forceFill([
            'answer_draft_text' => 'Eksisterende svarutkast som aldri skal endres.',
            'answer_draft_generated_at' => now(),
        ])->save();

        $this->mockResearchService($this->fakeResearchContext($requirement, [], 'no_relevant_candidates'));

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $requirement->refresh();
        $this->assertSame('Eksisterende svarutkast som aldri skal endres.', $requirement->answer_draft_text);
    }

    public function test_regenerating_updates_the_same_row_instead_of_creating_a_duplicate(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [], 'no_relevant_candidates'));
        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Nytt svar.', 'page_ids' => [$page->id]]],
            'missing_summary' => null,
            'used_page_ids' => [$page->id],
        ]);
        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $count = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->count();
        $this->assertSame(1, $count);
        $this->assertSame('Nytt svar.', $requirement->wikiAnswer()->first()->answer_text);
    }

    public function test_sources_carry_discovery_provenance_for_pages_actually_cited(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $directPage = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $linkedPage = $this->createWikiPageWithVersion($customer, 'Continual Improvement', 'Innhold.');

        $pages = [
            $this->fakePage($directPage->id, 'Problem Management'),
            array_merge($this->fakePage($linkedPage->id, 'Continual Improvement'), [
                'selection_type' => 'wikilink',
                'discovered_from_page_id' => $directPage->id,
                'discovered_from_title' => 'Problem Management',
                'link_direction' => 'outgoing',
            ]),
        ];

        $this->mockResearchService($this->fakeResearchContext($requirement, $pages));
        $this->mockAnswerClient([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar.', 'page_ids' => [$directPage->id, $linkedPage->id]]],
            'missing_summary' => null,
            'used_page_ids' => [$directPage->id, $linkedPage->id],
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $byId = collect($answer->sources)->keyBy('enterprise_wiki_page_id');
        $this->assertSame('direct_search', $byId[$directPage->id]['selection_type']);
        $this->assertSame('wikilink', $byId[$linkedPage->id]['selection_type']);
        $this->assertSame('Problem Management', $byId[$linkedPage->id]['discovered_from_title']);
    }

    public function test_it_throws_when_wiki_ai_generation_is_disabled_and_candidates_exist(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        // Real collaborators here (not mocked) — the exception must originate from the real
        // RequirementWikiResearchService, propagated unchanged through the answer service.
        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    private function mockResearchService(array $context): void
    {
        $this->mock(RequirementWikiResearchService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('research')->once()->andReturn($context));
    }

    private function mockAnswerClient(array $result): void
    {
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')->once()->andReturn($result));
    }

    private function fakePage(int $pageId, string $title): array
    {
        return [
            'page_id' => $pageId,
            'title' => $title,
            'page_type' => 'concept',
            'slug' => Str::slug($title),
            'selection_type' => 'direct_search',
            'discovered_from_page_id' => null,
            'discovered_from_title' => null,
            'link_direction' => null,
            'content_mode' => 'full',
            'content_markdown' => "# {$title}\n\nInnhold om {$title}.",
            'selected_headings' => [],
            'supporting_claim_ids' => [],
            'round_read' => 1,
        ];
    }

    private function fakeResearchContext(SavedNoticeAiRequirement $requirement, array $pages, ?string $stopReason = 'enough_context'): array
    {
        return [
            'requirement' => ['id' => $requirement->id, 'text' => $requirement->requirement_text],
            'initial_candidates' => [],
            'research_rounds' => [],
            'pages' => $pages,
            'limits' => [
                'catalog_size' => count($pages),
                'rounds_used' => 1,
                'pages_read' => count($pages),
                'context_size' => array_sum(array_map(static fn (array $page): int => mb_strlen($page['content_markdown'], 'UTF-8'), $pages)),
                'stop_reason' => $stopReason,
                'max_rounds' => 3,
                'max_pages' => 8,
                'max_context_size' => 24000,
            ],
        ];
    }

    private function createRequirement(Customer $customer, string $requirementText): SavedNoticeAiRequirement
    {
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'WIKI-ANSWER-'.Str::random(8),
            'title' => 'Wiki answer test case',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/wiki-answer-test',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-04-01 00:00:00',
            'deadline' => '2026-05-01 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_identifier' => '1.1',
            'requirement_text' => $requirementText,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
