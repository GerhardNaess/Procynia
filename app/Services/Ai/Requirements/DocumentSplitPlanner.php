<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiDocument;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentSplitPlanner
{
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
     * Purpose: Build a deterministic split plan that keeps only requirement-bearing sections and blocks.
     * Inputs: The extracted document text.
     * Returns: A summary and a split plan with start/end positions and anchor previews.
     * Side effects: None.
     */
    private function buildDeterministicPlan(string $documentText, ?string $runId = null): array
    {
        $rawSections = $this->extractMainSections($documentText);
        $filteredSections = $this->filterRequirementBearingSections(
            $rawSections,
            $documentText,
            $runId,
        );
        $sections = $this->mergeSectionsIntoPlanningUnits($filteredSections, $documentText);
        $splitPlan = [];
        $chunkIndex = 1;
        $skippedSectionCount = max(0, count($rawSections) - count($filteredSections));

        foreach ($sections as $section) {
            $chunks = $this->splitSectionIntoChunks(
                documentText: $documentText,
                start: $section['start_position'],
                end: $section['end_position'],
                title: $section['title'],
                groupType: $section['group_type'],
            );
            $chunks = $this->filterRequirementBearingChunks($chunks, $documentText, $runId, (string) $section['title']);

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
                'document_type' => 'Requirement-bearing document with front matter filtered locally',
                'overall_assessment' => sprintf(
                    'The document was split into %d requirement-bearing chunk(s) after filtering non-requirement front matter and instruction text. %d section(s) were skipped before chunking.',
                    count($splitPlan),
                    $skippedSectionCount
                ),
            ],
            'split_plan' => $splitPlan,
        ];
    }

    /**
     * Purpose: Analyse a section into logical blocks and resolve its first real requirement anchor.
     * Inputs: The full document text, the absolute section boundaries, and the section title.
     * Returns: Section-level block analysis, classification, and requirement anchor metadata.
     * Side effects: None.
     */
    private function analyseSectionBlocks(string $documentText, int $sectionStart, int $sectionEnd, string $sectionTitle = ''): array
    {
        $sectionLength = max(0, $sectionEnd - $sectionStart);
        $sectionText = mb_substr($documentText, $sectionStart, $sectionLength, 'UTF-8');
        $blocks = $this->splitIntoLogicalBlocks($sectionText, $sectionStart);
        $firstRequirementBlockIndex = null;
        $containsFrontMatter = false;
        $containsRequirement = false;
        $containsAppendix = false;
        $containsContext = false;
        $blockAnalyses = [];

        foreach ($blocks as $blockIndex => $block) {
            $signals = $this->classifyTextSignals((string) $block['text']);

            if ($signals['contains_requirement_signals'] ?? false) {
                $containsRequirement = true;

                if ($firstRequirementBlockIndex === null) {
                    $firstRequirementBlockIndex = $blockIndex;
                }
            }

            if ($signals['contains_front_matter_signals'] ?? false) {
                $containsFrontMatter = true;
            }

            if ($signals['contains_appendix_signals'] ?? false) {
                $containsAppendix = true;
            }

            $blockAnalyses[] = array_merge($block, $signals, [
                'block_index' => $blockIndex,
                'classification' => 'unknown',
            ]);
        }

        foreach ($blockAnalyses as $blockIndex => &$block) {
            $signals = $block;

            if ($firstRequirementBlockIndex === null) {
                if (($signals['contains_front_matter_signals'] ?? false) === true) {
                    $block['classification'] = 'non_requirement_front_matter';
                    continue;
                }

                if (($signals['contains_appendix_signals'] ?? false) === true) {
                    $block['classification'] = 'appendix_non_requirements';
                    continue;
                }

                if (($signals['contains_requirement_signals'] ?? false) === true) {
                    $block['classification'] = ($signals['contains_table_signals'] ?? false) ? 'requirement_section' : 'requirement_block';
                    continue;
                }

                $block['classification'] = 'unknown';
                continue;
            }

            if ($blockIndex < $firstRequirementBlockIndex) {
                if (($signals['contains_front_matter_signals'] ?? false) === true) {
                    $block['classification'] = 'non_requirement_front_matter';
                } elseif (($signals['contains_appendix_signals'] ?? false) === true) {
                    $block['classification'] = 'appendix_non_requirements';
                } else {
                    $block['classification'] = 'context_for_requirements';
                    $containsContext = true;
                }

                continue;
            }

            if (($signals['contains_requirement_signals'] ?? false) === true) {
                $block['classification'] = ($signals['contains_table_signals'] ?? false) ? 'requirement_section' : 'requirement_block';
                continue;
            }

            if (($signals['contains_front_matter_signals'] ?? false) === true) {
                $block['classification'] = 'non_requirement_front_matter';
                continue;
            }

            if (($signals['contains_appendix_signals'] ?? false) === true) {
                $block['classification'] = 'appendix_non_requirements';
                continue;
            }

            $block['classification'] = 'unknown';
        }
        unset($block);

        foreach ($blockAnalyses as $block) {
            if (($block['classification'] ?? '') === 'context_for_requirements') {
                $containsContext = true;
            }
        }

        $firstRequirementAnchor = null;
        $firstRequirementPosition = null;

        if ($firstRequirementBlockIndex !== null && array_key_exists($firstRequirementBlockIndex, $blockAnalyses)) {
            $firstRequirementAnchor = $this->findFirstRequirementAnchorInText((string) $blockAnalyses[$firstRequirementBlockIndex]['text']);
            $firstRequirementPosition = $firstRequirementAnchor !== null
                ? (int) $blockAnalyses[$firstRequirementBlockIndex]['start_position'] + (int) $firstRequirementAnchor['offset']
                : (int) $blockAnalyses[$firstRequirementBlockIndex]['start_position'];
        }

        $groupType = 'context_only';
        if ($containsRequirement) {
            $groupType = ($firstRequirementPosition !== null && $firstRequirementPosition > $sectionStart)
                || ($firstRequirementBlockIndex !== null && $firstRequirementBlockIndex > 0)
                ? 'mixed_section'
                : 'requirements_section';
        } elseif ($containsAppendix) {
            $groupType = 'attachment_section';
        }

        $reasonParts = [];

        if ($containsRequirement) {
            $reasonParts[] = ($firstRequirementPosition !== null && $firstRequirementPosition > $sectionStart)
                || ($firstRequirementBlockIndex !== null && $firstRequirementBlockIndex > 0)
                ? 'The section contains leading context that was trimmed before the first requirement anchor.'
                : 'The section starts at the first requirement anchor.';
        }

        if ($containsFrontMatter) {
            $reasonParts[] = 'Front matter and instruction-like blocks were identified and excluded from emitted chunks.';
        }

        if ($containsAppendix) {
            $reasonParts[] = 'Appendix-like blocks were detected.';
        }

        if ($reasonParts === []) {
            $reasonParts[] = 'The section did not contain requirement-bearing blocks.';
        }

        return [
            'blocks' => $blockAnalyses,
            'block_count' => count($blockAnalyses),
            'requirement_block_count' => count(array_filter($blockAnalyses, static fn (array $block): bool => ($block['classification'] ?? '') === 'requirement_block')),
            'requirement_section_count' => count(array_filter($blockAnalyses, static fn (array $block): bool => ($block['classification'] ?? '') === 'requirement_section')),
            'context_block_count' => count(array_filter($blockAnalyses, static fn (array $block): bool => ($block['classification'] ?? '') === 'context_for_requirements')),
            'front_matter_block_count' => count(array_filter($blockAnalyses, static fn (array $block): bool => ($block['classification'] ?? '') === 'non_requirement_front_matter')),
            'appendix_block_count' => count(array_filter($blockAnalyses, static fn (array $block): bool => ($block['classification'] ?? '') === 'appendix_non_requirements')),
            'contains_requirement_signals' => $containsRequirement,
            'contains_front_matter_signals' => $containsFrontMatter,
            'contains_appendix_signals' => $containsAppendix,
            'contains_context_signals' => $containsContext,
            'first_requirement_block_index' => $firstRequirementBlockIndex,
            'first_requirement_anchor' => $firstRequirementAnchor !== null ? (string) $firstRequirementAnchor['anchor'] : null,
            'first_requirement_position' => $firstRequirementPosition,
            'group_type' => $groupType,
            'excluded_prefix_length' => $firstRequirementPosition !== null ? max(0, $firstRequirementPosition - $sectionStart) : 0,
            'reason' => implode(' ', $reasonParts),
            'section_title' => $sectionTitle,
        ];
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
        $hasRequirementId = preg_match('/\b\d+(?:[-.]\d+)+(?:[A-Za-z])?\b/u', $trimmedText) === 1;
        $hasNumberedRequirement = preg_match('/^\s*(?:[-*•]\s*)?\d+[.)]\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $trimmedText) === 1
            || preg_match('/^\s*(?:[-*•]\s*)?\d+(?:[-.]\d+)+\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $trimmedText) === 1;
        $hasTableSignals = str_contains($trimmedText, '|')
            || preg_match('/\t/u', $trimmedText) === 1
            || preg_match('/\bID\b.*\bKravtekst\b.*\bBesvarelse\b/ui', $trimmedText) === 1
            || preg_match('/\bKravtekst\b.*\bBesvarelse\b/ui', $trimmedText) === 1;
        $hasRequirementVerb = preg_match('/\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui', $normalizedText) === 1;
        $hasStrongRequirementSignals = $hasRequirementId || $hasNumberedRequirement || $hasTableSignals;
        $containsRequirementSignals = $hasStrongRequirementSignals
            || ($hasRequirementVerb && ! $hasFrontMatterSignals && ! $hasAppendixSignals);

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

        if (preg_match('/^\s*\d+(?:\.\d+)*\s+[^\n]+?\s+\d+\s*$/u', $trimmedLine) === 1) {
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
            '/\b\d+(?:[-.]\d+)+(?:[A-Za-z])?\b/u',
            '/^\s*(?:[-*•]\s*)?\d+[.)]\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui',
            '/^\s*(?:[-*•]\s*)?\d+(?:[-.]\d+)+\s+.*\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui',
            '/\b(?:skal kunne|skal levere|skal beskrive|skal sørge for|skal være|skal dokumentere|må|bør|skal)\b/ui',
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
     * Purpose: Remove sections that do not contain requirement-bearing blocks and trim sections to the first real requirement anchor.
     * Inputs: The raw top-level sections and the full document text.
     * Returns: Sections that should be considered for chunking.
     * Side effects: Writes diagnostic split logs.
     */
    private function filterRequirementBearingSections(array $sections, string $documentText, ?string $runId = null): array
    {
        $filteredSections = [];

        foreach ($sections as $sectionIndex => $section) {
            $analysis = $this->analyseSectionBlocks(
                documentText: $documentText,
                sectionStart: (int) $section['start_position'],
                sectionEnd: (int) $section['end_position'],
                sectionTitle: (string) ($section['title'] ?? ''),
            );

            Log::info('[PROCYNIA][DOC_SPLIT] Section analysed.', [
                'run_id' => $runId,
                'section_index' => $sectionIndex,
                'section_title' => (string) ($section['title'] ?? ''),
                'group_type' => (string) ($analysis['group_type'] ?? $section['group_type'] ?? 'unknown'),
                'block_count' => (int) ($analysis['block_count'] ?? 0),
                'requirement_block_count' => (int) ($analysis['requirement_block_count'] ?? 0),
                'context_block_count' => (int) ($analysis['context_block_count'] ?? 0),
                'front_matter_block_count' => (int) ($analysis['front_matter_block_count'] ?? 0),
                'appendix_block_count' => (int) ($analysis['appendix_block_count'] ?? 0),
                'contains_requirement_signals' => (bool) ($analysis['contains_requirement_signals'] ?? false),
                'first_requirement_position' => $analysis['first_requirement_position'] ?? null,
                'excluded_prefix_length' => (int) ($analysis['excluded_prefix_length'] ?? 0),
                'reason' => (string) ($analysis['reason'] ?? ''),
            ]);

            if (! ($analysis['contains_requirement_signals'] ?? false)) {
                Log::info('[PROCYNIA][DOC_SPLIT] Section skipped before chunking because it contained no requirement-bearing blocks.', [
                    'run_id' => $runId,
                    'section_index' => $sectionIndex,
                    'section_title' => (string) ($section['title'] ?? ''),
                    'group_type' => (string) ($analysis['group_type'] ?? $section['group_type'] ?? 'unknown'),
                    'block_count' => (int) ($analysis['block_count'] ?? 0),
                    'front_matter_block_count' => (int) ($analysis['front_matter_block_count'] ?? 0),
                    'appendix_block_count' => (int) ($analysis['appendix_block_count'] ?? 0),
                    'reason' => (string) ($analysis['reason'] ?? ''),
                ]);

                continue;
            }

            $startPosition = (int) ($analysis['first_requirement_position'] ?? $section['start_position']);
            $startPosition = max((int) $section['start_position'], $startPosition);
            $excludedPrefixLength = max(0, $startPosition - (int) $section['start_position']);

            if ($excludedPrefixLength > 0) {
                Log::info('[PROCYNIA][DOC_SPLIT] First real requirement anchor found; section start moved forward.', [
                    'run_id' => $runId,
                    'section_index' => $sectionIndex,
                    'section_title' => (string) ($section['title'] ?? ''),
                    'original_start_position' => (int) $section['start_position'],
                    'start_position' => $startPosition,
                    'excluded_prefix_length' => $excludedPrefixLength,
                    'first_requirement_anchor' => (string) ($analysis['first_requirement_anchor'] ?? ''),
                    'group_type' => (string) ($analysis['group_type'] ?? $section['group_type'] ?? 'unknown'),
                ]);
            }

            $filteredSections[] = [
                'title' => (string) ($section['title'] ?? ''),
                'start_position' => $startPosition,
                'end_position' => (int) $section['end_position'],
                'group_type' => (string) ($analysis['group_type'] ?? $section['group_type'] ?? 'requirements_section'),
                'reason' => (string) ($analysis['reason'] ?? $section['reason'] ?? ''),
                'excluded_prefix_length' => $excludedPrefixLength,
                'contains_requirement_signals' => true,
            ];
        }

        return $filteredSections;
    }

    /**
     * Purpose: Split one emitted section into smaller chunks and remove any chunk that is still pure non-requirement text.
     * Inputs: The full document text, a list of section chunks, and the section title for logging.
     * Returns: Chunks that contain requirement-bearing text.
     * Side effects: Writes diagnostic split logs.
     */
    private function filterRequirementBearingChunks(array $chunks, string $documentText, ?string $runId = null, string $sectionTitle = ''): array
    {
        $filteredChunks = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkText = trim((string) mb_substr($documentText, (int) $chunk['start_position'], (int) $chunk['end_position'] - (int) $chunk['start_position'], 'UTF-8'));
            $signals = $this->classifyTextSignals($chunkText);

            if (! ($signals['contains_requirement_signals'] ?? false)) {
                Log::info('[PROCYNIA][DOC_SPLIT] Chunk skipped because it did not contain requirement-bearing text.', [
                    'run_id' => $runId,
                    'section_title' => $sectionTitle,
                    'chunk_index' => $chunkIndex,
                    'group_type' => (string) ($chunk['group_type'] ?? 'unknown'),
                    'classification' => (string) ($signals['classification'] ?? 'unknown'),
                    'reason' => (string) ($signals['reason'] ?? ''),
                    'chunk_preview' => $this->previewText($chunkText, 160),
                ]);

                continue;
            }

            $filteredChunks[] = $chunk;
        }

        return $filteredChunks;
    }

    /**
     * Purpose: Extract top-level document sections using chapter headings before any size-based subdivision.
     * Inputs: The full extracted document text.
     * Returns: Ordered section metadata with start and end positions.
     * Side effects: None.
     */
    private function extractMainSections(string $documentText): array
    {
        $documentLength = mb_strlen($documentText, 'UTF-8');
        $pattern = '/^(Bilag\s+\d+[^\r\n]*|\d+\.\s+[^\r\n]+)$/mu';
        $sections = [];
        $matches = [];

        preg_match_all($pattern, $documentText, $matches, PREG_OFFSET_CAPTURE);

        $headings = [];

        foreach ($matches[0] as $match) {
            $headingText = trim((string) ($match[0] ?? ''));
            $byteOffset = (int) ($match[1] ?? 0);
            $charOffset = $this->byteOffsetToCharOffset($documentText, $byteOffset);

            if ($headingText === '') {
                continue;
            }

            $headings[] = [
                'title' => $headingText,
                'start_position' => $charOffset,
            ];
        }

        if ($headings === []) {
            return [[
                'title' => $this->previewText($documentText, 100),
                'start_position' => 0,
                'end_position' => $documentLength,
                'group_type' => 'requirements_section',
            ]];
        }

        if ($headings[0]['start_position'] > 0) {
            $sections[] = [
                'title' => 'Preamble and introductory context',
                'start_position' => 0,
                'end_position' => $headings[0]['start_position'],
                'group_type' => 'context_only',
            ];
        }

        $headingCount = count($headings);

        for ($i = 0; $i < $headingCount; $i++) {
            $start = $headings[$i]['start_position'];
            $end = $i + 1 < $headingCount ? $headings[$i + 1]['start_position'] : $documentLength;
            $title = $headings[$i]['title'];
            $content = trim(mb_substr($documentText, $start, $end - $start, 'UTF-8'));

            if ($content === '') {
                continue;
            }

            $sections[] = [
                'title' => $title,
                'start_position' => $start,
                'end_position' => $end,
                'group_type' => $this->inferSectionGroupType($title, $content),
            ];
        }

        return $sections;
    }

    /**
     * Purpose: Split one top-level section into a reasonable number of chunks only when the section is oversized.
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
        $sectionText = mb_substr($documentText, $start, $end - $start, 'UTF-8');
        $trimmedSectionText = trim($sectionText);
        $sectionLength = mb_strlen($sectionText, 'UTF-8');
        $chunkCount = $this->determineSectionChunkCount($sectionLength);

        if ($trimmedSectionText === '' || $chunkCount === 1) {
            return [[
                'title' => $this->previewText($title !== '' ? $title : $trimmedSectionText, 100),
                'group_type' => $groupType,
                'start_position' => $start,
                'end_position' => $end,
                'reason' => sprintf(
                    'Top-level chapter kept intact because its length (%d chars) is within the stable section threshold.',
                    $sectionLength
                ),
            ]];
        }

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
                    'Oversized top-level chapter was subdivided into %d parts within chapter boundaries only. This part was placed near character %d inside the chapter using nearby natural breakpoints.',
                    $finalChunkCount,
                    $relativeEnd
                ),
            ];
        }

        return $chunks;
    }

    /**
     * Purpose: Merge raw top-level sections into a small number of planning units for the whole document.
     * Inputs: Extracted top-level sections and the full document text.
     * Returns: A reduced list of larger planning units.
     * Side effects: None.
     */
    private function mergeSectionsIntoPlanningUnits(array $sections, string $documentText): array
    {
        if ($sections === []) {
            return [];
        }

        $documentLength = mb_strlen($documentText, 'UTF-8');
        $targetChunkCount = $this->determineTargetDocumentChunkCount($documentLength);
        $maxUnitLength = (int) ceil($documentLength / max(1, $targetChunkCount));
        $maxUnitLength = max(6000, $maxUnitLength);
        $merged = [];
        $current = null;

        foreach ($sections as $section) {
            $sectionLength = max(0, (int) $section['end_position'] - (int) $section['start_position']);

            if ($current === null) {
                $current = $section;
                continue;
            }

            $currentLength = max(0, (int) $current['end_position'] - (int) $current['start_position']);
            $currentIsBilag = preg_match('/^Bilag\s+\d+/u', (string) $current['title']) === 1;
            $nextIsBilag = preg_match('/^Bilag\s+\d+/u', (string) $section['title']) === 1;
            $combinedLength = $currentLength + $sectionLength;
            $sectionsAreContiguous = (int) $current['end_position'] === (int) $section['start_position'];
            $shouldMerge = $sectionsAreContiguous && $combinedLength <= $maxUnitLength;

            if ($currentIsBilag && $nextIsBilag && $currentLength >= (int) floor($maxUnitLength * 0.6)) {
                $shouldMerge = false;
            }

            if ($shouldMerge) {
                $current['end_position'] = (int) $section['end_position'];
                $current['title'] = $this->previewText($current['title'] . ' + ' . $section['title'], 140);
                $current['group_type'] = $this->mergeGroupTypes((string) $current['group_type'], (string) $section['group_type']);

                continue;
            }

            $merged[] = $current;
            $current = $section;
        }

        if ($current !== null) {
            $merged[] = $current;
        }

        if (count($merged) > $targetChunkCount) {
            $compressed = [];
            $current = null;

            foreach ($merged as $unit) {
                if ($current === null) {
                    $current = $unit;
                    continue;
                }

                $currentLength = max(0, (int) $current['end_position'] - (int) $current['start_position']);
                $unitLength = max(0, (int) $unit['end_position'] - (int) $unit['start_position']);
                $remainingUnits = count($merged) - count($compressed) - 1;
                $remainingSlots = max(1, $targetChunkCount - count($compressed));
                $adaptiveMaxLength = (int) ceil(($documentLength - (int) $current['start_position']) / $remainingSlots);
                $adaptiveMaxLength = max($maxUnitLength, $adaptiveMaxLength);

                $unitsAreContiguous = (int) $current['end_position'] === (int) $unit['start_position'];

                if ($unitsAreContiguous && (($currentLength + $unitLength) <= $adaptiveMaxLength || $remainingUnits < $remainingSlots)) {
                    $current['end_position'] = (int) $unit['end_position'];
                    $current['title'] = $this->previewText($current['title'] . ' + ' . $unit['title'], 140);
                    $current['group_type'] = $this->mergeGroupTypes((string) $current['group_type'], (string) $unit['group_type']);
                    continue;
                }

                $compressed[] = $current;
                $current = $unit;
            }

            if ($current !== null) {
                $compressed[] = $current;
            }

            $merged = $compressed;
        }

        return $merged;
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

        $patterns = [
            '/(?:\r\n|\r|\n){2,}/u',
            '/(?:\r\n|\r|\n)(?=(?:Bilag\s+\d+|\d+\.\s|\d+\-\d+|Skal-krav|Bør-krav|ID\s*$))/u',
        ];

        $bestBoundary = null;
        $bestDistance = null;

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $windowText, $matches, PREG_OFFSET_CAPTURE);

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

            if ($bestBoundary !== null) {
                return $bestBoundary;
            }
        }

        return $target;
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
