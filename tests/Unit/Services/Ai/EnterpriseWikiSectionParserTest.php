<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use Tests\TestCase;

class EnterpriseWikiSectionParserTest extends TestCase
{
    private EnterpriseWikiSectionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new EnterpriseWikiSectionParser();
    }

    // --- Claim parsing ---

    public function test_empty_claim_text_is_rejected(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => '', 'confidence' => 'high', 'excerpt' => null, 'conflict_note' => null],
            ],
        ]);

        $this->assertEmpty($result);
    }

    public function test_whitespace_only_claim_text_is_rejected(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => '   ', 'confidence' => 'high', 'excerpt' => null, 'conflict_note' => null],
            ],
        ]);

        $this->assertEmpty($result);
    }

    public function test_valid_claim_is_included_in_result(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'Selskapet har ISO 9001-sertifisering.', 'confidence' => 'high', 'excerpt' => 'ISO 9001 bekreftet 2023.', 'conflict_note' => null],
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Selskapet har ISO 9001-sertifisering.', $result[0]['text']);
    }

    public function test_non_null_conflict_note_sets_conflict_flag_to_true(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'Selskapet er sertifisert.', 'confidence' => 'medium', 'excerpt' => null, 'conflict_note' => 'Dokument B angir noe annet.'],
            ],
        ]);

        $this->assertTrue($result[0]['conflict_flag']);
    }

    public function test_null_conflict_note_sets_conflict_flag_to_false(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'Selskapet er sertifisert.', 'confidence' => 'high', 'excerpt' => null, 'conflict_note' => null],
            ],
        ]);

        $this->assertFalse($result[0]['conflict_flag']);
    }

    public function test_excerpt_longer_than_500_chars_is_trimmed_to_limit(): void
    {
        $longExcerpt = str_repeat('a', 600);

        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'En påstand.', 'confidence' => 'low', 'excerpt' => $longExcerpt, 'conflict_note' => null],
            ],
        ]);

        $this->assertSame(EnterpriseWikiSectionParser::MAX_EXCERPT_CHARS, mb_strlen($result[0]['excerpt']));
    }

    public function test_excerpt_at_exact_limit_is_not_modified(): void
    {
        $exactExcerpt = str_repeat('b', EnterpriseWikiSectionParser::MAX_EXCERPT_CHARS);

        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'En påstand.', 'confidence' => 'low', 'excerpt' => $exactExcerpt, 'conflict_note' => null],
            ],
        ]);

        $this->assertSame($exactExcerpt, $result[0]['excerpt']);
    }

    public function test_null_excerpt_is_stored_as_empty_string(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'En påstand.', 'confidence' => 'uncertain', 'excerpt' => null, 'conflict_note' => null],
            ],
        ]);

        $this->assertSame('', $result[0]['excerpt']);
    }

    public function test_mixed_valid_and_empty_claims_returns_only_valid(): void
    {
        $result = $this->parser->parseClaimsFromResponse([
            'claims' => [
                ['text' => 'Gyldig påstand.', 'confidence' => 'high', 'excerpt' => null, 'conflict_note' => null],
                ['text' => '', 'confidence' => 'high', 'excerpt' => null, 'conflict_note' => null],
                ['text' => 'En annen gyldig påstand.', 'confidence' => 'medium', 'excerpt' => null, 'conflict_note' => null],
            ],
        ]);

        $this->assertCount(2, $result);
    }

    public function test_missing_claims_key_returns_empty_array(): void
    {
        $result = $this->parser->parseClaimsFromResponse(['proposed_topic' => 'HMS']);

        $this->assertSame([], $result);
    }

    // --- Section splitting ---

    public function test_splits_text_on_h2_boundaries(): void
    {
        $text = "## Første seksjon\nNoe innhold her.\n\n## Andre seksjon\nMer innhold.";

        $sections = $this->parser->splitIntoSections($text);

        $this->assertCount(2, $sections);
        $this->assertSame('Første seksjon', $sections[0]['heading']);
        $this->assertSame('Andre seksjon', $sections[1]['heading']);
        $this->assertStringContainsString('Noe innhold her.', $sections[0]['content']);
        $this->assertStringContainsString('Mer innhold.', $sections[1]['content']);
    }

    public function test_splits_text_on_h1_boundaries(): void
    {
        $text = "# Kapittel 1\nFørste del.\n\n# Kapittel 2\nAndre del.";

        $sections = $this->parser->splitIntoSections($text);

        $this->assertCount(2, $sections);
        $this->assertSame('Kapittel 1', $sections[0]['heading']);
        $this->assertSame('Kapittel 2', $sections[1]['heading']);
    }

    public function test_fallback_splitting_is_used_when_no_headings_detected(): void
    {
        $text = str_repeat("Setning uten overskrift. ", 200);

        $sections = $this->parser->splitIntoSections($text);

        $this->assertGreaterThan(1, count($sections));

        foreach ($sections as $section) {
            $this->assertNull($section['heading']);
        }
    }

    public function test_fallback_sections_contain_non_empty_content(): void
    {
        $text = str_repeat('x', EnterpriseWikiSectionParser::MAX_SECTION_CHARS * 2);

        $sections = $this->parser->splitIntoSections($text);

        foreach ($sections as $section) {
            $this->assertNotEmpty($section['content']);
        }
    }

    public function test_max_sections_limit_is_enforced_for_heading_split(): void
    {
        $parts = [];

        for ($i = 1; $i <= 30; $i++) {
            $parts[] = "## Seksjon {$i}\nInnhold for seksjon {$i}.";
        }

        $text = implode("\n\n", $parts);

        $sections = $this->parser->splitIntoSections($text);

        $this->assertCount(EnterpriseWikiSectionParser::MAX_SECTIONS, $sections);
    }

    public function test_max_sections_limit_is_enforced_for_fallback_split(): void
    {
        $text = str_repeat("Tekst. ", EnterpriseWikiSectionParser::MAX_SECTION_CHARS * 25);

        $sections = $this->parser->splitIntoSections($text);

        $this->assertLessThanOrEqual(EnterpriseWikiSectionParser::MAX_SECTIONS, count($sections));
    }

    public function test_empty_text_returns_no_sections(): void
    {
        $sections = $this->parser->splitIntoSections('');

        $this->assertSame([], $sections);
    }

    public function test_single_section_with_one_heading_returns_one_section(): void
    {
        $text = "## Én seksjon\nInnhold.";

        $sections = $this->parser->splitIntoSections($text);

        $this->assertCount(1, $sections);
        $this->assertSame('Én seksjon', $sections[0]['heading']);
    }
}
