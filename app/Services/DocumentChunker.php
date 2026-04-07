<?php

namespace App\Services;

use Throwable;

class DocumentChunker
{
    private const TARGET_CHUNK_SIZE = 1200;

    private const HARD_MAX_CHUNK_SIZE = 1600;

    /**
     * Purpose: Split normalized extracted text into deterministic storage chunks.
     * Inputs: Raw extracted text from a saved notice AI document.
     * Returns: An ordered list of chunk payloads with content and offsets.
     * Side effects: None.
     */
    public function chunkText(string $text): array
    {
        try {
            $normalizedText = $this->normalizeText($text);

            if ($normalizedText === '') {
                return [];
            }

            $chunks = [];
            $parts = preg_split('/(\n{2,})/u', $normalizedText, -1, PREG_SPLIT_DELIM_CAPTURE);

            if (! is_array($parts) || $parts === []) {
                return [];
            }

            $cursor = 0;
            $buffer = '';
            $bufferStart = null;
            $partCount = count($parts);

            for ($index = 0; $index < $partCount; $index += 2) {
                $paragraph = (string) ($parts[$index] ?? '');
                $separator = (string) ($parts[$index + 1] ?? '');
                $paragraphLength = mb_strlen($paragraph, 'UTF-8');
                $separatorLength = mb_strlen($separator, 'UTF-8');
                $segmentLength = $paragraphLength + $separatorLength;

                if ($paragraph === '') {
                    $cursor += $separatorLength;
                    continue;
                }

                if ($paragraphLength > self::HARD_MAX_CHUNK_SIZE) {
                    if ($buffer !== '' && $bufferStart !== null) {
                        $chunks[] = $this->makeChunkPayload($buffer, $bufferStart);
                        $buffer = '';
                        $bufferStart = null;
                    }

                    foreach ($this->chunkLongParagraph($paragraph, $cursor) as $chunk) {
                        $chunks[] = $chunk;
                    }

                    $cursor += $segmentLength;
                    continue;
                }

                if ($buffer !== '' && mb_strlen($buffer, 'UTF-8') + $segmentLength > self::HARD_MAX_CHUNK_SIZE) {
                    $chunks[] = $this->makeChunkPayload($buffer, (int) $bufferStart);
                    $buffer = '';
                    $bufferStart = null;
                }

                if ($buffer === '') {
                    $bufferStart = $cursor;
                }

                $buffer .= $paragraph.$separator;
                $cursor += $segmentLength;

                if (mb_strlen($buffer, 'UTF-8') >= self::TARGET_CHUNK_SIZE) {
                    $chunks[] = $this->makeChunkPayload($buffer, (int) $bufferStart);
                    $buffer = '';
                    $bufferStart = null;
                }
            }

            if ($buffer !== '' && $bufferStart !== null) {
                $chunks[] = $this->makeChunkPayload($buffer, $bufferStart);
            }

            return array_values(array_filter($chunks, static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Purpose: Split a long paragraph into whitespace-aware chunks.
     * Inputs: A paragraph of normalized extracted text and its absolute start offset.
     * Returns: An ordered list of chunk payloads for the paragraph.
     * Side effects: None.
     */
    private function chunkLongParagraph(string $paragraph, int $absoluteStart): array
    {
        $chunks = [];
        $paragraphLength = mb_strlen($paragraph, 'UTF-8');
        $offset = 0;

        while ($offset < $paragraphLength) {
            $remaining = $paragraphLength - $offset;

            if ($remaining <= self::HARD_MAX_CHUNK_SIZE) {
                $content = mb_substr($paragraph, $offset, $remaining, 'UTF-8');
                $chunks[] = $this->makeChunkPayload($content, $absoluteStart + $offset);
                break;
            }

            $preferredCut = min(self::TARGET_CHUNK_SIZE, $remaining);
            $hardCut = min(self::HARD_MAX_CHUNK_SIZE, $remaining);
            $cutLength = $this->findCutLength($paragraph, $offset, $preferredCut, $hardCut);

            if ($cutLength <= 0) {
                $cutLength = $hardCut;
            }

            $content = mb_substr($paragraph, $offset, $cutLength, 'UTF-8');

            if ($content === '') {
                break;
            }

            $chunks[] = $this->makeChunkPayload($content, $absoluteStart + $offset);
            $offset = $this->skipWhitespace($paragraph, $offset + $cutLength);
        }

        return $chunks;
    }

    /**
     * Purpose: Resolve a safe cut length for a long text window.
     * Inputs: The source text, current offset, preferred cut length, and hard cut length.
     * Returns: A cut length relative to the current offset.
     * Side effects: None.
     */
    private function findCutLength(string $text, int $offset, int $preferredCut, int $hardCut): int
    {
        $window = mb_substr($text, $offset, $hardCut, 'UTF-8');
        $preferredCut = min($preferredCut, mb_strlen($window, 'UTF-8'));
        $hardCut = min($hardCut, mb_strlen($window, 'UTF-8'));

        foreach (["\n\n", "\n"] as $separator) {
            $cut = $this->findLastNeedleOffset($window, $separator, $preferredCut);

            if ($cut !== null && $cut > 0) {
                return $cut;
            }

            $cut = $this->findLastNeedleOffset($window, $separator, $hardCut);

            if ($cut !== null && $cut > 0) {
                return $cut;
            }
        }

        $cut = $this->findLastWhitespaceOffset($window, $preferredCut);

        if ($cut !== null && $cut > 0) {
            return $cut;
        }

        $cut = $this->findLastWhitespaceOffset($window, $hardCut);

        if ($cut !== null && $cut > 0) {
            return $cut;
        }

        return $hardCut;
    }

    /**
     * Purpose: Locate the last occurrence of a needle before a given limit.
     * Inputs: The source text, a search needle, and the maximum search length.
     * Returns: The needle offset or null when the needle is not found.
     * Side effects: None.
     */
    private function findLastNeedleOffset(string $text, string $needle, int $limit): ?int
    {
        $segment = mb_substr($text, 0, $limit, 'UTF-8');
        $position = mb_strrpos($segment, $needle, 0, 'UTF-8');

        return $position === false ? null : $position;
    }

    /**
     * Purpose: Locate the last whitespace character before a given limit.
     * Inputs: The source text and the maximum search length.
     * Returns: The whitespace offset or null when no whitespace is found.
     * Side effects: None.
     */
    private function findLastWhitespaceOffset(string $text, int $limit): ?int
    {
        $segmentLength = min($limit, mb_strlen($text, 'UTF-8'));

        for ($index = $segmentLength - 1; $index >= 0; $index--) {
            $character = mb_substr($text, $index, 1, 'UTF-8');

            if ($character !== '' && preg_match('/\s/u', $character) === 1) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Purpose: Skip whitespace characters from the given offset.
     * Inputs: The source text and the current offset.
     * Returns: The next non-whitespace offset.
     * Side effects: None.
     */
    private function skipWhitespace(string $text, int $offset): int
    {
        $length = mb_strlen($text, 'UTF-8');

        while ($offset < $length) {
            $character = mb_substr($text, $offset, 1, 'UTF-8');

            if ($character === '' || preg_match('/\s/u', $character) !== 1) {
                break;
            }

            $offset++;
        }

        return $offset;
    }

    /**
     * Purpose: Normalize extracted text before chunking.
     * Inputs: Raw extracted text from a document.
     * Returns: A lightly normalized string that preserves content.
     * Side effects: None.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * Purpose: Build a chunk payload from normalized text and an absolute offset.
     * Inputs: The chunk content and its absolute start offset in the source text.
     * Returns: An array ready for persistence.
     * Side effects: None.
     */
    private function makeChunkPayload(string $content, int $charStart): array
    {
        $content = trim($content);

        if ($content === '') {
            return [
                'content' => '',
                'char_start' => $charStart,
                'char_end' => $charStart,
                'word_count' => 0,
            ];
        }

        return [
            'content' => $content,
            'char_start' => $charStart,
            'char_end' => $charStart + mb_strlen($content, 'UTF-8'),
            'word_count' => preg_match_all('/\S+/u', $content) ?: 0,
        ];
    }
}
