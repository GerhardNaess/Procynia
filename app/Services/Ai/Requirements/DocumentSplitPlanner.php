<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentSplitPlanner
{

    private const IDEAL_CHUNK_SIZE = 30000;
    private const MAX_CHUNK_SIZE = 36000;
    private const MIN_CHUNK_SIZE = 12000;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Build a deterministic chapter-first split plan locally without calling OpenAI.
     * Inputs: The source AI document and an optional run id.
     * Returns: A structured array containing plan status and a locally generated split plan.
     * Side effects: Writes diagnostic logs.
     */
    public function plan(SavedNoticeAiDocument $document, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $documentText = trim((string) $document->extracted_text);
        $model = 'deterministic_local_splitter';
        $promptVersion = 'deterministic_local_splitter.v3';

        Log::info('[PROCYNIA][DOC_SPLIT] Document split planning started.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'model' => $model,
            'prompt_version' => $promptVersion,
        ]);

        if ($documentText === '') {
            return $this->failedResult(
                document: $document,
                runId: $runId,
                model: $model,
                promptVersion: $promptVersion,
                elapsedMs: $this->elapsedMs($startedAt),
                errorType: 'invalid_request',
                errorMessage: 'Document extracted text is missing.',
            );
        }

        $parsed = $this->buildDeterministicPlan($documentText, $runId);

        $result = [
            'ok' => true,
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => 200,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $this->elapsedMs($startedAt),
            'raw_output' => null,
            'document_summary' => $parsed['document_summary'],
            'split_plan' => $parsed['split_plan'],
        ];

        Log::info('[PROCYNIA][DOC_SPLIT] Document split planning completed.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => 200,
            'document_text_length' => mb_strlen($documentText, 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $result['elapsed_ms'],
            'document_summary' => $result['document_summary'],
            'split_plan_count' => count($result['split_plan']),
            'split_plan' => $result['split_plan'],
        ]);

        return $result;
    }

    /**
     * Purpose: Build a deterministic failure payload for document split planning.
     * Inputs: The source document and stable failure metadata.
     * Returns: A uniform failure array.
     * Side effects: Writes failure logs.
     */
    private function failedResult(
        SavedNoticeAiDocument $document,
        string $runId,
        string $model,
        string $promptVersion,
        ?int $elapsedMs = null,
        string $errorType = 'unexpected_error',
        string $errorMessage = 'Document split planning failed.',
    ): array {
        $result = [
            'ok' => false,
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_id' => null,
            'response_id' => null,
            'status' => null,
            'document_text_length' => mb_strlen(trim((string) $document->extracted_text), 'UTF-8'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'elapsed_ms' => $elapsedMs,
            'raw_output' => null,
            'document_summary' => null,
            'split_plan' => [],
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ];

        Log::warning('[PROCYNIA][DOC_SPLIT] Document split planning failed.', [
            'run_id' => $runId,
            'saved_notice_id' => (int) $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'document_title' => (string) $document->original_filename,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'status' => null,
            'document_text_length' => $result['document_text_length'],
            'elapsed_ms' => $elapsedMs,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
        ]);

        return $result;
    }

    /**
     * Purpose: Build a deterministic split plan from heading boundaries only.
     * Inputs: The extracted document text.
     * Returns: A summary and a split plan with start/end positions and anchor previews.
     * Side effects: None.
     */
    private function buildDeterministicPlan(string $documentText, ?string $runId = null): array
    {
        $sections = $this->mergeSectionsIntoPlanningUnits(
            $this->extractMainSections($documentText, $runId),
            $documentText,
        );
        $splitPlan = [];
        $chunkIndex = 1;

        foreach ($sections as $section) {
            $chunks = $this->splitSectionIntoChunks(
                documentText: $documentText,
                start: (int) $section['start_position'],
                end: (int) $section['end_position'],
                title: (string) $section['title'],
                groupType: (string) $section['group_type'],
            );

            foreach ($chunks as $chunk) {
                $splitPlan[] = [
                    'group_id' => sprintf('chunk_%03d', $chunkIndex),
                    'group_type' => $chunk['group_type'],
                    'title' => sprintf('Chunk %d: %s', $chunkIndex, $chunk['title']),
                    'start_anchor' => $this->anchorPreviewFromOffset($documentText, $chunk['start_position']),
                    'end_anchor' => $this->anchorPreviewFromOffset($documentText, max($chunk['start_position'], $chunk['end_position'] - 180)),
                    'start_position' => $chunk['start_position'],
                    'end_position' => $chunk['end_position'],
                    'reason' => $chunk['reason'],
                ];

                $chunkIndex++;
            }
        }

        return [
            'document_summary' => [
                'document_type' => 'H1-based document split with TOC removal',
                'overall_assessment' => sprintf(
                    'The document was split into %d H1-based chunk(s) after removing the table of contents and dropping all pre-body content.',
                    count($splitPlan)
                ),
            ],
            'split_plan' => $splitPlan,
        ];
    }

    /**
     * Purpose: Split document text into lines with stable character offsets.
     * Inputs: The full document text.
     * Returns: Ordered line metadata with start and end positions.
     * Side effects: None.
     */
    private function splitTextIntoLinesWithOffsets(string $documentText): array
    {
        $parts = preg_split('/(\R)/u', $documentText, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts) || $parts === []) {
            return [];
        }

        $lines = [];
        $cursor = 0;
        $partCount = count($parts);

        for ($i = 0; $i < $partCount; $i += 2) {
            $lineText = (string) ($parts[$i] ?? '');
            $delimiter = (string) ($parts[$i + 1] ?? '');
            $startPosition = $cursor;
            $endPosition = $startPosition + mb_strlen($lineText, 'UTF-8');
            $delimiterLength = mb_strlen($delimiter, 'UTF-8');

            $lines[] = [
                'text' => $lineText,
                'start_position' => $startPosition,
                'end_position' => $endPosition,
                'end_with_delimiter_position' => $endPosition + $delimiterLength,
            ];

            $cursor = $endPosition + $delimiterLength;
        }

        return $lines;
    }

    /**
     * Purpose: Detect a leading table-of-contents block using conservative outline patterns.
     * Inputs: Ordered lines with offsets.
     * Returns: A TOC range or null when no block is found.
     * Side effects: None.
     */
    private function detectTableOfContentsRange(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        $maxScanLines = min(count($lines), 80);
        $tocStartIndex = null;
        $tocEndIndex = null;
        $outlineCount = 0;
        $seenContentsHeading = false;

        for ($lineIndex = 0; $lineIndex < $maxScanLines; $lineIndex++) {
            $lineText = trim((string) ($lines[$lineIndex]['text'] ?? ''));

            if ($lineText === '') {
                continue;
            }

            if ($tocStartIndex === null && $this->isContentsHeadingLine($lineText)) {
                $tocStartIndex = $lineIndex;
                $seenContentsHeading = true;
                $tocEndIndex = $lineIndex;

                continue;
            }

            if ($seenContentsHeading && $this->isOutlineEntryLine($lineText)) {
                $tocStartIndex ??= $lineIndex;
                $outlineCount++;
                $tocEndIndex = $lineIndex;

                continue;
            }

            $headingCandidate = $this->classifyHeadingCandidate($lines, $lineIndex, $lineText);

            if ($headingCandidate !== null && ($headingCandidate['classification'] ?? '') === 'toc_only') {
                $tocStartIndex ??= $lineIndex;
                $outlineCount++;
                $tocEndIndex = $lineIndex;

                continue;
            }

            if ($seenContentsHeading || $outlineCount > 0) {
                break;
            }
        }

        if ($tocStartIndex === null || $tocEndIndex === null || $outlineCount < 2) {
            return null;
        }

        while (($tocEndIndex + 1) < count($lines)) {
            $nextText = trim((string) ($lines[$tocEndIndex + 1]['text'] ?? ''));

            if ($nextText !== '') {
                break;
            }

            $tocEndIndex++;
        }

        return [
            'start_position' => (int) ($lines[$tocStartIndex]['start_position'] ?? 0),
            'end_position' => (int) ($lines[$tocEndIndex]['end_with_delimiter_position'] ?? $lines[$tocEndIndex]['end_position'] ?? 0),
            'line_count' => $tocEndIndex - $tocStartIndex + 1,
        ];
    }

    /**
     * Purpose: Determine whether a line is a contents heading.
     * Inputs: One trimmed line of text.
     * Returns: True when the line clearly introduces a table of contents.
     * Side effects: None.
     */
    private function isContentsHeadingLine(string $line): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $line) ?? $line), 'UTF-8');

        if ($normalized === '') {
            return false;
        }

        if (mb_strlen($normalized, 'UTF-8') > 80) {
            return false;
        }

        return preg_match('/\b(?:innholdsfortegnelse|table of contents|contents)\b/ui', $normalized) === 1
            || $normalized === 'innhold'
            || $normalized === 'innholdsfortegnelse';
    }

    /**
     * Purpose: Determine the heading level for one line of text.
     * Inputs: One trimmed line of text.
     * Returns: 1 or 2 for usable headings, or null when the line is not a heading.
     * Side effects: None.
     */
    private function detectHeadingLevel(string $line): ?int
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return null;
        }

        if (preg_match('/^\s*(?:Bilag|Vedlegg|Appendix|Appendiks)\s+\d+\b.*$/ui', $trimmedLine) === 1) {
            return 1;
        }

        if (preg_match('/^\s*\d+\.\d+\.\d+(?:\.\d+)*\s+[^\d].*$/u', $trimmedLine) === 1) {
            return 3;
        }

        if (preg_match('/^\s*\d+\.\d+\s+[^\d].*$/u', $trimmedLine) === 1) {
            return 2;
        }

        if (preg_match('/^\s*\d+\.\s+[^\d].*$/u', $trimmedLine) === 1) {
            return 1;
        }

        return null;
    }

    /**
     * Purpose: Classify a heading candidate as TOC-only or a real body heading.
     * Inputs: The full line list, the candidate line index, and one trimmed candidate line of text.
     * Returns: Classification metadata or null when the line is not a heading candidate.
     * Side effects: None.
     */
    private function classifyHeadingCandidate(array $lines, int $lineIndex, string $line): ?array
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return null;
        }

        if ($this->isContentsHeadingLine($trimmedLine)) {
            return [
                'classification' => 'toc_only',
                'level' => null,
                'reason' => 'Contents heading detected.',
            ];
        }

        $level = $this->detectHeadingLevel($trimmedLine);

        if ($level === null) {
            return null;
        }

        if ($this->isOutlineOnlyHeadingLine($trimmedLine)) {
            return [
                'classification' => 'toc_only',
                'level' => $level,
                'reason' => 'Outline-style heading with trailing page number detected.',
            ];
        }

        return [
            'classification' => 'body_candidate',
            'level' => $level,
            'reason' => 'Body heading detected.',
        ];
    }

    /**
     * Purpose: Find the next non-empty line after a given line index.
     * Inputs: The full line list and the current line index.
     * Returns: The next trimmed non-empty line or null when none exists.
     * Side effects: None.
     */
    private function nextNonEmptyLineText(array $lines, int $lineIndex): ?string
    {
        $lineCount = count($lines);

        for ($candidateIndex = $lineIndex + 1; $candidateIndex < $lineCount; $candidateIndex++) {
            $candidateText = trim((string) ($lines[$candidateIndex]['text'] ?? ''));

            if ($candidateText === '') {
                continue;
            }

            return $candidateText;
        }

        return null;
    }

    /**
     * Purpose: Determine whether a line still looks like heading or outline text.
     * Inputs: One trimmed line of text.
     * Returns: True when the line is likely a heading-like line rather than body content.
     * Side effects: None.
     */
    private function isHeadingLikeText(string $line): bool
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return false;
        }

        return $this->isContentsHeadingLine($trimmedLine)
            || $this->isOutlineOnlyHeadingLine($trimmedLine)
            || $this->detectHeadingLevel($trimmedLine) !== null;
    }

    /**
     * Purpose: Normalize a heading title for deterministic keyword checks.
     * Inputs: A raw heading line.
     * Returns: A lower-cased, whitespace-normalized string.
     * Side effects: None.
     */
    private function normalizeHeadingTitle(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title), 'UTF-8');
    }

    /**
     * Purpose: Determine whether a heading is clearly guidance or front matter.
     * Inputs: A normalized heading title.
     * Returns: True when the heading should not produce an emitted section on its own.
     * Side effects: None.
     */
    private function isGuidanceHeadingTitle(string $normalizedTitle): bool
    {
        $keywords = [
            'innholdsfortegnelse',
            'veiledning',
            'leverandørens besvarelse',
            'besvarelse av krav',
            'forklaring til nummerering',
            'nummerering',
            'tildelingskriterier',
            'kravtekst',
            'skal-krav',
            'bør-krav',
            'dokumentstruktur',
            'generelt oppfordres',
            'oppfordres',
            'svarinstruksjon',
        ];

        return $this->containsAnyKeyword($normalizedTitle, $keywords);
    }

    /**
     * Purpose: Determine whether a heading belongs to an appendix or attachment block.
     * Inputs: A normalized heading title.
     * Returns: True when the heading should only be kept if it contains real requirement signals.
     * Side effects: None.
     */
    private function isAttachmentHeadingTitle(string $normalizedTitle): bool
    {
        return preg_match('/\b(?:bilag|vedlegg|appendix|appendiks)\b/ui', $normalizedTitle) === 1;
    }

    /**
     * Purpose: Find child heading nodes inside a bounded section.
     * Inputs: All detected headings and a section range.
     * Returns: A list of headings that are strictly inside the section.
     * Side effects: None.
     */
    private function findSubheadingsWithinRange(array $headings, int $sectionStart, int $sectionEnd, int $level): array
    {
        $subheadings = [];

        foreach ($headings as $heading) {
            if ((int) ($heading['level'] ?? 0) !== $level) {
                continue;
            }

            $headingStart = (int) ($heading['start_position'] ?? -1);

            if ($headingStart <= $sectionStart || $headingStart >= $sectionEnd) {
                continue;
            }

            $subheadings[] = $heading;
        }

        usort($subheadings, static fn (array $left, array $right): int => (int) $left['start_position'] <=> (int) $right['start_position']);

        return $subheadings;
    }

    /**
     * Purpose: Analyse a section and classify it into a deterministic section type.
     * Inputs: The full document text, the absolute section boundaries, and the section title.
     * Returns: Section-level classification metadata without shifting boundaries.
     * Side effects: None.
     */
    private function analyseSectionBlocks(string $documentText, int $sectionStart, int $sectionEnd, string $sectionTitle = ''): array
    {
        $sectionLength = max(0, $sectionEnd - $sectionStart);
        $sectionText = mb_substr($documentText, $sectionStart, $sectionLength, 'UTF-8');
        $blocks = $this->splitIntoLogicalBlocks($sectionText, $sectionStart);
        $combinedText = trim(($sectionTitle !== '' ? $sectionTitle . "\n" : '') . $sectionText);
        $analysis = $this->classifySectionText($combinedText);

        return [
            'blocks' => $blocks,
            'block_count' => count($blocks),
            'section_type' => $analysis['section_type'],
            'emission_decision' => $analysis['emission_decision'],
            'reason' => $analysis['reason'],
            'outline_line_count' => $analysis['outline_line_count'],
            'guidance_signal_count' => $analysis['guidance_signal_count'],
            'legend_signal_count' => $analysis['legend_signal_count'],
            'attachment_signal_count' => $analysis['attachment_signal_count'],
            'strong_requirement_signal_count' => $analysis['strong_requirement_signal_count'],
            'long_line_count' => $analysis['long_line_count'],
            'short_line_count' => $analysis['short_line_count'],
            'word_count' => $analysis['word_count'],
            'section_title' => $sectionTitle,
        ];
    }

    /**
     * Purpose: Classify one section of text without moving its boundaries.
     * Inputs: The full section text including its heading line context.
     * Returns: Stable section type, emission decision, and supporting metrics.
     * Side effects: None.
     */
    private function classifySectionText(string $sectionText): array
    {
        $trimmedText = trim($sectionText);
        $normalizedText = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmedText) ?? $trimmedText, 'UTF-8');
        $lines = array_values(array_filter(preg_split('/\R/u', $trimmedText) ?: [], fn (string $line): bool => trim($line) !== ''));
        $lineCount = count($lines);
        $outlineLineCount = $this->countMatchingLines($lines, fn (string $line): bool => $this->isOutlineEntryLine($line));
        $shortLineCount = $this->countMatchingLines($lines, fn (string $line): bool => mb_strlen(trim($line), 'UTF-8') <= 60);
        $longLineCount = $this->countMatchingLines($lines, fn (string $line): bool => mb_strlen(trim($line), 'UTF-8') >= 90 || (preg_match('/[.!?]/u', trim($line)) === 1 && mb_strlen(trim($line), 'UTF-8') >= 60));
        $wordCount = count(preg_split('/\s+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $signals = $this->classifyTextSignals($trimmedText);
        $strongRequirementSignalCount = count(array_filter([
            (bool) ($signals['contains_requirement_id'] ?? false),
            (bool) ($signals['contains_numbered_requirement'] ?? false),
            (bool) ($signals['contains_table_signals'] ?? false),
        ]));
        $hasRequirementVerb = (bool) ($signals['contains_requirement_verb'] ?? false);
        $hasGuidanceSignals = $this->containsGuidanceMetaLanguage($normalizedText);
        $hasLegendSignals = $this->containsNumberingLegendLanguage($normalizedText);
        $hasAttachmentSignals = $this->containsAttachmentIndexLanguage($normalizedText);
        $hasTocSignals = $this->isTocLikeBlock($lines, $trimmedText) || $outlineLineCount >= 2;

        $sectionType = 'requirement_body';
        $reason = 'Substantive body content or concrete requirement signals detected.';

        if ($hasTocSignals && $strongRequirementSignalCount === 0 && $longLineCount === 0) {
            $sectionType = 'toc_only';
            $reason = 'Outline-style lines with page numbers detected before the body.';
        } elseif ($hasGuidanceSignals && $strongRequirementSignalCount === 0 && $longLineCount <= 1) {
            $sectionType = 'guidance_meta';
            $reason = 'Guidance or answer-instruction language detected without a concrete requirement block.';
        } elseif ($hasLegendSignals && $longLineCount <= 1) {
            $sectionType = 'numbering_legend';
            $reason = 'Numbering legend or example-ID explanation detected without a concrete requirement block.';
        } elseif ($hasAttachmentSignals && $strongRequirementSignalCount === 0 && $longLineCount <= 1 && ($outlineLineCount >= 1 || $wordCount < 140)) {
            $sectionType = 'attachment_index_only';
            $reason = 'Attachment or index-style section detected without a concrete requirement block.';
        } elseif (
            ($outlineLineCount >= 1 && $wordCount < 120 && $longLineCount <= 1 && ! $hasRequirementVerb)
            || ($wordCount < 25 && $strongRequirementSignalCount === 0 && ! $hasRequirementVerb)
        ) {
            $sectionType = 'front_matter';
            $reason = 'Short outline-like section detected without substantive body content.';
        } elseif ($strongRequirementSignalCount > 0 || ($hasRequirementVerb && ! $hasGuidanceSignals) || $longLineCount > 0 || $wordCount >= 40) {
            $sectionType = 'requirement_body';
            $reason = $strongRequirementSignalCount > 0
                ? 'Concrete requirement identifiers or table-like requirement layout detected.'
                : 'Substantive body text or requirement language detected.';
        } else {
            $sectionType = 'front_matter';
            $reason = 'Section remained short and outline-like after classification.';
        }

        return [
            'section_type' => $sectionType,
            'emission_decision' => $sectionType === 'requirement_body' ? 'emitted' : 'skipped',
            'reason' => $reason,
            'outline_line_count' => $outlineLineCount,
            'guidance_signal_count' => $hasGuidanceSignals ? 1 : 0,
            'legend_signal_count' => $hasLegendSignals ? 1 : 0,
            'attachment_signal_count' => $hasAttachmentSignals ? 1 : 0,
            'strong_requirement_signal_count' => $strongRequirementSignalCount,
            'long_line_count' => $longLineCount,
            'short_line_count' => $shortLineCount,
            'word_count' => $wordCount,
        ];
    }

    /**
     * Purpose: Count how many lines match one predicate.
     * Inputs: Ordered lines and a predicate.
     * Returns: The number of matching lines.
     * Side effects: None.
     */
    private function countMatchingLines(array $lines, callable $predicate): int
    {
        $count = 0;

        foreach ($lines as $line) {
            if ($predicate((string) $line)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Purpose: Determine whether text contains guidance or answer-instruction language.
     * Inputs: One normalized text string.
     * Returns: True when the text is instruction-like and not a concrete requirement body.
     * Side effects: None.
     */
    private function containsGuidanceMetaLanguage(string $normalizedText): bool
    {
        $keywords = [
            'veiledning',
            'leserveiledning',
            'leverandørens besvarelse',
            'besvarelse av krav',
            'hvordan leverandøren skal',
            'hvordan du svarer',
            'skriv kort',
            'beskriv hvordan',
            'vis til vedlegg',
            'svarinstruksjon',
            'oppfordres',
            'tildelingskriterier',
            'ved evalueringen legges',
            'i hvilken grad',
            'generelt oppfordres',
            'formatering av besvarelsen',
        ];

        return $this->containsAnyKeyword($normalizedText, $keywords);
    }

    /**
     * Purpose: Determine whether text explains numbering or example IDs instead of stating requirements.
     * Inputs: One normalized text string.
     * Returns: True when the text is a numbering legend or format explanation.
     * Side effects: None.
     */
    private function containsNumberingLegendLanguage(string $normalizedText): bool
    {
        $keywords = [
            'forklaring til nummerering',
            'nummerering av krav',
            'krav-id',
            'kravid',
            'eksempel på',
            'eksempler på',
            'nummerering',
            'betyr',
            'står for',
            'består av',
            'format',
            'struktur',
            'leses som',
        ];

        if ($this->containsAnyKeyword($normalizedText, $keywords)) {
            return true;
        }

        return preg_match('/\b\d+(?:[-.]\d+)+(?:\s*[-.]\s*[A-Za-z0-9]+)?\s*=\s*/u', $normalizedText) === 1;
    }

    /**
     * Purpose: Determine whether text is an attachment or index-style section without real requirements.
     * Inputs: One normalized text string.
     * Returns: True when the text is an attachment index or list rather than a requirement body.
     * Side effects: None.
     */
    private function containsAttachmentIndexLanguage(string $normalizedText): bool
    {
        return preg_match('/\b(?:bilag|vedlegg|appendix|appendiks)\b/ui', $normalizedText) === 1
            && (
                preg_match('/\b(?:oversikt|liste|indeks|register|vedleggsoversikt|bilagsoversikt)\b/ui', $normalizedText) === 1
                || preg_match('/\b(?:punkt|punktliste|vedleggsliste)\b/ui', $normalizedText) === 1
            );
    }

    /**
     * Purpose: Split a section into logical text blocks separated by blank lines.
     * Inputs: The section text and its absolute start position in the document.
     * Returns: Block metadata with absolute offsets and raw text.
     * Side effects: None.
     */
    private function splitIntoLogicalBlocks(string $sectionText, int $sectionStartPosition): array
    {
        $pieces = preg_split('/(?:\r\n|\r|\n){2,}/u', $sectionText, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (! is_array($pieces) || $pieces === []) {
            $trimmedSectionText = trim($sectionText);

            if ($trimmedSectionText === '') {
                return [];
            }

            return [[
                'text' => $trimmedSectionText,
                'start_position' => $sectionStartPosition,
                'end_position' => $sectionStartPosition + mb_strlen($trimmedSectionText, 'UTF-8'),
                'line_count' => count(preg_split('/\R/u', $trimmedSectionText, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ]];
        }

        $blocks = [];

        foreach ($pieces as $piece) {
            $blockText = (string) ($piece[0] ?? '');
            $byteOffset = (int) ($piece[1] ?? 0);
            $charOffset = $this->byteOffsetToCharOffset($sectionText, $byteOffset);
            $absoluteStart = $sectionStartPosition + $charOffset;
            $lineCount = count(preg_split('/\R/u', trim($blockText), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            $blocks[] = [
                'text' => $blockText,
                'start_position' => $absoluteStart,
                'end_position' => $absoluteStart + mb_strlen($blockText, 'UTF-8'),
                'line_count' => $lineCount,
            ];
        }

        return $blocks;
    }

    /**
     * Purpose: Classify a logical text block as requirement-bearing or non-requirement content.
     * Inputs: One block of section text.
     * Returns: Signal booleans, a classification hint, and a short reason string.
     * Side effects: None.
     */
    private function classifyTextSignals(string $text): array
    {
        $trimmedText = trim($text);
        $normalizedText = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmedText) ?? $trimmedText, 'UTF-8');
        $lines = array_values(array_filter(preg_split('/\R/u', $trimmedText) ?: [], static fn (string $line): bool => trim($line) !== ''));
        $hasTocLayout = $this->isTocLikeBlock($lines, $trimmedText);
        $hasOutlineEntry = $this->isOutlineEntryLine($trimmedText);
        $frontMatterKeywords = [
            'innholdsfortegnelse',
            'veiledning',
            'leserveiledning',
            'leverandørens besvarelse',
            'besvarelse av krav',
            'nummerering',
            'forklaring',
            'slik svarer du',
            'hvordan du svarer',
            'dokumentstruktur',
            'kravnummer',
            'kravid',
            'utfyll',
            'retningslinjer',
        ];
        $hasFrontMatterSignals = $hasTocLayout || $hasOutlineEntry || $this->containsAnyKeyword($normalizedText, $frontMatterKeywords);
        $hasAppendixSignals = preg_match('/\b(?:bilag|vedlegg|appendix|appendiks)\b/ui', $normalizedText) === 1;
        $normalizedIdentifierText = preg_replace('/\s*([.-])\s*/u', '$1', $trimmedText) ?? $trimmedText;
        $hasRequirementId = preg_match('/\b\d+\s*[-.]\s*\d+\b/u', $normalizedIdentifierText) === 1
            || preg_match('/\b\d+(?:[-.][A-Za-z0-9]+){2,}\b/u', $normalizedIdentifierText) === 1;
        $hasNumberedRequirement = preg_match('/^\s*(?:[-*•]\s*)?\d+[.)]\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $trimmedText) === 1
            || preg_match('/^\s*(?:[-*•]\s*)?\d+(?:[-.]\d+)+\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $trimmedText) === 1;
        $hasTableSignals = str_contains($trimmedText, '|')
            || preg_match('/\t/u', $trimmedText) === 1
            || preg_match('/\bID\b.*\bKravtekst\b.*\bBesvarelse\b/ui', $trimmedText) === 1
            || preg_match('/\bKravtekst\b.*\bBesvarelse\b/ui', $trimmedText) === 1;
        $hasRequirementVerb = preg_match('/\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $normalizedText) === 1;
        $hasStrongRequirementSignals = $hasRequirementId || $hasNumberedRequirement || $hasTableSignals;
        $containsRequirementSignals = $hasStrongRequirementSignals
            || ($hasRequirementVerb && ! $hasFrontMatterSignals);

        $reasonParts = [];

        if ($hasFrontMatterSignals) {
            $reasonParts[] = $hasOutlineEntry || $hasTocLayout
                ? 'Outline entry with page number detected.'
                : 'Instructional or numbering guidance detected.';
        }

        if ($hasAppendixSignals) {
            $reasonParts[] = 'Appendix-style language detected.';
        }

        if ($hasRequirementId) {
            $reasonParts[] = 'Requirement identifier pattern detected.';
        }

        if ($hasNumberedRequirement) {
            $reasonParts[] = 'Numbered requirement list pattern detected.';
        }

        if ($hasTableSignals) {
            $reasonParts[] = 'Table-like requirement layout detected.';
        }

        if ($hasRequirementVerb) {
            $reasonParts[] = 'Requirement verb pattern detected.';
        }

        $classification = 'unknown';

        if ($containsRequirementSignals) {
            $classification = $hasTableSignals ? 'requirement_section' : 'requirement_block';
        } elseif ($hasFrontMatterSignals) {
            $classification = 'non_requirement_front_matter';
        } elseif ($hasAppendixSignals) {
            $classification = 'appendix_non_requirements';
        }

        return [
            'contains_requirement_signals' => $containsRequirementSignals,
            'contains_front_matter_signals' => $hasFrontMatterSignals,
            'contains_appendix_signals' => $hasAppendixSignals,
            'contains_outline_entry_signals' => $hasOutlineEntry,
            'contains_table_signals' => $hasTableSignals,
            'contains_requirement_id' => $hasRequirementId,
            'contains_numbered_requirement' => $hasNumberedRequirement,
            'contains_requirement_verb' => $hasRequirementVerb,
            'classification' => $classification,
            'reason' => $reasonParts !== [] ? implode(' ', $reasonParts) : 'No strong requirement or front-matter signals detected.',
        ];
    }

    /**
     * Purpose: Determine whether a block is TOC-like by looking for repeated short lines with page numbers.
     * Inputs: The block lines and the raw block text.
     * Returns: True when the block resembles an innholdsfortegnelse or outline.
     * Side effects: None.
     */
    private function isTocLikeBlock(array $lines, string $text): bool
    {
        if ($lines === []) {
            return false;
        }

        $tocLineCount = 0;

        foreach ($lines as $line) {
            $trimmedLine = trim((string) $line);

            if ($trimmedLine === '') {
                continue;
            }

            if ($this->isOutlineEntryLine($trimmedLine)) {
                $tocLineCount++;
            }
        }

        if ($tocLineCount >= 1) {
            return true;
        }

        return preg_match('/\binnholdsfortegnelse\b/ui', $text) === 1
            || preg_match('/\btable of contents\b/ui', $text) === 1;
    }

    /**
     * Purpose: Determine whether one line looks like a table-of-contents or outline entry with a page number.
     * Inputs: A single trimmed line of text.
     * Returns: True when the line is likely front matter rather than a requirement block.
     * Side effects: None.
     */
    private function isOutlineEntryLine(string $line): bool
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return false;
        }

        if (preg_match('/\.\.\.+\s*\d+\s*$/u', $trimmedLine) === 1) {
            return true;
        }

        if (preg_match('/^\s*\d+(?:\.\d+)*\.?\s+[^\n]+?\s+\d+\s*$/u', $trimmedLine) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Purpose: Determine whether a heading candidate is actually part of an outline or contents listing.
     * Inputs: One trimmed heading-like line of text.
     * Returns: True when the line should be excluded from body heading detection.
     * Side effects: None.
     */
    private function isOutlineOnlyHeadingLine(string $line): bool
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return false;
        }

        if ($this->isContentsHeadingLine($trimmedLine) || $this->isOutlineEntryLine($trimmedLine)) {
            return true;
        }

        if (preg_match('/^\s*(?:Bilag|Vedlegg|Appendix|Appendiks)\s+\d+\b.*\s+\d+\s*$/ui', $trimmedLine) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Purpose: Find the first requirement anchor within one block of text.
     * Inputs: The block text.
     * Returns: The first anchor text and its relative offset, or null when nothing suitable is found.
     * Side effects: None.
     */
    private function findFirstRequirementAnchorInText(string $text): ?array
    {
        $patterns = [
            '/\b\d+\s*[-.]\s*\d+\b/u',
            '/\b\d+(?:\s*[-.]\s*[A-Za-z0-9]+){2,}\b/u',
            '/^\s*(?:[-*•]\s*)?\d+[.)]\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui',
            '/^\s*(?:[-*•]\s*)?\d+(?:\s*[-.]\s*[A-Za-z0-9]+){1,}\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui',
        ];

        $bestMatch = null;
        $bestOffset = null;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $matchedText = (string) ($matches[0][0] ?? '');
            $byteOffset = (int) ($matches[0][1] ?? 0);
            $charOffset = $this->byteOffsetToCharOffset($text, $byteOffset);

            if ($bestOffset === null || $charOffset < $bestOffset) {
                $bestOffset = $charOffset;
                $bestMatch = [
                    'anchor' => $matchedText !== '' ? $matchedText : $this->previewText($text, 60),
                    'offset' => $charOffset,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Purpose: Check whether a normalized text fragment contains any of the provided keywords.
     * Inputs: One normalized text string and a keyword list.
     * Returns: True when at least one keyword is present.
     * Side effects: None.
     */
    private function containsAnyKeyword(string $normalizedText, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Purpose: Classify heading sections and keep only requirement bodies for chunking.
     * Inputs: The raw top-level sections and the full document text.
     * Returns: Sections that should be considered for chunking.
     * Side effects: Writes diagnostic split logs.
     */
    private function filterRequirementBearingSections(array $sections, string $documentText, ?string $runId = null): array
    {
        $filteredSections = [];
        $summaryCounts = [
            'toc_only' => 0,
            'front_matter' => 0,
            'guidance_meta' => 0,
            'numbering_legend' => 0,
            'attachment_index_only' => 0,
            'requirement_body' => 0,
        ];

        foreach ($sections as $sectionIndex => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $sectionStart = (int) ($section['start_position'] ?? 0);
            $sectionEnd = (int) ($section['end_position'] ?? $sectionStart);
            $sectionLength = max(0, $sectionEnd - $sectionStart);
            $sectionText = trim((string) mb_substr($documentText, $sectionStart, $sectionLength, 'UTF-8'));
            $analysis = $this->analyseSectionBlocks($documentText, $sectionStart, $sectionEnd, $title);
            $sectionType = (string) ($analysis['section_type'] ?? 'front_matter');
            $emissionDecision = (string) ($analysis['emission_decision'] ?? ($sectionType === 'requirement_body' ? 'emitted' : 'skipped'));
            $summaryCounts[$sectionType] = ($summaryCounts[$sectionType] ?? 0) + 1;

            Log::info('[PROCYNIA][DOC_SPLIT] Heading section classified.', [
                'run_id' => $runId,
                'section_index' => $sectionIndex,
                'section_title' => $title,
                'heading_level' => (int) ($section['heading_level'] ?? 0),
                'detected_section_type' => $sectionType,
                'emission_decision' => $emissionDecision,
                'child_heading_count' => count(is_array($section['child_headings'] ?? null) ? $section['child_headings'] : []),
                'section_length' => $sectionLength,
                'outline_line_count' => (int) ($analysis['outline_line_count'] ?? 0),
                'guidance_signal_count' => (int) ($analysis['guidance_signal_count'] ?? 0),
                'legend_signal_count' => (int) ($analysis['legend_signal_count'] ?? 0),
                'attachment_signal_count' => (int) ($analysis['attachment_signal_count'] ?? 0),
                'strong_requirement_signal_count' => (int) ($analysis['strong_requirement_signal_count'] ?? 0),
                'long_line_count' => (int) ($analysis['long_line_count'] ?? 0),
                'short_line_count' => (int) ($analysis['short_line_count'] ?? 0),
                'word_count' => (int) ($analysis['word_count'] ?? 0),
                'reason' => (string) ($analysis['reason'] ?? 'No classification reason available.'),
            ]);

            if ($emissionDecision !== 'emitted') {
                continue;
            }

            $filteredSections[] = [
                'title' => $title,
                'start_position' => $sectionStart,
                'end_position' => $sectionEnd,
                'group_type' => $sectionType,
                'reason' => (string) ($analysis['reason'] ?? 'Requirement body retained for chunking.'),
                'heading_level' => (int) ($section['heading_level'] ?? 0),
                'child_headings' => is_array($section['child_headings'] ?? null) ? array_values($section['child_headings']) : [],
            ];

            Log::info('[PROCYNIA][DOC_SPLIT] Heading section emitted from heading boundary.', [
                'run_id' => $runId,
                'section_index' => $sectionIndex,
                'section_title' => $title,
                'heading_level' => (int) ($section['heading_level'] ?? 0),
                'group_type' => $sectionType,
                'child_heading_count' => count($filteredSections[count($filteredSections) - 1]['child_headings']),
                'section_start_position' => $sectionStart,
                'section_end_position' => $sectionEnd,
                'reason' => (string) ($analysis['reason'] ?? 'Requirement body retained for chunking.'),
            ]);
        }

        Log::info('[PROCYNIA][DOC_SPLIT] Section classification summary.', [
            'run_id' => $runId,
            'total_section_count' => count($sections),
            'emitted_requirement_body_count' => $summaryCounts['requirement_body'],
            'skipped_toc_only_count' => $summaryCounts['toc_only'],
            'skipped_front_matter_count' => $summaryCounts['front_matter'],
            'skipped_guidance_meta_count' => $summaryCounts['guidance_meta'],
            'skipped_numbering_legend_count' => $summaryCounts['numbering_legend'],
            'skipped_attachment_index_only_count' => $summaryCounts['attachment_index_only'],
        ]);

        return $filteredSections;
    }

    /**
     * Purpose: Keep heading-based chunks intact while emitting useful diagnostics.
     * Inputs: The raw section chunks and the full document text.
     * Returns: The input chunks without additional filtering.
     * Side effects: Writes diagnostic split logs.
     */
    private function filterRequirementBearingChunks(array $chunks, string $documentText, ?string $runId = null, string $sectionTitle = ''): array
    {
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkText = trim((string) mb_substr(
                $documentText,
                (int) $chunk['start_position'],
                (int) $chunk['end_position'] - (int) $chunk['start_position'],
                'UTF-8'
            ));

            Log::info('[PROCYNIA][DOC_SPLIT] Chunk emitted from heading-based section.', [
                'run_id' => $runId,
                'section_title' => $sectionTitle,
                'chunk_index' => $chunkIndex,
                'group_type' => (string) ($chunk['group_type'] ?? 'unknown'),
                'chunk_preview' => $this->previewText($chunkText, 160),
            ]);
        }

        return array_values($chunks);
    }

    /**
     * Purpose: Extract top-level document sections using deterministic heading boundaries.
     * Inputs: The full extracted document text.
     * Returns: Ordered section metadata with start and end positions.
     * Side effects: None.
     */
    private function extractMainSections(string $documentText, ?string $runId = null): array
    {
        $documentLength = mb_strlen($documentText, 'UTF-8');
        $lines = $this->splitTextIntoLinesWithOffsets($documentText);
        $tocRange = $this->detectTableOfContentsRange($lines);
        $headings = [];
        $headingCandidateCount = 0;
        $tocOnlyHeadingCount = 0;
        $bodyHeadingCount = 0;

        if ($tocRange !== null) {
            Log::info('[PROCYNIA][DOC_SPLIT] TOC detected and removed before heading scan.', [
                'run_id' => $runId,
                'toc_start_position' => (int) ($tocRange['start_position'] ?? 0),
                'toc_end_position' => (int) ($tocRange['end_position'] ?? 0),
                'toc_line_count' => (int) ($tocRange['line_count'] ?? 0),
            ]);
        }

        foreach ($lines as $lineIndex => $line) {
            $lineText = trim((string) ($line['text'] ?? ''));

            if ($lineText === '') {
                continue;
            }

            $lineStart = (int) ($line['start_position'] ?? 0);

            if ($tocRange !== null
                && $lineStart >= (int) $tocRange['start_position']
                && $lineStart < (int) $tocRange['end_position']
            ) {
                continue;
            }

            $headingCandidate = $this->classifyHeadingCandidate($lines, $lineIndex, $lineText);

            if ($headingCandidate === null) {
                continue;
            }

            $headingCandidateCount++;

            if (($headingCandidate['classification'] ?? '') === 'toc_only') {
                $tocOnlyHeadingCount++;

                Log::info('[PROCYNIA][DOC_SPLIT] Heading candidate ignored because it was outline-only.', [
                    'run_id' => $runId,
                    'line_index' => $lineIndex,
                    'heading_title' => $lineText,
                    'reason' => (string) ($headingCandidate['reason'] ?? 'Outline-only heading detected.'),
                ]);

                continue;
            }

            if ((int) ($headingCandidate['level'] ?? 0) !== 1) {
                continue;
            }

            $headings[] = [
                'title' => $lineText,
                'start_position' => $lineStart,
                'end_position' => (int) ($line['end_position'] ?? $lineStart),
                'level' => 1,
                'line_index' => $lineIndex,
                'classification' => (string) ($headingCandidate['classification'] ?? 'body_candidate'),
            ];
            $bodyHeadingCount++;
        }

        $firstBodyHeading = $headings[0] ?? null;

        Log::info('[PROCYNIA][DOC_SPLIT] Heading scan completed.', [
            'run_id' => $runId,
            'document_length' => $documentLength,
            'toc_removed' => $tocRange !== null,
            'toc_start_position' => $tocRange['start_position'] ?? null,
            'toc_end_position' => $tocRange['end_position'] ?? null,
            'heading_candidate_count' => $headingCandidateCount,
            'toc_only_heading_count' => $tocOnlyHeadingCount,
            'body_h1_heading_count' => $bodyHeadingCount,
            'heading_count' => count($headings),
            'first_body_heading_title' => (string) ($firstBodyHeading['title'] ?? ''),
            'first_body_heading_start_position' => $firstBodyHeading['start_position'] ?? null,
        ]);

        if ($headings === []) {
            $bodyStartPosition = $tocRange['end_position'] ?? 0;
            $fallbackTitle = 'Document text';

            foreach ($lines as $line) {
                $lineText = trim((string) ($line['text'] ?? ''));

                if ($lineText === '') {
                    continue;
                }

                if ((int) ($line['start_position'] ?? 0) < (int) $bodyStartPosition) {
                    continue;
                }

                $fallbackTitle = $lineText;
                break;
            }

            return [[
                'title' => $fallbackTitle,
                'start_position' => (int) $bodyStartPosition,
                'end_position' => $documentLength,
                'group_type' => 'requirements_section',
                'heading_level' => 1,
                'child_headings' => [],
            ]];
        }

        $sections = [];
        $primaryCount = count($headings);

        for ($i = 0; $i < $primaryCount; $i++) {
            $heading = $headings[$i];
            $start = (int) $heading['start_position'];
            $end = $i + 1 < $primaryCount ? (int) $headings[$i + 1]['start_position'] : $documentLength;
            $sectionText = trim((string) mb_substr($documentText, $start, $end - $start, 'UTF-8'));

            if ($sectionText === '') {
                continue;
            }

            $sections[] = [
                'title' => (string) ($heading['title'] ?? ''),
                'start_position' => $start,
                'end_position' => $end,
                'group_type' => 'requirements_section',
                'heading_level' => 1,
                'child_headings' => [],
            ];
        }

        return $sections;
    }

    /**
     * Purpose: Split one top-level section into chunks while staying inside heading boundaries.
     * Inputs: The full document text and a bounded section range.
     * Returns: One or more chunk definitions constrained to the section.
     * Side effects: None.
     */
    private function splitSectionIntoChunks(
        string $documentText,
        int $start,
        int $end,
        string $title,
        string $groupType,
    ): array {
        $sectionText = trim((string) mb_substr($documentText, $start, $end - $start, 'UTF-8'));

        if ($sectionText === '') {
            return [];
        }

        $sectionLength = mb_strlen($sectionText, 'UTF-8');

        if ($sectionLength <= self::IDEAL_CHUNK_SIZE) {
            return [[
                'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => sprintf(
                    'Heading-based section kept intact because its length (%d chars) is within the ideal chunk size.',
                    $sectionLength
                ),
            ]];
        }

        $h2Headings = $this->findConservativeBodyH2Headings($documentText, $start, $end);

        if ($h2Headings === []) {
            return [[
                'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => 'Oversized H1 section kept intact because no valid H2 boundaries were found.',
            ]];
        }

        $boundaryStarts = [$start];

        foreach ($h2Headings as $heading) {
            $headingStart = (int) ($heading['start_position'] ?? 0);

            if ($headingStart <= $start || $headingStart >= $end) {
                continue;
            }

            $boundaryStarts[] = $headingStart;
        }

        $boundaryStarts[] = $end;
        $boundaryStarts = array_values(array_unique($boundaryStarts));
        sort($boundaryStarts);

        $chunks = [];
        $chunkStart = $start;

        while ($chunkStart < $end) {
            $remainingLength = $end - $chunkStart;

            if ($remainingLength <= self::IDEAL_CHUNK_SIZE) {
                $chunks[] = [
                    'title' => $this->resolveChunkTitle($title, $h2Headings, $chunkStart),
                    'group_type' => $groupType,
                    'start_position' => $chunkStart,
                    'end_position' => $end,
                    'reason' => 'Final heading-based chunk kept intact because the remaining section is within the ideal chunk size.',
                ];

                break;
            }

            $splitPoint = $this->findBestH2SplitPoint($boundaryStarts, $chunkStart, $end);

            if ($splitPoint === null || $splitPoint <= $chunkStart) {
                $chunks[] = [
                    'title' => $this->resolveChunkTitle($title, $h2Headings, $chunkStart),
                    'group_type' => $groupType,
                    'start_position' => $chunkStart,
                    'end_position' => $end,
                    'reason' => 'Oversized H1 section kept intact because no safe H2 split point could produce a stable chunk near the ideal size.',
                ];

                break;
            }

            $chunks[] = [
                'title' => $this->resolveChunkTitle($title, $h2Headings, $chunkStart),
                'group_type' => $groupType,
                'start_position' => $chunkStart,
                'end_position' => $splitPoint,
                'reason' => 'Heading-based section split on a valid H2 boundary chosen to keep the chunk close to the ideal size.',
            ];

            $chunkStart = $splitPoint;
        }

        Log::info('[PROCYNIA][DOC_SPLIT] H1 section evaluated for H2 subdivision near ideal chunk size.', [
            'section_title' => $title,
            'section_start_position' => $start,
            'section_end_position' => $end,
            'section_length' => $sectionLength,
            'h2_heading_count' => count($h2Headings),
            'chunk_count' => count($chunks),
        ]);

        return $chunks !== [] ? $chunks : [[
            'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
            'group_type' => $groupType,
            'start_position' => $start,
            'end_position' => $end,
            'reason' => 'Oversized H1 section kept intact because no usable chunk plan could be built from valid H2 boundaries.',
        ]];
    }

    /**
     * Purpose: Split a heading section only on deeper heading boundaries.
     * Inputs: The full document text, a bounded section range, and the current heading level.
     * Returns: Chunk definitions constrained to heading boundaries.
     * Side effects: None.
     */
    private function splitSectionByHeadingLevel(
        string $documentText,
        int $start,
        int $end,
        string $title,
        string $groupType,
        int $headingLevel,
    ): array {
        $sectionText = trim((string) mb_substr($documentText, $start, $end - $start, 'UTF-8'));

        if ($sectionText === '') {
            return [];
        }

        if ($headingLevel >= 3) {
            return [[
                'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => 'Heading-based section kept intact at the deepest supported heading level.',
            ]];
        }

        $nextHeadingLevel = $headingLevel + 1;
        $childHeadings = $this->findHeadingsWithinRange($documentText, $start, $end, $nextHeadingLevel);

        if ($childHeadings === []) {
            return [[
                'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => sprintf(
                    'Heading-based section kept intact because no level %d headings were found inside it.',
                    $nextHeadingLevel
                ),
            ]];
        }

        Log::info('[PROCYNIA][DOC_SPLIT] Heading section subdivided by deeper headings.', [
            'section_title' => $title,
            'current_heading_level' => $headingLevel,
            'child_heading_level' => $nextHeadingLevel,
            'child_heading_count' => count($childHeadings),
            'section_start_position' => $start,
            'section_end_position' => $end,
        ]);

        $chunks = [];
        $childCount = count($childHeadings);
        $firstChildStart = (int) ($childHeadings[0]['start_position'] ?? $start);

        if ($firstChildStart > $start) {
            $chunks[] = [
                'title' => $this->previewText($title !== '' ? $title : $sectionText, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $firstChildStart,
                'reason' => sprintf(
                    'Leading content before level %d heading.',
                    $nextHeadingLevel
                ),
            ];
        }

        foreach ($childHeadings as $index => $childHeading) {
            $childStart = (int) ($childHeading['start_position'] ?? $start);
            $childEnd = $index + 1 < $childCount
                ? (int) ($childHeadings[$index + 1]['start_position'] ?? $end)
                : $end;

            $chunks = array_merge(
                $chunks,
                $this->splitSectionByHeadingLevel(
                    documentText: $documentText,
                    start: $childStart,
                    end: $childEnd,
                    title: (string) ($childHeading['title'] ?? $title),
                    groupType: $groupType,
                    headingLevel: $nextHeadingLevel,
                )
            );
        }

        return $chunks;
    }

    /**
     * Purpose: Split a bounded range by length using only blank lines as natural breakpoints.
     * Inputs: The full document text and a bounded range.
     * Returns: One or more chunk definitions constrained to the range.
     * Side effects: None.
     */
    private function splitSectionByLength(
        string $documentText,
        int $start,
        int $end,
        string $title,
        string $groupType,
        string $reasonPrefix = 'Oversized heading-based section.',
    ): array {
        $sectionText = mb_substr($documentText, $start, $end - $start, 'UTF-8');
        $sectionLength = mb_strlen($sectionText, 'UTF-8');
        $chunkCount = $this->determineSectionChunkCount($sectionLength);

        if ($chunkCount === 1) {
            return [[
                'title' => $this->previewText($title, 80),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => sprintf('%s Section kept intact because its length (%d chars) is within the stable section threshold.', $reasonPrefix, $sectionLength),
            ]];
        }

        Log::info('[PROCYNIA][DOC_SPLIT] Oversized heading section chunked internally without anchor shifting.', [
            'section_title' => $title,
            'section_start_position' => $start,
            'section_end_position' => $end,
            'section_length' => $sectionLength,
            'chunk_count' => $chunkCount,
        ]);

        $boundaries = [0];

        for ($chunkIndex = 1; $chunkIndex < $chunkCount; $chunkIndex++) {
            $target = (int) floor(($sectionLength * $chunkIndex) / $chunkCount);
            $min = max($boundaries[count($boundaries) - 1] + 1, $target - 2500);
            $max = min($sectionLength, $target + 2500);
            $boundary = $this->findBestBoundary($sectionText, $target, $min, $max);

            if ($boundary <= $boundaries[count($boundaries) - 1]) {
                $boundary = $target;
            }

            if ($boundary <= $boundaries[count($boundaries) - 1]) {
                $boundary = min($sectionLength, $boundaries[count($boundaries) - 1] + max(1, (int) floor($sectionLength / $chunkCount)));
            }

            $boundaries[] = $boundary;
        }

        $boundaries[] = $sectionLength;
        $boundaries = $this->normalizeBoundaries($boundaries, $sectionLength);
        $chunks = [];
        $finalChunkCount = count($boundaries) - 1;

        for ($i = 0; $i < $finalChunkCount; $i++) {
            $relativeStart = $boundaries[$i];
            $relativeEnd = $boundaries[$i + 1];
            $content = trim(mb_substr($sectionText, $relativeStart, $relativeEnd - $relativeStart, 'UTF-8'));

            if ($content === '' && $chunks !== []) {
                $chunks[count($chunks) - 1]['end_position'] = $start + $relativeEnd;
                continue;
            }

            $chunks[] = [
                'title' => sprintf('%s (part %d/%d)', $this->previewText($title, 80), $i + 1, $finalChunkCount),
                'group_type' => $groupType,
                'start_position' => $start + $relativeStart,
                'end_position' => $start + $relativeEnd,
                'reason' => sprintf(
                    '%s This part was placed near character %d inside the section using blank-line breakpoints only.',
                    $reasonPrefix,
                    $relativeEnd
                ),
            ];
        }

        return $chunks;
    }

    /**
     * Purpose: Find heading lines of a specific level inside a bounded range.
     * Inputs: The full document text, section boundaries, and a heading level.
     * Returns: Ordered heading metadata within the range.
     * Side effects: None.
     */
    private function findHeadingsWithinRange(string $documentText, int $start, int $end, int $level): array
    {
        if ($end <= $start || $level < 1 || $level > 3) {
            return [];
        }

        $sectionText = mb_substr($documentText, $start, $end - $start, 'UTF-8');
        $lines = $this->splitTextIntoLinesWithOffsets($sectionText);
        $headings = [];

        foreach ($lines as $line) {
            $lineText = trim((string) ($line['text'] ?? ''));

            if ($lineText === '' || $this->isOutlineOnlyHeadingLine($lineText)) {
                continue;
            }

            if ($this->detectHeadingLevel($lineText) !== $level) {
                continue;
            }

            $headings[] = [
                'title' => $lineText,
                'start_position' => $start + (int) ($line['start_position'] ?? 0),
                'end_position' => $start + (int) ($line['end_position'] ?? 0),
                'level' => $level,
            ];
        }

        usort($headings, static fn (array $left, array $right): int => (int) $left['start_position'] <=> (int) $right['start_position']);

        return $headings;
    }


    /**
     * Purpose: Find conservative body H2 headings inside one H1-bounded section.
     * Inputs: The full document text and a bounded H1 section range.
     * Returns: Ordered H2 heading metadata that are safe to use as chunk boundaries.
     * Side effects: None.
     */

    /**
     * Purpose: Choose the safest H2 split point that keeps one chunk as close as possible to the ideal size.
     * Inputs: Ordered boundary start positions, the current chunk start, and the enclosing section end.
     * Returns: One H2 boundary or null when no safe split point exists.
     * Side effects: None.
     */
    private function findBestH2SplitPoint(array $boundaryStarts, int $chunkStart, int $sectionEnd): ?int
    {
        $candidates = [];

        foreach ($boundaryStarts as $boundaryStart) {
            $boundaryStart = (int) $boundaryStart;

            if ($boundaryStart <= $chunkStart || $boundaryStart >= $sectionEnd) {
                continue;
            }

            $chunkLength = $boundaryStart - $chunkStart;

            if ($chunkLength <= 0) {
                continue;
            }

            if ($chunkLength > self::MAX_CHUNK_SIZE) {
                continue;
            }

            $remainingLength = $sectionEnd - $boundaryStart;

            if ($remainingLength <= 0) {
                continue;
            }

            $candidates[] = [
                'boundary' => $boundaryStart,
                'distance_to_ideal' => abs(self::IDEAL_CHUNK_SIZE - $chunkLength),
                'chunk_length' => $chunkLength,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['distance_to_ideal'] !== $right['distance_to_ideal']) {
                return $left['distance_to_ideal'] <=> $right['distance_to_ideal'];
            }

            return $right['chunk_length'] <=> $left['chunk_length'];
        });

        return (int) $candidates[0]['boundary'];
    }

    /**
     * Purpose: Resolve the visible chunk title from the nearest valid H2 or the parent H1 title.
     * Inputs: The parent H1 title, detected H2 headings, and the chunk start position.
     * Returns: A stable chunk title.
     * Side effects: None.
     */
    private function resolveChunkTitle(string $fallbackTitle, array $h2Headings, int $chunkStart): string
    {
        foreach ($h2Headings as $heading) {
            if ((int) ($heading['start_position'] ?? -1) === $chunkStart) {
                return (string) ($heading['title'] ?? $fallbackTitle);
            }
        }

        return $this->previewText($fallbackTitle, 80);
    }

    private function findConservativeBodyH2Headings(string $documentText, int $start, int $end): array
    {
        if ($end <= $start) {
            return [];
        }

        $sectionText = mb_substr($documentText, $start, $end - $start, 'UTF-8');
        $lines = $this->splitTextIntoLinesWithOffsets($sectionText);

        if ($lines === []) {
            return [];
        }

        $headings = [];
        $lastAcceptedStart = null;
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $lineText = trim((string) ($lines[$index]['text'] ?? ''));

            if ($lineText === '') {
                continue;
            }

            if (! $this->isConservativeBodyH2Candidate($lines, $index, $lineText)) {
                continue;
            }

            $absoluteStart = $start + (int) ($lines[$index]['start_position'] ?? 0);

            if ($lastAcceptedStart !== null && ($absoluteStart - $lastAcceptedStart) < 1200) {
                continue;
            }

            if (($end - $absoluteStart) < 600) {
                continue;
            }

            $headings[] = [
                'title' => $lineText,
                'start_position' => $absoluteStart,
                'end_position' => $start + (int) ($lines[$index]['end_position'] ?? 0),
                'level' => 2,
            ];

            $lastAcceptedStart = $absoluteStart;
        }

        return $headings;
    }

    /**
     * Purpose: Determine whether one line is a conservative body H2 heading candidate.
     * Inputs: The section line list, the candidate index, and one trimmed line.
     * Returns: True when the line is safe to use as an H2 chunk boundary.
     * Side effects: None.
     */
    private function isConservativeBodyH2Candidate(array $lines, int $index, string $line): bool
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            return false;
        }

        if ($this->isOutlineOnlyHeadingLine($trimmedLine)) {
            return false;
        }

        $headingLevel = $this->detectHeadingLevel($trimmedLine);

        if ($headingLevel === 1 || $headingLevel === 3) {
            return false;
        }

        if ($this->looksLikeRequirementTableMarker($trimmedLine)) {
            return false;
        }

        if ($this->looksLikeRequirementIdentifierLine($trimmedLine)) {
            return false;
        }

        if (preg_match('/[:;]$/u', $trimmedLine) === 1) {
            return false;
        }

        if (preg_match('/[.!?]$/u', $trimmedLine) === 1) {
            return false;
        }

        if (preg_match('/^\s*Punkt\s+\d+(?:\s*[\.-]\s*\d+)*\b/ui', $trimmedLine) === 1) {
            return false;
        }

        if (preg_match('/(?:\b\p{L}\b\s+){2,}\b\p{L}\b/u', $trimmedLine) === 1) {
            return false;
        }

        if (preg_match('/^\s*[a-zæøå]/u', $trimmedLine) === 1) {
            return false;
        }

        $length = mb_strlen($trimmedLine, 'UTF-8');

        if ($length < 8 || $length > 90) {
            return false;
        }

        $wordCount = count(preg_split('/\s+/u', $trimmedLine, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($wordCount < 1 || $wordCount > 8) {
            return false;
        }

        $normalized = $this->normalizeHeadingTitle($trimmedLine);
        $genericTitles = [
            'innledning',
            'bakgrunn',
            'kravtekst',
            'leverandørens besvarelse',
            'skal-krav',
            'bør-krav',
            'id',
            'prioritet',
            'begrunnelse',
            'strategiske mål',
        ];

        if (in_array($normalized, $genericTitles, true)) {
            return false;
        }

        if ($this->containsProseLikeHeadingLanguage($normalized)) {
            return false;
        }

        $previousLine = $this->previousNonEmptyLineText($lines, $index);
        $nextLine = $this->nextNonEmptyLineText($lines, $index);

        if ($nextLine === null) {
            return false;
        }

        if ($this->isHeadingLikeText($nextLine)) {
            return false;
        }

        if ($this->looksLikeRequirementTableMarker($nextLine) || $this->looksLikeRequirementIdentifierLine($nextLine)) {
            return false;
        }

        if ($previousLine !== null && $this->looksLikeRequirementIdentifierLine($previousLine)) {
            return false;
        }

        if ($previousLine !== null && $this->looksLikeRequirementTableMarker($previousLine)) {
            return false;
        }

        return preg_match('/^(?:\d+(?:\.\d+)*\s+)?[\p{Lu}\p{N}ÆØÅ][\p{L}\p{N}\s()\/&,-]*$/u', $trimmedLine) === 1;
    }

    /**
     * Purpose: Find the previous non-empty line before a given index.
     * Inputs: The full line list and the current line index.
     * Returns: The previous trimmed non-empty line or null when none exists.
     * Side effects: None.
     */
    private function previousNonEmptyLineText(array $lines, int $lineIndex): ?string
    {
        for ($candidateIndex = $lineIndex - 1; $candidateIndex >= 0; $candidateIndex--) {
            $candidateText = trim((string) ($lines[$candidateIndex]['text'] ?? ''));

            if ($candidateText === '') {
                continue;
            }

            return $candidateText;
        }

        return null;
    }

    /**
     * Purpose: Determine whether a heading-like line is really prose, evaluation language, or an instruction line.
     * Inputs: One normalized heading candidate.
     * Returns: True when the candidate should be rejected as a heading.
     * Side effects: None.
     */
    private function containsProseLikeHeadingLanguage(string $normalizedLine): bool
    {
        $phrases = [
            'på hvilken måte',
            'i hvilken grad',
            'hvorvidt',
            'hvordan leverandøren',
            'bidra aktivt',
            'bidra til',
            'legges det',
            'legges særlig vekt',
            'vil benyttes',
            'vil bli benyttet',
            'skal kunne',
            'skal levere',
            'skal beskrive',
            'skal sørge for',
            'skal være',
            'skal dokumentere',
            'skal benyttes',
            'skal bidra',
            'må være',
            'bør være',
            'kan være',
            'oppfordres',
            'evaluering',
            'evaluerings',
            'besvare',
            'beskrivelse',
            'dokumentere',
            'redegjøre',
            'vurdere',
            'vurderes',
        ];

        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($normalizedLine, $phrase)) {
                return true;
            }
        }

        return preg_match('/\b(?:skal|bør|må|kan|vil|bidra|sikre|legges|benyttes|benytte|beskrive|dokumentere|redegjøre|vurdere|vurderes)\b/ui', $normalizedLine) === 1;
    }

    /**
     * Purpose: Determine whether a line is a requirement table marker rather than a real heading.
     * Inputs: One trimmed line of text.
     * Returns: True when the line is a table label or answer placeholder.
     * Side effects: None.
     */
    private function looksLikeRequirementTableMarker(string $line): bool
    {
        $normalized = $this->normalizeHeadingTitle($line);

        return in_array($normalized, [
            'skal-krav',
            'bør-krav',
            'id',
            'kravtekst',
            'leverandørens besvarelse',
            'leverandorens besvarelse',
        ], true);
    }

    /**
     * Purpose: Determine whether a line is a requirement identifier rather than a structural heading.
     * Inputs: One trimmed line of text.
     * Returns: True when the line looks like a requirement ID.
     * Side effects: None.
     */
    private function looksLikeRequirementIdentifierLine(string $line): bool
    {
        $normalized = preg_replace('/\s+/u', '', trim($line)) ?? trim($line);

        return preg_match('/^\d+(?:[-.]\d+)*(?:[. -]?[A-ZÆØÅ]{1,3})\d+$/u', $normalized) === 1
            || preg_match('/^\d+(?:[-.]\d+)+(?:[A-ZÆØÅ]\d+)?$/u', $normalized) === 1;
    }

    /**
     * Purpose: Keep heading sections separate and avoid cross-section merging.
     * Inputs: Extracted top-level sections and the full document text.
     * Returns: The input sections unchanged.
     * Side effects: None.
     */
    private function mergeSectionsIntoPlanningUnits(array $sections, string $documentText): array
    {
        return array_values($sections);
    }

    /**
     * Purpose: Determine the desired global chunk count for one document.
     * Inputs: Full document length in characters.
     * Returns: A target count between 5 and 7 for larger documents.
     * Side effects: None.
     */
    private function determineTargetDocumentChunkCount(int $documentLength): int
    {
        return match (true) {
            $documentLength <= 12000 => 3,
            $documentLength <= 22000 => 4,
            $documentLength <= 32000 => 5,
            $documentLength <= 45000 => 6,
            default => 7,
        };
    }

    /**
     * Purpose: Merge section group types without downgrading requirement-bearing sections.
     * Inputs: Two stable group type values.
     * Returns: One stable merged group type value.
     * Side effects: None.
     */
    private function mergeGroupTypes(string $left, string $right): string
    {
        if ($left === 'mixed_section' || $right === 'mixed_section') {
            return 'mixed_section';
        }

        if ($left === 'requirements_section' || $right === 'requirements_section') {
            return 'requirements_section';
        }

        return 'context_only';
    }

    /**
     * Purpose: Determine whether a top-level section is context or likely contains requirements.
     * Inputs: The section title and the full section content.
     * Returns: A stable group type value.
     * Side effects: None.
     */
    private function inferSectionGroupType(string $title, string $content): string
    {
        $signals = $this->classifyTextSignals($title . "\n" . $content);

        if ($signals['contains_requirement_signals'] ?? false) {
            return ($signals['contains_front_matter_signals'] ?? false) ? 'mixed_section' : 'requirements_section';
        }

        if ($signals['contains_front_matter_signals'] ?? false) {
            return 'context_only';
        }

        if ($signals['contains_appendix_signals'] ?? false) {
            return 'context_only';
        }

        return 'requirements_section';
    }

    /**
     * Purpose: Determine a reasonable number of chunks for a single top-level section.
     * Inputs: Section length in characters.
     * Returns: A chunk count between 1 and 4.
     * Side effects: None.
     */
    private function determineSectionChunkCount(int $sectionLength): int
    {
        return match (true) {
            $sectionLength <= 12000 => 1,
            $sectionLength <= 22000 => 2,
            $sectionLength <= 34000 => 3,
            default => 4,
        };
    }

    /**
     * Purpose: Find the best nearby break position for a boundary within one section.
     * Inputs: The section text, a target offset, and a search window.
     * Returns: A selected boundary offset.
     * Side effects: None.
     */
    private function findBestBoundary(string $documentText, int $target, int $min, int $max): int
    {
        $windowStart = max(0, $min);
        $windowLength = max(0, $max - $windowStart);
        $windowText = mb_substr($documentText, $windowStart, $windowLength, 'UTF-8');

        if ($windowText === '') {
            return $target;
        }

        $bestBoundary = null;
        $bestDistance = null;

        preg_match_all('/(?:\r\n|\r|\n){2,}/u', $windowText, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $matchText = (string) ($match[0] ?? '');
            $byteOffset = (int) ($match[1] ?? 0);
            $charOffset = mb_strlen(substr($windowText, 0, $byteOffset), 'UTF-8');
            $boundary = $windowStart + $charOffset + mb_strlen($matchText, 'UTF-8');
            $distance = abs($boundary - $target);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestBoundary = $boundary;
            }
        }

        return $bestBoundary ?? $target;
    }

    /**
     * Purpose: Normalize boundary positions into a strictly increasing list within the section length.
     * Inputs: Raw boundary positions and the full section length.
     * Returns: A strict ordered boundary list.
     * Side effects: None.
     */
    private function normalizeBoundaries(array $boundaries, int $documentLength): array
    {
        $normalized = [0];

        foreach ($boundaries as $boundary) {
            $position = max(0, min($documentLength, (int) $boundary));

            if ($position <= $normalized[count($normalized) - 1]) {
                continue;
            }

            $normalized[] = $position;
        }

        if ($normalized[count($normalized) - 1] !== $documentLength) {
            $normalized[] = $documentLength;
        }

        return $normalized;
    }

    /**
     * Purpose: Convert a byte offset from a regex match into a UTF-8 character offset.
     * Inputs: The full text and a byte offset.
     * Returns: A character offset.
     * Side effects: None.
     */
    private function byteOffsetToCharOffset(string $text, int $byteOffset): int
    {
        return mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
    }

    /**
     * Purpose: Build a short anchor preview from a character offset in the original text.
     * Inputs: The full document text and the source offset.
     * Returns: A bounded preview string.
     * Side effects: None.
     */
    private function anchorPreviewFromOffset(string $documentText, int $offset, int $length = 180): string
    {
        $preview = mb_substr($documentText, max(0, $offset), $length, 'UTF-8');

        return trim($preview);
    }

    /**
     * Purpose: Build a bounded preview string for titles and logs.
     * Inputs: Raw text and an optional length cap.
     * Returns: A compact preview string.
     * Side effects: None.
     */
    private function previewText(string $text, int $limit = 100): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return '';
        }

        return Str::limit($normalized, $limit, '...');
    }

    /**
     * Purpose: Convert a microtime start value into a rounded millisecond duration.
     * Inputs: The float timestamp captured before the work started.
     * Returns: The elapsed duration in milliseconds.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
