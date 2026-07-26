<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;

/**
 * Builds genuine, deterministic "image" content blocks (figure blocks) for Word images the AI-
 * generated page blocks actually cited — never AI-authored or AI-interpreted, matching
 * EnterpriseWikiTableBlockBuilder's precedent (see docs/chunking-strategy.md and CLAUDE.md: "AI
 * supports structure, it does not replace it"). A figure is attached to a generated page only when
 * a block cited it (via source_element_type=image, source_element_key matching "img{n}" — the
 * existing convention from EnterpriseWikiDocumentSourceElementService) — Fase 1 Section 3 forbids
 * attaching every image in a document to every page generated from it.
 *
 * Fase 1 scope: no per-image claims are generated here (contrast with
 * EnterpriseWikiTableBlockBuilder::tableClaimPayloads()) — an image's caption/alt-text is
 * contextual metadata, not a verified fact suitable for the claim-approval workflow (Section 9:
 * "do not create secure factual claims solely from pixels in this phase").
 */
class EnterpriseWikiImageBlockBuilder
{
    private const IMAGE_KEY_PATTERN = '/^img(\d+)$/';

    public function __construct(
        private readonly EnterpriseWikiImageClassificationService $classificationService,
        private readonly EnterpriseWikiImageDescriptionBuilder $descriptionBuilder,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $contentBlocks  AI-generated blocks already built by
     *                                                     EnterpriseWikiPageContentBlockService.
     * @return list<int> distinct image ordinals (DocxImageData::$imageIndex) referenced anywhere
     *                   in $contentBlocks
     */
    public function referencedImageIndexes(array $contentBlocks): array
    {
        $indexes = [];

        foreach ($contentBlocks as $block) {
            foreach ((array) ($block['source_elements'] ?? []) as $element) {
                if (! is_array($element) || ($element['source_element_type'] ?? null) !== EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE) {
                    continue;
                }

                $imageKey = (string) ($element['source_element_key'] ?? '');

                if (preg_match(self::IMAGE_KEY_PATTERN, $imageKey, $matches) === 1) {
                    $imageIndex = (int) $matches[1];

                    if (! in_array($imageIndex, $indexes, true)) {
                        $indexes[] = $imageIndex;
                    }
                }
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * @param  list<DocxImageData>  $images  full parse result, see
     *                                       EnterpriseWikiDocumentSourceElementService::imagesForDocument()
     * @param  list<int>  $imageIndexes  which images (by DocxImageData::$imageIndex) to build blocks for
     * @return list<array<string, mixed>> one content block per requested image, positioned
     *                                    starting at $startingPosition, in image order
     */
    public function buildImageBlocks(EnterpriseWikiDocument $document, array $images, array $imageIndexes, int $startingPosition): array
    {
        if ($imageIndexes === []) {
            return [];
        }

        $imagesByIndex = [];
        $occurrenceCounts = [];

        foreach ($images as $image) {
            $imagesByIndex[$image->imageIndex] = $image;
            $occurrenceCounts[$image->contentHash] = ($occurrenceCounts[$image->contentHash] ?? 0) + 1;
        }

        $blocks = [];
        $position = $startingPosition;

        foreach ($imageIndexes as $imageIndex) {
            $image = $imagesByIndex[$imageIndex] ?? null;

            if ($image === null) {
                continue;
            }

            // Classified with the same occurrence-count signal EnterpriseWikiDocumentSourceElementService
            // used to decide this image was citable in the first place — same $images list, same result.
            $category = $this->classificationService->classify($image, $occurrenceCounts[$image->contentHash] ?? 1);

            if (! $this->classificationService->isShowable($category)) {
                // Defense in depth: a citable element is only ever exposed for showable
                // categories (see EnterpriseWikiDocumentSourceElementService), but re-check here
                // too since this method trusts whatever indexes it is given.
                continue;
            }

            $blocks[] = $this->buildImageBlock($document, $image, $category, $position);
            $position++;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImageBlock(EnterpriseWikiDocument $document, DocxImageData $image, string $category, int $position): array
    {
        $citation = $this->descriptionBuilder->citation($image, $document);
        $description = $this->descriptionBuilder->build($image, $category, $document);
        $markdown = $this->renderMarkdown($image, $citation);

        return [
            'block_key' => sprintf('image-block-%04d', $image->imageIndex + 1),
            'position' => $position,
            'block_type' => 'image',
            'markdown' => $markdown,
            'image_data' => [
                'source_image_key' => $image->sourceImageKey,
                'figure_number' => $image->imageIndex + 1,
                'category' => $category,
                'alt_text' => $this->altTextForDisplay($image, $category),
                'caption' => $image->caption,
                'width' => $image->width,
                'height' => $image->height,
                'mime_type' => $image->mimeType,
                'description' => $description,
            ],
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256 ?? '',
            'document_version_hash' => $document->file_hash_sha256 ?? '',
            'source_element_key' => $image->sourceImageKey,
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
            'source_excerpt' => $description,
            'page_reference' => $citation,
            'source_elements' => [[
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_hash' => $document->file_hash_sha256 ?? '',
                'document_version_hash' => $document->file_hash_sha256 ?? '',
                'source_element_key' => $image->sourceImageKey,
                'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
                'source_excerpt' => $description,
                'page_reference' => $citation,
            ]],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /**
     * Decorative-leaning but still shown images (Fase 1 only shows 'informative'/'screenshot'/
     * 'diagram'/'chart'/'unknown') get their real alt-text when it actually describes the image;
     * an image with no alt-text at all gets an empty alt so screen readers skip it rather than
     * read a fabricated description (Section 6: "decorative images should have empty alt-text").
     */
    private function altTextForDisplay(DocxImageData $image, string $category): string
    {
        if ($category === EnterpriseWikiImageClassificationService::CATEGORY_DECORATIVE) {
            return '';
        }

        return trim((string) ($image->altText ?? ''));
    }

    /**
     * Markdown fallback so the block always has non-empty markdown (required by
     * WikiController::displayContentBlocks() to not be filtered out) even for a Markdown-only
     * consumer that ignores block_type/image_data — caption and citation only, no image syntax
     * (there is no public URL to embed; the frontend renders the real <img> from image_data).
     */
    private function renderMarkdown(DocxImageData $image, string $citation): string
    {
        $caption = $image->caption !== null && trim($image->caption) !== ''
            ? trim($image->caption)
            : sprintf('Figur %d', $image->imageIndex + 1);

        return sprintf('**%s**'."\n".'_Kilde: %s_', $caption, $citation);
    }
}
