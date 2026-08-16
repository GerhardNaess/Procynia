<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Services\Ai\Wiki\RequirementWikiAnswerFigureResolver;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Figure references live in their own column and are resolved against live Wiki state on every
 * read. These tests pin the two halves of that contract: an ordinary hand-edit of answer_text must
 * not disturb the references, and a reference whose Wiki figure is gone must disappear from the
 * payload rather than render as a broken image.
 */
class RequirementWikiAnswerFigurePersistenceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    private function imageBlock(int $documentId = 63, string $imageKey = 'img3'): array
    {
        return [
            'block_key' => 'image-block-0001',
            'block_type' => 'image',
            'position' => 1,
            'source_id' => $documentId,
            'markdown' => "**Figur 4**\n_Kilde: Masterdata Samhandling.docx → Figur 4_",
            'page_reference' => 'Masterdata Samhandling.docx → Figur 4',
            'image_data' => [
                'source_image_key' => $imageKey,
                'figure_number' => 4,
                'caption' => null,
                'alt_text' => 'Samhandlingsmodellen',
                'description' => 'Viser samhandlingsmodellen.',
            ],
        ];
    }

    /** Builds a standalone answer row without going through generation (no AI calls here). */
    private function answerRow(EnterpriseWikiPage $page, array $sections, array $figures): SavedNoticeAiRequirementWikiAnswer
    {
        $answer = new SavedNoticeAiRequirementWikiAnswer;
        $answer->forceFill([
            'saved_notice_ai_requirement_id' => 0,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => implode("\n\n", array_column($sections, 'text')),
            'answer_figures' => $figures,
            'research_trace' => ['answer' => ['answer_sections' => $sections]],
        ]);

        return $answer;
    }

    public function test_a_stored_reference_resolves_to_a_secure_customer_scoped_image_url(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion(
            $customer,
            'Samhandling',
            "# Samhandling\n\nTekst.",
            [],
            ['content_blocks_json' => [$this->imageBlock()]],
        );

        $answer = $this->answerRow(
            $page,
            [['key' => 'S1', 'heading' => 'Samhandling', 'text' => 'Svar.']],
            [['figure_ref' => 'fig:63:img3', 'document_id' => 63, 'source_image_key' => 'img3', 'page_id' => $page->id, 'section_key' => 'S1', 'section_index' => 0]],
        );

        $resolved = app(RequirementWikiAnswerFigureResolver::class)->resolve($answer, $customer->id);

        $this->assertCount(1, $resolved);
        $this->assertSame('fig:63:img3', $resolved[0]['figure_ref']);
        $this->assertSame('Figur 4', $resolved[0]['caption']);
        $this->assertSame('Samhandlingsmodellen', $resolved[0]['alt_text']);
        $this->assertSame('Masterdata Samhandling.docx → Figur 4', $resolved[0]['page_reference']);
        $this->assertStringContainsString('/app/wiki/sources/63/images/img3', $resolved[0]['image_url']);
    }

    public function test_a_figure_whose_page_belongs_to_another_customer_is_never_resolved(): void
    {
        $owner = $this->createWikiCustomer('Eier');
        $other = $this->createWikiCustomer('Andre');
        $page = $this->createWikiPageWithVersion(
            $owner,
            'Samhandling',
            'Tekst.',
            [],
            ['content_blocks_json' => [$this->imageBlock()]],
        );

        $answer = $this->answerRow(
            $page,
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.']],
            [['figure_ref' => 'fig:63:img3', 'document_id' => 63, 'source_image_key' => 'img3', 'page_id' => $page->id, 'section_key' => 'S1', 'section_index' => 0]],
        );

        $this->assertSame([], app(RequirementWikiAnswerFigureResolver::class)->resolve($answer, $other->id));
    }

    public function test_a_figure_withdrawn_from_the_wiki_page_drops_out_instead_of_breaking(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion(
            $customer,
            'Samhandling',
            'Tekst.',
            [],
            ['content_blocks_json' => [$this->imageBlock()]],
        );

        $answer = $this->answerRow(
            $page,
            [['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.']],
            [['figure_ref' => 'fig:63:img3', 'document_id' => 63, 'source_image_key' => 'img3', 'page_id' => $page->id, 'section_key' => 'S1', 'section_index' => 0]],
        );

        // The source document is deleted: withdrawal rewrites the page without that block.
        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->update(['content_blocks_json' => json_encode([['block_type' => 'paragraph', 'markdown' => 'Tekst.']])]);

        $resolver = app(RequirementWikiAnswerFigureResolver::class);
        $resolved = $resolver->resolve($answer->fresh() ?? $answer, $customer->id);

        $this->assertSame([], $resolved);
        // The answer itself is untouched — losing a figure never invalidates the draft.
        $this->assertSame('Svar.', $answer->answer_text);
    }

    public function test_a_figure_is_placed_at_the_section_the_generator_chose(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion(
            $customer,
            'Samhandling',
            'Tekst.',
            [],
            ['content_blocks_json' => [$this->imageBlock()]],
        );

        $sections = [
            ['key' => 'S1', 'heading' => 'Innledning', 'text' => 'Første avsnitt.'],
            ['key' => 'S2', 'heading' => 'Organisering', 'text' => 'Andre avsnitt.'],
        ];
        $answer = $this->answerRow($page, $sections, [
            ['figure_ref' => 'fig:63:img3', 'document_id' => 63, 'source_image_key' => 'img3', 'page_id' => $page->id, 'section_key' => 'S2', 'section_index' => 1],
        ]);

        $resolver = app(RequirementWikiAnswerFigureResolver::class);
        $segments = $resolver->segments($answer, $resolver->resolve($answer, $customer->id));

        $this->assertCount(2, $segments);
        $this->assertSame([], $segments[0]['figures']);
        $this->assertCount(1, $segments[1]['figures']);
        $this->assertSame('S2', $segments[1]['section_key']);
    }

    public function test_a_hand_edited_answer_keeps_its_figures_but_stops_claiming_exact_placement(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion(
            $customer,
            'Samhandling',
            'Tekst.',
            [],
            ['content_blocks_json' => [$this->imageBlock()]],
        );

        $sections = [
            ['key' => 'S1', 'heading' => 'Innledning', 'text' => 'Første avsnitt.'],
            ['key' => 'S2', 'heading' => 'Organisering', 'text' => 'Andre avsnitt.'],
        ];
        $answer = $this->answerRow($page, $sections, [
            ['figure_ref' => 'fig:63:img3', 'document_id' => 63, 'source_image_key' => 'img3', 'page_id' => $page->id, 'section_key' => 'S2', 'section_index' => 1],
        ]);

        // The bid manager rewrites the answer; answer_figures is untouched by that edit.
        $answer->forceFill(['answer_text' => 'Et helt omskrevet svar med annen struktur.']);

        $resolver = app(RequirementWikiAnswerFigureResolver::class);
        $resolved = $resolver->resolve($answer, $customer->id);

        $this->assertCount(1, $resolved, 'The figure reference survives an ordinary text edit.');
        $this->assertNull(
            $resolver->segments($answer, $resolved),
            'Placement is dropped rather than guessed once the text no longer matches the generated sections.',
        );
    }

    public function test_an_answer_with_no_figure_data_at_all_resolves_to_nothing(): void
    {
        $customer = $this->createWikiCustomer();

        $answer = new SavedNoticeAiRequirementWikiAnswer;
        $answer->forceFill([
            'saved_notice_ai_requirement_id' => 0,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE,
            'answer_text' => 'Et eldre svar generert før figurstøtte.',
            'answer_figures' => null,
        ]);

        $resolver = app(RequirementWikiAnswerFigureResolver::class);

        $this->assertSame([], $resolver->resolve($answer, $customer->id));
        $this->assertNull($resolver->segments($answer, []));
    }
}
