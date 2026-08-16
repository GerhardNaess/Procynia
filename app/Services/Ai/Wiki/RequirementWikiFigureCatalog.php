<?php

namespace App\Services\Ai\Wiki;

/**
 * Turns a Wiki page version's deterministic "image" content blocks (see
 * EnterpriseWikiImageBlockBuilder) into figure metadata a requirement answer may offer to the
 * answer-writing model.
 *
 * A Wiki figure lives ONLY in content_blocks_json. Its content_markdown fallback is caption plus
 * citation and deliberately carries no image syntax ("there is no public URL to embed"), which is
 * exactly why a figure could never reach an answer as long as the answer engine read
 * content_markdown alone.
 *
 * Two rules make this safe to hand to a model:
 *
 * - Identity is never model-authored. A figure is addressed by the same pair the Wiki page itself
 *   uses — (document_id, source_image_key) — and the model only ever sees the opaque ref string
 *   built from it. It cannot construct a URL, a storage path, or a figure that was not offered.
 * - No bytes. Only text metadata travels to the model: the caption, the alt text, and the
 *   contextual description EnterpriseWikiImageDescriptionBuilder already produced at ingest.
 */
class RequirementWikiFigureCatalog
{
    public const BLOCK_TYPE = 'image';

    /**
     * Purpose: The stable, opaque handle the model selects a figure by.
     * Inputs: The owning Wiki document's id and the image key within it.
     * Returns: "fig:{document_id}:{source_image_key}".
     *
     * Never parsed to recover identity — a ref is only ever LOOKED UP in the map of figures that
     * were actually offered, so an invented ref cannot resolve to anything by construction.
     */
    public static function ref(int $documentId, string $sourceImageKey): string
    {
        return sprintf('fig:%d:%s', $documentId, $sourceImageKey);
    }

    /**
     * Purpose: Extract every usable figure from one page version's content blocks.
     * Inputs: The version's content_blocks_json as a plain array.
     * Returns: One entry per image block that carries a resolvable identity:
     *          {figure_ref, document_id, source_image_key, caption, alt_text, description,
     *           figure_number, page_reference, block_markdown}.
     *          block_markdown is the block's own Markdown fallback, kept so a caller can tell
     *          whether the figure survived into a partially-read page (see readable()).
     * Side effects: None.
     *
     * @param  array<int, mixed>  $contentBlocks
     * @return list<array<string, mixed>>
     */
    public function fromContentBlocks(array $contentBlocks): array
    {
        $figures = [];

        foreach ($contentBlocks as $block) {
            if (! is_array($block) || ($block['block_type'] ?? null) !== self::BLOCK_TYPE) {
                continue;
            }

            $imageData = $block['image_data'] ?? null;
            $documentId = (int) ($block['source_id'] ?? 0);
            $sourceImageKey = is_array($imageData) ? trim((string) ($imageData['source_image_key'] ?? '')) : '';

            // Same guard WikiController::renderedImageData() applies before it will build a URL:
            // a block without both halves of the identity is not a servable figure.
            if (! is_array($imageData) || $documentId <= 0 || $sourceImageKey === '') {
                continue;
            }

            $figures[] = [
                'figure_ref' => self::ref($documentId, $sourceImageKey),
                'document_id' => $documentId,
                'source_image_key' => $sourceImageKey,
                'caption' => $this->nullableString($imageData['caption'] ?? null),
                'alt_text' => $this->nullableString($imageData['alt_text'] ?? null),
                'description' => $this->nullableString($imageData['description'] ?? null),
                'figure_number' => isset($imageData['figure_number']) ? (int) $imageData['figure_number'] : null,
                'page_reference' => $this->nullableString($block['page_reference'] ?? null),
                'block_markdown' => trim((string) ($block['markdown'] ?? '')),
            ];
        }

        return $figures;
    }

    /**
     * Purpose: Narrow a page's figures to the ones a reader actually read.
     * Inputs: The page's figures and the content_markdown RequirementWikiPageReader returned for
     *         this run (which, for a long page, is only the selected sections).
     * Returns: The figures whose own Markdown block is present in that content.
     * Side effects: None.
     *
     * A long page is read section by section, so offering every figure on the page would let the
     * model illustrate the answer from material it never saw. Matching on the block's exact
     * Markdown is deterministic — it is the same string the serializer wrote into the page.
     *
     * @param  list<array<string, mixed>>  $figures
     * @return list<array<string, mixed>>
     */
    public function readable(array $figures, string $readContentMarkdown): array
    {
        return array_values(array_filter(
            $figures,
            static function (array $figure) use ($readContentMarkdown): bool {
                $blockMarkdown = (string) ($figure['block_markdown'] ?? '');

                return $blockMarkdown !== '' && str_contains($readContentMarkdown, $blockMarkdown);
            },
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
