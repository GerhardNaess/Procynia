<?php

namespace App\Services\Ai\Wiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Models\EnterpriseWikiClaim;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityPlanner;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityRetryExecutor;
use App\Services\EnterpriseWiki\EnterpriseWikiAiRequestTimeoutPolicy;
use RuntimeException;

/**
 * Wiki run-6: max_output_tokens for every call this class makes (full-page generation, figure
 * repair, and planned-section repair) previously shared one hardcoded 6000-token ceiling,
 * regardless of page type, source size, or — for repair — how many sections actually needed
 * fixing. Run 6's article page had 2 planned sections missing after the first generation pass;
 * the repair call for both of them together hit status=incomplete/reason=max_output_tokens
 * (input_tokens=15594, output_tokens capped at exactly 6000, 768 of it spent on reasoning).
 *
 * Now sized via EnterpriseWikiAiCapacityPlanner/EnterpriseWikiAiCapacityRetryExecutor — the same
 * generic, config-driven capacity-planning-plus-bounded-retry mechanism
 * EnterpriseWikiMaintainerDecisionAiClient already uses, adopted here instead of inventing a
 * second, bespoke retry mechanism (see config('ai_capacity.operations.enterprise_wiki_page_content')
 * for the tunable profile, including its 'batch' sub-profile for section repair).
 */
class WikiPageContentAiClient
{
    public const MODEL = 'gpt-5';

    private const REASONING_EFFORT = 'low';

    private const PROMPT_NAME = 'wiki_page_content_generation';

    private const CAPACITY_OPERATION_TYPE = 'enterprise_wiki_page_content';

    /**
     * Rough expected content-block count per page type, standing in for
     * AiCapacityRequest::$expectedResultObjects — an article page's own structure (title +
     * intro + several `## ` sections, each usually more than one block) is materially larger
     * than a summary/concept/entity page's, so treating every page type as the same size (the
     * run-6 bug) undersizes the budget for exactly the page type most likely to need it.
     */
    private const EXPECTED_BLOCK_COUNTS = [
        'article' => 8,
        'summary' => 4,
        'concept' => 5,
        'entity' => 5,
    ];

    private const DEFAULT_EXPECTED_BLOCK_COUNT = 5;

