<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;

class EnterpriseWikiPageContentBlockService
{
    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    public function buildBlocks(string $markdown, EnterpriseWikiDocument $document, array $sourceElements): array
    {
        $elements = $this->sourceElementsForGeneration($document, $sourceElements);

        $blocks = [];
        $parts = preg_split("/\n{2,}/", trim($markdown)) ?: [];

        foreach ($parts as $index => $part) {
            $text = trim($part);

            if ($text === '') {
                continue;
            }

            $element = $elements[min($index, count($elements) - 1)];

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) (count($blocks) + 1), 4, '0', STR_PAD_LEFT),
                'position' => count($blocks),
                'markdown' => $text,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'source_hash' => $document->file_hash_sha256 ?? '',
                'document_version_hash' => $document->file_hash_sha256 ?? '',
                'source_element_key' => $element['source_element_key'] ?? null,
                'source_element_type' => $element['source_element_type'] ?? null,
                'source_row_key' => $element['source_row_key'] ?? null,
                'source_excerpt' => $element['reference_text'] ?? mb_substr((string) $document->extracted_text, 0, 1000),
                'page_reference' => $element['page_reference'] ?? null,
                'source_elements' => [$this->sourceElementPayload($document, $element)],
                'best_practice_reason' => null,
                'link_intents' => [],
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    public function sourceElementsForGeneration(EnterpriseWikiDocument $document, array $sourceElements): array
    {
        return $sourceElements !== []
            ? $sourceElements
            : [$this->wholeDocumentElement($document)];
    }

    /**
     * @param  list<array<string, mixed>>  $structuredBlocks
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    public function buildBlocksFromStructuredResult(EnterpriseWikiDocument $document, array $structuredBlocks, array $sourceElements): array
    {
        $elements = [];

        foreach ($this->sourceElementsForGeneration($document, $sourceElements) as $element) {
            $key = (string) ($element['source_element_key'] ?? '');

            if ($key !== '') {
                $elements[$key] = $element;
            }
        }

        $blocks = [];

        foreach ($structuredBlocks as $block) {
            if (! is_array($block)) {
                throw new \RuntimeException('WikiPageContentAiClient: generated page block was invalid.');
            }

            $markdown = trim((string) ($block['markdown'] ?? ''));
            $origin = (string) ($block['content_origin'] ?? '');
            $sourceElementKeys = array_values(array_filter(
                (array) ($block['source_element_keys'] ?? []),
                static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
            ));

            if ($markdown === '') {
                throw new \RuntimeException('WikiPageContentAiClient: generated page block markdown was empty.');
            }

            $resolvedElements = [];

            foreach ($sourceElementKeys as $sourceElementKey) {
                if (! array_key_exists($sourceElementKey, $elements)) {
                    throw new \RuntimeException("WikiPageContentAiClient: generated block cited unknown source_element_key [{$sourceElementKey}].");
                }

                $resolvedElements[] = $elements[$sourceElementKey];
            }

            if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED && $resolvedElements === []) {
                throw new \RuntimeException('WikiPageContentAiClient: source-based block had no valid source elements.');
            }

            if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE && trim((string) ($block['best_practice_reason'] ?? '')) === '') {
                throw new \RuntimeException('WikiPageContentAiClient: best-practice block had no reason.');
            }

            if (! in_array($origin, [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            ], true)) {
                throw new \RuntimeException("WikiPageContentAiClient: generated block had unsupported content_origin [{$origin}].");
            }

            $primaryElement = $resolvedElements[0] ?? null;

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) (count($blocks) + 1), 4, '0', STR_PAD_LEFT),
                'position' => count($blocks),
                'markdown' => $markdown,
                'content_origin' => $origin,
                'source_type' => $primaryElement !== null ? EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT : null,
                'source_id' => $primaryElement !== null ? $document->id : null,
                'source_label' => $primaryElement !== null ? $document->original_filename : null,
                'source_hash' => $primaryElement !== null ? ($document->file_hash_sha256 ?? '') : null,
                'document_version_hash' => $primaryElement !== null ? ($document->file_hash_sha256 ?? '') : null,
                'source_element_key' => $primaryElement['source_element_key'] ?? null,
                'source_element_type' => $primaryElement['source_element_type'] ?? null,
                'source_row_key' => $primaryElement['source_row_key'] ?? null,
                'source_excerpt' => $primaryElement['reference_text'] ?? null,
                'page_reference' => $primaryElement['page_reference'] ?? null,
                'source_elements' => array_map(
                    fn (array $element): array => $this->sourceElementPayload($document, $element),
                    $resolvedElements,
                ),
                'best_practice_reason' => $origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    ? trim((string) ($block['best_practice_reason'] ?? ''))
                    : null,
                'link_intents' => array_values(array_filter(
                    (array) ($block['link_intents'] ?? []),
                    static fn (mixed $intent): bool => is_array($intent),
                )),
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUniqueBlockForExcerpt(EnterpriseWikiPageVersion $version, string $excerpt): ?array
    {
        if (trim($excerpt) === '') {
            return null;
        }

        $matches = [];

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            if ($this->textNormalizer->contains((string) ($block['markdown'] ?? ''), $excerpt)) {
                $matches[] = $block;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function replaceBlockMarkdown(EnterpriseWikiPageVersion $version, string $blockKey, string $replacement): bool
    {
        $blocks = (array) ($version->content_blocks_json ?? []);
        $oldMarkdown = null;
        $blockPosition = null;

        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ($block['block_key'] ?? null) !== $blockKey) {
                continue;
            }

            $oldMarkdown = (string) ($block['markdown'] ?? '');
            $blockPosition = is_numeric($block['position'] ?? null) ? (int) $block['position'] : null;
            $blocks[$index]['markdown'] = $replacement;
            break;
        }

        if ($oldMarkdown === null || $oldMarkdown === '') {
            return false;
        }

        $parts = preg_split("/\n{2,}/", trim((string) $version->content_markdown)) ?: [];
        $replaced = false;

        if ($blockPosition !== null
            && array_key_exists($blockPosition, $parts)
            && trim((string) $parts[$blockPosition]) === trim($oldMarkdown)) {
            $parts[$blockPosition] = $replacement;
            $replaced = true;
        }

        if (! $replaced) {
            $matchingIndexes = [];

            foreach ($parts as $index => $part) {
                if (trim($part) === trim($oldMarkdown)) {
                    $matchingIndexes[] = $index;
                }
            }

            if (count($matchingIndexes) === 1) {
                $parts[$matchingIndexes[0]] = $replacement;
                $replaced = true;
            }
        }

        if (! $replaced) {
            return false;
        }

        $version->update([
            'content_markdown' => implode("\n\n", $parts),
            'content_blocks_json' => array_values($blocks),
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function wholeDocumentElement(EnterpriseWikiDocument $document): array
    {
        return [
            'source_element_key' => 'document-'.$document->id.'-full-text',
            'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL,
            'source_row_key' => null,
            'reference_text' => mb_substr((string) $document->extracted_text, 0, 1000),
            'page_reference' => 'Hele dokumentet',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceElementPayload(EnterpriseWikiDocument $document, array $element): array
    {
        return [
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'source_hash' => $document->file_hash_sha256 ?? '',
            'document_version_hash' => $document->file_hash_sha256 ?? '',
            'source_element_key' => $element['source_element_key'] ?? null,
            'source_element_type' => $element['source_element_type'] ?? null,
            'source_row_key' => $element['source_row_key'] ?? null,
            'source_excerpt' => $element['reference_text'] ?? null,
            'page_reference' => $element['page_reference'] ?? null,
        ];
    }
}
