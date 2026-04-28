<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class DocumentChunker
{
    private const TARGET_CHUNK_SIZE = 2400;

    private const HARD_MAX_CHUNK_SIZE = 3200;

    private const STRUCTURED_SECTION_MAX_WORDS = 30000;

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

            $lines = explode("\n", $normalizedText);
            $lineCount = count($lines);
            $chunks = [];
            $currentChunk = '';
            $currentChunkStart = 0;
            $cursor = 0;
            $hasSeenH1 = false;

            for ($index = 0; $index < $lineCount; $index++) {
                $line = (string) ($lines[$index] ?? '');
                $lineWithDelimiter = $index < $lineCount - 1 ? $line."\n" : $line;
                $lineStart = $cursor;
                $trimmedLine = trim($line);
                $isH1Boundary = $this->isH1HeadingLine($trimmedLine);

                if ($isH1Boundary) {
                    if ($hasSeenH1 && trim($currentChunk) !== '') {
                        $chunks[] = $this->makeChunkPayload($currentChunk, $currentChunkStart);
                    }

                    $currentChunk = $lineWithDelimiter;
                    $currentChunkStart = $lineStart;
                    $hasSeenH1 = true;
                    $cursor += mb_strlen($lineWithDelimiter, 'UTF-8');

                    continue;
                }

                if ($hasSeenH1) {
                    $currentChunk .= $lineWithDelimiter;
                }

                $cursor += mb_strlen($lineWithDelimiter, 'UTF-8');
            }

            if ($hasSeenH1 && trim($currentChunk) !== '') {
                $chunks[] = $this->makeChunkPayload($currentChunk, $currentChunkStart);
            }

            if (! $hasSeenH1 && trim($normalizedText) !== '') {
                $chunks[] = $this->makeChunkPayload($normalizedText, 0);
            }

            return array_values(array_filter(
                $chunks,
                static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''
            ));
        } catch (Throwable $throwable) {
            Log::error('[PROCYNIA][DocumentChunker] Failed to chunk extracted text.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Purpose: Split structured document blocks into chunks using H1 as the normal boundary and H2 only as overflow.
     * Inputs: Ordered structured blocks with text and heading levels.
     * Returns: An ordered list of chunk payloads built from full blocks.
     * Side effects: None.
     *
     * @param array<int, array{text: string, style: ?string, level: ?int}> $blocks
     */
    public function chunkStructured(array $blocks): array
    {
        try {
            $normalizedBlocks = [];

            foreach ($blocks as $block) {
                $text = trim((string) ($block['text'] ?? ''));

                if ($text === '') {
                    continue;
                }

                $normalizedBlocks[] = [
                    'text' => $text,
                    'style' => isset($block['style']) ? (string) $block['style'] : null,
                    'level' => isset($block['level']) && $block['level'] !== null ? (int) $block['level'] : null,
                ];
            }

            if ($normalizedBlocks === []) {
                return [];
            }

            $sourceTextParts = [];
            $blockStarts = [];
            $blockEnds = [];
            $cursor = 0;

            foreach ($normalizedBlocks as $index => $block) {
                $blockStarts[$index] = $cursor;
                $sourceTextParts[] = $block['text'];
                $cursor += mb_strlen($block['text'], 'UTF-8');
                $blockEnds[$index] = $cursor;

                if ($index < count($normalizedBlocks) - 1) {
                    $cursor += 2;
                }
            }

            $sourceText = implode("\n\n", $sourceTextParts);

            $h1Indices = [];

            foreach ($normalizedBlocks as $index => $block) {
                if ((int) ($block['level'] ?? 0) === 1) {
                    $h1Indices[] = $index;
                }
            }

            if ($h1Indices === []) {
                return [$this->makeChunkPayload($sourceText, 0)];
            }

            $chunks = [];
            $h1Count = count($h1Indices);
            $hasLeadingPrelude = ($h1Indices[0] ?? 0) > 0;
            $currentStartIndex = null;
            $currentEndExclusive = null;
            $currentMetadata = null;
            $currentWordCount = 0;

            $flushCurrentChunk = function () use (
                &$chunks,
                $sourceText,
                $blockStarts,
                $blockEnds,
                &$currentStartIndex,
                &$currentEndExclusive,
                &$currentMetadata,
                &$currentWordCount,
            ): void {
                if ($currentStartIndex === null || $currentEndExclusive === null) {
                    return;
                }

                $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                    $sourceText,
                    $blockStarts,
                    $blockEnds,
                    $currentStartIndex,
                    $currentEndExclusive,
                    $currentMetadata ?? [],
                );

                $currentStartIndex = null;
                $currentEndExclusive = null;
                $currentMetadata = null;
                $currentWordCount = 0;
            };

            for ($i = 0; $i < $h1Count; $i++) {
                $sectionStartIndex = $h1Indices[$i];
                $sectionEndExclusive = $h1Indices[$i + 1] ?? count($normalizedBlocks);
                $sectionStartOffset = (int) ($blockStarts[$sectionStartIndex] ?? 0);
                $sectionEndOffset = (int) ($blockEnds[$sectionEndExclusive - 1] ?? $sectionStartOffset);
                $sectionWordCount = $this->countWordsInRange(
                    $sourceText,
                    $sectionStartOffset,
                    $sectionEndOffset,
                );
                $sectionContentStartIndex = $i === 0 && $hasLeadingPrelude ? 0 : $sectionStartIndex;
                $sectionContentStartOffset = (int) ($blockStarts[$sectionContentStartIndex] ?? $sectionStartOffset);
                $sectionContentWordCount = $this->countWordsInRange(
                    $sourceText,
                    $sectionContentStartOffset,
                    $sectionEndOffset,
                );

                $sectionMetadata = $this->structuredSectionMetadata(
                    (string) ($normalizedBlocks[$sectionStartIndex]['text'] ?? ''),
                );

                if ($sectionWordCount <= self::STRUCTURED_SECTION_MAX_WORDS) {
                    if ($currentStartIndex === null || $currentEndExclusive === null) {
                        $currentStartIndex = $sectionContentStartIndex;
                        $currentEndExclusive = $sectionEndExclusive;
                        $currentMetadata = $sectionMetadata;
                        $currentWordCount = $sectionContentWordCount;

                        continue;
                    }

                    if ($currentWordCount + $sectionWordCount <= self::STRUCTURED_SECTION_MAX_WORDS) {
                        $currentEndExclusive = $sectionEndExclusive;
                        $currentWordCount += $sectionWordCount;

                        continue;
                    }

                    $flushCurrentChunk();
                    $currentStartIndex = $sectionStartIndex;
                    $currentEndExclusive = $sectionEndExclusive;
                    $currentMetadata = $sectionMetadata;
                    $currentWordCount = $sectionWordCount;

                    continue;
                }

                $flushCurrentChunk();

                $h2Spans = $this->buildStructuredH2Spans($normalizedBlocks, $sectionStartIndex, $sectionEndExclusive);

                if ($h2Spans === []) {
                    $chunks = array_merge(
                        $chunks,
                        $this->splitStructuredRangeByBlocks(
                            $sourceText,
                            $blockStarts,
                            $blockEnds,
                            $sectionContentStartIndex,
                            $sectionEndExclusive,
                            $sectionMetadata,
                        ),
                    );

                    continue;
                }

                $chunks = array_merge(
                    $chunks,
                    $this->packStructuredH2SpansIntoChunks(
                        $normalizedBlocks,
                        $blockStarts,
                        $blockEnds,
                        $sourceText,
                        $h2Spans,
                        (string) ($normalizedBlocks[$sectionStartIndex]['text'] ?? ''),
                        $sectionContentStartIndex !== $sectionStartIndex ? $sectionContentStartIndex : null,
                    ),
                );
            }

            $flushCurrentChunk();

            return array_values(array_filter(
                $chunks,
                static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''
            ));
        } catch (Throwable $throwable) {
            Log::error('[PROCYNIA][DocumentChunker] Failed to chunk structured blocks.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Purpose: Build H2 spans inside one oversized H1 section.
     * Inputs: Structured blocks and the boundaries of a single H1 section.
     * Returns: Ordered spans that keep the H1 intro with the first H2 group and then advance from H2 to H2.
     *
     * @param array<int, array{text: string, style: ?string, level: ?int}> $blocks
     * @return array<int, array{start: int, end: int, structured_subheading: ?string, structured_subheading_level: ?int}>
     */
    private function buildStructuredH2Spans(array $blocks, int $sectionStartIndex, int $sectionEndExclusive): array
    {
        $h2Indices = [];

        for ($index = $sectionStartIndex + 1; $index < $sectionEndExclusive; $index++) {
            if ((int) ($blocks[$index]['level'] ?? 0) === 2) {
                $h2Indices[] = $index;
            }
        }

        if ($h2Indices === []) {
            return [];
        }

        $spans = [];
        $spanStartIndex = $sectionStartIndex;

        foreach ($h2Indices as $position => $h2Index) {
            $spanEndExclusive = $h2Indices[$position + 1] ?? $sectionEndExclusive;

            $spans[] = [
                'start' => $spanStartIndex,
                'end' => $spanEndExclusive,
                'structured_subheading' => (string) ($blocks[$h2Index]['text'] ?? ''),
                'structured_subheading_level' => 2,
            ];

            $spanStartIndex = $spanEndExclusive;
        }

        return $spans;
    }

    /**
     * Purpose: Pack H2 spans into word-limited chunks only when a single H1 section overflows.
     * Inputs: Structured H2 spans plus the offsets needed to slice them back into source text.
     * Returns: Structured chunk payloads that preserve H1 as the parent section metadata.
     *
     * @param array<int, array{text: string, style: ?string, level: ?int}> $blocks
     * @param array<int, int> $blockStarts
     * @param array<int, int> $blockEnds
     * @param array<int, array{start: int, end: int, structured_subheading: ?string, structured_subheading_level: ?int}> $spans
     * @return array<int, array<string, mixed>>
     */
    private function packStructuredH2SpansIntoChunks(
        array $blocks,
        array $blockStarts,
        array $blockEnds,
        string $sourceText,
        array $spans,
        string $sectionHeading,
        ?int $leadingStartIndex = null,
    ): array {
        $chunks = [];
        $currentStartIndex = null;
        $currentEndExclusive = null;
        $currentMetadata = null;
        $currentWordCount = 0;

        foreach ($spans as $span) {
            $spanStartIndex = (int) ($span['start'] ?? 0);
            $spanEndExclusive = (int) ($span['end'] ?? $spanStartIndex);
            $spanStartOffset = (int) ($blockStarts[$spanStartIndex] ?? 0);
            $spanEndOffset = (int) ($blockEnds[$spanEndExclusive - 1] ?? $spanStartOffset);
            $spanWordCount = $this->countWordsInRange(
                $sourceText,
                $spanStartOffset,
                $spanEndOffset,
            );
            $spanMetadata = [
                'structured_section_heading' => $sectionHeading,
                'structured_section_level' => 1,
                'structured_subheading' => isset($span['structured_subheading']) && $span['structured_subheading'] !== ''
                    ? (string) $span['structured_subheading']
                    : null,
                'structured_subheading_level' => isset($span['structured_subheading_level'])
                    ? (int) $span['structured_subheading_level']
                    : null,
                'section_title' => isset($span['structured_subheading']) && $span['structured_subheading'] !== ''
                    ? (string) $span['structured_subheading']
                    : (trim($sectionHeading) !== '' ? trim($sectionHeading) : null),
                'section_path' => $this->structuredSectionPath(
                    $sectionHeading,
                    isset($span['structured_subheading']) && $span['structured_subheading'] !== ''
                        ? (string) $span['structured_subheading']
                        : null,
                ),
            ];

            if ($spanWordCount > self::STRUCTURED_SECTION_MAX_WORDS) {
                if ($currentStartIndex !== null && $currentEndExclusive !== null) {
                    $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                        $sourceText,
                        $blockStarts,
                        $blockEnds,
                        $currentStartIndex,
                        $currentEndExclusive,
                        $currentMetadata ?? $spanMetadata,
                    );

                    $currentStartIndex = null;
                    $currentEndExclusive = null;
                    $currentMetadata = null;
                    $currentWordCount = 0;
                }

                $chunks = array_merge(
                    $chunks,
                    $this->splitStructuredRangeByBlocks(
                        $sourceText,
                        $blockStarts,
                        $blockEnds,
                        $spanStartIndex,
                        $spanEndExclusive,
                        $spanMetadata,
                    ),
                );

                continue;
            }

            if ($currentStartIndex === null || $currentEndExclusive === null) {
                $currentStartIndex = $leadingStartIndex ?? $spanStartIndex;
                $currentEndExclusive = $spanEndExclusive;
                $currentMetadata = $spanMetadata;
                $currentWordCount = $leadingStartIndex !== null
                    ? $this->countWordsInRange(
                        $sourceText,
                        (int) ($blockStarts[$leadingStartIndex] ?? $spanStartOffset),
                        $spanEndOffset,
                    )
                    : $spanWordCount;

                continue;
            }

            if ($currentWordCount + $spanWordCount > self::STRUCTURED_SECTION_MAX_WORDS) {
                $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                    $sourceText,
                    $blockStarts,
                    $blockEnds,
                    $currentStartIndex,
                    $currentEndExclusive,
                    $currentMetadata ?? $spanMetadata,
                );

                $currentStartIndex = $spanStartIndex;
                $currentEndExclusive = $spanEndExclusive;
                $currentMetadata = $spanMetadata;
                $currentWordCount = $spanWordCount;

                continue;
            }

            $currentEndExclusive = $spanEndExclusive;
            $currentWordCount += $spanWordCount;
        }

        if ($currentStartIndex !== null && $currentEndExclusive !== null) {
            $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                $sourceText,
                $blockStarts,
                $blockEnds,
                $currentStartIndex,
                $currentEndExclusive,
                $currentMetadata ?? [
                    'structured_section_heading' => $sectionHeading,
                    'structured_section_level' => 1,
                    'structured_subheading' => null,
                    'structured_subheading_level' => null,
                    'section_title' => trim($sectionHeading) !== '' ? trim($sectionHeading) : null,
                    'section_path' => $this->structuredSectionPath($sectionHeading, null),
                ],
            );
        }

        return array_values(array_filter(
            $chunks,
            static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''
        ));
    }

    /**
     * Purpose: Split one oversized structured range into chunks without breaking individual blocks.
     * Inputs: The source text, the block offsets, and a block range.
     * Returns: Chunk payloads that preserve source offsets while respecting whole-block boundaries.
     *
     * @param array<int, int> $blockStarts
     * @param array<int, int> $blockEnds
     * @param array<string, mixed> $metadata
     * @return array<int, array<string, mixed>>
     */
    private function splitStructuredRangeByBlocks(
        string $sourceText,
        array $blockStarts,
        array $blockEnds,
        int $startIndex,
        int $endExclusive,
        array $metadata,
    ): array {
        if ($startIndex >= $endExclusive) {
            return [];
        }

        $chunks = [];
        $currentStartIndex = $startIndex;
        $currentEndExclusive = $startIndex + 1;

        for ($index = $startIndex + 1; $index < $endExclusive; $index++) {
            $currentStartOffset = (int) ($blockStarts[$currentStartIndex] ?? 0);
            $currentEndOffset = (int) ($blockEnds[$currentEndExclusive - 1] ?? $currentStartOffset);
            $currentWordCount = $this->countWordsInRange(
                $sourceText,
                $currentStartOffset,
                $currentEndOffset,
            );
            $candidateEndOffset = (int) ($blockEnds[$index] ?? $currentEndOffset);
            $candidateWordCount = $this->countWordsInRange(
                $sourceText,
                $currentStartOffset,
                $candidateEndOffset,
            );

            if ($currentWordCount >= self::STRUCTURED_SECTION_MAX_WORDS || $candidateWordCount > self::STRUCTURED_SECTION_MAX_WORDS) {
                $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                    $sourceText,
                    $blockStarts,
                    $blockEnds,
                    $currentStartIndex,
                    $currentEndExclusive,
                    $metadata,
                );

                $currentStartIndex = $index;
                $currentEndExclusive = $index + 1;

                continue;
            }

            $currentEndExclusive = $index + 1;
        }

        if ($currentEndExclusive > $currentStartIndex) {
            $chunks[] = $this->makeStructuredChunkPayloadFromRange(
                $sourceText,
                $blockStarts,
                $blockEnds,
                $currentStartIndex,
                $currentEndExclusive,
                $metadata,
            );
        }

        return array_values(array_filter(
            $chunks,
            static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''
        ));
    }

    /**
     * Purpose: Build one structured chunk payload from a block range and optional metadata.
     * Inputs: The source text, block offsets, and the block range to slice.
     * Returns: A chunk payload that preserves the original source offsets.
     *
     * @param array<int, int> $blockStarts
     * @param array<int, int> $blockEnds
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function makeStructuredChunkPayloadFromRange(
        string $sourceText,
        array $blockStarts,
        array $blockEnds,
        int $startIndex,
        int $endExclusive,
        array $metadata = [],
    ): array {
        if ($startIndex >= $endExclusive) {
            return array_merge($this->makeChunkPayload('', 0), $metadata);
        }

        $startOffset = (int) ($blockStarts[$startIndex] ?? 0);
        $endOffset = (int) ($blockEnds[$endExclusive - 1] ?? $startOffset);
        $contentLength = max(0, $endOffset - $startOffset);
        $content = mb_substr($sourceText, $startOffset, $contentLength, 'UTF-8');

        return array_merge($this->makeChunkPayload($content, $startOffset), $metadata);
    }

    /**
     * Purpose: Build the canonical section metadata for a structured chunk.
     * Inputs: The parent H1 heading and an optional H2 subheading.
     * Returns: Section metadata ready for chunk persistence.
     * Side effects: None.
     */
    private function structuredSectionMetadata(string $sectionHeading, ?string $subheading = null): array
    {
        $sectionHeading = trim($sectionHeading);
        $subheading = $subheading !== null ? trim($subheading) : null;
        $hasSectionHeading = $sectionHeading !== '';
        $hasSubheading = $subheading !== null && $subheading !== '';

        return [
            'structured_section_heading' => $hasSectionHeading ? $sectionHeading : null,
            'structured_section_level' => 1,
            'structured_subheading' => $hasSubheading ? $subheading : null,
            'structured_subheading_level' => $hasSubheading ? 2 : null,
            'section_title' => $hasSubheading
                ? $subheading
                : ($hasSectionHeading ? $sectionHeading : null),
            'section_path' => $this->structuredSectionPath($sectionHeading, $subheading),
        ];
    }

    /**
     * Purpose: Build a stable display path from the current heading hierarchy.
     * Inputs: The parent H1 heading and an optional H2 subheading.
     * Returns: A readable heading path or null when no heading context exists.
     * Side effects: None.
     */
    private function structuredSectionPath(string $sectionHeading, ?string $subheading = null): ?string
    {
        $sectionHeading = trim($sectionHeading);
        $subheading = $subheading !== null ? trim($subheading) : null;

        if ($sectionHeading === '') {
            return null;
        }

        if ($subheading !== null && $subheading !== '') {
            return $sectionHeading.' > '.$subheading;
        }

        return $sectionHeading;
    }

    /**
     * Purpose: Determine whether a single extracted line is a level-1 heading boundary.
     * Inputs: One trimmed line from the normalized document text.
     * Returns: True only for numeric H1-style headings, not H2/H3 headings or prose.
     * Side effects: None.
     */
    private function isH1HeadingLine(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        if (mb_strlen($line, 'UTF-8') > 160) {
            return false;
        }

        if (preg_match('/[.!?;:]$/u', $line) === 1) {
            return false;
        }

        return preg_match('/^\d+[).:]?\s+\S+/u', $line) === 1;
    }

    /**
     * Purpose: Split normalized text into structural segments separated by blank lines.
     * Inputs: Normalized document text.
     * Returns: An ordered list of segment payloads with offsets and lightweight structural profiles.
     * Side effects: None.
     */
    private function splitIntoSegments(string $text): array
    {
        $parts = preg_split('/(
{2,})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts) || $parts === []) {
            return [];
        }

        $segments = [];
        $cursor = 0;
        $partCount = count($parts);

        for ($index = 0; $index < $partCount; $index += 2) {
            $content = (string) ($parts[$index] ?? '');
            $separator = (string) ($parts[$index + 1] ?? '');
            $segmentLength = mb_strlen($content, 'UTF-8') + mb_strlen($separator, 'UTF-8');

            if ($content === '' && $separator !== '') {
                $cursor += $segmentLength;

                continue;
            }

            if (trim($content) === '') {
                $cursor += $segmentLength;

                continue;
            }

            foreach ($this->expandStructuralSegmentsWithinBlock($content, $cursor, $separator) as $expandedSegment) {
                $segments[] = $expandedSegment;
            }

            $cursor += $segmentLength;
        }

        return $segments;
    }

    /**
     * Purpose: Merge adjacent structural segments into a single chunk-friendly group.
     * Inputs: A list of segment payloads with structural profiles.
     * Returns: A normalized list of merged segment payloads.
     * Side effects: None.
     */
    private function mergeStructuralSegments(array $segments): array
    {
        $merged = [];
        $segmentCount = count($segments);

        for ($index = 0; $index < $segmentCount; ) {
            $segment = $segments[$index];
            $segmentKind = (string) ($segment['kind'] ?? 'body');

            if ($this->isOpeningSegmentKind($segmentKind)) {
                $group = [$segment];
                $cursor = $index + 1;

                while (isset($segments[$cursor]) && $this->isOpeningSegmentKind((string) ($segments[$cursor]['kind'] ?? 'body'))) {
                    $group[] = $segments[$cursor];
                    $cursor++;
                }

                $merged[] = $this->mergeSegments($group, (string) ($group[0]['kind'] ?? $segmentKind));
                $index = $cursor;

                continue;
            }

            if ($segmentKind === 'lead_in' || $this->canAttachToFollowingListGroup($segment)) {
                $group = [$segment];
                $cursor = $index + 1;
                [$listGroup, $cursor] = $this->collectContiguousListGroup($segments, $cursor);

                if ($listGroup !== []) {
                    $group = array_merge($group, $listGroup);
                    $merged[] = $this->buildStructuralGroupSegment($group, 'list', 'list_group');
                    $index = $cursor;

                    continue;
                }
            }

            if ($this->isListLikeSegment($segment) || $this->isCompactRowLikeSegment($segment)) {
                $group = [$segment];
                $groupLength = (int) ($segment['length'] ?? 0);
                $cursor = $index + 1;

                while (isset($segments[$cursor])) {
                    $nextSegment = $segments[$cursor];

                    if (! ($this->isListLikeSegment($nextSegment) || $this->isCompactRowLikeSegment($nextSegment))) {
                        break;
                    }

                    $nextLength = (int) ($nextSegment['length'] ?? 0);

                    if ($groupLength + $nextLength > self::HARD_MAX_CHUNK_SIZE) {
                        break;
                    }

                    $group[] = $nextSegment;
                    $groupLength += $nextLength;
                    $cursor++;
                }

                $merged[] = $this->mergeSegments($group, 'table');
                $index += count($group);

                continue;
            }

            $merged[] = $segment;
            $index++;
        }

        return $merged;
    }

    /**
     * Purpose: Build a structural group segment for chunk-time grouping decisions.
     * Inputs: Ordered segments and the semantic kind for the merged payload.
     * Returns: A merged segment payload that still retains its structural parts.
     * Side effects: None.
     */
    private function buildStructuralGroupSegment(array $segments, string $kind, ?string $groupType = null): array
    {
        $merged = $this->mergeSegments($segments, $kind);
        $merged['group_type'] = $groupType;
        $merged['parts'] = $segments;

        return $merged;
    }

    /**
     * Purpose: Split one blank-line block into smaller structural subsegments when bullets start inside the block.
     * Inputs: Raw block content, the absolute start offset, and the trailing blank-line separator.
     * Returns: Ordered subsegments that preserve offsets and keep bullet continuations together.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>
     */
    private function expandStructuralSegmentsWithinBlock(string $content, int $blockStart, string $separator): array
    {
        $lines = preg_split('/
/u', $content);

        if (! is_array($lines) || $lines === []) {
            return [];
        }

        $trailingCueSplit = $this->splitTrailingLeadInLine($content, $blockStart, $separator);

        if ($trailingCueSplit !== null) {
            return $trailingCueSplit;
        }

        $inlineSubsectionSplit = $this->splitInlineSubsectionBlocks($content, $blockStart, $separator);

        if ($inlineSubsectionSplit !== null) {
            return $inlineSubsectionSplit;
        }

        $containsBulletLine = false;
        $containsPlainLeadLine = false;

        foreach ($lines as $line) {
            $trimmedLine = trim((string) $line);

            if ($trimmedLine === '') {
                continue;
            }

            if ($this->isBulletLine($trimmedLine)) {
                $containsBulletLine = true;
            } else {
                $containsPlainLeadLine = true;
            }
        }

        if (! $containsBulletLine || ! $containsPlainLeadLine) {
            return [$this->makeSegmentPayload($content.$separator, $blockStart)];
        }

        $subsegments = [];
        $currentLines = [];
        $currentStart = $blockStart;
        $lineCursor = $blockStart;
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $line = (string) $lines[$index];
            $trimmedLine = trim($line);
            $isBulletLine = $trimmedLine !== '' && $this->isBulletLine($trimmedLine);

            if ($isBulletLine && $currentLines !== []) {
                $subsegments[] = $this->makeSegmentPayload(implode("\n", $currentLines)."\n", $currentStart);
                $currentLines = [];
                $currentStart = $lineCursor;
            }

            if ($currentLines === []) {
                $currentStart = $lineCursor;
            }

            $currentLines[] = $line;
            $lineCursor += mb_strlen($line, 'UTF-8');

            if ($index < $lineCount - 1) {
                $lineCursor += 1;
            }
        }

        if ($currentLines !== []) {
            $subsegments[] = $this->makeSegmentPayload(implode("\n", $currentLines).$separator, $currentStart);
        }


        return array_values(array_filter($subsegments, static fn (array $segment): bool => trim((string) ($segment['content'] ?? '')) !== ''));
    }

    /**
     * Purpose: Split a prose block so a trailing lead-in line becomes its own segment.
     * Inputs: Raw block content, the absolute start offset, and the trailing blank-line separator.
     * Returns: Two ordered segments when the block ends with a short cue line, otherwise null.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function splitTrailingLeadInLine(string $content, int $blockStart, string $separator): ?array
    {
        $trimmedContent = trim($content);

        if ($trimmedContent === '') {
            return null;
        }

        $lines = preg_split('/
/u', $content);

        if (! is_array($lines) || count($lines) < 2) {
            return null;
        }

        $nonEmptyIndexes = [];

        foreach ($lines as $index => $line) {
            if (trim((string) $line) !== '') {
                $nonEmptyIndexes[] = $index;
            }
        }

        if (count($nonEmptyIndexes) < 2) {
            return null;
        }

        $lastIndex = $nonEmptyIndexes[count($nonEmptyIndexes) - 1];
        $lastLine = trim((string) $lines[$lastIndex]);

        if (! $this->isTrailingLeadInCueLine($lastLine)) {
            return null;
        }

        $beforeLines = array_slice($lines, 0, $lastIndex);
        $afterLines = array_slice($lines, $lastIndex);
        $beforeContent = rtrim(implode("\n", $beforeLines), "\n") . "\n";
        $afterContent = implode("
", $afterLines);

        if (trim($beforeContent) === '' || trim($afterContent) === '') {
            return null;
        }

        $beforeTrimmedLines = preg_split('/
/u', trim($beforeContent)) ?: [];

        foreach ($beforeTrimmedLines as $line) {
            if ($this->isBulletLine(trim((string) $line))) {
                return null;
            }
        }

        $leadInStart = $blockStart + mb_strlen($beforeContent, 'UTF-8');

        return [
            $this->makeSegmentPayload($beforeContent, $blockStart),
            $this->makeSegmentPayload($afterContent.$separator, $leadInStart),
        ];
    }


    /**
     * Purpose: Split a dense block into subsection-sized segments when title-like lines appear inline.
     * Inputs: Raw block content, the absolute start offset, and the trailing blank-line separator.
     * Returns: Ordered subsection segments or null when no safe subsection structure is found.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function splitInlineSubsectionBlocks(string $content, int $blockStart, string $separator): ?array
    {
        $lines = preg_split('/\n/u', $content);

        if (! is_array($lines) || count($lines) < 3) {
            return null;
        }

        $groups = [];
        $currentLines = [];
        $currentStart = $blockStart;
        $lineCursor = $blockStart;
        $hasInlineSubsectionBoundary = false;

        foreach ($lines as $index => $line) {
            $trimmedLine = trim((string) $line);
            $isInlineBoundary = $trimmedLine !== ''
                && $index > 0
                && $this->looksLikeInlineSubsectionHeading($trimmedLine);

            if ($isInlineBoundary && $currentLines !== []) {
                $groups[] = [
                    'content' => implode("\n", $currentLines)."\n",
                    'start' => $currentStart,
                ];
                $currentLines = [];
                $currentStart = $lineCursor;
                $hasInlineSubsectionBoundary = true;
            }

            if ($currentLines === []) {
                $currentStart = $lineCursor;
            }

            $currentLines[] = $line;
            $lineCursor += mb_strlen($line, 'UTF-8');

            if ($index < count($lines) - 1) {
                $lineCursor += 1;
            }
        }

        if (! $hasInlineSubsectionBoundary || $currentLines === []) {
            return null;
        }

        $groups[] = [
            'content' => implode("\n", $currentLines).$separator,
            'start' => $currentStart,
        ];

        $segments = [];

        foreach ($groups as $group) {
            $groupContent = (string) ($group['content'] ?? '');

            if (trim($groupContent) === '') {
                continue;
            }

            $segments[] = $this->makeSegmentPayload($groupContent, (int) ($group['start'] ?? $blockStart));
        }

        return count($segments) > 1 ? $segments : null;
    }

    /**
     * Purpose: Determine whether one line looks like an inline subsection heading inside a dense prose block.
     * Inputs: A trimmed text line.
     * Returns: True when the line is title-like enough to start a new subsection.
     * Side effects: None.
     */
    private function looksLikeInlineSubsectionHeading(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        if ($this->isBulletLine($line)) {
            return false;
        }

        if (preg_match('/[.!?;:]$/u', $line) === 1) {
            return false;
        }

        if (preg_match('/^\d+(?:\.\d+)*[).:]?\s+/u', $line) === 1) {
            return false;
        }

        if (mb_strlen($line, 'UTF-8') > 120) {
            return false;
        }

        if ((preg_match_all('/\S+/u', $line) ?: 0) > 10) {
            return false;
        }

        return $this->looksLikeGenericTitleHeading($line);
    }

    /**
     * Purpose: Determine whether a line is a short cue that should stay with following list-like content.
     * Inputs: One trimmed text line.
     * Returns: True when the line looks like a lead-in cue.
     * Side effects: None.
     */
    private function isTrailingLeadInCueLine(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        if ($this->isBulletLine($line)) {
            return false;
        }

        if (mb_strlen($line, 'UTF-8') > 120) {
            return false;
        }

        if ((preg_match_all('/\S+/u', $line) ?: 0) > 18) {
            return false;
        }

        return preg_match('/[:–-]\s*$/u', $line) === 1;
    }

    /**
     * Purpose: Build one normalized segment payload from raw content and a stable start offset.
     * Inputs: Raw segment content and the absolute start offset.
     * Returns: A segment payload with profile and kind.
     * Side effects: None.
     */
    private function makeSegmentPayload(string $content, int $start): array
    {
        $profile = $this->segmentProfile($content);

        return [
            'content' => $content,
            'start' => $start,
            'length' => mb_strlen($content, 'UTF-8'),
            'content_length' => mb_strlen(trim($content), 'UTF-8'),
            'profile' => $profile,
            'kind' => $this->classifySegmentKind($profile),
        ];
    }

    /**
     * Purpose: Collect a contiguous list-like group from the current cursor.
     * Inputs: The segment list and the current cursor position.
     * Returns: A tuple-like array with the collected group and the updated cursor.
     * Side effects: None.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function collectContiguousListGroup(array $segments, int $cursor): array
    {
        $group = [];

        while (isset($segments[$cursor])) {
            $nextSegment = $segments[$cursor];

            if (! ($this->isListLikeSegment($nextSegment) || $this->isCompactRowLikeSegment($nextSegment))) {
                break;
            }

            $group[] = $nextSegment;
            $cursor++;
        }

        return [$group, $cursor];
    }

    /**
     * Purpose: Split a semantic list group into deterministic chunks without breaking intro + first bullet.
     * Inputs: A merged structural list group payload.
     * Returns: An ordered list of chunk payloads.
     * Side effects: None.
     */
    private function chunkListGroup(array $segment): array
    {
        $parts = array_values(array_filter(
            (array) ($segment['parts'] ?? []),
            static fn (array $part): bool => trim((string) ($part['content'] ?? '')) !== ''
        ));

        if ($parts === []) {
            $content = (string) ($segment['content'] ?? '');
            $start = (int) ($segment['start'] ?? 0);

            return $content === '' ? [] : [$this->makeChunkPayload($content, $start)];
        }

        $totalLength = 0;

        foreach ($parts as $part) {
            $totalLength += (int) ($part['length'] ?? mb_strlen((string) ($part['content'] ?? ''), 'UTF-8'));
        }

        if ($totalLength <= self::HARD_MAX_CHUNK_SIZE) {
            return [$this->makeChunkPayload((string) ($segment['content'] ?? ''), (int) ($segment['start'] ?? 0))];
        }

        $chunks = [];
        $currentParts = [];
        $currentLength = 0;
        $firstListLocked = false;

        foreach ($parts as $part) {
            $partContent = (string) ($part['content'] ?? '');
            $partLength = (int) ($part['length'] ?? mb_strlen($partContent, 'UTF-8'));

            if ($partContent === '') {
                continue;
            }

            if ($currentParts === []) {
                $currentParts[] = $part;
                $currentLength = $partLength;

                if ($this->isListLikeSegment($part) || $this->isCompactRowLikeSegment($part)) {
                    $firstListLocked = true;
                }

                continue;
            }

            if (! $firstListLocked) {
                $currentParts[] = $part;
                $currentLength += $partLength;

                if ($this->isListLikeSegment($part) || $this->isCompactRowLikeSegment($part)) {
                    $firstListLocked = true;
                }

                continue;
            }

            if ($currentLength + $partLength > self::HARD_MAX_CHUNK_SIZE) {
                $mergedChunk = $this->mergeSegments($currentParts, 'list');
                $chunks[] = $this->makeChunkPayload((string) ($mergedChunk['content'] ?? ''), (int) ($mergedChunk['start'] ?? 0));
                $currentParts = [$part];
                $currentLength = $partLength;

                continue;
            }

            $currentParts[] = $part;
            $currentLength += $partLength;
        }

        if ($currentParts !== []) {
            $mergedChunk = $this->mergeSegments($currentParts, 'list');
            $chunks[] = $this->makeChunkPayload((string) ($mergedChunk['content'] ?? ''), (int) ($mergedChunk['start'] ?? 0));
        }

        return array_values(array_filter($chunks, static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''));
    }

    /**
     * Purpose: Merge a list of segment payloads into one contiguous payload.
     * Inputs: A list of ordered segments from the same normalized source text.
     * Returns: A merged segment payload with a stable start offset.
     * Side effects: None.
     */
    private function mergeSegments(array $segments, ?string $kind = null): array
    {
        $content = '';
        $start = null;
        $contentLength = 0;

        foreach ($segments as $segment) {
            $segmentContent = (string) ($segment['content'] ?? '');
            $segmentStart = (int) ($segment['start'] ?? 0);

            if ($start === null) {
                $start = $segmentStart;
            }

            $content .= $segmentContent;
            $contentLength += (int) ($segment['content_length'] ?? mb_strlen(trim($segmentContent), 'UTF-8'));
        }

        return [
            'content' => $content,
            'start' => $start ?? 0,
            'length' => mb_strlen($content, 'UTF-8'),
            'content_length' => $contentLength,
            'kind' => $kind ?? (string) ($segments[0]['kind'] ?? 'body'),
        ];
    }

    /**
     * Purpose: Classify a segment into a lightweight generic structural kind.
     * Inputs: A segment's raw content.
     * Returns: One of body, list, or table.
     * Side effects: None.
     */
    private function classifySegmentKind(array $profile): string
    {
        $headingKind = $this->classifyHeadingKind($profile);

        if ($headingKind !== null) {
            return $headingKind;
        }

        if ($this->isLeadInLikeSegment(['profile' => $profile])) {
            return 'lead_in';
        }

        $segment = [
            'profile' => $profile,
        ];

        if ($this->isListLikeSegment($segment)) {
            return 'list';
        }

        if ($this->isCompactRowLikeSegment($segment)) {
            return 'table';
        }

        return 'body';
    }

    /**
     * Purpose: Determine whether a segment looks like a hard-boundary heading.
     * Inputs: A segment profile.
     * Returns: The heading level kind or null when the segment is not heading-like enough.
     * Side effects: None.
     */
    private function classifyHeadingKind(array $profile): ?string
    {
        $text = (string) ($profile['text'] ?? '');

        if ($text === '') {
            return null;
        }

        if ((int) ($profile['line_count'] ?? 0) !== 1) {
            return null;
        }

        if ((int) ($profile['length'] ?? 0) > 120) {
            return null;
        }

        if ((int) ($profile['word_count'] ?? 0) > 12) {
            return null;
        }

        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        if (preg_match('/[.!?;:]$/u', $text) === 1) {
            return null;
        }

        if (preg_match('/^\d+\.\d+(?:\.\d+)*[).:]?\s+\S+/u', $text) === 1) {
            return 'heading_h2';
        }

        if (preg_match('/^\d+[).:]?\s+\S+/u', $text) === 1) {
            return 'heading_h1';
        }

        if ($this->isBulletLine($text)) {
            return null;
        }

        if ($this->looksLikeGenericTitleHeading($text)) {
            return 'heading_h1';
        }

        return null;
    }

    /**
     * Purpose: Determine whether a short line looks like a generic section title.
     * Inputs: A trimmed heading candidate.
     * Returns: True when the line has a conservative title-like shape.
     * Side effects: None.
     */
    private function looksLikeGenericTitleHeading(string $text): bool
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        $wordCount = count($words);

        if ($wordCount === 0 || $wordCount > 8) {
            return false;
        }

        if (mb_strlen($text, 'UTF-8') > 80) {
            return false;
        }

        if (preg_match('/\d/u', $text) === 1) {
            return false;
        }

        $lowercaseWordCount = 0;

        foreach ($words as $word) {
            if (preg_match('/^\p{Ll}/u', $word) === 1) {
                $lowercaseWordCount++;
            }
        }

        if ($lowercaseWordCount > 2) {
            return false;
        }

        return preg_match('/^[\p{Lu}\p{N}]/u', $words[0]) === 1;
    }

    /**
     * Purpose: Resolve lightweight structural metadata for a segment.
     * Inputs: A segment's raw content.
     * Returns: A compact profile used for generic grouping heuristics.
     * Side effects: None.
     */
    private function segmentProfile(string $content): array
    {
        $trimmed = trim($content);
        $lines = preg_split('/\n/u', $trimmed) ?: [];
        $normalizedLines = [];
        $bulletLineCount = 0;

        foreach ($lines as $line) {
            $normalizedLine = trim(preg_replace('/\s+/u', ' ', (string) $line) ?? '');

            if ($normalizedLine === '') {
                continue;
            }

            $normalizedLines[] = $normalizedLine;

            if ($this->isBulletLine($normalizedLine)) {
                $bulletLineCount++;
            }
        }

        $lineCount = count($normalizedLines);
        $wordCount = preg_match_all('/\S+/u', $trimmed) ?: 0;
        $length = mb_strlen($trimmed, 'UTF-8');

        return [
            'text' => $trimmed,
            'lines' => $normalizedLines,
            'line_count' => $lineCount,
            'word_count' => $wordCount,
            'length' => $length,
            'average_line_length' => $lineCount > 0 ? $length / $lineCount : $length,
            'bullet_line_count' => $bulletLineCount,
            'ends_with_sentence_punctuation' => preg_match('/[.!?;:]$/u', $trimmed) === 1,
            'starts_with_bullet_or_number' => $this->isBulletLine($trimmed),
        ];
    }

    /**
     * Purpose: Determine whether a segment looks like a heading.
     * Inputs: A segment payload with a structural profile.
     * Returns: True when the segment is a short heading-like block.
     * Side effects: None.
     */
    private function isHeadingLikeSegment(array $segment): bool
    {
        return $this->classifyHeadingKind((array) ($segment['profile'] ?? [])) !== null;
    }

    /**
     * Purpose: Determine whether a segment is a short lead-in that should stay with a following list.
     * Inputs: A segment payload with a structural profile.
     * Returns: True when the segment is a short introductory block ending in a cue delimiter.
     * Side effects: None.
     */
    private function isLeadInLikeSegment(array $segment): bool
    {
        $profile = (array) ($segment['profile'] ?? []);
        $text = (string) ($profile['text'] ?? '');
        $lines = (array) ($profile['lines'] ?? []);
        $lineCount = count($lines);

        if ($text === '') {
            return false;
        }

        if ($lineCount === 0 || $lineCount > 3) {
            return false;
        }

        if ((int) ($profile['length'] ?? 0) > 220) {
            return false;
        }

        if ((int) ($profile['word_count'] ?? 0) > 28) {
            return false;
        }

        if (preg_match('/[:–-]\s*$/u', $text) !== 1) {
            return false;
        }

        if (($profile['starts_with_bullet_or_number'] ?? false) === true) {
            return false;
        }

        foreach ($lines as $line) {
            if ($this->isBulletLine((string) $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Purpose: Determine whether a short body segment should stay with a following list group even without a trailing colon.
     * Inputs: A segment payload with a structural profile.
     * Returns: True when the segment is a compact introductory block that should be locked to the next list group.
     * Side effects: None.
     */
    private function canAttachToFollowingListGroup(array $segment): bool
    {
        $kind = (string) ($segment['kind'] ?? 'body');
        $profile = (array) ($segment['profile'] ?? []);
        $text = (string) ($profile['text'] ?? '');
        $lines = (array) ($profile['lines'] ?? []);
        $lineCount = count($lines);

        if ($kind !== 'body') {
            return false;
        }

        if ($text === '') {
            return false;
        }

        if ($lineCount === 0 || $lineCount > 3) {
            return false;
        }

        if ((int) ($profile['length'] ?? 0) > 320) {
            return false;
        }

        if ((int) ($profile['word_count'] ?? 0) > 45) {
            return false;
        }

        if (($profile['starts_with_bullet_or_number'] ?? false) === true) {
            return false;
        }

        if (preg_match('/[!?]\s*$/u', $text) === 1) {
            return false;
        }

        foreach ($lines as $line) {
            if ($this->isBulletLine((string) $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Purpose: Determine whether a segment looks like a list item or a list block.
     * Inputs: A segment payload with a structural profile.
     * Returns: True when the segment is bullet- or number-led list content.
     * Side effects: None.
     */
    private function isListLikeSegment(array $segment): bool
    {
        $profile = (array) ($segment['profile'] ?? []);

        if (($profile['starts_with_bullet_or_number'] ?? false) === true) {
            return true;
        }

        return (int) ($profile['line_count'] ?? 0) > 1
            && (int) ($profile['bullet_line_count'] ?? 0) >= 2;
    }

    /**
     * Purpose: Determine whether a segment looks like a compact row from a table-like block.
     * Inputs: A segment payload with a structural profile.
     * Returns: True when the segment is a short, compact row-like block.
     * Side effects: None.
     */
    private function isCompactRowLikeSegment(array $segment): bool
    {
        $profile = (array) ($segment['profile'] ?? []);
        $text = (string) ($profile['text'] ?? '');

        if ($text === '') {
            return false;
        }

        return (int) ($profile['line_count'] ?? 0) === 1
            && (int) ($profile['length'] ?? 0) <= 140
            && (int) ($profile['word_count'] ?? 0) >= 3
            && (int) ($profile['word_count'] ?? 0) <= 20
            && ($profile['ends_with_sentence_punctuation'] ?? false) === false
            && ($profile['starts_with_bullet_or_number'] ?? false) === false
            && preg_match('/^[\p{L}\d]/u', $text) === 1;
    }

    /**
     * Purpose: Determine whether one line looks like a bullet or a numbered list item.
     * Inputs: A normalized text line.
     * Returns: True when the line starts with a generic list marker.
     * Side effects: None.
     */
    private function isBulletLine(string $line): bool
    {
        return preg_match('/^\s*(?:[-*•]|(?:\d+[\.\)]|[a-zA-Z][\.\)]))\s+\S+/u', $line) === 1;
    }

    /**
     * Purpose: Flush the current chunk buffer into the chunk list.
     * Inputs: The chunk list, buffer content, and buffer start offset.
     * Returns: None.
     * Side effects: Adds a chunk payload and resets the buffer state.
     */
    private function flushChunkBuffer(array &$chunks, string &$buffer, ?int &$bufferStart): void
    {
        if ($buffer === '' || $bufferStart === null) {
            return;
        }

        $chunks[] = $this->makeChunkPayload($buffer, $bufferStart);
        $buffer = '';
        $bufferStart = null;
    }

    /**
     * Purpose: Determine whether two segment kinds should stay in separate chunks.
     * Inputs: The current buffer kind and the next segment kind.
     * Returns: True when the kinds should not be merged into the same chunk.
     * Side effects: None.
     */
    private function shouldSeparateChunkKinds(string $bufferKind, string $segmentKind): bool
    {
        $separatingKinds = ['list', 'table'];

        if ($this->isOpeningSegmentKind($bufferKind) || $this->isOpeningSegmentKind($segmentKind)) {
            return false;
        }

        if ($bufferKind === $segmentKind) {
            return false;
        }

        return in_array($bufferKind, $separatingKinds, true) || in_array($segmentKind, $separatingKinds, true);
    }

    /**
     * Purpose: Determine whether a segment kind is an opening chunk boundary.
     * Inputs: A lightweight segment kind.
     * Returns: True when the kind should start a new chunk and be kept with its first real content.
     * Side effects: None.
     */
    private function isOpeningSegmentKind(string $kind): bool
    {
        return str_starts_with($kind, 'heading_');
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
            'word_count' => $this->countWordsInText($content),
        ];
    }

    /**
     * Purpose: Count words in a piece of text using whitespace tokenization.
     * Inputs: Raw text.
     * Returns: Word count.
     * Side effects: None.
     */
    private function countWordsInText(string $text): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        return preg_match_all('/\S+/u', $text) ?: 0;
    }

    /**
     * Purpose: Count words in a slice of the structured source text.
     * Inputs: Full source text and absolute character offsets.
     * Returns: Word count for the selected range.
     * Side effects: None.
     */
    private function countWordsInRange(string $sourceText, int $startOffset, int $endOffset): int
    {
        $length = max(0, $endOffset - $startOffset);

        if ($length === 0) {
            return 0;
        }

        $slice = mb_substr($sourceText, $startOffset, $length, 'UTF-8');

        return $this->countWordsInText($slice);
    }
}