    public function __construct(
        private readonly EnterpriseWikiAiCapacityPlanner $capacityPlanner,
        private readonly EnterpriseWikiAiCapacityRetryExecutor $capacityRetryExecutor,
        private readonly EnterpriseWikiAiRequestTimeoutPolicy $timeoutPolicy,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Generate markdown content for any wiki page type from a source document.
     * Pass $additionalContext for concept/entity pages to include article/summary content
     * and maintainer notes alongside the source text.
     * Pass $linkCatalog (see EnterpriseWikiLinkCatalogService) so the model can reference
     * other wiki pages via inline [[slug]]/[[slug|anchor]] wikilinks without inventing slugs.
     *
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function generateFromSource(
        string $pageTitle,
        string $pageType,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
    ): string {
        return $this->generatePageFromSource(
            pageTitle: $pageTitle,
            pageType: $pageType,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $linkCatalog,
        )['markdown'];
    }

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function generatePageFromSource(
        string $pageTitle,
        string $pageType,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        $inputSizeChars = mb_strlen($sourceText);

        $decoded = $this->capacityRetryExecutor->execute(
            'WikiPageContentAiClient',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity($pageType, $inputSizeChars, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildPayload($pageTitle, $pageType, $sourceText, $additionalContext, $languageName, $linkCatalog, $sourceElements, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );

        return $this->parseBlocksResponse($decoded);
    }

    private function planCapacity(string $pageType, int $inputSizeChars, int $retryAttempt): AiCapacityPlan
    {
        return $this->capacityPlanner->plan(new AiCapacityRequest(
            operationType: self::CAPACITY_OPERATION_TYPE,
            model: self::MODEL,
            inputSizeChars: $inputSizeChars,
            expectedResultObjects: self::EXPECTED_BLOCK_COUNTS[$pageType] ?? self::DEFAULT_EXPECTED_BLOCK_COUNT,
            retryAttempt: $retryAttempt,
        ));
    }

    /**
     * Single bounded repair attempt for a page whose planned/owned-topic sections were found
     * missing, empty, or link-only by EnterpriseWikiPlannedSectionCoverageValidator (Wiki run-586
     * incident, precision-repair redesign for Wiki run-593). Generates ONLY the content for the
     * requested section(s) — never the whole page — and never the heading line itself: the caller
     * (EnterpriseWikiGenerateAppliedPagesService) prepends the EXACT planned_topic wording as the
     * `## ` heading in code, so the persisted heading can never drift from what the maintainer
     * decision's own owned_topics says, however the model might have paraphrased it. This is a
     * structural guarantee, not an instruction the model could ignore — the previous design asked
     * for the FULL corrected page back and relied on the prompt alone to avoid rewriting content
     * that was already good.
     *
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array{planned_topic: string, blocks: list<array<string, mixed>>}> one entry per
     *                                                                                requested issue, in the same order; `blocks` is body content only (no heading line),
     *                                                                                same per-block shape generatePageFromSource() returns.
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function repairPlannedSections(
        string $pageTitle,
        string $pageType,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        $repairPromptText = $this->repairUserPrompt($pageTitle, $existingMarkdown, $issues, $sourceText, $additionalContext, $linkCatalog, $sourceElements);
        $inputSizeChars = mb_strlen($repairPromptText);
        // Wiki run-6: each missing/empty/link-only planned section is sized as one "candidate" —
        // repairing 2 sections at once (run 6's article page) must get a materially larger budget
        // than repairing 1, instead of sharing the exact same flat ceiling.
        $sectionsToRepair = max(1, count($issues));

        $decoded = $this->capacityRetryExecutor->execute(
            'WikiPageContentAiClient(repair)',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planSectionRepairCapacity($inputSizeChars, $sectionsToRepair, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildRepairPayload($pageType, $languageName, $repairPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForBatch(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            ), $sectionsToRepair),
            $context,
        );

        return $this->parseSectionRepairResponse($decoded, $issues);
    }

    private function planSectionRepairCapacity(int $inputSizeChars, int $sectionsToRepair, int $retryAttempt): AiCapacityPlan
    {
        return $this->capacityPlanner->planBatchCall(
            operationType: self::CAPACITY_OPERATION_TYPE,
            model: self::MODEL,
            candidatesInBatch: $sectionsToRepair,
            inputSizeChars: $inputSizeChars,
            retryAttempt: $retryAttempt,
        );
    }

    /**
     * Single bounded repair attempt for a page whose required planned_figures were found
     * missing/invalid by EnterpriseWikiPlannedFigureCoverageValidator (Wiki run-587 incident: 4 of
     * 4 professionally significant figures were extracted, classified, and made citable, but never
     * once cited). Deliberately a SEPARATE repair from repairPlannedSections() — a missing figure
     * citation and an empty prose section are different defects with different fixes, and mixing
     * them into one repair call would blur "the section-coverage repair is untouched by this task"
     * from "the figure-coverage repair this task adds".
     *
     * @param  list<array{type: string, source_element_key: string, required: bool, planned_section: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function repairPlannedFigures(
        string $pageTitle,
        string $pageType,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        // Returns the FULL corrected page (unlike repairPlannedSections()), so it shares the
        // main page-content capacity profile — sized here off the actual built prompt (which
        // echoes the whole existing page back), not just the raw source text.
        $figureRepairPromptText = $this->figureRepairUserPrompt($pageTitle, $existingMarkdown, $issues, $sourceText, $additionalContext, $linkCatalog, $sourceElements);
        $inputSizeChars = mb_strlen($figureRepairPromptText);

        $decoded = $this->capacityRetryExecutor->execute(
            'WikiPageContentAiClient(figure_repair)',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity($pageType, $inputSizeChars, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildFigureRepairPayload($pageType, $languageName, $figureRepairPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );

        return $this->parseBlocksResponse($decoded);
    }

    /**
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function parseBlocksResponse(array $decoded): array
    {
        $blocks = data_get($decoded, 'page.blocks', []);

        if (! is_array($blocks) || $blocks === []) {
            throw new RuntimeException('WikiPageContentAiClient: generated page blocks were empty.');
        }

        $markdownParts = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] was invalid.");
            }

            $blockMarkdown = trim((string) ($block['markdown'] ?? ''));

            if ($blockMarkdown === '') {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] markdown was empty.");
            }

            $origin = (string) ($block['content_origin'] ?? '');
            if (! in_array($origin, [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
            ], true)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] has invalid content_origin.");
            }

            $sourceElementKeys = $block['source_element_keys'] ?? [];
            if (! is_array($sourceElementKeys)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] source_element_keys was invalid.");
            }

            if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED && $sourceElementKeys === []) {
                throw new RuntimeException("WikiPageContentAiClient: source-based block [{$index}] has no source_element_keys.");
            }

            $blocks[$index]['markdown'] = $blockMarkdown;
            $markdownParts[] = $blockMarkdown;
        }

        $markdown = trim(implode("\n\n", $markdownParts));

        if ($markdown === '') {
            throw new RuntimeException('WikiPageContentAiClient: generated page content was empty.');
        }

        $this->validateMarkdown($markdown);

        return [
            'markdown' => $markdown,
            'blocks' => array_values($blocks),
        ];
    }

    /**
     * Structural validation only — mirrors parseBlocksResponse()'s own checks (empty content,
     * valid content_origin, source_element_keys present for source_based blocks, no HTML
     * comments/citation lines/blockquotes), scoped to one section at a time. Whether a
     * structurally-valid section is actually SUBSTANTIAL enough (not below-minimum-substance,
     * not link-only) is still decided entirely by
     * EnterpriseWikiPlannedSectionCoverageValidator::validate(), unchanged, after the caller
     * splices this section into the full page markdown — this method never duplicates that
     * matching/substance rule.
     *
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @return list<array{planned_topic: string, blocks: list<array<string, mixed>>}>
     */
    private function parseSectionRepairResponse(array $decoded, array $issues): array
    {
        $sections = data_get($decoded, 'sections', []);

        if (! is_array($sections) || $sections === []) {
            throw new RuntimeException('WikiPageContentAiClient: repaired sections were empty.');
        }

        $requestedTopics = array_map(
            static fn (array $issue): string => trim((string) $issue['planned_topic']),
            $issues,
        );

        $result = [];

        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] was invalid.");
            }

            $plannedTopic = trim((string) ($section['planned_topic'] ?? ''));

            if (! in_array($plannedTopic, $requestedTopics, true)) {
                throw new RuntimeException(
                    "WikiPageContentAiClient: repaired section [{$sectionIndex}] did not match any requested planned_topic."
                );
            }

            $blocks = $section['blocks'] ?? [];

            if (! is_array($blocks) || $blocks === []) {
                throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] had no blocks.");
            }

            $markdownParts = [];

            foreach ($blocks as $blockIndex => $block) {
                if (! is_array($block)) {
                    throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] block [{$blockIndex}] was invalid.");
                }

                $blockMarkdown = trim((string) ($block['markdown'] ?? ''));

                if ($blockMarkdown === '') {
                    throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] block [{$blockIndex}] markdown was empty.");
                }

