<?php

namespace Tests\Unit;

use App\Services\DocumentChunker;
use PHPUnit\Framework\TestCase;

class DocumentChunkerTest extends TestCase
{
    public function test_it_chunks_structured_blocks_only_on_real_h1_boundaries(): void
    {
        $blocks = [
            ['text' => 'Forord før første heading.', 'style' => 'Normal', 'level' => null],
            ['text' => '1 - Høy', 'style' => 'Normal', 'level' => null],
            ['text' => 'Applikasjonsdrift', 'style' => 'Overskrift 1', 'level' => 1],
            ['text' => 'Første avsnitt under hovedseksjonen.', 'style' => 'Normal', 'level' => null],
            ['text' => 'Underseksjon', 'style' => 'Heading 2', 'level' => 2],
            ['text' => 'Mer innhold i underseksjonen.', 'style' => 'Normal', 'level' => null],
            ['text' => 'Neste hovedseksjon', 'style' => 'Heading 1', 'level' => 1],
            ['text' => 'Siste innhold.', 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(2, $chunks);
        $this->assertStringNotContainsString('Forord før første heading.', $chunks[0]['content']);
        $this->assertStringNotContainsString('1 - Høy', $chunks[0]['content']);
        $this->assertStringContainsString('Applikasjonsdrift', $chunks[0]['content']);
        $this->assertStringContainsString('Første avsnitt under hovedseksjonen.', $chunks[0]['content']);
        $this->assertStringContainsString('Underseksjon', $chunks[0]['content']);
        $this->assertStringContainsString('Mer innhold i underseksjonen.', $chunks[0]['content']);
        $this->assertStringContainsString('Neste hovedseksjon', $chunks[1]['content']);
        $this->assertStringContainsString('Siste innhold.', $chunks[1]['content']);
    }

    public function test_it_assigns_h1_section_context_to_chunks_inside_single_heading_sections(): void
    {
        $blocks = [
            ['text' => 'Bemanning og roller', 'style' => 'Heading 1', 'level' => 1],
            ['text' => 'Første innholdsblokk under bemanningsseksjonen.', 'style' => 'Normal', 'level' => null],
            ['text' => 'Organisering og styring', 'style' => 'Heading 1', 'level' => 1],
            ['text' => 'Andre innholdsblokk under organisasjonsseksjonen.', 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(2, $chunks);
        $this->assertSame('Bemanning og roller', $chunks[0]['section_title']);
        $this->assertSame('Bemanning og roller', $chunks[0]['section_path']);
        $this->assertSame('Organisering og styring', $chunks[1]['section_title']);
        $this->assertSame('Organisering og styring', $chunks[1]['section_path']);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    public function test_it_assigns_h2_section_context_when_an_h1_section_overflows(): void
    {
        $blocks = [
            ['text' => 'SOC-tjenester', 'style' => 'Heading 1', 'level' => 1],
            ['text' => str_repeat('Intro før H2. ', 2200), 'style' => 'Normal', 'level' => null],
            ['text' => 'SIEM', 'style' => 'Heading 2', 'level' => 2],
            ['text' => 'Innhold om SIEM-området.', 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(1, $chunks);
        $this->assertSame('SIEM', $chunks[0]['section_title']);
        $this->assertSame('SOC-tjenester > SIEM', $chunks[0]['section_path']);
        $this->assertStringContainsString('SOC-tjenester', $chunks[0]['content']);
        $this->assertStringContainsString('SIEM', $chunks[0]['content']);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    public function test_it_groups_an_oversized_h1_section_into_few_h2_chunks(): void
    {
        $blocks = [
            ['text' => 'Kort seksjon', 'style' => 'Heading 1', 'level' => 1],
            ['text' => str_repeat('Kort innhold. ', 20), 'style' => 'Normal', 'level' => null],
            ['text' => 'Stor seksjon', 'style' => 'Heading 1', 'level' => 1],
            ['text' => str_repeat('Intro før H2. ', 100), 'style' => 'Normal', 'level' => null],
            ['text' => 'Underseksjon A', 'style' => 'Heading 2', 'level' => 2],
            ['text' => str_repeat('A', 11000), 'style' => 'Normal', 'level' => null],
            ['text' => 'Underseksjon B', 'style' => 'Heading 2', 'level' => 2],
            ['text' => str_repeat('B', 11000), 'style' => 'Normal', 'level' => null],
            ['text' => 'Underseksjon C', 'style' => 'Heading 2', 'level' => 2],
            ['text' => str_repeat('C', 11000), 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('Kort seksjon', $chunks[0]['content']);
        $this->assertStringContainsString('Stor seksjon', $chunks[1]['content']);
        $this->assertStringContainsString('Intro før H2.', $chunks[1]['content']);
        $this->assertStringContainsString('Underseksjon A', $chunks[1]['content']);
        $this->assertStringContainsString('Underseksjon B', $chunks[1]['content']);
        $this->assertStringNotContainsString('Underseksjon C', $chunks[1]['content']);
        $this->assertStringContainsString('Underseksjon C', $chunks[2]['content']);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    public function test_it_returns_the_whole_document_as_one_chunk_when_no_real_h1_exists(): void
    {
        $blocks = [
            ['text' => '1 - Høy', 'style' => 'Normal', 'level' => null],
            ['text' => 'Vanlig tekstlinje uten heading.', 'style' => 'Normal', 'level' => null],
            ['text' => '- Punkt 1', 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('1 - Høy', $chunks[0]['content']);
        $this->assertStringContainsString('Vanlig tekstlinje uten heading.', $chunks[0]['content']);
        $this->assertStringContainsString('- Punkt 1', $chunks[0]['content']);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    public function test_it_keeps_a_structured_body_block_intact_without_mid_block_splitting(): void
    {
        $blocks = [
            ['text' => 'Applikasjonsdrift', 'style' => 'Heading 1', 'level' => 1],
            ['text' => str_repeat('Dette er en lengre innholdsblokk som skal forbli samlet. ', 20), 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Applikasjonsdrift', $chunks[0]['content']);
        $this->assertStringContainsString('Dette er en lengre innholdsblokk', $chunks[0]['content']);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    public function test_it_preserves_offsets_for_structured_chunks(): void
    {
        $blocks = [
            ['text' => 'Forord før første heading.', 'style' => 'Normal', 'level' => null],
            ['text' => 'Applikasjonsdrift', 'style' => 'Heading 1', 'level' => 1],
            ['text' => 'Første avsnitt.', 'style' => 'Normal', 'level' => null],
            ['text' => 'Neste seksjon', 'style' => 'Heading 1', 'level' => 1],
            ['text' => 'Andre avsnitt.', 'style' => 'Normal', 'level' => null],
        ];

        $chunks = (new DocumentChunker())->chunkStructured($blocks);

        $this->assertCount(2, $chunks);
        $this->assertChunkOffsetsMatchSource($chunks, $this->structuredSourceText($blocks));
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    private function assertChunkOffsetsMatchSource(array $chunks, string $sourceText): void
    {
        $previousEnd = 0;

        foreach ($chunks as $chunk) {
            $content = (string) ($chunk['content'] ?? '');
            $start = (int) ($chunk['char_start'] ?? 0);
            $end = (int) ($chunk['char_end'] ?? 0);
            $expected = trim((string) mb_substr($sourceText, $start, max(0, $end - $start), 'UTF-8'));

            $this->assertGreaterThanOrEqual($previousEnd, $start);
            $this->assertSame($expected, $content);

            $previousEnd = $end;
        }
    }

    /**
     * @param array<int, array{text: string, style: ?string, level: ?int}> $blocks
     */
    private function structuredSourceText(array $blocks): string
    {
        $texts = [];

        foreach ($blocks as $block) {
            $text = trim((string) ($block['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $texts[] = $text;
        }

        return implode("\n\n", $texts);
    }
}
