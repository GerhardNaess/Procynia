<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The figure contract between the answer engine and the model: the model is shown figure metadata
 * and may select refs from it, but owns no part of a figure's identity. Everything it returns is
 * checked against the exact set it was offered.
 */
class RequirementWikiAnswerFigureContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    private function response(array $payload): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($payload)];
    }

    /** A read page that carries one real Wiki figure. */
    private function pageWithFigure(): array
    {
        return [[
            'page_id' => 101,
            'title' => 'Samhandling',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => "# Samhandling\n\nModellen beskriver samspillet.",
            'selected_headings' => [],
            'figures' => [[
                'figure_ref' => 'fig:63:img3',
                'document_id' => 63,
                'source_image_key' => 'img3',
                'caption' => null,
                'alt_text' => '',
                'description' => 'Viser samhandlingsmodellen mellom kunde og leverandør.',
                'figure_number' => 4,
                'page_reference' => 'Masterdata Samhandling.docx → Figur 4',
                'block_markdown' => '**Figur 4**',
            ]],
            'source_based_claim_texts' => [],
            'best_practice_claim_texts' => [],
        ]];
    }

    private function pageWithoutFigures(): array
    {
        return [[
            'page_id' => 102,
            'title' => 'Endringsstyring',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => 'Innhold om endringsstyring.',
            'selected_headings' => [],
            'figures' => [],
            'source_based_claim_texts' => [],
            'best_practice_claim_texts' => [],
        ]];
    }

    private function captureRequest(array $pages, array $modelSections): array
    {
        $captured = null;

        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured, $modelSections): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured, $modelSections): array {
                $captured = $payload;

                return $this->response(['answer_sections' => $modelSections]);
            });
        });

        $result = app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'Beskriv samhandlingsmodellen.', $pages, 'no');

        return [$captured, $result];
    }

    public function test_figure_metadata_is_offered_to_the_model_without_any_image_data(): void
    {
        [$captured] = $this->captureRequest($this->pageWithFigure(), [
            ['key' => 'S1', 'heading' => 'Samhandling', 'text' => 'Svar.', 'used_page_ids' => [101], 'figure_refs' => []],
        ]);

        $userText = data_get($captured, 'input.1.content.0.text');

        $this->assertStringContainsString('AVAILABLE FIGURES', $userText);
        $this->assertStringContainsString('FIGURE_REF: fig:63:img3', $userText);
        $this->assertStringContainsString('Viser samhandlingsmodellen mellom kunde og leverandør.', $userText);

        // The whole request, developer prompt included — no bytes, no data URI, no route.
        $wholeRequest = json_encode($captured);
        $this->assertStringNotContainsString('data:image', $wholeRequest);
        $this->assertStringNotContainsString('base64', $wholeRequest);
        $this->assertStringNotContainsString('/app/wiki/sources', $wholeRequest);
    }

    public function test_the_prompt_forbids_inventing_or_modifying_a_figure_ref(): void
    {
        [$captured] = $this->captureRequest($this->pageWithFigure(), [
            ['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.', 'used_page_ids' => [], 'figure_refs' => []],
        ]);

        $developerPrompt = data_get($captured, 'input.0.content.0.text');

        $this->assertStringContainsString('Use figure_refs EXACTLY as given', $developerPrompt);
        $this->assertStringContainsString('Never invent a ref', $developerPrompt);
    }

    public function test_a_page_without_figures_offers_no_figure_block_at_all(): void
    {
        [$captured] = $this->captureRequest($this->pageWithoutFigures(), [
            ['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.', 'used_page_ids' => [102], 'figure_refs' => []],
        ]);

        $this->assertStringNotContainsString('AVAILABLE FIGURES', data_get($captured, 'input.1.content.0.text'));
    }

    public function test_a_figure_ref_that_was_offered_is_accepted(): void
    {
        [, $result] = $this->captureRequest($this->pageWithFigure(), [
            ['key' => 'S1', 'heading' => 'Samhandling', 'text' => 'Svar.', 'used_page_ids' => [101], 'figure_refs' => ['fig:63:img3']],
        ]);

        $this->assertSame(['fig:63:img3'], $result['answer_sections'][0]['figure_refs']);
    }

    public function test_an_invented_figure_ref_is_dropped_without_losing_the_section(): void
    {
        [, $result] = $this->captureRequest($this->pageWithFigure(), [
            [
                'key' => 'S1',
                'heading' => 'Samhandling',
                'text' => 'Svaret står fortsatt.',
                'used_page_ids' => [101],
                'figure_refs' => ['fig:99:img1', 'fig:63:img99', 'https://example.test/bilde.png', 'fig:63:img3'],
            ],
        ]);

        $this->assertCount(1, $result['answer_sections']);
        $this->assertSame('Svaret står fortsatt.', $result['answer_sections'][0]['text']);
        $this->assertSame(['fig:63:img3'], $result['answer_sections'][0]['figure_refs']);
    }

    public function test_a_figure_from_a_page_that_was_not_read_cannot_be_selected(): void
    {
        // The figure exists in the Wiki, but this generation only read page 102.
        [, $result] = $this->captureRequest($this->pageWithoutFigures(), [
            ['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.', 'used_page_ids' => [102], 'figure_refs' => ['fig:63:img3']],
        ]);

        $this->assertSame([], $result['answer_sections'][0]['figure_refs']);
    }

    public function test_an_answer_with_no_figures_behaves_exactly_as_before(): void
    {
        [, $result] = $this->captureRequest($this->pageWithoutFigures(), [
            ['key' => 'S1', 'heading' => 'Endringsstyring', 'text' => 'Svar uten figurer.', 'used_page_ids' => [102], 'figure_refs' => []],
        ]);

        $this->assertSame('Svar uten figurer.', $result['answer_sections'][0]['text']);
        $this->assertSame([102], $result['answer_sections'][0]['used_page_ids']);
        $this->assertSame([], $result['answer_sections'][0]['figure_refs']);
    }

    public function test_the_schema_requires_figure_refs_on_every_section(): void
    {
        [$captured] = $this->captureRequest($this->pageWithFigure(), [
            ['key' => 'S1', 'heading' => 'S', 'text' => 'Svar.', 'used_page_ids' => [], 'figure_refs' => []],
        ]);

        $sectionSchema = data_get($captured, 'text.format.schema.properties.answer_sections.items');

        $this->assertContains('figure_refs', $sectionSchema['required']);
        $this->assertSame('string', $sectionSchema['properties']['figure_refs']['items']['type']);
        $this->assertFalse($sectionSchema['additionalProperties']);
    }
}