                $origin = (string) ($block['content_origin'] ?? '');
                if (! in_array($origin, [
                    EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
                ], true)) {
                    throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] block [{$blockIndex}] has invalid content_origin.");
                }

                $sourceElementKeys = $block['source_element_keys'] ?? [];
                if (! is_array($sourceElementKeys)) {
                    throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] block [{$blockIndex}] source_element_keys was invalid.");
                }

                if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED && $sourceElementKeys === []) {
                    throw new RuntimeException("WikiPageContentAiClient: source-based repaired block [{$sectionIndex}][{$blockIndex}] has no source_element_keys.");
                }

                $blocks[$blockIndex]['markdown'] = $blockMarkdown;
                $markdownParts[] = $blockMarkdown;
            }

            $sectionMarkdown = trim(implode("\n\n", $markdownParts));

            if ($sectionMarkdown === '') {
                throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] content was empty.");
            }

            $this->validateMarkdown($sectionMarkdown);

            $result[] = [
                'planned_topic' => $plannedTopic,
                'blocks' => array_values($blocks),
            ];
        }

        return $result;
    }

    private function validateMarkdown(string $markdown): void
    {
        if (str_contains($markdown, '<!--')) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains HTML comments — rejected.');
        }

        if (preg_match_all('/^(Kilde|Source|Ref)\s*:/im', $markdown) >= 2) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains source citation lines — rejected.');
        }

        if (preg_match_all('/^>/m', $markdown) >= 3) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains blockquote lines — rejected.');
        }
    }

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function buildRepairPayload(string $pageType, string $languageName, string $repairPromptText, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->repairDeveloperPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $repairPromptText,
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::repairSectionsSchema(),
                ],
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function repairDeveloperPrompt(string $pageType, string $languageName): string
    {
        return implode("\n", [
            "You are an editorial wiki writer repairing specific missing or empty planned sections of a previously generated {$pageType} page in {$languageName}.",
            '',
            'REPAIR RULES (mandatory):',
            '- Return ONLY the content for the section(s) listed in "SECTIONS TO REPAIR" below — never the rest of the page, and never repeat or rewrite content from a section not listed there.',
            '- Do NOT write the section heading (the "## ..." line) yourself — return only the body content that belongs under that heading. The exact heading text is added separately by the caller, verbatim from the planned topic.',
            '- Return exactly one entry in "sections" per requested planned_topic, in the same order, each with "planned_topic" copied back verbatim and its own "blocks".',
            '- Write a real, substantial paragraph of flowing prose for each section — grounded in the source document. Never return an empty section, a section containing only a wikilink, or a bare punctuation mark.',
            '- Preserve concrete details the source document actually states for each section — roles, responsibilities, agenda items, participants, frequency, and decision flow — when the source material describes them.',
            '- Do not invent facts not supported by the source document; if the source genuinely says nothing more about a section, write the fullest accurate paragraph the source material supports rather than an empty or thin section.',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Every content block must explicitly choose content_origin: source_based, best_practice, or structural — for source_based blocks, copy exact source_element_keys from SOURCE ELEMENTS. structural is for a pure "Se også"/cross-reference one-liner with no assertion of its own; best_practice requires a concrete obligation, control, or mechanism that goes beyond the source, never a bare heading or reference.',
            'Write every block as finished agreement text, exactly as the rest of the page: a best_practice block states its clause normatively ("skal ...") and never as advice — no "Procynia anbefaler", "det anbefales", "beste praksis tilsier", or any equivalent advisory opener, and no "fordi ..." justification in the text itself. The justification belongs in best_practice_reason.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function repairUserPrompt(
        string $pageTitle,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $additionalContext,
        array $linkCatalog = [],
        array $sourceElements = [],
    ): string {
        $parts = [
            "Page title: {$pageTitle}",
            '',
            'PREVIOUSLY GENERATED PAGE (context only, so you know what already exists and never repeat it — it is kept exactly as-is except for the section(s) below, which do not exist yet or are still empty/link-only):',
            '',
            $existingMarkdown,
            '',
            '---',
            '',
            'SECTIONS TO REPAIR (return ONLY these, one per planned_topic, in this exact order — do not include the heading itself):',
            '',
        ];

        foreach ($issues as $issue) {
            $parts[] = sprintf('- planned_topic: %s (previous problem: %s)', $issue['planned_topic'], $issue['type']);
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';

        // Wiki run-6: the flat source text and SOURCE ELEMENTS below are the exact same
        // underlying content when structured elements exist — one as continuous prose, the
        // other broken into citable segments. Sending both roughly doubles this already-large
        // repair prompt's input for no benefit (the model only ever cites source_element_key
        // values) — run 6's article repair sent both at once, reaching input_tokens=15594 for a
        // 2-section repair that then hit max_output_tokens.
        if ($sourceElements !== []) {
            $parts[] = 'Source document: see SOURCE ELEMENTS below — the full source content is provided there, broken into citable segments, and is not repeated here as flat text.';
        } else {
            $parts[] = 'Source document:';
            $parts[] = '';
            $parts[] = $sourceText;
        }

        if (trim($additionalContext) !== '') {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '';
            $parts[] = 'Additional context:';
            $parts[] = '';
            $parts[] = $additionalContext;
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' pages):';
        $parts[] = '';

        if ($linkCatalog !== []) {
            foreach ($linkCatalog as $entry) {
                $parts[] = sprintf('[[%s|%s]]', $entry['slug'], $entry['title']);
            }
        } else {
            $parts[] = 'No other pages available to link to.';
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'SOURCE ELEMENTS ('.count($sourceElements).' elements):';
        $parts[] = '';
        $parts[] = 'Every source_based block must cite one or more source_element_key values from this list.';
        $parts[] = 'Do not invent source_element_key values.';
        $parts[] = '';
        $parts[] = (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $parts);
    }

    /**
     * @param  list<array{type: string, source_element_key: string, required: bool, planned_section: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function buildFigureRepairPayload(string $pageType, string $languageName, string $figureRepairPromptText, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->figureRepairDeveloperPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $figureRepairPromptText,
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function figureRepairDeveloperPrompt(string $pageType, string $languageName): string
    {
        return implode("\n", [
            "You are an editorial wiki writer repairing a previously generated {$pageType} page in {$languageName} that failed to cite one or more of its planned figures.",
            '',
            'REPAIR RULES (mandatory):',
            '- Return the FULL corrected page as page.blocks, using the exact same block schema as ordinary generation.',
            '- Keep everything from the previously generated page that is already good — do not rewrite or remove content unrelated to the figures listed below.',
            '- For each figure named in "FIGURES TO REPAIR" below, add (or correct) a source_based block whose source_element_keys includes that figure\'s exact source_element_key, with source_element_types including "image". Write real, specific prose describing what the figure shows and why it matters here — never a vague summary sentence, and never omit it.',
            '- Place the citing block near the figure\'s given section placement when one is given.',
            '- Do not invent visual details the figure\'s description does not support.',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Every ordinary content block must explicitly choose content_origin: source_based, best_practice, or structural — for source_based blocks, copy exact source_element_keys from SOURCE ELEMENTS. Keep every existing block\'s own content_origin unchanged unless you are correcting it.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array{type: string, source_element_key: string, required: bool, planned_section: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function figureRepairUserPrompt(
        string $pageTitle,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $additionalContext,
        array $linkCatalog = [],
        array $sourceElements = [],
    ): string {
        $parts = [
            "Page title: {$pageTitle}",
            '',
            'PREVIOUSLY GENERATED PAGE (needs repair — keep what is good, fix only the figures listed below):',
            '',
            $existingMarkdown,
            '',
            '---',
            '',
            'FIGURES TO REPAIR:',
            '',
        ];

        foreach ($issues as $issue) {
            $section = $issue['planned_section'] ?? '(page introduction — no section given)';
            $requiredLabel = ($issue['required'] ?? false) ? 'required' : 'optional';
            $parts[] = sprintf(
                '- source_element_key "%s" — section placement: %s (%s, issue: %s)',
                $issue['source_element_key'],
                $section,
                $requiredLabel,
                $issue['type'],
            );
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';

        if ($sourceElements !== []) {
            $parts[] = 'Source document: see SOURCE ELEMENTS below — the full source content is provided there, broken into citable segments, and is not repeated here as flat text.';
        } else {
            $parts[] = 'Source document:';
            $parts[] = '';
            $parts[] = $sourceText;
        }

        if (trim($additionalContext) !== '') {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '';
            $parts[] = 'Additional context:';
            $parts[] = '';
            $parts[] = $additionalContext;
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' pages):';
        $parts[] = '';

        if ($linkCatalog !== []) {
            foreach ($linkCatalog as $entry) {
                $parts[] = sprintf('[[%s|%s]]', $entry['slug'], $entry['title']);
            }
        } else {
            $parts[] = 'No other pages available to link to.';
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'SOURCE ELEMENTS ('.count($sourceElements).' elements):';
        $parts[] = '';
        $parts[] = 'Every source_based block must cite one or more source_element_key values from this list.';
        $parts[] = 'Do not invent source_element_key values.';
        $parts[] = '';
        $parts[] = (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $parts);
    }

    private function buildPayload(string $pageTitle, string $pageType, string $sourceText, string $additionalContext, string $languageName, array $linkCatalog, array $sourceElements, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($pageTitle, $sourceText, $additionalContext, $linkCatalog, $sourceElements),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function developerPrompt(string $pageType, string $languageName): string
    {
        $prohibitions = implode("\n", [
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);

        $blockRules = implode("\n", [
            'STRUCTURED BLOCK OUTPUT:',
            '- Return page.blocks as the only content-bearing output. Do not return a single free-form markdown field.',
            '- Each block must be one independently traceable heading, paragraph, or heading+paragraph group.',
            '- Every content block must explicitly choose content_origin: source_based, best_practice, or structural. There is no other option and no default — choose deliberately for each block.',
            '',
            'HOUSE STYLE — every content block is finished agreement text:',
            '- The whole page must read as usable agreement text: wording that could stand in the contract or governing document as it is, without further editing. Write in continuous, professional prose.',
            '- Never write about the source, about this system, or about your own work: no "kilden beskriver", "dokumentet sier", "ifølge kildedokumentet", "Procynia", "vi", or any other meta-commentary about where the text came from or why it is here. State the substance directly.',
            '- Use the party and role names that actually follow from the source and its context (for example Kunden, Leverandøren, a named role, or a specific function). Never invent a party the material does not support, and never default every obligation to "Kunden skal" — each obligation belongs to the party that would realistically carry it.',
            '',
            'SOURCE_BASED — direct content from the source document:',
            '- Copy one or more exact source_element_keys from SOURCE ELEMENTS and include the corresponding source_element_types.',
            '- A source_based block without source_element_keys is invalid. Never mark a block source_based just because it sounds plausible or reads like something the source document would say — only when you can cite the specific source_element_keys it is drawn from.',
            '- Preserve the meaning, the obligations, and the parties/roles exactly as the source states them. Rewriting for flow is expected; changing who owes what, or how binding it is, is not.',
            '',
            'STRUCTURAL — content with no factual or professional assertion of its own:',
            '- Use structural for the page title, a section heading that introduces a topic without itself stating a claim, a "Se også"/"See also" cross-reference sentence, a plain wikilink-only reference, or other purely navigational/editorial text.',
            '- Never use best_practice as a fallback for content that has no source_element_keys to cite — if a block carries no assertion at all, it is structural, not best_practice.',
            '- Leave best_practice_reason null and source_element_keys/source_element_types empty for structural blocks.',
            '',
            'BEST_PRACTICE — a genuine Procynia assertion, never a fallback category:',
            '- A best_practice block must contain at least one concrete Procynia assertion: a recommendation, an identified gap or weakness in what the source document describes, a control or safeguard that should be introduced, a clarification of ownership/responsibility, a measurable target or KPI, a decision criterion, a risk control, a follow-up/monitoring mechanism, or a process improvement. A heading, title, or cross-reference is NEVER best_practice by itself — classify it as structural instead.',
            '- Write the improvement itself as finished, normative agreement text — the clause as it would stand in the contract or governing document, ready to use as it is. Use ordinary obligation language where it fits the content: "skal", "skal sikre", "skal etablere", "skal dokumentere", "skal følge opp".',
            '- NEVER frame it as advice, opinion, or a suggestion from anyone. Do not write "Procynia anbefaler", "vi anbefaler", "det anbefales", "beste praksis tilsier", "bør vurderes", or any equivalent advisory opener in any language, and never append a justification clause such as "fordi dette vil ..." to the text itself. Never write a vague, generic sentence like "Det anbefales å følge beste praksis." — state the concrete obligation, control, or mechanism itself rather than gesturing at "best practice" in the abstract.',
            '- The block\'s markdown carries ONLY the clause. What was missing and why this was proposed belongs exclusively in best_practice_reason, never in the visible text. content_origin, that stored reason, and human review are what mark the block as a Procynia contribution — the sentence itself must read exactly like the rest of the agreement.',
            '- Keep it normative, never descriptive: write what shall apply ("Leverandøren skal etablere ..."), never what a party already has, does, or follows today ("Leverandøren sikrer ..."). A descriptive sentence would misrepresent a proposal as an already-documented fact about this particular customer\'s or supplier\'s actual agreement, system, or process.',
            '- best_practice_reason is where the justification lives: state what the source leaves unclear, missing, or unhandled, and what this clause adds. Write it for the human reviewer who decides whether to keep the clause — it is stored as review metadata and never rendered as page text.',
            '- When a specific part of the source document is what exposed the gap or weakness your recommendation addresses, cite it: include the relevant source_element_key(s)/source_element_types alongside content_origin=best_practice. This does not make the block source_based — it only records what motivated your own assessment. Leave source_element_keys empty when no single source element specifically triggered the recommendation.',
            '- Do not classify factual statements, uncertain source facts, rewritten source facts, statements about the customer\'s or supplier\'s current/actual state, or likely hallucinations as best_practice — those must be source_based (if genuinely grounded, with real source_element_keys) or omitted.',
            '- A best_practice block must be relevant to this page\'s own owned topics (see PAGE RESPONSIBILITY below) — never add general professional/industry elaboration of the wider subject area just because it is true and related. A thin source document justifies thin SOURCE_BASED content (you can only report what the source actually states); it never justifies skipping the best-practice synthesis below. Resolve doubt on RELEVANCE, not on whether to propose at all: if a well-founded recommendation genuinely concerns one of this page\'s owned topics, include it; if it belongs to another page\'s topic or to the wider subject area, leave it out.',
            '',
            'PROFESSIONAL BEST-PRACTICE SYNTHESIS (work through this deliberately before writing any best_practice content):',
            '- Step 1 — understand the source: establish what the source document actually describes for this page\'s own owned topics, and what it leaves unsaid. Do this before forming any judgment about it.',
            '- Step 2 — identify the subject: name, for yourself, which professional domain, process, or concept this page actually deals with (for example a specific service-management practice, a governance process, a delivery model, or a security or quality discipline). Your reference frame must be the established good practice for THAT subject specifically.',
            '- Step 3 — compare against mature practice: assess whether the source covers what a professionally mature, well-run treatment of that specific subject would normally be expected to cover. Measure the source against that standard instead of only restating it.',
            '- Step 4 — contribute where it adds value: when established good practice for this subject would add substantial value beyond what the source states, write a concrete best_practice block for it — the clause itself as finished agreement text, with the reasoning recorded separately in best_practice_reason.',
            '- Depth over breadth: a few genuinely subject-specific, professionally grounded recommendations are worth far more than a broad sweep of generic checklist items. Never emit a recommendation that would read exactly the same for an unrelated document about an unrelated subject.',
            '- Being absent from the source is never a reason to withhold a relevant recommendation — that absence is precisely what makes it a Procynia contribution rather than source_based content. Procynia is expected to add professional value here, and every best_practice block is routed to a human reviewer who approves or rejects it before it counts as accepted, so a well-founded recommendation is safe to put forward.',
            '- No quota, no minimum, no padding: propose only what the comparison in step 3 genuinely supports, and never manufacture a recommendation to fill space or reach a number. Zero best_practice blocks is the correct outcome when the source already treats its subject well — but reach that conclusion by actually performing the comparison, not by defaulting to it.',
            '',
            'link_intents must list only useful visible Wiki links the block should contain; use an empty list when no visible link is useful.',
        ]);

        $responsibilityRules = implode("\n", [
            'PAGE RESPONSIBILITY:',
            '- "Additional context" (if present) states this page\'s own content responsibility in three tiers: what to explain in depth ("own content responsibility"), what to mention only briefly ("Reference only"), and what to never mention ("EXCLUDED").',
            '- Treat every EXCLUDED topic as a hard, binding boundary: do not mention it, allude to it, or give it a section, list, or even one sentence — not even to note that it exists or that it is covered elsewhere.',
            '- For a "Reference only" topic, write AT MOST one short sentence plus an inline [[wikilink]] to the page that owns it — never its own heading, paragraph, or list of sub-points. This one-liner is structural (a cross-reference), not best_practice — unless it also happens to state a genuine Procynia recommendation of its own, which the "Reference only" tier does not call for.',
            '- Stay strictly inside this page\'s own content responsibility for everything else. The number of sections this page contains should track directly with the number of items in its own content responsibility — do not add or expand a section to cover something that is not one of those items, even if it is topically related.',
            '- When no such guidance is given at all, fall back to writing only what the source material actually supports for this specific page — a short, thin source document justifies a short, thin page; do not expand into a comprehensive treatment of the wider subject just because more could technically be said about it.',
            '- Never restate the same sentence, fact, or near-identical wording more than once on this page, and never copy wording verbatim from another page\'s content given as context — express a shared idea once, in your own words, in the place it actually belongs.',
        ]);

        $figureRules = implode("\n", [
            'PLANNED FIGURES:',
            '- "Additional context" may include a "PLANNED FIGURES FOR THIS PAGE" section — figures the maintainer decision assigned to this page specifically, each with a source_element_key, classification, section placement, purpose, required/optional flag, and an optional caption hint.',
            '- For each figure marked required: you MUST cite its exact source_element_key in a source_based block\'s source_element_keys, with source_element_types including "image". Write real, specific prose for that block describing what the figure shows and why it matters — never replace the figure with a vague summary sentence, and never omit it.',
            '- For each figure marked optional: cite it the same way when it fits naturally within this page\'s own content responsibility; it is not an error to leave an optional figure out when there is no natural place for it.',
            '- Place the citing block near the figure\'s given section placement — if the block belongs under one of this page\'s own ## sections, put it there, not at the very end of the page.',
            '- Use the figure\'s caption hint (if given) or its existing description to write the citing text — never invent visual details the source description does not support.',
            '- Never cite a source_element_key of type image that is not listed in SOURCE ELEMENTS or in PLANNED FIGURES.',
        ]);

        $wikilinkRules = implode("\n", [
            'INLINE WIKILINKS:',
            '- content_markdown is wiki content — reference other pages inline the way a wiki article does.',
            '- Use [[target-slug|natural visible text]] inside ordinary prose to reference a page from the "ALLOWED WIKILINK TARGETS" list provided in the user message.',
            '- Use [[target-slug]] only when the slug itself already reads naturally as the visible text.',
            '- The "slug" field is the exact, literal identifier you must copy into the [[...]] markup — it is frequently lowercase or hyphenated and different from the page\'s "title" field. Never substitute the title text where the slug is expected, and never change the slug\'s case, spelling, or hyphenation.',
            '- The user message includes ready-to-copy [[slug|Title]] markup for every allowed target — copy that markup verbatim rather than typing the slug from memory.',
            '- Only link to slugs that appear in the allowed wikilink target list — never invent a slug, and never link to this page\'s own slug.',
            '- Link the first or most natural occurrence of a concept or entity — do not repeat the same link for every mention.',
            '- Only link semantically important concepts and entities that the text is actually about — do not link generic words.',
            '- Place links inside normal flowing prose. Never replace natural inline linking with a separate "Related pages" list or section.',
            '- Do not create a link for every allowed target just to reach a number — link only where it reads naturally.',
        ]);

        return match ($pageType) {
            'summary' => implode("\n", [
                "You are an editorial wiki writer. Write a concise professional summary page in {$languageName}.",
                '',
                'SUMMARY STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with 2-4 paragraphs summarising the key points',
                '- Write flowing prose — no bullet lists, no headings beyond the title',
                '- Inline-link the most important concept/entity pages from the allowed targets; keep the summary short and natural',
                '',
                'SOURCE FOR THIS SUMMARY:',
                '- If "Additional context" provides the finished article for this same source document, base this summary on that article\'s actual content and structure — condense what the article covers and link to it and other detail pages, rather than independently re-deriving the summary from the raw source document.',
                '- If no finished article is provided, summarise the source document directly.',
                '',
                $responsibilityRules,
                '',
                $figureRules,
                '',
                $blockRules,
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            'concept' => implode("\n", [
                "You are an editorial wiki writer. Write a professional concept page in {$languageName} explaining the given concept as it appears in the source material.",
                '',
                'CONCEPT PAGE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the concept name',
                '- Follow with a definition paragraph (2-4 sentences) explaining what the concept is',
                '- Add AT MOST one ## section per item in this page\'s own content responsibility (see PAGE RESPONSIBILITY below) — never a section for a reference-only or excluded topic, and never more sections than that list has items. When no content-responsibility guidance is given, add at most one or two sections and only for what the source material itself supports.',
                '- When you add a section for an own content responsibility item, the heading line must be exactly "## " followed by that item text copied verbatim. Do not paraphrase, shorten, use ###, bold labels, or plain paragraph labels for those planned sections.',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article, concept, and entity pages from the allowed targets',
                '',
                'SYNTHESIS RULES:',
                '- Derive meaning from the provided source text and related page content',
                '- Do not invent facts not supported by the provided material',
                '- Related article or summary content, when provided, is background for understanding context ONLY — it is never itself a license to explain a topic in depth. Use it to decide what this page is responsible for, then write strictly within that responsibility (see PAGE RESPONSIBILITY below); a topic another page owns, or that is not one of this page\'s own responsibility items, belongs behind a short mention and a link at most, never a repeated or newly-invented explanation',
                '',
                $responsibilityRules,
                '',
                $figureRules,
                '',
                $blockRules,
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            'entity' => implode("\n", [
                "You are an editorial wiki writer. Write a professional entity page in {$languageName} describing the given entity (person, organisation, product, system, or place) as it appears in the source material.",
                '',
                'ENTITY PAGE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the entity name',
                '- Follow with an identification paragraph (2-4 sentences) stating what the entity is',
                '- Add AT MOST one ## section per item in this page\'s own content responsibility (see PAGE RESPONSIBILITY below) — never a section for a reference-only or excluded topic, and never more sections than that list has items. When no content-responsibility guidance is given, add at most one or two sections and only for what the source material itself supports.',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article and concept pages from the allowed targets; avoid speculative relationships',
                '',
                'SYNTHESIS RULES:',
                '- Derive all facts from the provided source text and related page content',
                '- Do not invent roles, relationships, or attributes not present in the material',
                '- Related article or summary content, when provided, is background for understanding context ONLY — it is never itself a license to explain a topic in depth. Use it to decide what this page is responsible for, then write strictly within that responsibility (see PAGE RESPONSIBILITY below); a topic another page owns, or that is not one of this page\'s own responsibility items, belongs behind a short mention and a link at most, never a repeated or newly-invented explanation',
                '',
                $responsibilityRules,
                '',
                $figureRules,
                '',
                $blockRules,
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            default => implode("\n", [
                "You are an editorial wiki article writer. Write a professional, readable internal wiki article in {$languageName} based on the provided source document.",
                '',
                'ARTICLE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with a short introductory paragraph (2-4 sentences) summarising the topic',
                '- Organise the body using ## subheadings for logical sections. When this page\'s own content responsibility is given (see PAGE RESPONSIBILITY below), add at most one subheading per item in it — do not add a subheading for a reference-only or excluded topic',
                '- Write flowing prose paragraphs within each section — no bullet lists',
                '- End the article naturally, without a separate summary or conclusion heading',
                '- Inline-link central concept and entity pages, and any other allowed target the text clearly relates to',
                '',
                'SYNTHESIS RULES:',
                '- Synthesise the source material into coherent prose',
                '- Do not repeat the same fact across multiple sections',
                '- Do not invent facts not present in the source document',
                '- Do not expand into general background on the wider subject beyond what this page\'s own content responsibility calls for — a short source document justifies a short article',
                '',
                $responsibilityRules,
                '',
                $figureRules,
                '',
                $blockRules,
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
        };
    }

    private function userPrompt(string $pageTitle, string $sourceText, string $additionalContext, array $linkCatalog = [], array $sourceElements = []): string
    {
        $parts = [
            "Page title: {$pageTitle}",
            '',
            'Source document:',
            '',
            $sourceText,
        ];

        if (trim($additionalContext) !== '') {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '';
            $parts[] = 'Additional context:';
            $parts[] = '';
            $parts[] = $additionalContext;
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' pages):';
        $parts[] = '';

        if ($linkCatalog !== []) {
            $parts[] = 'Copy the exact markup below when linking to a page — do not retype the slug from the title, and do not change its case or spelling:';
            $parts[] = '';

            foreach ($linkCatalog as $entry) {
                $parts[] = sprintf('[[%s|%s]]', $entry['slug'], $entry['title']);
            }

            $parts[] = '';
            $parts[] = 'Full catalog (slug, title, page_type):';
            $parts[] = '';
            $parts[] = (string) json_encode($linkCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $parts[] = 'No other pages available to link to.';
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'SOURCE ELEMENTS ('.count($sourceElements).' elements):';
        $parts[] = '';
        $parts[] = 'Every source_based block must cite one or more source_element_key values from this list.';
        $parts[] = 'Do not invent source_element_key values.';
        $parts[] = '';
        $parts[] = (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $parts);
    }

    /**
     * The per-block item schema shared by schema() (full-page generation) and
     * repairSectionsSchema() (Wiki run-593: section-only repair) — kept as one definition so the
     * two request shapes can never silently drift apart on what a "block" is.
     */
    private static function blockItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'markdown' => ['type' => 'string'],
                'content_origin' => [
                    'type' => 'string',
                    'enum' => [
                        EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                        EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                        EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL,
                    ],
                ],
                'source_element_keys' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'source_element_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'best_practice_reason' => [
                    'type' => ['string', 'null'],
                ],
                'link_intents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'target_slug' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['target_slug', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'markdown',
                'content_origin',
                'source_element_keys',
                'source_element_types',
                'best_practice_reason',
                'link_intents',
            ],
            'additionalProperties' => false,
        ];
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'object',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => self::blockItemSchema(),
                        ],
                    ],
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['page'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Wiki run-593: one entry per requested planned_topic — "blocks" is body content only (no
     * heading), the exact same per-block shape schema() uses. The caller
     * (EnterpriseWikiGenerateAppliedPagesService) prepends the literal planned_topic text as the
     * `## ` heading itself; the model is never asked to produce a heading at all.
     */
    private static function repairSectionsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'planned_topic' => ['type' => 'string'],
                            'blocks' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => self::blockItemSchema(),
                            ],
                        ],
                        'required' => ['planned_topic', 'blocks'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['sections'],
            'additionalProperties' => false,
        ];
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
