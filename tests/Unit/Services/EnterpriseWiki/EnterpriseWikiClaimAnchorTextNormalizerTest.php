<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiClaimAnchorTextNormalizer;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use PHPUnit\Framework\TestCase;

/**
 * Wiki run-34 fix: a literal substring comparison of a claim's plain-text anchor against
 * already-generated Wiki markdown produces a false "anchor not found" whenever the markdown
 * contains [[wikilink]] markup (or other formatting) that wasn't present when the anchor text
 * was captured. This normalizer removes that false-negative source.
 */
class EnterpriseWikiClaimAnchorTextNormalizerTest extends TestCase
{
    private function normalizer(): EnterpriseWikiClaimAnchorTextNormalizer
    {
        return new EnterpriseWikiClaimAnchorTextNormalizer(new EnterpriseWikiLinkParser);
    }

    // =========================================================================
    // Wiki-link markup
    // =========================================================================

    public function test_piped_wikilink_resolves_to_its_anchor_text(): void
    {
        $haystack = '[[itil|ITIL]] brukes som rammeverk.';
        $needle = 'ITIL brukes som rammeverk.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_unpiped_wikilink_resolves_to_the_slug_itself(): void
    {
        // No visible-text override — EnterpriseWikiLinkParser's own rule for [[slug]] is that
        // the visible text defaults to the slug.
        $haystack = 'Se [[itil]] for detaljer.';
        $needle = 'Se itil for detaljer.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_ordinary_markdown_link_resolves_to_its_link_text(): void
    {
        $haystack = 'Se [dokumentasjonen](https://example.test/docs) for detaljer.';
        $needle = 'Se dokumentasjonen for detaljer.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    // =========================================================================
    // Emphasis / headings / lists
    // =========================================================================

    public function test_bold_and_italic_markers_are_stripped(): void
    {
        $haystack = 'Applikasjoner klassifiseres etter modellen **Rød, Gul og Grønn**.';
        $needle = 'Applikasjoner klassifiseres etter modellen Rød, Gul og Grønn.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_heading_marker_is_stripped(): void
    {
        $haystack = "## Tilgangsstyring\n\nTilgangsrettigheter gjennomgås hver måned.";
        $needle = 'Tilgangsstyring';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_list_prefix_is_stripped(): void
    {
        $haystack = '- Servicedesk Bravo er tilgjengelig mandag til fredag.';
        $needle = 'Servicedesk Bravo er tilgjengelig mandag til fredag.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    // =========================================================================
    // Whitespace / line breaks / entities / Unicode
    // =========================================================================

    public function test_whitespace_and_line_breaks_do_not_cause_a_false_mismatch(): void
    {
        $haystack = "Kritiske   hendelser\nskal registreres av driftsvakt.";
        $needle = 'Kritiske hendelser skal registreres av driftsvakt.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_html_entities_are_decoded(): void
    {
        $haystack = 'Rettigheter &amp; roller gjennomgås månedlig.';
        $needle = 'Rettigheter & roller gjennomgås månedlig.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_norwegian_characters_are_preserved_and_case_insensitive(): void
    {
        $haystack = 'Tilgangsrettigheter gjennomgås av tjenesteeier hver måned.';
        $needle = 'TILGANGSRETTIGHETER GJENNOMGÅS AV TJENESTEEIER HVER MÅNED.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    // =========================================================================
    // Run-38 fix: comma-for-period and wikilink-suffix boundary artifacts
    // =========================================================================

    public function test_comma_at_clause_boundary_matches_a_needle_ending_in_a_period(): void
    {
        // Claim extraction closed the anchor with a period, but the source itself continues the
        // sentence past that point with a comma + conjunction.
        $haystack = 'Prosessen reduserer risiko for nye driftsavbrudd, mens endringer vurderes separat.';
        $needle = 'Prosessen reduserer risiko for nye driftsavbrudd.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    public function test_wikilink_with_inflectional_suffix_matches_despite_stray_space(): void
    {
        // Source: "[[itil|ITIL-rammeverk]]et" resolves to "ITIL-rammeverket" (one word); the
        // claim's captured anchor text has a stray space before the suffix: "ITIL-rammeverk et".
        $haystack = 'Leverandøren bruker [[itil|ITIL-rammeverk]]et som styringsverktøy.';
        $needle = 'Leverandøren bruker ITIL-rammeverk et som styringsverktøy.';

        $this->assertTrue($this->normalizer()->contains($haystack, $needle));
    }

    // =========================================================================
    // Genuine mismatches remain mismatches
    // =========================================================================

    public function test_genuinely_absent_text_is_not_found(): void
    {
        $haystack = 'Servicedesk Bravo er tilgjengelig mandag til fredag.';
        $needle = 'Kritiske hendelser skal registreres av driftsvakt.';

        $this->assertFalse($this->normalizer()->contains($haystack, $needle));
    }

    public function test_empty_needle_is_never_found(): void
    {
        $this->assertFalse($this->normalizer()->contains('Some content.', ''));
        $this->assertFalse($this->normalizer()->contains('Some content.', '   '));
    }
}
