<?php

namespace App\Services\Ai\Wiki;

class EnterpriseWikiSectionParser
{
    public const MAX_EXCERPT_CHARS = 500;

    public const MAX_SECTIONS = 20;

    public const MAX_SECTION_CHARS = 3000;

    /**
     * Split extracted text into sections using H1/H2 markdown heading boundaries.
     * Falls back to fixed character-length chunks when no headings are detected.
     * Returns at most MAX_SECTIONS sections regardless of document length.
     */
    public function splitIntoSections(string $text): array
    {
        $sections = $this->splitByHeadings($text);

        if (empty($sections)) {
            $sections = $this->splitByCharLimit($text, self::MAX_SECTION_CHARS);
        }

        return array_slice($sections, 0, self::MAX_SECTIONS);
    }

    /**
     * Parse a validated claim list from an AI section response array.
     * Claims with empty or whitespace-only text are silently dropped.
     * Sets conflict_flag from presence of a non-null conflict_note.
     * Trims excerpt to MAX_EXCERPT_CHARS; null excerpt becomes empty string.
     *
     * @return array<int, array{text: string, confidence: string, conflict_flag: bool, excerpt: string}>
     */
    public function parseClaimsFromResponse(array $response): array
    {
        $rawClaims = $response['claims'] ?? [];

        if (! is_array($rawClaims)) {
            return [];
        }

        $claims = [];

        foreach ($rawClaims as $raw) {
            $claim = $this->parseSingleClaim($raw);

            if ($claim !== null) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    /**
     * @return array{text: string, confidence: string, conflict_flag: bool, excerpt: string}|null
     */
    private function parseSingleClaim(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $text = trim((string) ($raw['text'] ?? ''));

        if ($text === '') {
            return null;
        }

        $conflictNote = array_key_exists('conflict_note', $raw) ? $raw['conflict_note'] : null;

        $rawExcerpt = array_key_exists('excerpt', $raw) ? $raw['excerpt'] : null;
        $excerpt = $rawExcerpt !== null
            ? mb_substr((string) $rawExcerpt, 0, self::MAX_EXCERPT_CHARS)
            : '';

        return [
            'text' => $text,
            'confidence' => (string) ($raw['confidence'] ?? 'uncertain'),
            'conflict_flag' => $conflictNote !== null,
            'excerpt' => $excerpt,
        ];
    }

    /**
     * Split text at H1 (`# `) or H2 (`## `) markdown heading boundaries.
     * Returns an empty array if no headings are found.
     *
     * @return array<int, array{heading: string, content: string}>
     */
    private function splitByHeadings(string $text): array
    {
        $lines = explode("\n", $text);
        $sections = [];
        $currentHeading = null;
        $currentLines = [];
        $foundHeading = false;

        foreach ($lines as $line) {
            if (preg_match('/^#{1,2}\s+(.+)$/', $line, $matches)) {
                if ($foundHeading) {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'content' => trim(implode("\n", $currentLines)),
                    ];
                }

                $currentHeading = trim($matches[1]);
                $currentLines = [];
                $foundHeading = true;
            } else {
                $currentLines[] = $line;
            }
        }

        if ($foundHeading) {
            $sections[] = [
                'heading' => $currentHeading,
                'content' => trim(implode("\n", $currentLines)),
            ];
        }

        return $foundHeading ? $sections : [];
    }

    /**
     * Split text into fixed-size character chunks, preferring newline boundaries.
     * Used as fallback when the document has no H1/H2 headings.
     *
     * @return array<int, array{heading: null, content: string}>
     */
    private function splitByCharLimit(string $text, int $chunkSize): array
    {
        if ($text === '') {
            return [];
        }

        $sections = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $chunkSize);

            if ($offset + $chunkSize < $length) {
                $lastNewline = mb_strrpos($chunk, "\n");

                if ($lastNewline !== false && $lastNewline > (int) ($chunkSize / 2)) {
                    $chunk = mb_substr($text, $offset, $lastNewline + 1);
                }
            }

            $chunkLen = mb_strlen($chunk);
            $sections[] = [
                'heading' => null,
                'content' => trim($chunk),
            ];

            $offset += $chunkLen;
        }

        return $sections;
    }
}
