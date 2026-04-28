<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItemChunk;
use Illuminate\Support\Str;

class KnowledgeChunkBuilder
{
    /**
     * Purpose: Build final chunk payloads from the canonical source text and validated chunk plans.
     * Inputs: The canonical source text and validated chunk plans.
     * Returns: Database-ready chunk payloads that can be inserted through the knowledge item relation.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $chunkPlans
     * @return array<int, array<string, mixed>>
     */
    public function build(string $sourceText, array $chunkPlans): array
    {
        $payloads = [];

        foreach (array_values($chunkPlans) as $index => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $startOffset = (int) data_get($plan, 'start_offset', 0);
            $endOffset = (int) data_get($plan, 'end_offset', $startOffset);
            $contentLength = max(0, $endOffset - $startOffset);
            $content = mb_substr($sourceText, $startOffset, $contentLength, 'UTF-8');

            $payloads[] = [
                'chunk_index' => $index,
                'content' => $content,
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
                'title' => $this->chunkTitle($plan, $content),
                'heading_path' => $this->nullableString(data_get($plan, 'heading_path')),
                'chunk_type' => $this->nullableString(data_get($plan, 'chunk_type')) ?? 'semantic',
                'section_title' => $this->sectionTitle($plan),
                'section_path' => $this->nullableString(data_get($plan, 'section_path')),
                'topic' => $this->nullableString(data_get($plan, 'topic')),
                'sub_topic' => $this->nullableString(data_get($plan, 'sub_topic')),
                'keywords' => $this->normalizeKeywords(data_get($plan, 'keywords')),
            ];
        }

        return $payloads;
    }

    /**
     * Purpose: Build a display title for one chunk payload.
     * Inputs: The validated chunk plan and the sliced content.
     * Returns: A compact title or null when no heading context exists.
     * Side effects: None.
     */
    private function chunkTitle(array $plan, string $content): ?string
    {
        $title = $this->nullableString(data_get($plan, 'title'));

        if ($title !== null) {
            return $this->compactTitle($title);
        }

        $sectionTitle = $this->sectionTitle($plan);

        if ($sectionTitle !== null) {
            return $this->compactTitle($sectionTitle);
        }

        $headingPath = $this->nullableString(data_get($plan, 'heading_path'));

        if ($headingPath !== null) {
            return $this->compactTitle($headingPath);
        }

        return null;
    }

    /**
     * Purpose: Resolve the most specific section title for a chunk payload.
     * Inputs: The validated chunk plan.
     * Returns: The final section title or null when no heading context exists.
     * Side effects: None.
     */
    private function sectionTitle(array $plan): ?string
    {
        $sectionTitle = $this->nullableString(data_get($plan, 'section_title'));

        if ($sectionTitle !== null) {
            return $sectionTitle;
        }

        $headingPath = $this->nullableString(data_get($plan, 'heading_path'));

        if ($headingPath === null) {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(' > ', $headingPath),
        ), static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? (string) end($parts) : $headingPath;
    }

    /**
     * Purpose: Normalize a keyword input into a stable list of strings.
     * Inputs: A keyword array, comma-separated string, or null.
     * Returns: A de-duplicated keyword list.
     * Side effects: None.
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n;]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $text = trim((string) $keyword);

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize an optional string into a trimmed nullable string.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Keep a chunk title within the database column limit.
     * Inputs: A normalized title string or null.
     * Returns: A compact title that fits the persisted title column.
     * Side effects: None.
     */
    private function compactTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        return Str::limit($title, 255, '');
    }
}
