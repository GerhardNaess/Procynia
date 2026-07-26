<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;

/**
 * Fase 1 informative-vs-decorative image classification (CLAUDE.md: "AI supports structure, it
 * does not replace it" — no AI call here at all, deterministic signals only). Every category is
 * derived from caption/alt-text/dimensions/repetition already captured by DocumentTextExtractor::
 * extractDocxImages() — never from the image's own pixels. When no signal is conclusive the image
 * is classified 'unknown' rather than assumed informative, per Section 4 of the Fase 1 spec.
 *
 * 'decorative' and 'logo' are the only categories EnterpriseWikiDocumentSourceElementService/
 * EnterpriseWikiImageBlockBuilder treat as "do not show as Wiki content" (see isShowable()) —
 * logos/icons/decorative elements should not surface as figure blocks or citable source elements.
 */
class EnterpriseWikiImageClassificationService
{
    public const CATEGORY_INFORMATIVE = 'informative';

    public const CATEGORY_SCREENSHOT = 'screenshot';

    public const CATEGORY_DIAGRAM = 'diagram';

    public const CATEGORY_CHART = 'chart';

    public const CATEGORY_DECORATIVE = 'decorative';

    public const CATEGORY_LOGO = 'logo';

    public const CATEGORY_UNKNOWN = 'unknown';

    private const TINY_DIMENSION_PX = 48;

    private const SMALL_DIMENSION_PX = 150;

    private const REPEATED_OCCURRENCE_THRESHOLD = 3;

    /**
     * Purpose: Classify a single extracted image as informative/decorative/etc. using only
     * deterministic signals (caption, alt-text, dimensions, repetition).
     * Inputs: The image, and how many times an image with the same content_hash occurs elsewhere
     * in the same document (1 = unique).
     * Returns: One of the CATEGORY_* constants.
     * Side effects: None.
     */
    public function classify(DocxImageData $image, int $occurrenceCount): string
    {
        if ($this->isTiny($image)) {
            return self::CATEGORY_LOGO;
        }

        if ($occurrenceCount >= self::REPEATED_OCCURRENCE_THRESHOLD) {
            // A graphic pasted 3+ times in the same document (outside headers/footers, which
            // this parser never reads) behaves like a recurring brand element, not a one-off
            // informative figure.
            return self::CATEGORY_LOGO;
        }

        $normalizedText = $this->normalizedCaptionAndAltText($image);

        if ($normalizedText !== '' && preg_match('/logo|ikon|icon/u', $normalizedText) === 1) {
            // No word boundaries: Norwegian compounds a lot ("firmalogo", "selskapslogo"), so a
            // substring match catches those too, at the cost of also matching e.g. "ikoniske" —
            // an acceptable false positive given Section 4's bias toward conservative logo/icon
            // detection over showing branding as Wiki content.
            return self::CATEGORY_LOGO;
        }

        if ($normalizedText === '') {
            // No caption, no alt-text: only a size-based guess is available. A small image with
            // no textual signal at all is conservatively treated as decorative; a larger one is
            // left 'unknown' rather than assumed to be either informative or decorative.
            return $this->isSmall($image) ? self::CATEGORY_DECORATIVE : self::CATEGORY_UNKNOWN;
        }

        if (preg_match('/skjermbilde|screenshot/u', $normalizedText) === 1) {
            return self::CATEGORY_SCREENSHOT;
        }

        if (preg_match('/\b(graf|chart|statistikk)\b/u', $normalizedText) === 1) {
            return self::CATEGORY_CHART;
        }

        if (preg_match('/diagram|arkitektur|flytskjema|flowchart/u', $normalizedText) === 1) {
            return self::CATEGORY_DIAGRAM;
        }

        return self::CATEGORY_INFORMATIVE;
    }

    /**
     * Purpose: Decide whether a classified image should ever be shown as Wiki content — as a
     * citable source element, a figure block, or in Wiki-answer context (Section 4: "Logos, icons,
     * and decorative elements should normally not be shown as Wiki content").
     * Inputs: A category returned by classify().
     * Returns: Whether the category is eligible to become Wiki content.
     * Side effects: None.
     */
    public function isShowable(string $category): bool
    {
        return ! in_array($category, [self::CATEGORY_DECORATIVE, self::CATEGORY_LOGO], true);
    }

    /**
     * Purpose: Human-readable Norwegian label for a figure-type category, used in the deterministic
     * textual figure description (Section 8's "Figurtype:" line).
     * Inputs: A category returned by classify().
     * Returns: A short Norwegian label.
     * Side effects: None.
     */
    public function label(string $category): string
    {
        return match ($category) {
            self::CATEGORY_SCREENSHOT => 'skjermbilde',
            self::CATEGORY_DIAGRAM => 'diagram',
            self::CATEGORY_CHART => 'graf',
            self::CATEGORY_INFORMATIVE => 'informativt bilde',
            default => 'ukjent figurtype',
        };
    }

    private function isTiny(DocxImageData $image): bool
    {
        return $image->width !== null && $image->height !== null
            && $image->width <= self::TINY_DIMENSION_PX && $image->height <= self::TINY_DIMENSION_PX;
    }

    private function isSmall(DocxImageData $image): bool
    {
        return $image->width !== null && $image->height !== null
            && $image->width <= self::SMALL_DIMENSION_PX && $image->height <= self::SMALL_DIMENSION_PX;
    }

    private function normalizedCaptionAndAltText(DocxImageData $image): string
    {
        $combined = trim(implode(' ', array_filter([$image->caption, $image->altText])));

        return mb_strtolower($combined, 'UTF-8');
    }
}
