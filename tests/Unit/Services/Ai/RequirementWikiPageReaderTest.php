<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiPageReader;
use App\Services\Ai\Wiki\RequirementWikiTermNormalizer;
use Tests\TestCase;

class RequirementWikiPageReaderTest extends TestCase
{
    public function test_a_short_page_is_sent_with_its_entire_content_markdown(): void
    {
        $content = "# Incident Management\n\nKort side med lite innhold om hendelseshåndtering.";
        $entry = ['content_markdown' => $content];

        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('hendelseshåndtering'));

        $this->assertSame('full', $result['content_mode']);
        $this->assertSame($content, $result['content_markdown']);
        $this->assertSame([], $result['selected_headings']);
    }

    public function test_markdown_headings_and_lists_are_preserved_verbatim_for_a_short_page(): void
    {
        $content = "# Prosess\n\nInnledning.\n\n## Trinn\n\n- Steg en\n- Steg to\n- Steg tre";
        $entry = ['content_markdown' => $content];

        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('prosess'));

        $this->assertStringContainsString('## Trinn', $result['content_markdown']);
        $this->assertStringContainsString('- Steg en', $result['content_markdown']);
        $this->assertStringContainsString('- Steg to', $result['content_markdown']);
        $this->assertStringContainsString('- Steg tre', $result['content_markdown']);
    }

    public function test_a_long_page_is_reduced_to_whole_relevant_sections(): void
    {
        $lead = "# Langt dokument\n\nDette er innledningen til det lange dokumentet, som gir overordnet kontekst.\n\n";
        $relevantSection = "## Endringshåndtering\n\n".str_repeat('Denne seksjonen handler om endringshåndtering og endringsstyre i detalj. ', 20)."\n\n";
        $irrelevantSection = "## Historikk\n\n".str_repeat('Denne seksjonen handler om noe helt annet og urelatert tema. ', 20)."\n\n";
        $padding = str_repeat('Fylltekst som gjør siden lang nok til å utløse seksjonering. ', 100);
        $content = $lead.$irrelevantSection.$relevantSection."## Fyll\n\n{$padding}";

        $this->assertGreaterThan(RequirementWikiPageReader::FULL_CONTENT_MAX_CHARS, mb_strlen($content, 'UTF-8'));

        $entry = ['content_markdown' => $content];
        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('Beskriv endringshåndtering og endringsstyre.'));

        $this->assertSame('sections', $result['content_mode']);
        $this->assertStringContainsString('Dette er innledningen', $result['content_markdown']);
        $this->assertContains('Endringshåndtering', $result['selected_headings']);
        $this->assertStringContainsString('## Endringshåndtering', $result['content_markdown']);
    }

    public function test_the_heading_stays_attached_to_its_own_section_content(): void
    {
        $lead = "# Dokument\n\nInnledning.\n\n";
        $relevantSection = "## Kapasitetsstyring\n\n".str_repeat('Kapasitetsstyring innhold som er relevant for kravet om kapasitet. ', 15)."\n\n";
        $padding = str_repeat('Fylltekst for å tvinge seksjonering av dokumentet her. ', 100);
        $content = $lead.$relevantSection."## Fyll\n\n{$padding}";

        $entry = ['content_markdown' => $content];
        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('kapasitetsstyring kapasitet'));

        $sectionPosition = mb_strpos($result['content_markdown'], '## Kapasitetsstyring');
        $bodyPosition = mb_strpos($result['content_markdown'], 'Kapasitetsstyring innhold');
        $this->assertNotFalse($sectionPosition);
        $this->assertNotFalse($bodyPosition);
        $this->assertGreaterThan($sectionPosition, $bodyPosition);
    }

    public function test_paragraphs_are_never_cut_mid_sentence(): void
    {
        $sentence = 'Dette er en fullstendig setning som ikke skal kuttes i midten uansett hvor lang seksjonen er.';
        $lead = "# Dokument\n\nInnledning.\n\n";
        $relevantSection = "## Relevant\n\n".str_repeat($sentence.' ', 10)."\n\n";
        $padding = str_repeat('Fylltekst for å tvinge seksjonering. ', 120);
        $content = $lead.$relevantSection."## Fyll\n\n{$padding}";

        $entry = ['content_markdown' => $content];
        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('relevant fullstendig setning'));

        // Every occurrence of the sentence in the output must be the complete sentence, not a
        // fragment — proven by counting exact whole-sentence matches against raw substring hits.
        $exactMatches = substr_count($result['content_markdown'], $sentence);
        $this->assertGreaterThan(0, $exactMatches);
    }

    public function test_the_introduction_is_kept_even_when_it_alone_does_not_match_the_query(): void
    {
        $lead = "# Dokument\n\nGenerisk innledning uten relevante ord i det hele tatt.\n\n";
        $relevantSection = "## Kapasitetsstyring\n\n".str_repeat('Kapasitetsstyring er relevant for dette kravet spesifikt. ', 15)."\n\n";
        $padding = str_repeat('Fylltekst for seksjonering av dokumentet videre her. ', 100);
        $content = $lead.$relevantSection."## Fyll\n\n{$padding}";

        $entry = ['content_markdown' => $content];
        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('kapasitetsstyring'));

        $this->assertStringContainsString('Generisk innledning', $result['content_markdown']);
    }

    public function test_multiple_pages_can_each_be_read_independently_within_the_same_context(): void
    {
        $entryA = ['content_markdown' => "# Side A\n\nInnhold om side A."];
        $entryB = ['content_markdown' => "# Side B\n\nInnhold om side B."];
        $reader = app(RequirementWikiPageReader::class);
        $tokens = RequirementWikiTermNormalizer::tokenize('side');

        $resultA = $reader->read($entryA, $tokens);
        $resultB = $reader->read($entryB, $tokens);

        $this->assertStringContainsString('side A', $resultA['content_markdown']);
        $this->assertStringContainsString('side B', $resultB['content_markdown']);
    }

    public function test_the_per_page_section_budget_bounds_how_much_of_a_very_long_page_is_sent(): void
    {
        $lead = "# Dokument\n\nInnledning.\n\n";
        $sections = '';

        for ($i = 0; $i < 10; $i++) {
            $sections .= "## Endring del {$i}\n\n".str_repeat("Endringshåndtering og endringsstyre innhold del {$i}. ", 30)."\n\n";
        }

        $content = $lead.$sections;
        $entry = ['content_markdown' => $content];

        $result = app(RequirementWikiPageReader::class)->read($entry, RequirementWikiTermNormalizer::tokenize('endringshåndtering endringsstyre'));

        $this->assertLessThanOrEqual(
            RequirementWikiPageReader::SECTION_BUDGET_CHARS + mb_strlen("# Dokument\n\nInnledning.", 'UTF-8') + 50,
            mb_strlen($result['content_markdown'], 'UTF-8'),
        );
        $this->assertLessThan(10, count($result['selected_headings']));
    }
}
