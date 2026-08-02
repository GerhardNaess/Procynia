<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiDuplicateContentRemover;
use PHPUnit\Framework\TestCase;

class EnterpriseWikiDuplicateContentRemoverTest extends TestCase
{
    private function remover(): EnterpriseWikiDuplicateContentRemover
    {
        return new EnterpriseWikiDuplicateContentRemover;
    }

    private function block(string $markdown, array $overrides = []): array
    {
        return array_merge([
            'markdown' => $markdown,
            'content_origin' => 'source_based',
            'source_element_keys' => [],
            'source_element_types' => [],
            'best_practice_reason' => null,
            'link_intents' => [],
        ], $overrides);
    }

    // =========================================================================
    // 1. Identical first sentence twice in the same paragraph -> one occurrence
    // =========================================================================

    public function test_repeated_leading_sentence_across_blocks_is_removed_keeping_first_occurrence(): void
    {
        $sentence = 'Som oversiktsbilde over en Incident-prosess viser illustrasjoner et løp fra innmelding til avslutning.';

        $blocks = [
            $this->block("# Sammendrag: Incident Management\n\n{$sentence}"),
            $this->block("{$sentence} Videre beskrives ansvarsfordelingen mellom kunde og leverandør i detalj."),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(2, $result);
        $this->assertSame("# Sammendrag: Incident Management\n\n{$sentence}", $result[0]['markdown']);
        $this->assertSame('Videre beskrives ansvarsfordelingen mellom kunde og leverandør i detalj.', $result[1]['markdown']);
        $this->assertStringNotContainsString($sentence, $result[1]['markdown']);
    }

    // =========================================================================
    // 2. Identical paragraphs in the same section -> one occurrence
    // =========================================================================

    public function test_fully_identical_paragraph_is_dropped_entirely_on_second_occurrence(): void
    {
        $paragraph = 'Dette er et helt identisk avsnitt som gjentas ordrett lenger ned på siden.';

        $blocks = [
            $this->block($paragraph),
            $this->block($paragraph),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(1, $result);
        $this->assertSame($paragraph, $result[0]['markdown']);
    }

    public function test_identical_paragraphs_within_one_block_separated_by_blank_line_collapse_to_one(): void
    {
        $paragraph = 'Et avsnitt som forekommer to ganger i samme blokk, adskilt av tomlinje.';

        $blocks = [
            $this->block("{$paragraph}\n\n{$paragraph}"),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(1, $result);
        $this->assertSame($paragraph, $result[0]['markdown']);
    }

    // =========================================================================
    // 3. Same sentence with only different whitespace is treated as a duplicate
    // =========================================================================

    public function test_whitespace_only_variant_is_still_treated_as_duplicate(): void
    {
        $blocks = [
            $this->block('Denne setningen   har   ekstra mellomrom og linjeskift.'),
            $this->block("Denne setningen\nhar ekstra mellomrom og linjeskift."),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(1, $result);
        $this->assertSame('Denne setningen   har   ekstra mellomrom og linjeskift.', $result[0]['markdown']);
    }

    // =========================================================================
    // 4. Two semantically similar but not identical sentences are both kept
    // =========================================================================

    public function test_semantically_similar_but_not_identical_sentences_are_both_kept(): void
    {
        $blocks = [
            $this->block('Prosessen starter med innmelding av en hendelse fra kunden.'),
            $this->block('Prosessen starter med registrering av en hendelse fra kunden.'),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(2, $result);
        $this->assertSame('Prosessen starter med innmelding av en hendelse fra kunden.', $result[0]['markdown']);
        $this->assertSame('Prosessen starter med registrering av en hendelse fra kunden.', $result[1]['markdown']);
    }

    // =========================================================================
    // 5. Same text on two different Wiki pages is unaffected
    // =========================================================================

    public function test_scope_is_per_call_so_cross_page_repetition_is_never_affected(): void
    {
        $sentence = 'Denne setningen finnes legitimt på flere sider i wikien.';

        $pageOne = $this->remover()->removeVerbatimDuplicates([
            $this->block($sentence),
        ]);

        $pageTwo = $this->remover()->removeVerbatimDuplicates([
            $this->block($sentence),
        ]);

        $this->assertSame($sentence, $pageOne[0]['markdown']);
        $this->assertSame($sentence, $pageTwo[0]['markdown']);
    }

    // =========================================================================
    // 6. Headings and block structure are preserved
    // =========================================================================

    public function test_heading_is_never_removed_or_split_even_if_repeated(): void
    {
        $blocks = [
            $this->block('# Incident Management'),
            $this->block('# Incident Management'),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(2, $result);
        $this->assertSame('# Incident Management', $result[0]['markdown']);
        $this->assertSame('# Incident Management', $result[1]['markdown']);
    }

    public function test_block_structure_and_non_markdown_fields_are_preserved_for_kept_blocks(): void
    {
        $blocks = [
            $this->block('Unikt innhold i første blokk.', ['content_origin' => 'source_based', 'source_element_keys' => ['el-1']]),
            $this->block('Unikt innhold i andre blokk.', ['content_origin' => 'best_practice', 'best_practice_reason' => 'generell fagkunnskap']),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(2, $result);
        $this->assertSame('source_based', $result[0]['content_origin']);
        $this->assertSame(['el-1'], $result[0]['source_element_keys']);
        $this->assertSame('best_practice', $result[1]['content_origin']);
        $this->assertSame('generell fagkunnskap', $result[1]['best_practice_reason']);
    }

    public function test_block_whose_entire_markdown_is_a_duplicate_is_dropped_from_result(): void
    {
        $paragraph = 'Dette avsnittet gjentas ordrett og utgjør hele den andre blokken.';

        $blocks = [
            $this->block($paragraph),
            $this->block($paragraph, ['content_origin' => 'best_practice']),
        ];

        $result = $this->remover()->removeVerbatimDuplicates($blocks);

        $this->assertCount(1, $result);
        $this->assertSame($paragraph, $result[0]['markdown']);
    }

    public function test_empty_block_list_returns_empty_list(): void
    {
        $this->assertSame([], $this->remover()->removeVerbatimDuplicates([]));
    }
}
