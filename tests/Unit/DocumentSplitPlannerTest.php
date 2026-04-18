<?php

namespace Tests\Unit;

use App\Models\SavedNoticeAiDocument;
use App\Services\Ai\Requirements\DocumentSplitPlanner;
use Tests\TestCase;

class DocumentSplitPlannerTest extends TestCase
{
    public function test_it_discards_content_before_the_first_body_h1_after_toc(): void
    {
        $documentText = implode("\n\n", [
            'Innholdsfortegnelse' . "\n" . '0. Innledning .... 1' . "\n" . '1. Kravspesifikasjon .... 2' . "\n" . '2. Vedlegg .... 3',
            'Dette er kontekst før første body-heading og skal ikke bli egen chunk.',
            '5. Dokumentasjonsforvaltning og oppbevaring 75',
            'Skal-krav 75',
            'Dette er body-kontekst som ikke skal starte en chunk.',
            '1. Første body heading' . "\n" . 'Leverandøren skal beskrive løsningen og leveransen.',
        ]);

        $result = $this->planDocument($documentText, 'split-toc-body-h1.docx', 201);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['split_plan']);

        $chunk = $result['split_plan'][0];
        $chunkText = $this->chunkText($documentText, $chunk);

        $this->assertStringStartsWith('1. Første body heading', $this->chunkFirstLine($documentText, $chunk));
        $this->assertStringNotContainsString('Dette er kontekst før første body-heading', $chunkText);
        $this->assertStringNotContainsString('5. Dokumentasjonsforvaltning og oppbevaring 75', $chunkText);
        $this->assertStringNotContainsString('Dette er body-kontekst som ikke skal starte en chunk.', $chunkText);
    }

    public function test_it_emits_chunks_from_one_h1_to_the_next_h1(): void
    {
        $documentText = implode("\n\n", [
            '1. Første seksjon' . "\n" . 'Leverandøren skal beskrive første del av leveransen.',
            '2. Andre seksjon' . "\n" . 'Leverandøren skal beskrive andre del av leveransen.',
            '3. Tredje seksjon' . "\n" . 'Leverandøren skal beskrive tredje del av leveransen.',
        ]);

        $result = $this->planDocument($documentText, 'split-h1-to-h1.docx', 202);

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $result['split_plan']);

        $this->assertStringStartsWith('1. Første seksjon', $this->chunkFirstLine($documentText, $result['split_plan'][0]));
        $this->assertStringStartsWith('2. Andre seksjon', $this->chunkFirstLine($documentText, $result['split_plan'][1]));
        $this->assertStringStartsWith('3. Tredje seksjon', $this->chunkFirstLine($documentText, $result['split_plan'][2]));

        $this->assertSame($result['split_plan'][0]['end_position'], $result['split_plan'][1]['start_position']);
        $this->assertSame($result['split_plan'][1]['end_position'], $result['split_plan'][2]['start_position']);

        $firstChunkText = $this->chunkText($documentText, $result['split_plan'][0]);
        $secondChunkText = $this->chunkText($documentText, $result['split_plan'][1]);

        $this->assertStringNotContainsString('2. Andre seksjon', $firstChunkText);
        $this->assertStringNotContainsString('3. Tredje seksjon', $secondChunkText);
    }

    public function test_it_keeps_a_single_large_h1_section_together(): void
    {
        $documentText = implode("\n\n", [
            '1. Stort hovedkapittel' . "\n" . str_repeat('Dette er et langt avsnitt som skal forbli i samme H1-chunk. ', 25),
        ]);

        $result = $this->planDocument($documentText, 'split-large-h1.docx', 203);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['split_plan']);

        $chunk = $result['split_plan'][0];

        $this->assertStringStartsWith('1. Stort hovedkapittel', $this->chunkFirstLine($documentText, $chunk));
        $this->assertStringContainsString('skal forbli i samme H1-chunk', $this->chunkText($documentText, $chunk));
    }

    public function test_it_does_not_use_h2_or_h3_headings_as_chunk_boundaries(): void
    {
        $documentText = implode("\n\n", [
            '1. Hovedkapittel' . "\n" . 'Innledning før underoverskriftene.' . "\n" . '1.1 Del A' . "\n" . 'Tekst under del A.' . "\n" . '1.1.1 Del A1' . "\n" . 'Tekst under del A1.' . "\n" . '1.2 Del B' . "\n" . 'Tekst under del B.',
        ]);

        $result = $this->planDocument($documentText, 'split-no-h2-h3-boundaries.docx', 204);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['split_plan']);

        $chunkText = $this->chunkText($documentText, $result['split_plan'][0]);

        $this->assertStringStartsWith('1. Hovedkapittel', $this->chunkFirstLine($documentText, $result['split_plan'][0]));
        $this->assertStringContainsString('1.1 Del A', $chunkText);
        $this->assertStringContainsString('1.1.1 Del A1', $chunkText);
        $this->assertStringContainsString('1.2 Del B', $chunkText);
    }

    public function test_it_does_not_use_blank_lines_as_chunk_boundaries(): void
    {
        $documentText = implode("\n\n", [
            '1. Hovedkapittel' . "\n" . "Første avsnitt.\n\nAndre avsnitt.\n\nTredje avsnitt som fortsatt skal bli i samme chunk.",
        ]);

        $result = $this->planDocument($documentText, 'split-no-blank-line-boundaries.docx', 205);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['split_plan']);

        $chunkText = $this->chunkText($documentText, $result['split_plan'][0]);

        $this->assertStringStartsWith('1. Hovedkapittel', $this->chunkFirstLine($documentText, $result['split_plan'][0]));
        $this->assertStringContainsString("Første avsnitt.\n\nAndre avsnitt.\n\nTredje avsnitt", $chunkText);
    }

    private function planDocument(string $text, string $filename, int $documentId): array
    {
        $document = new SavedNoticeAiDocument();
        $document->id = $documentId;
        $document->saved_notice_id = 9000 + $documentId;
        $document->original_filename = $filename;
        $document->extracted_text = $text;

        return app(DocumentSplitPlanner::class)->plan($document, sprintf('run-%s', $filename));
    }

    private function chunkText(string $documentText, array $chunk): string
    {
        return trim((string) mb_substr(
            $documentText,
            (int) ($chunk['start_position'] ?? 0),
            (int) ($chunk['end_position'] ?? 0) - (int) ($chunk['start_position'] ?? 0),
            'UTF-8'
        ));
    }

    private function chunkFirstLine(string $documentText, array $chunk): string
    {
        $chunkText = trim($this->chunkText($documentText, $chunk));
        $lines = preg_split('/\R/u', $chunkText, 2) ?: [];

        return trim((string) ($lines[0] ?? ''));
    }
}
