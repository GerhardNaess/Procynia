<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiPlannedFigureCoverageValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pure, deterministic unit tests for the Wiki run-587 fix (4 of 4 professionally significant
 * figures extracted, classified, and made citable, but zero ever materialized on any page, and
 * nothing checked for this). No AI, no DB — checks what was ACTUALLY PERSISTED (content_blocks),
 * never AI intent alone.
 */
class EnterpriseWikiPlannedFigureCoverageValidatorTest extends TestCase
{
    private function validator(): EnterpriseWikiPlannedFigureCoverageValidator
    {
        return new EnterpriseWikiPlannedFigureCoverageValidator;
    }

    public function test_no_planned_figures_produces_no_issues(): void
    {
        $issues = $this->validator()->validate([], '# Page', []);
        $this->assertSame([], $issues);
    }

    public function test_correctly_materialized_required_figure_produces_no_issues(): void
    {
        $figure = $this->plannedFigure('img1', required: true, section: 'Roller', captionHint: 'Styringsmodell');
        $markdown = "# Page\n\n## Roller\n\n**Styringsmodell**\n_Kilde: dok.docx_";
        $blocks = [$this->imageBlock('img1', caption: 'Styringsmodell', altText: 'Figur som viser styringsmodellen', markdown: "**Styringsmodell**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertSame([], $issues);
    }

    public function test_missing_figure_is_reported_with_its_required_flag_preserved(): void
    {
        $required = $this->plannedFigure('img1', required: true);
        $optional = $this->plannedFigure('img2', required: false);

        $issues = $this->validator()->validate([$required, $optional], '# Page', []);

        $byKey = collect($issues)->keyBy('source_element_key');
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $byKey['img1']['type']);
        $this->assertTrue($byKey['img1']['required']);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $byKey['img2']['type']);
        $this->assertFalse($byKey['img2']['required']);
    }

    public function test_is_blocking_is_true_for_required_missing_and_false_for_optional_missing(): void
    {
        $requiredIssue = ['type' => EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, 'required' => true];
        $optionalIssue = ['type' => EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, 'required' => false];

        $this->assertTrue(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($requiredIssue));
        $this->assertFalse(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($optionalIssue));
    }

