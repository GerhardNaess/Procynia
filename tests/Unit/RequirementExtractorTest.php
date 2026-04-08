<?php

namespace Tests\Unit;

use App\Services\RequirementExtractor;
use Tests\TestCase;

class RequirementExtractorTest extends TestCase
{
    private function extractRequirementTexts(string $chunkText): array
    {
        return array_column(app(RequirementExtractor::class)->extractFromChunk($chunkText), 'requirement_text');
    }

    public function test_it_extracts_a_full_prose_sentence(): void
    {
        $texts = $this->extractRequirementTexts('Leverandøren skal levere dokumentasjon innen 10 dager.');

        $this->assertSame(['Leverandøren skal levere dokumentasjon innen 10 dager.'], $texts);
    }

    public function test_it_extracts_the_full_sentence_when_the_trigger_is_in_the_middle(): void
    {
        $texts = $this->extractRequirementTexts('Ved avtalens opphør skal skytjenesten avsluttes eller tilbakeføres til Kunden.');

        $this->assertSame(['Ved avtalens opphør skal skytjenesten avsluttes eller tilbakeføres til Kunden.'], $texts);
    }

    public function test_it_preserves_the_full_continuation_after_eller_in_the_broken_pattern(): void
    {
        $texts = $this->extractRequirementTexts(implode("\n", [
            'Skytjeneste skal avsluttes eller',
            'tilbakeføres til Kunden ved opphør av avtalen.',
        ]));

        $this->assertSame(['Skytjeneste skal avsluttes eller tilbakeføres til Kunden ved opphør av avtalen.'], $texts);
    }

    public function test_it_extracts_a_full_bullet_item(): void
    {
        $texts = $this->extractRequirementTexts('- Leverandøren skal ha etablert sikkerhetskopiering og gjenoppretting.');

        $this->assertSame(['Leverandøren skal ha etablert sikkerhetskopiering og gjenoppretting.'], $texts);
    }

    public function test_it_extracts_a_full_numbered_clause(): void
    {
        $texts = $this->extractRequirementTexts('3.2 Leverandøren skal sørge for logging av alle administrative handlinger.');

        $this->assertSame(['Leverandøren skal sørge for logging av alle administrative handlinger.'], $texts);
    }

    public function test_it_extracts_multiple_requirements_in_the_same_paragraph(): void
    {
        $texts = $this->extractRequirementTexts('Leverandøren skal levere dokumentasjon innen 10 dager. Kunden skal motta bekreftelse innen 5 dager.');

        $this->assertSame([
            'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'Kunden skal motta bekreftelse innen 5 dager.',
        ], $texts);
    }

    public function test_it_ignores_text_without_requirement_triggers(): void
    {
        $texts = $this->extractRequirementTexts('Dette er en ren beskrivende tekst.');

        $this->assertSame([], $texts);
    }

    public function test_it_normalizes_whitespace_and_newlines_inside_requirement_text(): void
    {
        $texts = $this->extractRequirementTexts("Leverandøren skal levere dokumentasjon \n innen 10 dager.");

        $this->assertSame(['Leverandøren skal levere dokumentasjon innen 10 dager.'], $texts);
    }

    public function test_it_recognizes_english_requirement_triggers(): void
    {
        $texts = $this->extractRequirementTexts('The supplier shall deliver documentation within 10 days.');

        $this->assertSame(['The supplier shall deliver documentation within 10 days.'], $texts);
    }
}
