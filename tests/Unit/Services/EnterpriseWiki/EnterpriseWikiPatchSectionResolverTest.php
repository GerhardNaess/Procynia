<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiPatchApplicationException;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchSectionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Fase 8K-3 — bounding the area a patch target may touch.
 *
 * The resolver is what turns "patch only what the target authorizes" into a computable range. These
 * tests are pure: no database, no container, no AI.
 *
 * The awkward case they exist for is real and observed in stored content: a heading is USUALLY its own
 * block, but it can also be the first line of a block that continues with prose (page 49 block-0018).
 * A resolver that assumed heading == whole block would mis-bound that section and let a patch reach
 * into the neighbouring one.
 */
class EnterpriseWikiPatchSectionResolverTest extends TestCase
{
    public function test_a_section_runs_from_its_heading_to_the_next_same_level_heading(): void
    {
        $area = $this->resolver()->resolve($this->blocks([
            '# Tittel',
            'Innledning.',
            '## Foerste seksjon',
            'Innhold i foerste.',
            'Mer innhold i foerste.',
            '## Andre seksjon',
            'Innhold i andre.',
        ]), 'Foerste seksjon', 'ignorert', 'ctx');

        $this->assertSame(2, $area['start_index']);
        $this->assertSame(4, $area['end_index'], 'the section ends before the next ## heading');
    }

    public function test_a_deeper_subheading_stays_inside_the_section(): void
    {
        $area = $this->resolver()->resolve($this->blocks([
            '## Foerste seksjon',
            'Innhold.',
            '### Underseksjon',
            'Underinnhold.',
            '## Andre seksjon',
        ]), 'Foerste seksjon', 'ignorert', 'ctx');

        $this->assertSame(0, $area['start_index']);
        $this->assertSame(3, $area['end_index'], 'a deeper heading does not end the section');
    }

    public function test_a_shallower_heading_ends_the_section(): void
    {
        $area = $this->resolver()->resolve($this->blocks([
            '### Dyp seksjon',
            'Innhold.',
            '# Toppnivaa',
            'Annet.',
        ]), 'Dyp seksjon', 'ignorert', 'ctx');

        $this->assertSame(0, $area['start_index']);
        $this->assertSame(1, $area['end_index']);
    }

    public function test_the_last_section_runs_to_the_end_of_the_page(): void
    {
        $area = $this->resolver()->resolve($this->blocks([
            '## Foerste',
            'A.',
            '## Siste',
            'B.',
            'C.',
        ]), 'Siste', 'ignorert', 'ctx');

        $this->assertSame(2, $area['start_index']);
        $this->assertSame(4, $area['end_index']);
    }

    public function test_a_heading_merged_with_content_in_one_block_still_bounds_correctly(): void
    {
        // The stored shape that breaks a whole-block assumption.
        $blocks = $this->blocks([
            '## Foerste seksjon',
            'Innhold i foerste.',
            "## Andre seksjon\n\nInnhold i andre, i samme blokk som overskriften.",
            'Mer i andre.',
        ]);

        $first = $this->resolver()->resolve($blocks, 'Foerste seksjon', 'x', 'ctx');
        $second = $this->resolver()->resolve($blocks, 'Andre seksjon', 'x', 'ctx');

        $this->assertSame(0, $first['start_index']);
        $this->assertSame(1, $first['end_index'], 'the merged block belongs to the SECOND section, not the first');
        $this->assertSame(2, $second['start_index']);
        $this->assertSame(3, $second['end_index']);
    }

    public function test_content_above_a_shared_heading_stays_outside_the_section(): void
    {
        // A block whose heading is NOT its first line is shared between two sections; only the part
        // from the heading onward belongs to the later one.
        $resolver = $this->resolver();
        $blockMarkdown = "Slutten av forrige seksjon.\n\n## Ny seksjon\n\nInnhold i ny seksjon.";
        $area = $resolver->resolve($this->blocks(['## Forrige', $blockMarkdown]), 'Ny seksjon', 'x', 'ctx');

        $inSection = $resolver->inSectionText($area, 1, $blockMarkdown);

        $this->assertStringNotContainsString('Slutten av forrige seksjon.', $inSection);
        $this->assertStringContainsString('Innhold i ny seksjon.', $inSection);
    }

    public function test_a_block_that_does_not_carry_the_heading_returns_its_full_text(): void
    {
        $resolver = $this->resolver();
        $area = $resolver->resolve($this->blocks(['## Seksjon', 'Hele denne teksten.']), 'Seksjon', 'x', 'ctx');

        $this->assertSame('Hele denne teksten.', $resolver->inSectionText($area, 1, 'Hele denne teksten.'));
    }

    public function test_heading_matching_ignores_case_whitespace_and_trailing_hashes(): void
    {
        $area = $this->resolver()->resolve(
            $this->blocks(['##   Krav   og  Terskler   ##', 'Innhold.']),
            'krav og terskler',
            'x',
            'ctx',
        );

        $this->assertSame(0, $area['start_index']);
    }

    public function test_a_missing_heading_is_a_controlled_failure(): void
    {
        $this->expectException(EnterpriseWikiPatchApplicationException::class);
        $this->expectExceptionMessageMatches('/is not a heading/');

        $this->resolver()->resolve($this->blocks(['## Finnes', 'Innhold.']), 'Finnes ikke', 'x', 'ctx');
    }

