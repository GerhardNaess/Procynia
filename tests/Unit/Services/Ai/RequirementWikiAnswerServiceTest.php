<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify RequirementWikiAnswerService's own orchestration — the balanced-model correction
 * that lets an expert draft (RequirementWikiAnswerAiClient) supplement Wiki knowledge with best
 * practice, classifies each section's grounding via RequirementWikiAlignmentAiClient, computes
 * coverage_status itself (never trusting a self-reported value from either AI client), and runs at
 * most one automatic revision pass via RequirementWikiAnswerRevisionAiClient when — and only when —
 * a section is flagged possible_conflict. Research (page discovery) and per-client prompt/schema
 * behavior are covered by RequirementWikiResearchServiceTest / RequirementWiki*AiClientTest.
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

    public function test_it_persists_research_trace_alignment_trace_and_engine_version(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Svaret.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertIsArray($answer->research_trace);
        $this->assertArrayHasKey('research', $answer->research_trace);
        $this->assertArrayHasKey('answer', $answer->research_trace);
        $this->assertIsArray($answer->alignment_trace);
        $this->assertArrayHasKey('sections', $answer->alignment_trace);
        $this->assertSame(RequirementWikiAnswerService::ENGINE_VERSION, $answer->engine_version);
    }

    /**
     * AI-to-Wiki consolidation: generate()'s optional $caseInstructions parameter (the owning
     * SavedNotice's ai_instructions) must reach RequirementWikiAnswerAiClient::generateAnswer()
     * unchanged, as its final positional argument — see that client's own tests for how it is framed
     * in the prompt as a style-only directive.
     */
    public function test_case_instructions_are_forwarded_to_the_answer_ai_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn ($identifier, $text, $pages, $languageCode, $caseInstructions) => $caseInstructions === 'Skriv formelt og presist.')
            ->andReturn(['answer_sections' => [$this->section('S1', 'Svaret.', [$page->id])]]));
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no', null, 'Skriv formelt og presist.');
    }

    public function test_no_case_instructions_forwards_null_to_the_answer_ai_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn ($identifier, $text, $pages, $languageCode, $caseInstructions) => $caseInstructions === null)
            ->andReturn(['answer_sections' => [$this->section('S1', 'Svaret.', [$page->id])]]));
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    /**
     * AI-to-Wiki consolidation (Wiki-answer-as-sole-engine): generate()'s optional
     * $requirementUserPrompt parameter (feature parity with the legacy answer-draft flow's
     * user_answer_prompt) must reach RequirementWikiAnswerAiClient::generateAnswer() unchanged, as
     * its final positional argument, applied after $caseInstructions.
     */
    public function test_requirement_user_prompt_is_forwarded_to_the_answer_ai_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn ($identifier, $text, $pages, $languageCode, $caseInstructions, $requirementUserPrompt) => $caseInstructions === 'Skriv formelt.'
                && $requirementUserPrompt === 'Fokuser spesielt på ansvarsfordeling.')
            ->andReturn(['answer_sections' => [$this->section('S1', 'Svaret.', [$page->id])]]));
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate(
            $requirement,
            $customer->id,
            'no',
            null,
            'Skriv formelt.',
            'Fokuser spesielt på ansvarsfordeling.',
        );
    }

    public function test_no_requirement_user_prompt_forwards_null_to_the_answer_ai_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn ($identifier, $text, $pages, $languageCode, $caseInstructions, $requirementUserPrompt) => $requirementUserPrompt === null)
            ->andReturn(['answer_sections' => [$this->section('S1', 'Svaret.', [$page->id])]]));
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    /**
     * AI-to-Wiki consolidation: updateAnswerText() lets a user hand-edit an already-generated Wiki
     * answer in place — mirrors RequirementAnswerDraftService::updateAnswerDraft().
     */
    public function test_update_answer_text_persists_an_edit_to_an_existing_wiki_answer(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Opprinnelig svar.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $updated = app(RequirementWikiAnswerService::class)->updateAnswerText($requirement, 'Redigert svar.');

        $this->assertSame('Redigert svar.', $updated->answer_text);
        $this->assertSame(
            'Redigert svar.',
            SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->firstOrFail()->answer_text,
        );
    }

    public function test_update_answer_text_throws_when_no_wiki_answer_exists_yet(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerService::class)->updateAnswerText($requirement, 'Redigert svar.');
    }

    public function test_update_answer_text_throws_when_the_text_is_blank(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Opprinnelig svar.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerService::class)->updateAnswerText($requirement, '   ');
    }

    public function test_old_answers_without_the_new_fields_can_still_be_loaded(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');

        DB::table('saved_notice_ai_requirement_wiki_answers')->insert([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => 'full',
            'answer_text' => 'Gammelt svar fra før alignment_trace fantes.',
            'sources' => json_encode([['enterprise_wiki_page_id' => 1, 'page_title' => 'Gammel side', 'page_slug' => 'gammel', 'page_type' => 'article', 'claim_ids' => [1]]]),
            'research_trace' => null,
            'engine_version' => null,
            'alignment_trace' => null,
            'has_possible_conflict' => null,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loaded = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->firstOrFail();

        $this->assertSame('Gammelt svar fra før alignment_trace fantes.', $loaded->answer_text);
        $this->assertNull($loaded->research_trace);
        $this->assertNull($loaded->engine_version);
        $this->assertNull($loaded->alignment_trace);
        $this->assertNull($loaded->has_possible_conflict);
        $this->assertIsArray($loaded->sources);
    }

    public function test_all_sections_aligned_gives_full_coverage(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Første avsnitt.', [$page->id]),
            $this->section('S2', 'Andre avsnitt.', [$page->id]),
        ]);
        $this->mockAlignmentClient([
            $this->assessment('S1', 'aligned', [$page->id]),
            $this->assessment('S2', 'aligned', [$page->id]),
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('full', $answer->coverage_status);
        $this->assertCount(2, $answer->alignment_trace['sections']);
        $this->assertSame("Første avsnitt.\n\nAndre avsnitt.", $answer->answer_text);
        $this->assertFalse($answer->has_possible_conflict);
        $this->assertCount(1, $answer->sources);
        $this->assertSame($page->id, $answer->sources[0]['enterprise_wiki_page_id']);
    }

    public function test_a_three_section_answer_can_end_after_the_last_needed_section_without_a_summary_block(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv en prosesskjede.');
        $page = $this->createWikiPageWithVersion($customer, 'Prosess', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Prosess')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Første fagavsnitt.', [$page->id]),
            $this->section('S2', 'Andre fagavsnitt.', [$page->id]),
            $this->section('S3', 'Tredje fagavsnitt.', [$page->id]),
        ]);
        $this->mockAlignmentClient([
            $this->assessment('S1', 'aligned', [$page->id]),
            $this->assessment('S2', 'aligned', [$page->id]),
            $this->assessment('S3', 'aligned', [$page->id]),
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('full', $answer->coverage_status);
        $this->assertSame(3, count($answer->alignment_trace['sections']));
        $this->assertSame(
            "Første fagavsnitt.\n\nAndre fagavsnitt.\n\nTredje fagavsnitt.",
            $answer->answer_text,
        );
    }

    public function test_a_four_section_answer_can_end_after_the_last_needed_section_without_a_summary_block(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv en utvidet prosesskjede.');
        $page = $this->createWikiPageWithVersion($customer, 'Prosess', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Prosess')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Første fagavsnitt.', [$page->id]),
            $this->section('S2', 'Andre fagavsnitt.', [$page->id]),
            $this->section('S3', 'Tredje fagavsnitt.', [$page->id]),
            $this->section('S4', 'Fjerde fagavsnitt.', [$page->id]),
        ]);
        $this->mockAlignmentClient([
            $this->assessment('S1', 'aligned', [$page->id]),
            $this->assessment('S2', 'aligned', [$page->id]),
            $this->assessment('S3', 'aligned', [$page->id]),
            $this->assessment('S4', 'aligned', [$page->id]),
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('full', $answer->coverage_status);
        $this->assertCount(4, $answer->alignment_trace['sections']);
        $this->assertSame(
            "Første fagavsnitt.\n\nAndre fagavsnitt.\n\nTredje fagavsnitt.\n\nFjerde fagavsnitt.",
            $answer->answer_text,
        );
    }

    public function test_a_mix_of_aligned_and_best_practice_gives_partial_coverage(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Wiki-forankret avsnitt.', [$page->id]),
            $this->section('S2', 'Beste praksis-avsnitt.', []),
        ]);
        $this->mockAlignmentClient([
            $this->assessment('S1', 'aligned', [$page->id]),
            $this->assessment('S2', 'best_practice'),
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('partial', $answer->coverage_status);
    }

    public function test_only_best_practice_sections_give_none_coverage(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Beste praksis-svar.', [])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'best_practice')]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('none', $answer->coverage_status);
    }

    public function test_none_coverage_never_forces_the_answer_text_to_null(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Et fortsatt nyttig ekspertutkast basert på beste praksis.', [])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'best_practice')]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('none', $answer->coverage_status);
        $this->assertSame('Et fortsatt nyttig ekspertutkast basert på beste praksis.', $answer->answer_text);
        $this->assertNotNull($answer->answer_text);
    }

    public function test_zero_pages_read_still_generates_a_best_practice_draft_via_the_answer_client(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [], 'no_relevant_candidates'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn (string $identifier, string $text, array $pages, string $language): bool => $pages === [])
            ->andReturn(['answer_sections' => [$this->section('S1', 'Beste praksis uten Wiki-treff.', [])]]));
        // No Wiki pages were read at all — alignment is deterministic (best_practice for every
        // section), so the alignment AI client must never be called for this run.
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('none', $answer->coverage_status);
        $this->assertCount(1, $answer->alignment_trace['sections']);
        $this->assertSame('Beste praksis uten Wiki-treff.', $answer->answer_text);
        $this->assertSame([], $answer->sources);
        $this->assertFalse($answer->has_possible_conflict);
    }

    public function test_possible_conflict_triggers_exactly_one_revision_and_one_re_alignment(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv ansvar for prosessen.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Etter etablering eier Kunden prosessen videre.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Leverandøren eier prosessen videre.', [])]);

        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->twice()
            ->andReturn(
                [$this->assessment('S1', 'possible_conflict', [], [], [], 'Svaret sier Leverandøren eier prosessen; Wiki-en sier Kunden gjør det.')],
                [$this->assessment('S1', 'aligned', [$page->id])],
            ));

        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('reviseSections')
            ->once()
            ->andReturn(['S1' => ['heading' => '', 'text' => 'Kunden eier prosessen videre etter etablering.', 'used_page_ids' => [$page->id]]]));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('Kunden eier prosessen videre etter etablering.', $answer->answer_text);
        $this->assertSame('full', $answer->coverage_status);
        $this->assertFalse($answer->has_possible_conflict);
        $this->assertTrue($answer->alignment_trace['sections'][0]['revised']);
        $this->assertSame('possible_conflict', $answer->alignment_trace['sections'][0]['alignment_status_before_revision']);
    }

    public function test_a_conflict_that_persists_after_revision_is_kept_and_flagged(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv ansvar for prosessen.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Etter etablering eier Kunden prosessen videre.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Leverandøren eier prosessen videre.', [])]);

        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->twice()
            ->andReturn(
                [$this->assessment('S1', 'possible_conflict', [], [], [], 'Første konfliktbeskrivelse.')],
                [$this->assessment('S1', 'possible_conflict', [], [], [], 'Fortsatt konflikt etter revisjon.')],
            ));

        // Even though the revision did not resolve the conflict, exactly one revision call is
        // made — there is no repair loop.
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('reviseSections')
            ->once()
            ->andReturn(['S1' => ['heading' => '', 'text' => 'Justert tekst som fortsatt kan avvike.', 'used_page_ids' => []]]));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertTrue($answer->has_possible_conflict);
        $this->assertSame('Justert tekst som fortsatt kan avvike.', $answer->answer_text);
        $this->assertSame('possible_conflict', $answer->alignment_trace['sections'][0]['alignment_status']);
        $this->assertNotNull($answer->alignment_trace['sections'][0]['conflict_summary']);
    }

    public function test_best_practice_sections_never_trigger_a_revision(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Beste praksis.', [])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'best_practice')]);
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    public function test_partially_aligned_sections_never_trigger_a_revision(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Delvis forankret.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'partially_aligned', [$page->id])]);
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));

        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');
    }

    public function test_only_conflicted_sections_are_sent_to_revision_and_others_are_preserved(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv prosessene.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Etter etablering eier Kunden prosessen videre.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Uendret avsnitt som skal bevares.', [$page->id]),
            $this->section('S2', 'Leverandøren eier prosessen videre.', []),
        ]);

        $capturedRevisionInput = null;
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->twice()
            ->andReturn(
                [
                    $this->assessment('S1', 'aligned', [$page->id]),
                    $this->assessment('S2', 'possible_conflict', [], [], [], 'Motstrid om eierskap.'),
                ],
                [
                    $this->assessment('S1', 'aligned', [$page->id]),
                    $this->assessment('S2', 'aligned', [$page->id]),
                ],
            ));

        $this->mock(RequirementWikiAnswerRevisionAiClient::class, function (MockInterface $mock) use (&$capturedRevisionInput, $page): void {
            $mock->shouldReceive('reviseSections')
                ->once()
                ->andReturnUsing(function (string $identifier, string $text, array $sectionsToRevise) use (&$capturedRevisionInput, $page): array {
                    $capturedRevisionInput = $sectionsToRevise;

                    return ['S2' => ['heading' => '', 'text' => 'Kunden eier prosessen videre.', 'used_page_ids' => [$page->id]]];
                });
        });

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertCount(1, $capturedRevisionInput);
        $this->assertSame('S2', $capturedRevisionInput[0]['key']);
        $this->assertSame("Uendret avsnitt som skal bevares.\n\nKunden eier prosessen videre.", $answer->answer_text);
    }

    public function test_a_possible_conflict_alongside_an_aligned_section_does_not_by_itself_force_none(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv prosessene.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([
            $this->section('S1', 'Wiki-forankret avsnitt.', [$page->id]),
            $this->section('S2', 'Mulig avvikende avsnitt.', []),
        ]);

        // Revision resolves S2 to aligned, so the final gradable set is [aligned, aligned] -> full,
        // and has_possible_conflict is false — this exercises the "no residual conflict" branch of
        // the same rule the two dedicated conflict tests above exercise for the opposite outcome.
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->twice()
            ->andReturn(
                [
                    $this->assessment('S1', 'aligned', [$page->id]),
                    $this->assessment('S2', 'possible_conflict', [], [], [], 'Mulig avvik.'),
                ],
                [
                    $this->assessment('S1', 'aligned', [$page->id]),
                    $this->assessment('S2', 'aligned', [$page->id]),
                ],
            ));

        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('reviseSections')
            ->once()
            ->andReturn(['S2' => ['heading' => '', 'text' => 'Korrigert avsnitt.', 'used_page_ids' => [$page->id]]]));

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('full', $answer->coverage_status);
        $this->assertFalse($answer->has_possible_conflict);
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
        $this->mockAnswerClient([$this->section('S1', 'Beste praksis.', [])]);

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
        $this->mockAnswerClient([$this->section('S1', 'Første generering.', [])]);
        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Nytt svar.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);
        app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $count = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->count();
        $this->assertSame(1, $count);
        $this->assertSame('Nytt svar.', $requirement->wikiAnswer()->first()->answer_text);
    }

    public function test_regenerating_a_stale_answer_clears_the_stale_markers(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold om Problem Management.');

        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => 'Gammelt svar.',
            'sources' => [[
                'enterprise_wiki_page_id' => $page->id,
                'page_title' => $page->title,
                'page_slug' => $page->slug,
                'page_type' => $page->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [],
            ]],
            'research_trace' => ['research' => ['pages' => []], 'answer' => ['answer_sections' => []]],
            'alignment_trace' => ['sections' => [], 'coverage_status' => 'full', 'has_possible_conflict' => false, 'revision' => ['attempted' => false, 'section_keys' => []]],
            'has_possible_conflict' => false,
            'stale_at' => now(),
            'stale_reason' => SavedNoticeAiRequirementWikiAnswer::STALE_REASON_SOURCE_DOCUMENT_DELETED,
            'stale_context' => ['deleted_document_name' => 'deleted-source.pdf'],
            'generated_at' => now(),
        ]);

        $this->mockResearchService($this->fakeResearchContext($requirement, [$this->fakePage($page->id, 'Problem Management')]));
        $this->mockAnswerClient([$this->section('S1', 'Nytt svar.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertNull($answer->stale_at);
        $this->assertNull($answer->stale_reason);
        $this->assertNull($answer->stale_context);
        $this->assertSame('Nytt svar.', $answer->answer_text);
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
        $this->mockAnswerClient([$this->section('S1', 'Svar.', [$directPage->id, $linkedPage->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$directPage->id, $linkedPage->id])]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $byId = collect($answer->sources)->keyBy('enterprise_wiki_page_id');
        $this->assertSame('direct_search', $byId[$directPage->id]['selection_type']);
        $this->assertSame('wikilink', $byId[$linkedPage->id]['selection_type']);
        $this->assertSame('Problem Management', $byId[$linkedPage->id]['discovered_from_title']);
    }

    /**
     * v0.9 provenance-gap closure — acceptance (1): a pure source_based answer shows documented
     * information and gets a source_based provenance_type, and its citing page carries a valid
     * source_based claim marker (has_source_based_claims=true).
     */
    public function test_a_pure_source_based_section_is_marked_source_based_with_valid_citations(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $claim = $this->createWikiClaim($page, 'Problem Management rapporteres månedlig.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);

        $this->mockResearchService($this->fakeResearchContext($requirement, [
            $this->fakePage($page->id, 'Problem Management', [$claim->id], []),
        ]));
        $this->mockAnswerClient([$this->section('S1', 'Dokumentert svar.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('source_based', $answer->alignment_trace['sections'][0]['provenance_type']);
        $this->assertTrue($answer->alignment_trace['has_source_based_support']);
        $this->assertTrue($answer->sources[0]['has_source_based_claims']);
        $this->assertFalse($answer->sources[0]['has_best_practice_claims']);
    }

    /**
     * v0.9 provenance-gap closure — acceptance (2)+(5): an answer grounded only in best_practice
     * claims is allowed but is clearly marked as a best-practice suggestion, never as documented
     * customer fact — has_source_based_support is false so the frontend shows the "not documented"
     * notice for a customer-fact question with only this kind of grounding.
     */
    public function test_a_pure_best_practice_section_is_marked_best_practice_and_reports_no_source_based_support(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $claim = $this->createWikiClaim($page, 'Det anbefales å automatisere rotårsaksanalyse.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);

        $this->mockResearchService($this->fakeResearchContext($requirement, [
            $this->fakePage($page->id, 'Problem Management', [], [$claim->id]),
        ]));
        $this->mockAnswerClient([$this->section('S1', 'Forslag basert på beste praksis.', [$page->id])]);
        $this->mockAlignmentClient([$this->assessment('S1', 'aligned', [$page->id])]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $this->assertSame('best_practice', $answer->alignment_trace['sections'][0]['provenance_type']);
        $this->assertFalse($answer->alignment_trace['has_source_based_support']);
        $this->assertFalse($answer->sources[0]['has_source_based_claims']);
        $this->assertTrue($answer->sources[0]['has_best_practice_claims']);
    }

    /**
     * v0.9 provenance-gap closure — acceptance (3)+(4): a mixed answer keeps each part's provenance
     * distinct — the source_based section and the best_practice section are tracked independently,
     * and the best_practice-only page never lands in the source_based bucket for either section
     * (i.e. it is never usable as source evidence for a customer claim).
     */
    public function test_a_mixed_answer_keeps_source_based_and_best_practice_sections_distinct(): void
    {
        $customer = $this->createWikiCustomer();
        $requirement = $this->createRequirement($customer, 'Beskriv Problem Management.');
        $documentedPage = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $suggestionPage = $this->createWikiPageWithVersion($customer, 'Kontinuerlig forbedring', 'Innhold.');
        $sourceBasedClaim = $this->createWikiClaim($documentedPage, 'Problem Management rapporteres månedlig.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);
        $bestPracticeClaim = $this->createWikiClaim($suggestionPage, 'Det anbefales kvartalsvise forbedringsmøter.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);

        $this->mockResearchService($this->fakeResearchContext($requirement, [
            $this->fakePage($documentedPage->id, 'Problem Management', [$sourceBasedClaim->id], []),
            $this->fakePage($suggestionPage->id, 'Kontinuerlig forbedring', [], [$bestPracticeClaim->id]),
        ]));
        $this->mockAnswerClient([
            $this->section('S1', 'Basert på kildedokumentene.', [$documentedPage->id]),
            $this->section('S2', 'Forslag basert på beste praksis.', [$suggestionPage->id]),
        ]);
        $this->mockAlignmentClient([
            $this->assessment('S1', 'aligned', [$documentedPage->id]),
            $this->assessment('S2', 'aligned', [$suggestionPage->id]),
        ]);

        $answer = app(RequirementWikiAnswerService::class)->generate($requirement, $customer->id, 'no');

        $sectionsByKey = collect($answer->alignment_trace['sections'])->keyBy('section_key');
        $this->assertSame('source_based', $sectionsByKey['S1']['provenance_type']);
        $this->assertSame([$documentedPage->id], $sectionsByKey['S1']['source_based_page_ids']);
        $this->assertSame([], $sectionsByKey['S1']['best_practice_page_ids']);
        $this->assertSame('best_practice', $sectionsByKey['S2']['provenance_type']);
        $this->assertSame([], $sectionsByKey['S2']['source_based_page_ids']);
        $this->assertSame([$suggestionPage->id], $sectionsByKey['S2']['best_practice_page_ids']);
        $this->assertTrue($answer->alignment_trace['has_source_based_support']);

        $sourcesByPageId = collect($answer->sources)->keyBy('enterprise_wiki_page_id');
        $this->assertTrue($sourcesByPageId[$documentedPage->id]['has_source_based_claims']);
        $this->assertFalse($sourcesByPageId[$suggestionPage->id]['has_source_based_claims']);
        $this->assertTrue($sourcesByPageId[$suggestionPage->id]['has_best_practice_claims']);
    }

    /**
     * v0.9 provenance-gap closure — acceptance (6): the research/ranking layer never loses
     * content_origin — a real (non-mocked) research pass over real EnterpriseWikiClaim rows must
     * bucket claim ids by content_origin, and multiple customers' claims stay isolated.
     */
    public function test_research_context_buckets_claims_by_content_origin_and_isolates_customers(): void
    {
        $customerA = $this->createWikiCustomer('Provenance Customer A');
        $customerB = $this->createWikiCustomer('Provenance Customer B');
        $requirement = $this->createRequirement($customerA, 'Beskriv rutinen for Problem Management.');
        $pageA = $this->createWikiPageWithVersion($customerA, 'Problem Management', 'Innhold om Problem Management.');
        $pageB = $this->createWikiPageWithVersion($customerB, 'Problem Management hos B', 'Innhold hos kunde B om Problem Management.');

        $sourceClaim = $this->createWikiClaim($pageA, 'Problem Management rapporteres månedlig til kunden.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);
        $bestPracticeClaim = $this->createWikiClaim($pageA, 'Problem Management bør automatiseres der mulig.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ]);
        $this->createWikiClaim($pageB, 'Problem Management rapporteres månedlig til kunden.', [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->once()
            ->andReturn(['action' => 'read_pages', 'page_ids' => [$pageA->id], 'search_terms' => [], 'reason' => 'Direkte relevant.']));

        $context = app(RequirementWikiResearchService::class)->research($requirement, $customerA->id, 'no');

        $this->assertCount(1, $context['pages']);
        $this->assertSame([$sourceClaim->id], $context['pages'][0]['source_based_claim_ids']);
        $this->assertSame([$bestPracticeClaim->id], $context['pages'][0]['best_practice_claim_ids']);
        // Customer B's page/claims were never even offered as a candidate — proving isolation at
        // the catalog level, before any claim bucketing happens.
        $this->assertNotContains($pageB->id, array_column($context['initial_candidates'], 'page_id'));
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

    private function section(string $key, string $text, array $usedPageIds, string $heading = ''): array
    {
        return ['key' => $key, 'heading' => $heading, 'text' => $text, 'used_page_ids' => $usedPageIds];
    }

    private function assessment(
        string $key,
        string $status,
        array $supportingPageIds = [],
        array $supportedPoints = [],
        array $uncoveredPoints = [],
        ?string $conflictSummary = null,
        ?string $reviewNote = null,
    ): array {
        return [
            'section_key' => $key,
            'alignment_status' => $status,
            'supporting_page_ids' => $supportingPageIds,
            'supported_points' => $supportedPoints,
            'uncovered_points' => $uncoveredPoints,
            'conflict_summary' => $conflictSummary,
            'review_note' => $reviewNote,
        ];
    }

    private function mockResearchService(array $context): void
    {
        $this->mock(RequirementWikiResearchService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('research')->once()->andReturn($context));
    }

    private function mockAnswerClient(array $sections): void
    {
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')->once()->andReturn(['answer_sections' => $sections]));
    }

    private function mockAlignmentClient(array $sections): void
    {
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')->once()->andReturn($sections));
    }

    private function fakePage(int $pageId, string $title, array $sourceBasedClaimIds = [], array $bestPracticeClaimIds = []): array
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
            'supporting_claim_ids' => [...$sourceBasedClaimIds, ...$bestPracticeClaimIds],
            'source_based_claim_ids' => $sourceBasedClaimIds,
            'best_practice_claim_ids' => $bestPracticeClaimIds,
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
