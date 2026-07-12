<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use App\Services\EnterpriseWiki\EnterpriseWikiWikilinkCanonicalizer;
use Tests\TestCase;

/**
 * Runtime fix: deterministic canonicalization of near-miss inline wikilinks (e.g. the model
 * writing a page's title instead of its differently-cased slug) before final validation.
 */
class EnterpriseWikiWikilinkCanonicalizerTest extends TestCase
{
    private function canonicalizer(): EnterpriseWikiWikilinkCanonicalizer
    {
        return new EnterpriseWikiWikilinkCanonicalizer(new EnterpriseWikiLinkParser());
    }

    private function catalog(): array
    {
        return [
            ['slug' => 'advania', 'title' => 'Advania', 'page_type' => 'entity'],
            ['slug' => 'risikostyring', 'title' => 'Risikostyring', 'page_type' => 'concept'],
        ];
    }

    // 1: exact canonical slug is accepted unchanged
    public function test_exact_canonical_slug_is_left_unchanged(): void
    {
        $markdown = 'Se [[advania|Advania]] for detaljer.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    // 2: case-insensitive slug is canonicalized
    public function test_case_insensitive_slug_is_canonicalized(): void
    {
        $result = $this->canonicalizer()->canonicalize('Se [[ADVANIA|Advania]] for detaljer.', $this->catalog());

        $this->assertSame('Se [[advania|Advania]] for detaljer.', $result);
    }

    // 3: exact unique title is canonicalized to its slug
    public function test_unique_title_match_is_canonicalized_to_its_slug(): void
    {
        $result = $this->canonicalizer()->canonicalize('Se [[Risikostyring]] for mer.', $this->catalog());

        $this->assertSame('Se [[risikostyring|Risikostyring]] for mer.', $result);
    }

    // 4: [[Advania]] becomes [[advania|Advania]] for the matching catalog page
    public function test_bare_title_cased_slug_becomes_canonical_slug_with_anchor(): void
    {
        $result = $this->canonicalizer()->canonicalize('Prosjektet eies av [[Advania]].', $this->catalog());

        $this->assertSame('Prosjektet eies av [[advania|Advania]].', $result);
    }

    // 5: an existing explicit anchor is preserved
    public function test_existing_anchor_text_is_preserved(): void
    {
        $result = $this->canonicalizer()->canonicalize('Se [[Advania|leverandøren]] for mer.', $this->catalog());

        $this->assertSame('Se [[advania|leverandøren]] for mer.', $result);
    }

    // 6: unknown target is left untouched (still rejected downstream)
    public function test_unknown_target_is_left_untouched(): void
    {
        $markdown = 'Se [[does-not-exist]] for detaljer.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    // 7: an ambiguous title match is left untouched (still rejected downstream)
    public function test_ambiguous_title_match_is_left_untouched(): void
    {
        $catalog = [
            ['slug' => 'risiko-a', 'title' => 'Risiko', 'page_type' => 'concept'],
            ['slug' => 'risiko-b', 'title' => 'Risiko', 'page_type' => 'concept'],
        ];

        $markdown = 'Se [[Risiko]] for mer.';

        $result = $this->canonicalizer()->canonicalize($markdown, $catalog);

        $this->assertSame($markdown, $result);
    }

    // 8: a title not present in the catalog (e.g. the source page's own title) is left
    // untouched — the catalog passed to this class already excludes the page being generated,
    // so canonicalization can never manufacture a self-link.
    public function test_title_absent_from_catalog_is_left_untouched(): void
    {
        $markdown = 'Denne siden heter [[Denne Siden]].';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    // 9: a title/slug belonging to another customer is never in the catalog (customer-scoped
    // upstream), so it is left untouched — still rejected downstream as broken.
    public function test_cross_customer_target_absent_from_catalog_is_left_untouched(): void
    {
        $markdown = 'Se [[foreign-page|Foreign Page]] for mer.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    public function test_empty_catalog_returns_markdown_unchanged(): void
    {
        $markdown = 'Se [[Advania]] for detaljer.';

        $result = $this->canonicalizer()->canonicalize($markdown, []);

        $this->assertSame($markdown, $result);
    }

    public function test_wikilink_syntax_inside_fenced_code_is_never_rewritten(): void
    {
        $markdown = "Text.\n\n```\n[[Advania]]\n```\n\nMore text.";

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    public function test_wikilink_syntax_inside_inline_code_is_never_rewritten(): void
    {
        $markdown = 'Use `[[Advania]]` as an example.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    public function test_ordinary_markdown_link_is_unaffected(): void
    {
        $markdown = 'See [Advania](https://example.com) for details.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    public function test_malformed_span_is_left_untouched(): void
    {
        $markdown = 'Se [[]] her.';

        $result = $this->canonicalizer()->canonicalize($markdown, $this->catalog());

        $this->assertSame($markdown, $result);
    }

    public function test_multiple_occurrences_are_each_canonicalized(): void
    {
        $result = $this->canonicalizer()->canonicalize(
            'Se [[Advania]] og [[RISIKOSTYRING|Risikostyring]] her.',
            $this->catalog(),
        );

        $this->assertSame('Se [[advania|Advania]] og [[risikostyring|Risikostyring]] her.', $result);
    }
}
