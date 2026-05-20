<?php

namespace App\Services\Knowledge;

use App\Services\DocumentTextExtractor;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class PdfFigurePreviewRenderer
{
    private const PREVIEW_DPI = 144;

    private const DEFAULT_PADDING = 42;

    private const MIN_CROP_WIDTH = 360;

    private const MIN_CROP_HEIGHT = 260;

    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {
    }

    /**
     * Purpose: Render a PNG preview for a text-derived PDF figure chunk.
     * Inputs: Absolute source PDF path and the chunk payload that already describes the figure.
     * Returns: Preview image bytes and metadata, or null when the figure cannot be rendered safely.
     * Side effects: Reads the PDF, renders one page to a temporary PNG, and crops it in memory.
     *
     * @param array<string, mixed> $chunkPayload
     * @return array{
     *     image_bytes: string,
     *     image_mime_type: string,
     *     image_original_filename: string,
     *     image_width: ?int,
     *     image_height: ?int,
     *     image_metadata: array<string, mixed>
     * }|null
     */
    public function renderPreview(string $sourcePdfPath, array $chunkPayload): ?array
    {
        try {
            if (! is_file($sourcePdfPath) || ! is_readable($sourcePdfPath)) {
                return null;
            }

            $imageMetadata = is_array($chunkPayload['image_metadata'] ?? null) ? $chunkPayload['image_metadata'] : [];

            if ((string) ($imageMetadata['source'] ?? '') !== 'pdf_figure_gap') {
                return null;
            }

            if (! (bool) ($imageMetadata['derived_from_text'] ?? false)) {
                return null;
            }

            if ($this->hasStoredImageAsset($chunkPayload)) {
                return null;
            }

            $searchProfile = $this->buildSearchProfile($chunkPayload);

            if ($searchProfile['terms'] === [] && $searchProfile['phrases'] === []) {
                return null;
            }

            $blocks = $this->documentTextExtractor->extractStructuredText($sourcePdfPath);

            if ($blocks === []) {
                return null;
            }

            $selection = $this->selectCandidatePageAndBlocks($blocks, $searchProfile);

            if ($selection === null) {
                return null;
            }

            $pageNumber = (int) ($selection['page_number'] ?? 0);
            $pageWidth = (int) ($selection['page_width'] ?? 0);
            $pageHeight = (int) ($selection['page_height'] ?? 0);
            $selectedBlocks = array_values(array_filter(
                (array) ($selection['blocks'] ?? []),
                static fn (array $block): bool => is_array($block),
            ));

            if ($pageNumber <= 0 || $pageWidth <= 0 || $pageHeight <= 0 || $selectedBlocks === []) {
                return null;
            }

            $cropBox = $this->buildCropBox($selectedBlocks, $pageWidth, $pageHeight);

            if ($cropBox === null) {
                return null;
            }

            $pngBytes = $this->renderPdfCropToPngBytes($sourcePdfPath, $pageNumber, $pageWidth, $pageHeight, $cropBox);

            if (! is_string($pngBytes) || trim($pngBytes) === '') {
                return null;
            }

            $imageSize = @getimagesizefromstring($pngBytes);
            $previewMetadata = array_merge(
                $imageMetadata,
                [
                    'preview_generated' => true,
                    'preview_renderer' => 'pdftoppm_gd',
                    'preview_page_number' => $pageNumber,
                    'preview_crop_box' => $cropBox,
                    'preview_page_width' => $pageWidth,
                    'preview_page_height' => $pageHeight,
                ],
            );

            return [
                'image_bytes' => $pngBytes,
                'image_mime_type' => 'image/png',
                'image_original_filename' => $this->previewFilenameForChunk($chunkPayload, $pageNumber),
                'image_width' => is_array($imageSize) ? (int) ($imageSize[0] ?? 0) : null,
                'image_height' => is_array($imageSize) ? (int) ($imageSize[1] ?? 0) : null,
                'image_metadata' => $previewMetadata,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Purpose: Determine whether the chunk already has a stored image asset.
     * Inputs: The chunk payload that will be persisted.
     * Returns: True when the chunk already has image bytes or a file path.
     * Side effects: None.
     *
     * @param array<string, mixed> $chunkPayload
     */
    private function hasStoredImageAsset(array $chunkPayload): bool
    {
        $imageBytes = $chunkPayload['image_bytes'] ?? null;
        $imagePath = $chunkPayload['image_path'] ?? null;

        return (is_string($imageBytes) && trim($imageBytes) !== '')
            || (is_string($imagePath) && trim($imagePath) !== '');
    }

    /**
     * Purpose: Build a normalized search profile from the figure chunk text fields.
     * Inputs: A text-derived PDF figure chunk payload.
     * Returns: Search terms and phrases that can identify the figure on the source page.
     *
     * @param array<string, mixed> $chunkPayload
     * @return array{caption: string, phrases: array<int, string>, terms: array<int, string>}
     */
    private function buildSearchProfile(array $chunkPayload): array
    {
        $phrases = [];
        $terms = [];
        $caption = $this->normalizeSearchText((string) ($chunkPayload['image_caption'] ?? ''));

        foreach ([
            $chunkPayload['image_caption'] ?? null,
            $chunkPayload['image_alt_text'] ?? null,
            $chunkPayload['ocr_text'] ?? null,
            $chunkPayload['image_description'] ?? null,
        ] as $candidateText) {
            foreach ($this->searchFragmentsFromText($candidateText) as $fragment) {
                $normalized = $this->normalizeSearchText($fragment);

                if ($normalized === '') {
                    continue;
                }

                if (str_contains($normalized, ' ')) {
                    $phrases[] = $normalized;
                }

                foreach (preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    if (mb_strlen($word, 'UTF-8') < 4) {
                        continue;
                    }

                    $terms[] = $word;
                }
            }
        }

        $phrases = array_values(array_unique(array_filter($phrases, static fn (string $phrase): bool => $phrase !== '')));
        $terms = array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));

        return [
            'caption' => $caption,
            'phrases' => $phrases,
            'terms' => $terms,
        ];
    }

    /**
     * Purpose: Split a chunk text field into search fragments.
     * Inputs: One text field from the figure chunk payload.
     * Returns: Non-empty fragments that can be used for page and block matching.
     *
     * @return array<int, string>
     */
    private function searchFragmentsFromText(mixed $text): array
    {
        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $fragments = preg_split('/[\r\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($fragments === []) {
            return [trim($text)];
        }

        return array_values(array_filter(array_map(static fn (string $fragment): string => trim($fragment), $fragments), static fn (string $fragment): bool => $fragment !== ''));
    }

    /**
     * Purpose: Find the best page candidate and the blocks that likely belong to the figure.
     * Inputs: Raw structured PDF blocks and a normalized search profile.
     * Returns: The chosen page, its geometry, and the matched blocks.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array{caption: string, phrases: array<int, string>, terms: array<int, string>} $searchProfile
     * @return array{page_number: int, page_width: int, page_height: int, blocks: array<int, array<string, mixed>>}|null
     */
    private function selectCandidatePageAndBlocks(array $blocks, array $searchProfile): ?array
    {
        $pageStats = [];

        foreach ($blocks as $block) {
            $pageNumber = (int) ($block['page_number'] ?? 0);
            $text = $this->normalizeSearchText((string) ($block['text'] ?? ''));

            if ($pageNumber <= 0 || $text === '') {
                continue;
            }

            $score = 0;
            $captionMatch = false;

            foreach ($searchProfile['phrases'] as $phrase) {
                if ($phrase !== '' && str_contains($text, $phrase)) {
                    $score += 8;
                }
            }

            if ($searchProfile['caption'] !== '' && str_contains($text, $searchProfile['caption'])) {
                $score += 20;
                $captionMatch = true;
            }

            foreach ($searchProfile['terms'] as $term) {
                if ($term !== '' && str_contains($text, $term)) {
                    $score++;
                }
            }

            if ($score <= 0) {
                continue;
            }

            if (! isset($pageStats[$pageNumber])) {
                $pageStats[$pageNumber] = [
                    'score' => 0,
                    'matches' => [],
                    'page_width' => (int) ($block['page_width'] ?? 0),
                    'page_height' => (int) ($block['page_height'] ?? 0),
                ];
            }

            $pageStats[$pageNumber]['score'] += $score;
            $pageStats[$pageNumber]['matches'][] = [
                'block' => $block,
                'score' => $score,
                'caption_match' => $captionMatch,
            ];

            if ((int) ($pageStats[$pageNumber]['page_width'] ?? 0) <= 0 && (int) ($block['page_width'] ?? 0) > 0) {
                $pageStats[$pageNumber]['page_width'] = (int) $block['page_width'];
            }

            if ((int) ($pageStats[$pageNumber]['page_height'] ?? 0) <= 0 && (int) ($block['page_height'] ?? 0) > 0) {
                $pageStats[$pageNumber]['page_height'] = (int) $block['page_height'];
            }
        }

        if ($pageStats === []) {
            return null;
        }

        $bestPageNumber = null;
        $bestScore = -1;
        $bestBlocks = [];
        $bestPageWidth = 0;
        $bestPageHeight = 0;

        foreach ($pageStats as $pageNumber => $stats) {
            $score = (int) ($stats['score'] ?? 0);
            $matchesOnPage = array_values(array_filter(
                (array) ($stats['matches'] ?? []),
                static fn (array $match): bool => is_array($match) && is_array($match['block'] ?? null),
            ));

            if ($score > $bestScore || ($score === $bestScore && count($matchesOnPage) > count($bestBlocks))) {
                $bestScore = $score;
                $bestPageNumber = (int) $pageNumber;
                $bestBlocks = $matchesOnPage;
                $bestPageWidth = (int) ($stats['page_width'] ?? 0);
                $bestPageHeight = (int) ($stats['page_height'] ?? 0);
            }
        }

        if ($bestPageNumber === null || $bestBlocks === [] || $bestPageWidth <= 0 || $bestPageHeight <= 0) {
            return null;
        }

        $caption = (string) ($searchProfile['caption'] ?? '');
        $seedBlock = null;

        if ($caption !== '') {
            foreach ($bestBlocks as $match) {
                $block = (array) ($match['block'] ?? []);
                $blockText = $this->normalizeSearchText((string) ($block['text'] ?? ''));

                if ($blockText !== '' && str_contains($blockText, $caption)) {
                    $seedBlock = $block;

                    break;
                }
            }
        }

        if ($seedBlock === null) {
            usort(
                $bestBlocks,
                static function (array $left, array $right): int {
                    return ((int) data_get($left, 'block.top', 0)) <=> ((int) data_get($right, 'block.top', 0));
                },
            );

            $seedBlock = (array) data_get($bestBlocks[0] ?? [], 'block', null);
        }

        if (! is_array($seedBlock)) {
            return null;
        }

        $selectedBlocks = $this->clusterFigureMatches($bestBlocks, $seedBlock, $bestPageHeight);

        if ($selectedBlocks === []) {
            $selectedBlocks = [[
                'block' => $seedBlock,
                'score' => 1,
                'caption_match' => false,
            ]];
        }

        return [
            'page_number' => $bestPageNumber,
            'page_width' => $bestPageWidth,
            'page_height' => $bestPageHeight,
            'blocks' => $selectedBlocks,
        ];
    }

    /**
     * Purpose: Keep only the contiguous figure-like block cluster around the chosen seed block.
     * Inputs: The matched blocks on the best page, the seed block, and the page height.
     * Returns: A compact ordered subset of blocks that belong to the same figure region.
     *
     * @param array<int, array{block: array<string, mixed>, score: int, caption_match: bool}> $matches
     * @param array<string, mixed> $seedBlock
     * @return array<int, array{block: array<string, mixed>, score: int, caption_match: bool}>
     */
    private function clusterFigureMatches(array $matches, array $seedBlock, int $pageHeight): array
    {
        if ($matches === []) {
            return [];
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                $topComparison = ((int) data_get($left, 'block.top', 0)) <=> ((int) data_get($right, 'block.top', 0));

                if ($topComparison !== 0) {
                    return $topComparison;
                }

                return ((int) data_get($left, 'block.left', 0)) <=> ((int) data_get($right, 'block.left', 0));
            },
        );

        $seedIndex = null;
        $seedTop = (int) ($seedBlock['top'] ?? 0);
        $seedLeft = (int) ($seedBlock['left'] ?? 0);
        $seedWidth = max(1, (int) ($seedBlock['width'] ?? 0));

        foreach ($matches as $index => $match) {
            $block = is_array($match['block'] ?? null) ? $match['block'] : [];

            if ((int) ($block['top'] ?? 0) === $seedTop && (int) ($block['left'] ?? 0) === $seedLeft && max(1, (int) ($block['width'] ?? 0)) === $seedWidth) {
                $seedIndex = $index;

                break;
            }
        }

        if ($seedIndex === null) {
            $seedIndex = 0;
        }

        $gapThreshold = $this->figureClusterGapThreshold($pageHeight);
        $horizontalPadding = 24;
        $cluster = [];
        $clusterLeft = null;
        $clusterRight = null;
        $clusterBottom = null;

        for ($index = $seedIndex; $index < count($matches); $index++) {
            $match = $matches[$index];
            $block = is_array($match['block'] ?? null) ? $match['block'] : [];

            if ($block === []) {
                continue;
            }

            if ($cluster === []) {
                $cluster[] = $match;
                $clusterLeft = (int) ($block['left'] ?? 0);
                $clusterRight = $clusterLeft + max(1, (int) ($block['width'] ?? 0));
                $clusterBottom = (int) ($block['top'] ?? 0) + max(1, (int) ($block['height'] ?? 0));

                continue;
            }

            $blockTop = (int) ($block['top'] ?? 0);
            $blockLeft = (int) ($block['left'] ?? 0);
            $blockRight = $blockLeft + max(1, (int) ($block['width'] ?? 0));
            $verticalGap = max(0, $blockTop - (int) $clusterBottom);
            $overlapsCluster = $this->blockOverlapsHorizontalCluster($blockLeft, $blockRight, (int) $clusterLeft, (int) $clusterRight, $horizontalPadding);

            if ($verticalGap > $gapThreshold && ! $overlapsCluster) {
                break;
            }

            if ($verticalGap > (int) ceil($gapThreshold * 1.5) && ! $overlapsCluster) {
                break;
            }

            $cluster[] = $match;
            $clusterLeft = min((int) $clusterLeft, $blockLeft);
            $clusterRight = max((int) $clusterRight, $blockRight);
            $clusterBottom = max((int) $clusterBottom, $blockTop + max(1, (int) ($block['height'] ?? 0)));
        }

        return $cluster;
    }

    /**
     * Purpose: Determine the maximum vertical gap allowed between blocks in one figure cluster.
     * Inputs: The PDF page height.
     * Returns: A conservative gap threshold in page coordinates.
     */
    private function figureClusterGapThreshold(int $pageHeight): int
    {
        if ($pageHeight <= 0) {
            return 80;
        }

        return max(48, min(72, (int) round($pageHeight * 0.05)));
    }

    /**
     * Purpose: Determine whether one block horizontally overlaps the current figure cluster.
     * Inputs: The candidate block bounds and the current cluster bounds.
     * Returns: True when the candidate is horizontally close enough to stay in the figure cluster.
     */
    private function blockOverlapsHorizontalCluster(int $blockLeft, int $blockRight, int $clusterLeft, int $clusterRight, int $padding): bool
    {
        return $blockRight >= ($clusterLeft - $padding) && $blockLeft <= ($clusterRight + $padding);
    }

    /**
     * Purpose: Build a conservative crop box around the selected figure blocks.
     * Inputs: Candidate blocks and the page geometry.
     * Returns: A crop box in PDF page coordinates or null when the blocks are unusable.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function buildCropBox(array $blocks, int $pageWidth, int $pageHeight): ?array
    {
        $left = null;
        $top = null;
        $right = null;
        $bottom = null;

        foreach ($blocks as $match) {
            $block = is_array($match['block'] ?? null) ? $match['block'] : [];

            if ($block === []) {
                continue;
            }

            $blockLeft = (int) ($block['left'] ?? 0);
            $blockTop = (int) ($block['top'] ?? 0);
            $blockWidth = max(1, (int) ($block['width'] ?? 0));
            $blockHeight = max(1, (int) ($block['height'] ?? 0));
            $blockRight = $blockLeft + $blockWidth;
            $blockBottom = $blockTop + $blockHeight;

            if ($left === null || $blockLeft < $left) {
                $left = $blockLeft;
            }

            if ($top === null || $blockTop < $top) {
                $top = $blockTop;
            }

            if ($right === null || $blockRight > $right) {
                $right = $blockRight;
            }

            if ($bottom === null || $blockBottom > $bottom) {
                $bottom = $blockBottom;
            }
        }

        if ($left === null || $top === null || $right === null || $bottom === null) {
            return null;
        }

        $left = max(0, $left - self::DEFAULT_PADDING);
        $top = max(0, $top - self::DEFAULT_PADDING);
        $right = min($pageWidth, $right + self::DEFAULT_PADDING);
        $bottom = min($pageHeight, $bottom + self::DEFAULT_PADDING);

        if ($right <= $left || $bottom <= $top) {
            return null;
        }

        $width = $right - $left;
        $height = $bottom - $top;

        if ($width < self::MIN_CROP_WIDTH) {
            $needed = self::MIN_CROP_WIDTH - $width;
            $left = max(0, $left - (int) floor($needed / 2));
            $right = min($pageWidth, $left + self::MIN_CROP_WIDTH);

            if ($right - $left < self::MIN_CROP_WIDTH) {
                $left = max(0, $right - self::MIN_CROP_WIDTH);
            }
        }

        if ($height < self::MIN_CROP_HEIGHT) {
            $needed = self::MIN_CROP_HEIGHT - $height;
            $top = max(0, $top - (int) floor($needed / 2));
            $bottom = min($pageHeight, $top + self::MIN_CROP_HEIGHT);

            if ($bottom - $top < self::MIN_CROP_HEIGHT) {
                $top = max(0, $bottom - self::MIN_CROP_HEIGHT);
            }
        }

        $width = max(1, min($pageWidth - $left, $right - $left));
        $height = max(1, min($pageHeight - $top, $bottom - $top));

        return [
            'x' => $left,
            'y' => $top,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Purpose: Render one cropped PDF page region into PNG bytes.
     * Inputs: Source PDF path, page number, page geometry, and the crop box.
     * Returns: PNG bytes or null when the rendering pipeline fails.
     *
     * @param array{x: int, y: int, width: int, height: int} $cropBox
     */
    private function renderPdfCropToPngBytes(string $sourcePdfPath, int $pageNumber, int $pageWidth, int $pageHeight, array $cropBox): ?string
    {
        $fullPagePrefix = tempnam(sys_get_temp_dir(), 'pdf-figure-page-');

        if ($fullPagePrefix === false) {
            return null;
        }

        @unlink($fullPagePrefix);

        $fullPagePath = $fullPagePrefix.'.png';
        $process = new Process([
            'pdftoppm',
            '-r',
            (string) self::PREVIEW_DPI,
            '-f',
            (string) $pageNumber,
            '-l',
            (string) $pageNumber,
            '-png',
            '-singlefile',
            $sourcePdfPath,
            $fullPagePrefix,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($fullPagePath)) {
            @unlink($fullPagePath);
            @unlink($fullPagePrefix);

            return null;
        }

        $fullImage = @imagecreatefrompng($fullPagePath);

        if (! is_resource($fullImage) && ! $fullImage instanceof \GdImage) {
            @unlink($fullPagePath);
            @unlink($fullPagePrefix);

            return null;
        }

        imagesavealpha($fullImage, true);

        $fullWidth = imagesx($fullImage);
        $fullHeight = imagesy($fullImage);
        $scaleX = $pageWidth > 0 ? $fullWidth / $pageWidth : 1.0;
        $scaleY = $pageHeight > 0 ? $fullHeight / $pageHeight : 1.0;

        $scaledCrop = [
            'x' => max(0, (int) floor($cropBox['x'] * $scaleX)),
            'y' => max(0, (int) floor($cropBox['y'] * $scaleY)),
            'width' => max(1, (int) ceil($cropBox['width'] * $scaleX)),
            'height' => max(1, (int) ceil($cropBox['height'] * $scaleY)),
        ];

        if ($scaledCrop['x'] + $scaledCrop['width'] > $fullWidth) {
            $scaledCrop['width'] = max(1, $fullWidth - $scaledCrop['x']);
        }

        if ($scaledCrop['y'] + $scaledCrop['height'] > $fullHeight) {
            $scaledCrop['height'] = max(1, $fullHeight - $scaledCrop['y']);
        }

        $cropped = imagecrop($fullImage, $scaledCrop);

        if ($cropped === false) {
            $cropped = imagecreatetruecolor($scaledCrop['width'], $scaledCrop['height']);

            if ($cropped === false) {
                imagedestroy($fullImage);
                @unlink($fullPagePath);
                @unlink($fullPagePrefix);

                return null;
            }

            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            imagecopy(
                $cropped,
                $fullImage,
                0,
                0,
                $scaledCrop['x'],
                $scaledCrop['y'],
                $scaledCrop['width'],
                $scaledCrop['height'],
            );
        }

        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        ob_start();
        imagepng($cropped);
        $pngBytes = ob_get_clean();

        imagedestroy($fullImage);
        imagedestroy($cropped);
        @unlink($fullPagePath);
        @unlink($fullPagePrefix);

        return is_string($pngBytes) && $pngBytes !== '' ? $pngBytes : null;
    }

    /**
     * Purpose: Build a stable filename for the rendered preview asset.
     * Inputs: The figure chunk payload and the resolved page number.
     * Returns: A filename with a PNG extension.
     *
     * @param array<string, mixed> $chunkPayload
     */
    private function previewFilenameForChunk(array $chunkPayload, int $pageNumber): string
    {
        $caption = $this->normalizeSearchText((string) ($chunkPayload['image_caption'] ?? ''));
        $slug = trim(Str::slug($caption, '-'), '-');

        if ($slug === '') {
            $slug = 'pdf-figure-'.$pageNumber;
        }

        return $slug.'.png';
    }

    /**
     * Purpose: Normalize a text string for loose search matching.
     * Inputs: A raw caption or OCR fragment.
     * Returns: Lower-cased ASCII text with punctuation collapsed to spaces.
     */
    private function normalizeSearchText(string $text): string
    {
        $text = Str::ascii(mb_strtolower(trim($text), 'UTF-8'));
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        return trim($text);
    }
}