    public function test_a_figure_referencing_an_unknown_source_element_key_is_source_missing(): void
    {
        $figure = $this->plannedFigure('img9', required: true);

        $issues = $this->validator()->validate([$figure], '# Page', [], ['img1', 'img2']);

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_SOURCE_MISSING, $issues[0]['type']);
    }

    public function test_empty_valid_keys_list_skips_the_source_missing_check(): void
    {
        // Matches EnterpriseWikiMaintainerDecisionConsistencyValidator's own convention: an empty
        // list means "unknown", not "nothing is valid" — the caller did not supply a known set.
        $figure = $this->plannedFigure('img9', required: true);

        $issues = $this->validator()->validate([$figure], '# Page', [], []);

        $this->assertCount(1, $issues);
        $this->assertSame(EnterpriseWikiPlannedFigureCoverageValidator::TYPE_MISSING, $issues[0]['type']);
    }

    public function test_duplicate_image_block_is_always_blocking_even_when_the_figure_is_optional(): void
    {
        $figure = $this->plannedFigure('img1', required: false, section: null);
        $markdown = "# Page\n\n**Fig**\n_Kilde: dok.docx_";
        $blocks = [
            $this->imageBlock('img1', markdown: "**Fig**\n_Kilde: dok.docx_"),
            $this->imageBlock('img1', markdown: "**Fig**\n_Kilde: dok.docx_"),
        ];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $duplicate = collect($issues)->firstWhere('type', EnterpriseWikiPlannedFigureCoverageValidator::TYPE_DUPLICATE);
        $this->assertNotNull($duplicate);
        $this->assertTrue($duplicate['required'], 'A duplicate must be treated as blocking regardless of the planned required flag.');
        $this->assertTrue(EnterpriseWikiPlannedFigureCoverageValidator::isBlocking($duplicate));
    }

    public function test_figure_materialized_outside_its_planned_section_is_wrong_section(): void
    {
        $figure = $this->plannedFigure('img1', required: true, section: 'Roller i styringsmodellen');
        $markdown = "# Page\n\n## Roller i styringsmodellen\n\nTekst uten figur.\n\n## Møtefora\n\n**Fig**\n_Kilde: dok.docx_";
        $blocks = [$this->imageBlock('img1', markdown: "**Fig**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertNotEmpty(array_filter($issues, fn (array $i): bool => $i['type'] === EnterpriseWikiPlannedFigureCoverageValidator::TYPE_WRONG_SECTION));
    }

    public function test_figure_planned_with_no_section_is_never_flagged_wrong_section(): void
    {
        $figure = $this->plannedFigure('img1', required: true, section: null);
        $markdown = "# Page\n\nInnledning.\n\n**Fig**\n_Kilde: dok.docx_\n\n## En seksjon\n\nTekst.";
        $blocks = [$this->imageBlock('img1', markdown: "**Fig**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertSame([], array_filter($issues, fn (array $i): bool => $i['type'] === EnterpriseWikiPlannedFigureCoverageValidator::TYPE_WRONG_SECTION));
    }

    public function test_missing_caption_when_a_caption_hint_was_given_is_reported(): void
    {
        $figure = $this->plannedFigure('img1', required: false, captionHint: 'Styringsmodell for tilbudsarbeid');
        $markdown = "# Page\n\n**Figur 1**\n_Kilde: dok.docx_";
        $blocks = [$this->imageBlock('img1', caption: '', markdown: "**Figur 1**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertNotEmpty(array_filter($issues, fn (array $i): bool => $i['type'] === EnterpriseWikiPlannedFigureCoverageValidator::TYPE_CAPTION_MISSING));
    }

    public function test_missing_alt_text_on_a_required_figure_is_reported(): void
    {
        $figure = $this->plannedFigure('img1', required: true);
        $markdown = "# Page\n\n**Fig**\n_Kilde: dok.docx_";
        $blocks = [$this->imageBlock('img1', altText: '', markdown: "**Fig**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertNotEmpty(array_filter($issues, fn (array $i): bool => $i['type'] === EnterpriseWikiPlannedFigureCoverageValidator::TYPE_ALT_TEXT_MISSING));
    }

    public function test_missing_alt_text_on_an_optional_figure_is_not_reported(): void
    {
        $figure = $this->plannedFigure('img1', required: false);
        $markdown = "# Page\n\n**Fig**\n_Kilde: dok.docx_";
        $blocks = [$this->imageBlock('img1', altText: '', markdown: "**Fig**\n_Kilde: dok.docx_")];

        $issues = $this->validator()->validate([$figure], $markdown, $blocks, ['img1']);

        $this->assertSame([], array_filter($issues, fn (array $i): bool => $i['type'] === EnterpriseWikiPlannedFigureCoverageValidator::TYPE_ALT_TEXT_MISSING));
    }

    public function test_a_figure_entry_with_no_source_element_key_is_silently_skipped(): void
    {
        $figure = $this->plannedFigure('', required: true);

        $issues = $this->validator()->validate([$figure], '# Page', []);

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function plannedFigure(
        string $sourceElementKey,
        bool $required = false,
        ?string $section = null,
        ?string $captionHint = null,
    ): array {
        return [
            'source_element_key' => $sourceElementKey,
            'classification' => 'diagram',
            'section_placement' => $section,
            'purpose' => 'Illustrates the governance model.',
            'required' => $required,
            'caption_hint' => $captionHint,
        ];
    }

    private function imageBlock(
        string $sourceElementKey,
        string $caption = 'Figur',
        string $altText = 'Figur som viser noe',
        string $markdown = '**Figur**',
    ): array {
        return [
            'block_type' => 'image',
            'markdown' => $markdown,
            'source_element_key' => $sourceElementKey,
            'source_element_type' => 'image',
            'image_data' => [
                'source_image_key' => $sourceElementKey,
                'caption' => $caption,
                'alt_text' => $altText,
            ],
        ];
    }
}