    public function test_two_identical_headings_are_refused_as_ambiguous(): void
    {
        // Guessing would patch one occurrence and silently leave the other stale.
        $this->expectException(EnterpriseWikiPatchApplicationException::class);
        $this->expectExceptionMessageMatches('/matches 2 headings/');

        $this->resolver()->resolve(
            $this->blocks(['## Samme', 'A.', '## Samme', 'B.']),
            'Samme',
            'x',
            'ctx',
        );
    }

    public function test_no_heading_falls_back_to_a_topic_that_names_a_section(): void
    {
        $area = $this->resolver()->resolve(
            $this->blocks(['## Krav og terskler', 'Innhold.']),
            null,
            'Krav og terskler',
            'ctx',
        );

        $this->assertSame(0, $area['start_index']);
        $this->assertSame(1, $area['end_index']);
    }

    public function test_no_heading_and_an_unmatched_topic_never_widens_to_the_whole_page(): void
    {
        // The page HAS sub-sections, so "somewhere on this page" is not a bounded area.
        $this->expectException(EnterpriseWikiPatchApplicationException::class);
        $this->expectExceptionMessageMatches('/never widens its own scope/');

        $this->resolver()->resolve(
            $this->blocks(['# Tittel', '## Krav og terskler', 'Innhold.']),
            null,
            'Et tema som ikke er en overskrift',
            'ctx',
        );
    }

    // =========================================================================
    // Flat pages — a page with no sub-sections (run 28: a real summary)
    // =========================================================================

    public function test_a_flat_page_resolves_to_its_body_when_no_heading_is_named(): void
    {
        $area = $this->resolver()->resolve(
            $this->blocks(['# Sammendrag: Noe', 'Kort oppsummering med en verdi.', 'Enda et avsnitt.']),
            null,
            'Verdien',
            'ctx',
        );

        $this->assertSame(1, $area['start_index'], 'the body starts BELOW the H1');
        $this->assertSame(2, $area['end_index']);
    }

    public function test_a_flat_page_body_never_includes_the_title_line(): void
    {
        // The H1 carries the page's identity; a patch must not be able to reach it.
        $resolver = $this->resolver();
        $blocks = $this->blocks(['# Sammendrag: Noe', 'Kort oppsummering.']);

        $area = $resolver->resolve($blocks, null, 'Verdien', 'ctx');

        for ($i = $area['start_index']; $i <= $area['end_index']; $i++) {
            $this->assertStringNotContainsString(
                '# Sammendrag: Noe',
                $resolver->inSectionText($area, $i, (string) $blocks[$i]['markdown']),
            );
        }
    }

    public function test_a_flat_page_whose_title_shares_its_block_excludes_only_the_title_line(): void
    {
        $resolver = $this->resolver();
        $blockMarkdown = "# Sammendrag: Noe\n\nKort oppsummering med en verdi.";
        $blocks = $this->blocks([$blockMarkdown]);

        $area = $resolver->resolve($blocks, null, 'Verdien', 'ctx');
        $inSection = $resolver->inSectionText($area, $area['start_index'], $blockMarkdown);

        $this->assertStringNotContainsString('# Sammendrag: Noe', $inSection);
        $this->assertStringContainsString('Kort oppsummering med en verdi.', $inSection);
    }

    public function test_a_page_with_subsections_does_not_get_the_flat_fallback(): void
    {
        $this->expectException(EnterpriseWikiPatchApplicationException::class);

        $this->resolver()->resolve(
            $this->blocks(['# Tittel', 'Ingress.', '## Foerste', 'A.', '## Andre', 'B.']),
            null,
            'Noe som ikke er en overskrift',
            'ctx',
        );
    }

    public function test_a_page_with_no_headings_at_all_resolves_to_every_block(): void
    {
        $area = $this->resolver()->resolve($this->blocks(['Bare innhold.', 'Mer innhold.']), null, 'Verdien', 'ctx');

        $this->assertSame(0, $area['start_index']);
        $this->assertSame(1, $area['end_index']);
    }

    public function test_the_flat_fallback_is_deterministic(): void
    {
        $blocks = $this->blocks(['# Sammendrag', 'Innhold.', 'Mer.']);

        $this->assertSame(
            $this->resolver()->resolve($blocks, null, 'Verdien', 'ctx'),
            $this->resolver()->resolve($blocks, null, 'Verdien', 'ctx'),
        );
    }

    public function test_a_named_heading_still_wins_over_the_flat_fallback(): void
    {
        // A flat page can still carry an H1-named target; naming it must behave exactly as before.
        $area = $this->resolver()->resolve($this->blocks(['# Sammendrag', 'Innhold.']), 'Sammendrag', 'x', 'ctx');

        $this->assertSame(0, $area['start_index'], 'a named heading includes its own line, unlike the body fallback');
    }

    public function test_a_version_without_blocks_is_a_controlled_failure(): void
    {
        $this->expectException(EnterpriseWikiPatchApplicationException::class);
        $this->expectExceptionMessageMatches('/no content blocks/');

        $this->resolver()->resolve([], 'Uansett', 'x', 'ctx');
    }

    private function resolver(): EnterpriseWikiPatchSectionResolver
    {
        return new EnterpriseWikiPatchSectionResolver;
    }

    /**
     * @param  list<string>  $markdowns
     * @return list<array<string, mixed>>
     */
    private function blocks(array $markdowns): array
    {
        $blocks = [];

        foreach ($markdowns as $index => $markdown) {
            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'position' => $index,
                'markdown' => $markdown,
            ];
        }

        return $blocks;
    }
}
