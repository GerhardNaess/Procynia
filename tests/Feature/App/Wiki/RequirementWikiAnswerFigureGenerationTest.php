<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * What generation does with the model's figure choices: they become their own persisted column,
 * never part of answer_text, and a later hand-edit of the text leaves them alone.
 */
class RequirementWikiAnswerFigureGenerationTest extends TestCase
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

    private function figure(string $imageKey = 'img3', int $figureNumber = 4): array
    {
        return [
            'figure_ref' => 'fig:63:'.$imageKey,
            'document_id' => 63,
            'source_image_key' => $imageKey,
            'caption' => null,
            'alt_text' => '',
            'description' => 'Beskrivelse.',
            'figure_number' => $figureNumber,
            'page_reference' => 'Masterdata.docx → Figur '.$figureNumber,
            'block_markdown' => '**Figur '.$figureNumber.'**',
        ];
    }

    private function researchContext(SavedNoticeAiRequirement $requirement, int $pageId, array $figures): array
    {
        return [
            'requirement' => ['id' => $requirement->id, 'text' => $requirement->requirement_text],
            'initial_candidates' => [],
            'research_rounds' => [],
            'pages' => [[
                'page_id' => $pageId,
                'title' => 'Samhandling',
                'page_type' => 'concept',
                'slug' => 'samhandling',
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'content_mode' => 'full',
                'content_markdown' => "# Samhandling\n\nInnhold.",
                'selected_headings' => [],
                'figures' => $figures,
                'supporting_claim_ids' => [],
                'source_based_claim_ids' => [],
                'best_practice_claim_ids' => [],
                'round_read' => 1,
            ]],
            'limits' => [
                'catalog_size' => 1, 'rounds_used' => 1, 'pages_read' => 1, 'context_size' => 50,
                'stop_reason' => 'enough_context', 'max_rounds' => 3, 'max_pages' => 8, 'max_context_size' => 24000,
            ],
        ];
    }

    private function createRequirement(Customer $customer): SavedNoticeAiRequirement
    {
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'WIKI-FIG-'.Str::random(8),
            'title' => 'Wiki figure test case',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/wiki-figure-test',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-04-01 00:00:00',
            'deadline' => '2026-05-01 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Beskriv Leverandørens samhandlingsmodell.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function mockPipeline(array $context, array $sections): void
    {
        $this->mock(RequirementWikiResearchService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('research')->once()->andReturn($context));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')->once()->andReturn(['answer_sections' => $sections]));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')->once()->andReturn(array_map(
                static fn (array $section): array => [
                    'section_key' => $section['key'],
                    'alignment_status' => 'aligned',
                    'supporting_page_ids' => $section['used_page_ids'],
                    'supported_points' => [],
                    'uncovered_points' => [],
                    'conflict_summary' => null,
                    'review_note' => null,
                ],
                $sections,
            )));
    }

    public function test_a_chosen_figure_is_persisted_as_a_reference_outside_the_answer_text(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [
                ['key' => 'S1', 'heading' => 'Innledning', 'text' => 'Første avsnitt.', 'used_page_ids' => [$page->id], 'figure_refs' => []],
                ['key' => 'S2', 'heading' => 'Organisering', 'text' => 'Andre avsnitt.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img3']],
            ],
        );

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame("Første avsnitt.\n\nAndre avsnitt.", $answer->answer_text);
        $this->assertStringNotContainsString('fig:63:img3', (string) $answer->answer_text);
        $this->assertCount(1, $answer->answer_figures);
        $this->assertSame('fig:63:img3', $answer->answer_figures[0]['figure_ref']);
        $this->assertSame($page->id, $answer->answer_figures[0]['page_id']);
        $this->assertSame('S2', $answer->answer_figures[0]['section_key']);
        $this->assertSame(1, $answer->answer_figures[0]['section_index']);
    }

    public function test_an_answer_that_chose_no_figures_stores_an_empty_list(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Svar uten figurer.', 'used_page_ids' => [$page->id], 'figure_refs' => []]],
        );

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame([], $answer->answer_figures);
        $this->assertSame('Svar uten figurer.', $answer->answer_text);
    }

    public function test_the_same_figure_is_never_stored_twice(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [
                ['key' => 'S1', 'heading' => 'A', 'text' => 'Ett.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img3']],
                ['key' => 'S2', 'heading' => 'B', 'text' => 'To.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img3']],
            ],
        );

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertCount(1, $answer->answer_figures);
        $this->assertSame('S1', $answer->answer_figures[0]['section_key']);
    }

    public function test_editing_the_answer_text_leaves_the_figure_references_untouched(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Generert svar.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img3']]],
        );

        $service = app(RequirementWikiAnswerService::class);
        $service->generate($requirement, $customer->id, 'no');

        $edited = $service->updateAnswerText($requirement, 'Et helt omskrevet svar fra tilbudslederen.');

        $this->assertSame('Et helt omskrevet svar fra tilbudslederen.', $edited->answer_text);
        $this->assertCount(1, $edited->answer_figures);
        $this->assertSame('fig:63:img3', $edited->answer_figures[0]['figure_ref']);
    }

    public function test_regenerating_replaces_the_figure_references_along_with_the_answer(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Første generering.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img3']]],
        );
        // Resolved after mocking so the service is built with the mocked clients, not the real ones.
        $first = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
        $this->assertSame('fig:63:img3', $first->answer_figures[0]['figure_ref']);

        // Second run: the model picks a different figure from the same page.
        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure(), $this->figure('img8', 9)]),
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Andre generering.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:63:img8']]],
        );
        $second = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertCount(1, $second->answer_figures);
        $this->assertSame('fig:63:img8', $second->answer_figures[0]['figure_ref']);
        $this->assertSame('Andre generering.', $second->answer_text);
    }

    public function test_a_ref_for_a_figure_that_was_never_offered_is_refused_at_persistence_too(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Samhandling', 'Innhold.');

        // The AI client is mocked here, so this bypasses its validation on purpose: the persistence
        // step must refuse an unoffered ref on its own rather than relying on an upstream check.
        $this->mockPipeline(
            $this->researchContext($requirement, $page->id, [$this->figure()]),
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.', 'used_page_ids' => [$page->id], 'figure_refs' => ['fig:999:img1']]],
        );

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame([], $answer->answer_figures);
    }
}
