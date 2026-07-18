<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;

class EnterpriseWikiPageContentBlockService
{
    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    public function buildBlocks(string $markdown, EnterpriseWikiDocument $document, array $sourceElements): array
    {
        $elements = $sourceElements !== []
            ? $sourceElements
            : [$this->wholeDocumentElement($document)];

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
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUniqueBlockForExcerpt(EnterpriseWikiPageVersion $version, string $excerpt): ?array
    {
        $excerpt = $this->normalize($excerpt);

        if ($excerpt === '') {
            return null;
        }

        $matches = [];

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (str_contains($this->normalize((string) ($block['markdown'] ?? '')), $excerpt)) {
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

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }
}
