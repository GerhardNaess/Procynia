<?php

namespace Tests\Unit\Services;

use App\Services\DocumentTextExtractor;
use App\Services\Knowledge\PdfFigurePreviewRenderer;
use Tests\TestCase;

class PdfFigurePreviewRendererTest extends TestCase
{
    public function test_it_keeps_pdf_figure_gap_crops_tight_and_stops_before_following_section_text(): void
    {
        $renderer = new PdfFigurePreviewRenderer($this->createMock(DocumentTextExtractor::class));

        $seedBlock = $this->figureBlock('Advania Risk Management', 450, 358, 396, 24);
        $matches = [
            $this->figureMatch($seedBlock, 20, true),
            $this->figureMatch($this->figureBlock('0. Identifisere', 490, 360, 110, 20), 8),
            $this->figureMatch($this->figureBlock('1. Beskrivelse', 490, 490, 118, 20), 8),
            $this->figureMatch($this->figureBlock('2. Analyse', 490, 640, 100, 20), 8),
            $this->figureMatch($this->figureBlock('Risiko register', 535, 358, 116, 20), 6),
            $this->figureMatch($this->figureBlock('Løste risikoer', 568, 358, 104, 20), 6),
            $this->figureMatch($this->figureBlock('5. Kontroll', 582, 486, 92, 20), 6),
            $this->figureMatch($this->figureBlock('3. Planlegge', 645, 726, 104, 20), 6),
            $this->figureMatch($this->figureBlock('4. Oppfølging', 681, 598, 108, 20), 6),
            $this->figureMatch($this->figureBlock('1.13 KOSTNADSSTYRING I PROSJEKTER', 772, 106, 214, 20), 1),
            $this->figureMatch($this->figureBlock(
                'Kostnadsstyring i prosjektet skal gjennomføres gjennom en strukturert prosess som sikrer løpende kontroll med prosjektets økonomiske rammer og status.',
                808,
                106,
                470,
                100,
            ), 1),
        ];

        $selectedBlocks = $this->invokePrivateMethod($renderer, 'clusterFigureMatches', [$matches, $seedBlock, 842]);
        $selectedTexts = array_values(array_map(
            static fn (array $match): string => (string) data_get($match, 'block.text', ''),
            $selectedBlocks,
        ));

        $this->assertContains('Advania Risk Management', $selectedTexts);
        $this->assertContains('4. Oppfølging', $selectedTexts);
        $this->assertNotContains('1.13 KOSTNADSSTYRING I PROSJEKTER', $selectedTexts);
        $this->assertNotContains('Kostnadsstyring i prosjektet skal gjennomføres gjennom en strukturert prosess som sikrer løpende kontroll med prosjektets økonomiske rammer og status.', $selectedTexts);

        $cropBox = $this->invokePrivateMethod($renderer, 'buildCropBox', [$selectedBlocks, 595, 842]);

        $this->assertIsArray($cropBox);
        $this->assertLessThan(772, (int) $cropBox['y'] + (int) $cropBox['height']);
    }

    /**
     * Purpose: Invoke a private method on the preview renderer for focused unit tests.
     * Inputs: The renderer instance, a private method name, and ordered arguments.
     * Returns: The invoked method result.
     *
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(PdfFigurePreviewRenderer $renderer, string $methodName, array $arguments = []): mixed
    {
        $method = new \ReflectionMethod($renderer, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($renderer, $arguments);
    }

    /**
     * Purpose: Build a synthetic PDF block for figure-preview clustering tests.
     * Inputs: The visible text and geometry.
     * Returns: A Poppler-like block array.
     *
     * @return array<string, mixed>
     */
    private function figureBlock(string $text, int $top, int $left, int $width, int $height): array
    {
        return [
            'text' => $text,
            'top' => $top,
            'left' => $left,
            'width' => $width,
            'height' => $height,
            'page_number' => 1,
            'page_width' => 595,
            'page_height' => 842,
        ];
    }

    /**
     * Purpose: Wrap one synthetic block in the shape used by the renderer's match list.
     * Inputs: A block and optional score/caption flags.
     * Returns: A renderer match descriptor.
     *
     * @param array<string, mixed> $block
     * @return array{block: array<string, mixed>, score: int, caption_match: bool}
     */
    private function figureMatch(array $block, int $score, bool $captionMatch = false): array
    {
        return [
            'block' => $block,
            'score' => $score,
            'caption_match' => $captionMatch,
        ];
    }
}
