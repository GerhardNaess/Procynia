<?php

namespace App\Services\Ai\Wiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiPlannedSectionRepairShapeException;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityPlanner;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityRetryExecutor;
use App\Services\EnterpriseWiki\EnterpriseWikiAiRequestTimeoutPolicy;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiUtf8Guard;
use Illuminate\Support\Facades\Log;
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

    /**
     * The review key for a page that has no sections to review per topic: a summary page, or a page
     * generated without a planned-section contract. One entry, covering the page as a whole.
     */
    public const REVIEW_TOPIC_WHOLE_PAGE = '__page__';

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
        private readonly EnterpriseWikiUtf8Guard $utf8Guard,
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
     * other wiki pages through server-authoritative link intents without inventing slugs.
     *
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $linkCatalog
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
        array $plannedSections = [],
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $context ??= AiCallContext::none();
        $this->utf8Guard->assertValid([
            'page_title' => $pageTitle,
            'source_text' => $sourceText,
            'additional_context' => $additionalContext,
            'link_catalog' => $linkCatalog,
            'source_elements' => $sourceElements,
            'planned_sections' => $plannedSections,
        ], 'enterprise_wiki_ai_request_input');
        $languageName = $this->languageName($languageCode);
        $generationPromptText = $this->userPrompt($pageTitle, $sourceText, $additionalContext, $linkCatalog, $sourceElements, $plannedSections);
        $inputSizeChars = mb_strlen($generationPromptText);

        $decoded = $this->capacityRetryExecutor->execute(
            'WikiPageContentAiClient',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity($pageType, $inputSizeChars, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildPayload($pageType, $languageName, $generationPromptText, $plannedSections, $linkCatalog, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );

        $this->utf8Guard->assertValid($decoded, 'enterprise_wiki_ai_response');

        return $this->parseBlocksResponse($decoded, 'generation', $pageType, $plannedSections);
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
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $linkCatalog
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
        array $plannedSections = [],
        array $sectionStatuses = [],
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $context ??= AiCallContext::none();
        $this->utf8Guard->assertValid([
            'page_title' => $pageTitle,
            'existing_markdown' => $existingMarkdown,
            'issues' => $issues,
            'source_text' => $sourceText,
            'additional_context' => $additionalContext,
            'link_catalog' => $linkCatalog,
            'source_elements' => $sourceElements,
            'planned_sections' => $plannedSections,
            'section_statuses' => $sectionStatuses,
        ], 'enterprise_wiki_ai_request_input');
        $languageName = $this->languageName($languageCode);
        $requestedPlannedTopics = $plannedSections === []
            ? $this->requestedPlannedTopics($issues)
            : $this->plannedTopicsFromContract($plannedSections);
        $repairPromptText = $this->repairUserPrompt($pageTitle, $existingMarkdown, $issues, $requestedPlannedTopics, $sourceText, $additionalContext, $linkCatalog, $sourceElements, $plannedSections, $sectionStatuses);
        $inputSizeChars = mb_strlen($repairPromptText);
        // Wiki run-6: each missing/empty/link-only planned section is sized as one "candidate" —
        // repairing 2 sections at once (run 6's article page) must get a materially larger budget
        // than repairing 1, instead of sharing the exact same flat ceiling.
        $sectionsToRepair = max(1, count($issues));

        $decoded = $this->capacityRetryExecutor->execute(
            'WikiPageContentAiClient(repair)',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planSectionRepairCapacity($inputSizeChars, $sectionsToRepair, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildRepairPayload($pageType, $languageName, $repairPromptText, $requestedPlannedTopics, $linkCatalog, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForBatch(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            ), $sectionsToRepair),
            $context,
        );

        $this->utf8Guard->assertValid($decoded, 'enterprise_wiki_ai_response');

        return $this->parseSectionRepairResponse($decoded, $requestedPlannedTopics, $pageType, $plannedSections);
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
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $linkCatalog
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
        $this->utf8Guard->assertValid([
            'page_title' => $pageTitle,
            'existing_markdown' => $existingMarkdown,
            'issues' => $issues,
            'source_text' => $sourceText,
            'additional_context' => $additionalContext,
            'link_catalog' => $linkCatalog,
            'source_elements' => $sourceElements,
        ], 'enterprise_wiki_ai_request_input');
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
            fn (int $maxOutputTokens): array => $this->buildFigureRepairPayload($pageType, $languageName, $figureRepairPromptText, $linkCatalog, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );

        $this->utf8Guard->assertValid($decoded, 'enterprise_wiki_ai_response');

        return $this->parseBlocksResponse($decoded, 'figure_repair', $pageType);
    }

    /**
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function parseBlocksResponse(array $decoded, string $operation, string $pageType, array $plannedSections = []): array
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

        $this->logParsedBlockOrigins($operation, $pageType, $blocks);

        $review = $this->parseBestPracticeReview($decoded, $pageType, $plannedSections);

        Log::info('[WIKI_BEST_PRACTICE_REVIEW] Parsed best-practice assessment.', [
            'operation' => $operation,
            'page_type' => $pageType,
            'reviewed' => count($review),
            'gap_found' => count(array_filter($review, static fn (array $entry): bool => $entry['gap_found'])),
        ]);

        return [
            'markdown' => $markdown,
            'blocks' => array_values($blocks),
            'best_practice_review' => $review,
        ];
    }

    /**
     * One entry per topic the page was asked to assess, normalised and ordered by the REQUESTED
     * topics rather than by the response — so a caller reads the same shape whatever order the
     * model answered in.
     *
     * The response cannot be missing an entry (strict cardinality + enum, see
     * bestPracticeReviewSchema()), so this never invents one; a legacy/stored response without the
     * field at all yields an empty list, which the reconciler treats as "not assessed" rather than
     * as "assessed, no gap".
     *
     * @param  list<array<string, mixed>>  $plannedSections
     * @return list<array{planned_topic: string, gap_found: bool, assessment: string}>
     */
    private function parseBestPracticeReview(array $decoded, string $pageType, array $plannedSections): array
    {
        $raw = data_get($decoded, 'best_practice_review', []);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $byTopic = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $topic = trim((string) ($entry['planned_topic'] ?? ''));

            if ($topic === '' || array_key_exists($topic, $byTopic)) {
                continue;
            }

            $byTopic[$topic] = [
                'planned_topic' => $topic,
                'gap_found' => (bool) ($entry['gap_found'] ?? false),
                'assessment' => trim((string) ($entry['assessment'] ?? '')),
            ];
        }

        $ordered = [];

        foreach (self::reviewTopics($plannedSections, $pageType) as $topic) {
            if (isset($byTopic[$topic])) {
                $ordered[] = $byTopic[$topic];
                unset($byTopic[$topic]);
            }
        }

        return array_values(array_merge($ordered, array_values($byTopic)));
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
    private function parseSectionRepairResponse(array $decoded, array $requestedPlannedTopics, string $pageType, array $plannedSections = []): array
    {
        $sections = data_get($decoded, 'sections', []);

        if (! is_array($sections) || $sections === []) {
            throw new EnterpriseWikiPlannedSectionRepairShapeException(count($requestedPlannedTopics), 0);
        }

        if (count($sections) !== count($requestedPlannedTopics)) {
            throw new EnterpriseWikiPlannedSectionRepairShapeException(count($requestedPlannedTopics), count($sections));
        }

        $result = [];

        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] was invalid.");
            }

            $plannedTopic = trim((string) ($section['planned_topic'] ?? ''));
            $requestedTopic = $requestedPlannedTopics[$sectionIndex] ?? null;

            if ($requestedTopic === null || $plannedTopic !== $requestedTopic) {
                throw new RuntimeException(
                    "WikiPageContentAiClient: repaired section [{$sectionIndex}] did not match requested planned_topic."
                );
            }

            $blocks = $section['blocks'] ?? [];

            if (! is_array($blocks) || $blocks === []) {
                throw new RuntimeException("WikiPageContentAiClient: repaired section [{$sectionIndex}] had no blocks.");
            }

            $markdownParts = [];
            $assignedKeys = $this->assignedSourceElementKeys($plannedSections, $sectionIndex);

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

                if ($assignedKeys !== [] && array_diff($sourceElementKeys, $assignedKeys) !== []) {
                    throw new RuntimeException("repair_section_unassigned_evidence: repaired section [{$sectionIndex}] cited source evidence outside its assigned planned section.");
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

        $this->logParsedBlockOrigins(
            'section_repair',
            $pageType,
            array_merge(...array_map(static fn (array $section): array => $section['blocks'], $result)),
            count($result),
        );

        return $result;
    }

    /**
     * TEMPORARY (Wiki run-12/13): counts the content_origin the AI itself returned, logged at the
     * only point where that is known — after schema validation, before
     * EnterpriseWikiGenerateAppliedPagesService touches a single block. Run 12 and 13 both
     * persisted zero best_practice blocks, and nothing in the chain records the AI response
     * ('store' => false, and the decoder logs only on rejection), so it is currently impossible to
     * tell whether the model returned none or the pipeline dropped them. This distinguishes the
     * two; remove it once that is settled.
     *
     * Counts only — never markdown, source text, the raw response, or any document content.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function logParsedBlockOrigins(string $operation, string $pageType, array $blocks, ?int $sectionCount = null): void
    {
        $counts = [
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED => 0,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE => 0,
            EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL => 0,
        ];

        foreach ($blocks as $block) {
            $origin = (string) ($block['content_origin'] ?? '');

            if (array_key_exists($origin, $counts)) {
                $counts[$origin]++;
            }
        }

        Log::info('[WIKI_PAGE_CONTENT_ORIGINS] Parsed AI block origins.', array_filter([
            'operation' => $operation,
            'page_type' => $pageType,
            'sections' => $sectionCount,
            'total' => count($blocks),
            'source_based' => $counts[EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED],
            'best_practice' => $counts[EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
            'structural' => $counts[EnterpriseWikiClaim::CONTENT_ORIGIN_STRUCTURAL],
        ], static fn (mixed $value): bool => $value !== null));
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
    private function buildRepairPayload(string $pageType, string $languageName, string $repairPromptText, array $plannedTopics, array $linkCatalog, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->repairDeveloperPrompt($pageType, $languageName, $plannedTopics),
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
                    'schema' => self::repairSectionsSchema($plannedTopics, $linkCatalog),
                ],
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function repairDeveloperPrompt(string $pageType, string $languageName, array $plannedTopics): string
    {
        $allowedPlannedTopics = implode("\n", array_map(
            static fn (string $plannedTopic): string => '- '.$plannedTopic,
            $plannedTopics,
        ));

        return implode("\n", [
            "You are an editorial wiki writer repairing specific missing or empty planned sections of a previously generated {$pageType} page in {$languageName}.",
            '',
            'REPAIR RULES (mandatory):',
            '- Return one entry for EVERY planned section listed below, in the exact order. For status=valid, reproduce the existing section body without changing its meaning; for an invalid status, supply a grounded replacement body.',
            '- Do NOT write the section heading (the "## ..." line) yourself — return only the body content that belongs under that heading. The exact heading text is added separately by the caller, verbatim from the planned topic.',
            '- Return exactly one entry in "sections" per requested planned_topic, in the same order, each with "planned_topic" copied back verbatim and its own "blocks".',
            '- The only valid planned_topic values for this response are the exact allowed topics listed below; do not reword, shorten, translate, merge, or invent any alternative.',
            '',
            'ALLOWED PLANNED TOPICS (exact copy only):',
            $allowedPlannedTopics,
            '- Write a real, substantial paragraph of flowing prose for each section — grounded in the assigned source evidence. Never return an empty section, a section containing only a wikilink, or a bare punctuation mark.',
            '- For planned_section_only_links, replace the link-only body with concise grounded prose supported by the assigned evidence. Existing valid wikilinks may remain inline, but the section must contain substantive prose. Do not use general model knowledge.',
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
            'For a useful Wiki cross-reference, add a link_intent selecting an allowed target_page_id and set anchor_text to the exact words in this block\'s markdown the link should sit on. Write no link syntax at all — no [[...]], no slug, no marker: the server inserts the link on those words.',
            'Write every block as finished agreement text, exactly as the rest of the page: a best_practice block states its clause normatively ("skal ...") and never as advice — no "Procynia anbefaler", "det anbefales", "beste praksis tilsier", or any equivalent advisory opener, and no "fordi ..." justification in the text itself. The justification belongs in best_practice_reason.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function repairUserPrompt(
        string $pageTitle,
        string $existingMarkdown,
        array $issues,
        array $plannedTopics,
        string $sourceText,
        string $additionalContext,
        array $linkCatalog = [],
        array $sourceElements = [],
        array $plannedSections = [],
        array $sectionStatuses = [],
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
            'COMPLETE PLANNED SECTION SET — SECTIONS TO REPAIR (return every planned_topic below, one per entry in exact order — do not include the heading itself):',
            '',
        ];

        $parts[] = 'ALLOWED PLANNED TOPICS FOR THIS PAGE (use these exact values and nothing else):';
        $parts[] = '';

        foreach ($plannedTopics as $plannedTopic) {
            $parts[] = '- '.$plannedTopic;
        }

        $parts[] = '';
        $parts[] = 'The planned_topic in each returned section must match one of the allowed values above exactly.';
        $parts[] = 'Each requested section is mandatory, must be populated individually, must not be merged with any other section, and must contain non-empty body content.';
        $parts[] = '';

        foreach ($issues as $issue) {
            $parts[] = 'REPAIR ISSUE (authoritative):';
            $parts[] = sprintf('  issue_code: %s', (string) ($issue['issue_code'] ?? $issue['type'] ?? 'planned_section_invalid'));
            $parts[] = sprintf('  section_index: %d', (int) ($issue['section_index'] ?? -1));
            $parts[] = sprintf('  planned_topic: %s', (string) ($issue['planned_topic'] ?? ''));
            $parts[] = sprintf('  heading: %s', (string) ($issue['heading'] ?? ''));
            $parts[] = sprintf('  current_invalid_body: %s', (string) ($issue['current_invalid_body'] ?? '(not available)'));
            $parts[] = '  assigned_source_element_keys: '.implode(', ', (array) ($issue['assigned_source_element_keys'] ?? []));
            $parts[] = '  assigned_source_evidence:';
            $parts[] = $this->renderSourceElementsBlock((array) ($issue['assigned_source_evidence'] ?? []), 3600);
            $parts[] = '  repair_instruction: Replace the link-only body with concise grounded prose supported by the assigned evidence. Existing valid wikilinks may remain inline, but the section must contain substantive prose. Do not use general model knowledge.';
            $parts[] = '';
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';

        $parts[] = 'PLANNED SECTION EVIDENCE (authoritative contract):';
        $parts[] = '';
        $parts[] = $plannedSections === []
            ? $this->plannedSectionEvidenceBlock($issues, $sourceElements)
            : $this->plannedSectionsBlock($plannedSections, $sectionStatuses);
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
            $parts[] = 'Choose target_page_id only from this catalog when a visible link is useful. Give the intent an intent_id and an anchor_text copied verbatim from your own markdown for this block; write no link syntax yourself.';
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
        $parts[] = $plannedSections === []
            ? $this->renderSourceElementsBlock($sourceElements)
            : 'The authoritative per-section evidence above is the complete source context for this repair. Cite only source_element_keys assigned to the matching section.';

        return implode("\n", $parts);
    }

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function plannedSectionEvidenceBlock(array $issues, array $sourceElements): string
    {
        $parts = [];

        foreach ($issues as $issue) {
            $plannedTopic = trim((string) ($issue['planned_topic'] ?? ''));
            $plannedTopicLabel = $plannedTopic !== '' ? $plannedTopic : '(empty planned_topic)';
            $matchedSourceElements = $this->matchingSourceElementsForPlannedTopic($plannedTopic, $sourceElements);

            $parts[] = sprintf('- planned_topic: %s', $plannedTopicLabel);
            $parts[] = sprintf('  required_heading: %s', $plannedTopicLabel);
            $parts[] = '  section_purpose: Write the full body for this exact section and keep it non-empty; do not merge it with any other planned topic.';
            $parts[] = '  must_be_non_empty: true';
            $parts[] = sprintf(
                '  source_element_keys: %s',
                $matchedSourceElements !== []
                    ? implode(', ', array_map(static fn (array $element): string => (string) ($element['source_element_key'] ?? ''), $matchedSourceElements))
                    : '(no direct match found; inspect the full SOURCE ELEMENTS block below)'
            );
            $parts[] = '  relevant_source_elements:';
            $parts[] = $matchedSourceElements !== []
                ? $this->renderSourceElementsBlock($matchedSourceElements, 3000)
                : '    (none matched directly — inspect the full SOURCE ELEMENTS block below)';
            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    /** @param list<array<string, mixed>> $plannedSections @param list<array<string, mixed>> $sectionStatuses */
    private function plannedSectionsBlock(array $plannedSections, array $sectionStatuses = []): string
    {
        $statusByTopic = [];
        foreach ($sectionStatuses as $status) {
            if (is_array($status) && trim((string) ($status['planned_topic'] ?? '')) !== '') {
                $statusByTopic[(string) $status['planned_topic']] = (string) ($status['status'] ?? 'valid');
            }
        }

        $parts = [];
        foreach ($plannedSections as $section) {
            $topic = trim((string) ($section['planned_topic'] ?? ''));
            $parts[] = sprintf('- section_index: %d', (int) ($section['section_index'] ?? 0));
            $parts[] = sprintf('  planned_topic: %s', $topic);
            $parts[] = sprintf('  required_heading: %s', (string) ($section['required_heading'] ?? $topic));
            $parts[] = sprintf('  section_purpose: %s', (string) ($section['section_purpose'] ?? 'Explain this exact planned topic.'));
            $parts[] = sprintf('  status: %s', $statusByTopic[$topic] ?? 'required');
            $parts[] = '  required: true';
            $parts[] = '  must_contain_grounded_prose: true';
            $parts[] = '  links_may_be_used_as_inline_references: true';
            $parts[] = '  links_must_not_be_the_only_content: true';
            $parts[] = '  do_not_output_link_only_sections: true';
            $parts[] = '  source_element_keys: '.implode(', ', (array) ($section['source_element_keys'] ?? []));
            $parts[] = '  source_evidence:';
            $parts[] = $this->renderSourceElementsBlock((array) ($section['source_evidence'] ?? []), 3600);
            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    private function matchingSourceElementsForPlannedTopic(string $plannedTopic, array $sourceElements): array
    {
        $plannedTopic = trim($plannedTopic);

        if ($plannedTopic === '' || $sourceElements === []) {
            return [];
        }

        $keywords = $this->plannedTopicKeywords($plannedTopic);
        $scored = [];

        foreach ($sourceElements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                (string) ($element['section_title'] ?? ''),
                (string) ($element['page_reference'] ?? ''),
                (string) ($element['reference_text'] ?? ''),
                (string) ($element['display_text'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '')));

            $score = 0;

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $score++;
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'index' => $index,
                    'element' => $element,
                ];
            }
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, static function (array $left, array $right): int {
            $scoreComparison = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ($left['index'] ?? 0) <=> ($right['index'] ?? 0);
        });

        return array_map(
            static fn (array $item): array => $item['element'],
            array_slice($scored, 0, 6),
        );
    }

    /**
     * @return list<string>
     */
    private function plannedTopicKeywords(string $plannedTopic): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($plannedTopic), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $keywords = array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= 4,
        );

        return array_values(array_unique($keywords));
    }

    /**
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function renderSourceElementsBlock(array $sourceElements, int $maxChars = 6000): string
    {
        return EnterpriseWikiMaintainerDecisionAiClient::sourceElementsBlock($sourceElements, $maxChars);
    }

    /**
     * @param  list<array{type: string, source_element_key: string, required: bool, planned_section: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function buildFigureRepairPayload(string $pageType, string $languageName, string $figureRepairPromptText, array $linkCatalog, int $maxOutputTokens): array
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
                    'schema' => self::schema($linkCatalog, $plannedSections, $pageType),
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
            'For a useful Wiki cross-reference, add a link_intent selecting an allowed target_page_id and set anchor_text to the exact words in this block\'s markdown the link should sit on. Write no link syntax at all — no [[...]], no slug, no marker: the server inserts the link on those words.',
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
            $parts[] = 'Choose target_page_id only from this catalog when a visible link is useful. Give the intent an intent_id and an anchor_text copied verbatim from your own markdown for this block; write no link syntax yourself.';
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

    private function buildPayload(string $pageType, string $languageName, string $generationPromptText, array $plannedSections, array $linkCatalog, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($pageType, $languageName, $plannedSections),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $generationPromptText,
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema($linkCatalog, $plannedSections, $pageType),
                ],
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function developerPrompt(string $pageType, string $languageName, array $plannedSections = []): string
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
            '- CLASSIFICATION IS DECIDED BY ORIGIN, NOT BY TONE: when one of the checks below surfaces a real gap and you write agreement text that closes it, that block is best_practice. It is NOT source_based, because the source document does not state it — you may only mark a block source_based when you can cite the exact source_element_keys it is drawn from. It is NOT structural either, because it carries a real obligation rather than being a heading or a cross-reference. Writing the clause in the same formal register as the rest of the agreement is required (see above) and never turns it into source content.',
            '- A best_practice block must be relevant to this page\'s own owned topics (see PAGE RESPONSIBILITY below) — never add general professional/industry elaboration of the wider subject area just because it is true and related. A thin source document justifies thin SOURCE_BASED content (you can only report what the source actually states); it never justifies skipping the best-practice synthesis below. Resolve doubt on RELEVANCE, not on whether to propose at all: if a well-founded recommendation genuinely concerns one of this page\'s owned topics, include it; if it belongs to another page\'s topic or to the wider subject area, leave it out.',
            '',
            'PROFESSIONAL BEST-PRACTICE SYNTHESIS (work through this deliberately before writing any best_practice content):',
            '- Step 1 — understand the source: establish what the source document actually describes for this page\'s own owned topics, and what it leaves unsaid. Do this before forming any judgment about it.',
            '- Step 2 — identify the subject: name, for yourself, which professional domain, process, or concept this page actually deals with (for example a specific service-management practice, a governance process, a delivery model, or a security or quality discipline). Your reference frame must be the established good practice for THAT subject specifically.',
            '- Step 3 — compare against mature practice: assess whether the source covers what a professionally mature, well-run treatment of that specific subject would normally be expected to cover. Measure the source against that standard instead of only restating it. Work through the checks below for this page\'s owned topics — they are professional triggers, not a quota: some, all, or none may apply to a given page.',
            '- Ownership and responsibility: is it clear who owns, decides, approves and carries out the work, or is responsibility implied, shared without a named owner, or absent?',
            '- Control points: are there defined checkpoints, gates or verification steps, or does the process run end to end with nothing that would catch an error?',
            '- Targets, KPIs and measurement frequency: are there measurable targets, service levels or indicators — and a stated frequency for measuring and reporting them — or is quality asserted with no way to measure it?',
            '- Deviation handling: is it defined what happens when something fails, breaches a limit or falls outside the normal path, or is only the happy path described?',
            '- Decision criteria: are the conditions for a decision (prioritisation, escalation, approval, go/no-go) explicit, or is the decision left to unstated judgment?',
            '- Risk and security: are the relevant risks, safeguards, access limits or continuity measures addressed, or are they missing from a process where they would clearly matter?',
            '- Process boundaries: is it clear where this process starts and ends, what the hand-offs are and how it connects to adjacent processes, or are the interfaces left vague?',
            '- Follow-up and improvement: is there a defined cadence or mechanism for review, improvement and closing the loop, or does the description simply end once the work is delivered?',
            '- Step 4 — contribute where it adds value: when established good practice for this subject would add substantial value beyond what the source states, write a concrete best_practice block for it — the clause itself as finished agreement text, with the reasoning recorded separately in best_practice_reason.',
            '- Keep every recommendation concrete and anchored in what this page actually deals with: name the specific control, target, responsibility or mechanism rather than restating the check in the abstract. A well-founded recommendation may of course also apply to other documents in the same discipline — that is normal for established practice and is never a reason to withhold it. What matters is that it genuinely closes a gap for THIS page.',
            '- Being absent from the source is never a reason to withhold a relevant recommendation — that absence is precisely what makes it a Procynia contribution rather than source_based content. Procynia is expected to add professional value here, and every best_practice block is routed to a human reviewer who approves or rejects it before it counts as accepted, so a well-founded recommendation is safe to put forward.',
            '- No quota, no minimum, no padding: propose only what the comparison in step 3 genuinely supports, and never manufacture a recommendation to fill space or reach a number. Zero best_practice blocks is the correct outcome when the source already treats its subject well — but reach that conclusion by actually performing the comparison, not by defaulting to it.',
            'BEST-PRACTICE REVIEW (best_practice_review — the output that records the comparison above):',
            '- Return exactly one entry per topic listed in the review contract, with planned_topic copied verbatim from the allowed values. The entry IS the assessment; there is no separate "reviewed" flag and no way to leave one out.',
            '- gap_found=false is a legitimate, expected and complete answer. It costs you nothing and is the right one whenever the source already treats that topic adequately. Do not set it true to look thorough.',
            '- gap_found=true means you also wrote at least one concrete best_practice block for that topic in this same response. A gap you cannot state as a concrete clause is not a gap you should report — the backend records the claim as unfounded and reads it back as false.',
            '- assessment: ONE short sentence, required in both cases. For false, say what the source already covers that makes a recommendation unnecessary. For true, say what is missing. Never a generic sentence that would fit any document.',
            '- best_practice_review is review metadata for a human reviewer. It is never rendered as page text, never part of any block, and never a substitute for writing the clause itself.',
            '',
            'link_intents must list only useful visible Wiki links the block should contain; use an empty list when no visible link is useful. For every intent, choose an intent_id and a target_page_id from ALLOWED WIKILINK TARGETS, and set anchor_text to the exact words in this block\'s markdown the link belongs on — copied character for character from what you wrote, and appearing there naturally as ordinary prose. Never write [[...]], a slug, or any link marker yourself: the server inserts the canonical link on those words.',
        ]);

        $responsibilityRules = implode("\n", [
            'PAGE RESPONSIBILITY:',
            '- "Additional context" (if present) states this page\'s own content responsibility in three tiers: what to explain in depth ("own content responsibility"), what to mention only briefly ("Reference only"), and what to never mention ("EXCLUDED").',
            '- Treat every EXCLUDED topic as a hard, binding boundary: do not mention it, allude to it, or give it a section, list, or even one sentence — not even to note that it exists or that it is covered elsewhere.',
            '- For a "Reference only" topic, write AT MOST one short sentence and, when useful, add a link_intent to the page that owns it — never its own heading, paragraph, or list of sub-points. This one-liner is structural (a cross-reference), not best_practice — unless it also happens to state a genuine Procynia recommendation of its own, which the "Reference only" tier does not call for.',
            '- Stay strictly inside this page\'s own content responsibility for everything else. The number of sections this page contains should track directly with the number of items in its own content responsibility — do not add or expand a section to cover something that is not one of those items, even if it is topically related.',
            '- When no such guidance is given at all, fall back to writing only what the source material actually supports for this specific page — a short, thin source document justifies a short, thin page; do not expand into a comprehensive treatment of the wider subject just because more could technically be said about it.',
            '- Never restate the same sentence, fact, or near-identical wording more than once on this page, and never copy wording verbatim from another page\'s content given as context — express a shared idea once, in your own words, in the place it actually belongs.',
        ]);

        if ($plannedSections !== []) {
            $responsibilityRules .= "\n\nPLANNED SECTION OUTPUT CONTRACT:\n"
                .'- The authoritative user contract lists every required section with section_index, planned_topic, required_heading, and assigned source evidence.\n'
                .'- Generate exactly one substantial ## section for every contract entry, in section_index order. Copy required_heading verbatim.\n'
                .'- A section body must be grounded in that section\'s assigned source_element_keys. Do not place evidence for one planned section under another.\n'
                .'- Every required body must_contain_grounded_prose = true. Wikilinks may be used as inline references, but links_must_not_be_the_only_content and do_not_output_link_only_sections.\n'
                .'- Do not omit, merge, replace with a wikilink, or leave any required section empty. Runtime validation rejects every one of those outcomes.';
        }

        $reviewTopics = self::reviewTopics($plannedSections, $pageType);
        $responsibilityRules .= "\n\nBEST-PRACTICE REVIEW CONTRACT:\n"
            .($reviewTopics === [self::REVIEW_TOPIC_WHOLE_PAGE]
                ? '- This page is not section-mapped, so return exactly ONE best_practice_review entry with planned_topic "'
                    .self::REVIEW_TOPIC_WHOLE_PAGE.'", assessing the page as a whole.'
                : "- Return one best_practice_review entry for each of these topics, planned_topic copied verbatim:\n"
                    .implode("\n", array_map(static fn (string $topic): string => '  - '.$topic, $reviewTopics)));

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
            'INLINE WIKILINK INTENTS:',
            '- content_markdown is wiki content — identify useful visible cross-references through link_intents, not through [[...]] markup.',
            '- For each useful link, add one link_intent with a unique intent_id, the exact target_page_id from the "ALLOWED WIKILINK TARGETS" list, an anchor_text copied verbatim from this block\'s markdown, and a concise reason. The markdown itself contains no link syntax — write the sentence naturally and name the words to link in anchor_text.',
            '- Never write a target slug, [[...]] markup, or a page identifier not listed in the allowed target list. The server owns target identity, canonical slug, and link syntax.',
            '- Select the first or most natural occurrence of a concept or entity for the marker — do not repeat the same link for every mention.',
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

    private function userPrompt(string $pageTitle, string $sourceText, string $additionalContext, array $linkCatalog = [], array $sourceElements = [], array $plannedSections = []): string
    {
        $parts = [
            "Page title: {$pageTitle}",
            '',
        ];

        if ($plannedSections === []) {
            $parts[] = 'Source document:';
            $parts[] = '';
            $parts[] = $sourceText;
        } else {
            $parts[] = 'AUTHORITATIVE PLANNED SECTION CONTRACT:';
            $parts[] = '';
            $parts[] = $this->plannedSectionsBlock($plannedSections);
            $parts[] = '';
            $parts[] = 'Return one populated section for every contract entry, in section_index order. Each section must use its exact required_heading and only its assigned source evidence. Every section must contain grounded prose; links may be inline references but can never be the only content. Do not merge sections, omit a section, or return a link-only or whitespace-only body.';
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
            $parts[] = 'Choose target_page_id only from this catalog when a visible link is useful. Keep its natural anchor in markdown and return it in link_intents; do not write [[...]] markup or a slug.';
            $parts[] = '';
            $parts[] = 'Full catalog (page_id, title, canonical slug, page_type):';
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
        $parts[] = $plannedSections === []
            ? (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'The authoritative per-section evidence above is the complete source context for this planned generation. Cite only source_element_keys assigned to the matching section.';

        return implode("\n", $parts);
    }

    /**
     * The per-block item schema shared by schema() (full-page generation) and
     * repairSectionsSchema() (Wiki run-593: section-only repair) — kept as one definition so the
     * two request shapes can never silently drift apart on what a "block" is.
     */
    private static function blockItemSchema(array $linkCatalog = []): array
    {
        $allowedTargetPageIds = array_values(array_map(static fn (array $entry): int => (int) $entry['page_id'], $linkCatalog));
        $targetPageIdSchema = ['type' => 'integer'];
        if ($allowedTargetPageIds !== []) {
            $targetPageIdSchema['enum'] = $allowedTargetPageIds;
        }

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
                            'intent_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$'],
                            'target_page_id' => $targetPageIdSchema,
                            // The visible words the link is placed on, quoted verbatim from this
                            // block's own markdown. Run 59 failed because the anchor used to live in
                            // a delimited marker the model had to write into free text
                            // ({{wiki_link:id|anchor}}): an anchor containing a pipe or a brace, or
                            // an intent id carrying a Norwegian letter, produced a token the backend
                            // could not parse and the whole page — and with it the run — failed. A
                            // structured field cannot be malformed; the backend owns the syntax.
                            'anchor_text' => ['type' => 'string', 'minLength' => 1],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['intent_id', 'target_page_id', 'anchor_text', 'reason'],
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

    private static function schema(array $linkCatalog = [], array $plannedSections = [], string $pageType = ''): array
    {
        $reviewTopics = self::reviewTopics($plannedSections, $pageType);

        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'object',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => self::blockItemSchema($linkCatalog),
                        ],
                    ],
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                ],
                'best_practice_review' => self::bestPracticeReviewSchema($reviewTopics),
            ],
            'required' => ['page', 'best_practice_review'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The topics this page must report a best-practice assessment for.
     *
     * article/concept/entity map owned topics onto `## ` sections, so the unit is the section.
     * A summary page is explicitly instructed to have no headings at all
     * (EnterpriseWikiPlannedSectionCoverageValidator::CHECKED_PAGE_TYPES leaves it out), so it
     * reports once for the page. A page generated without a planned-section contract at all — the
     * legacy path — reports once for the page too, under the same key.
     *
     * @param  list<array<string, mixed>>  $plannedSections
     * @return list<string>
     */
    public static function reviewTopics(array $plannedSections, string $pageType): array
    {
        if ($pageType === EnterpriseWikiPage::PAGE_TYPE_SUMMARY) {
            return [self::REVIEW_TOPIC_WHOLE_PAGE];
        }

        $topics = [];

        foreach ($plannedSections as $section) {
            $topic = trim((string) ($section['planned_topic'] ?? ''));

            if ($topic !== '' && ! in_array($topic, $topics, true)) {
                $topics[] = $topic;
            }
        }

        return $topics !== [] ? $topics : [self::REVIEW_TOPIC_WHOLE_PAGE];
    }

    /**
     * The observable best-practice contract (Wiki runs 56–58: 0, 0 and 1 best_practice blocks
     * across 21 generated pages, with the AI-side counters proving the model returned none and
     * nothing downstream dropped any).
     *
     * Everything else in this response is structurally required and deterministically verified —
     * sections against planned topics, grounding against element keys, figures against figure keys,
     * links against the catalog. The best-practice synthesis was the one part that was instructed
     * and nothing more, so "assessed, nothing to add" and "never assessed" were indistinguishable
     * in the data. This makes the assessment itself an output.
     *
     * Deliberately NOT a "reviewed" flag: the entry's existence is the assessment, and strict
     * cardinality (one entry per topic, enum-constrained) makes a missing one impossible at the API
     * boundary rather than something a validator has to catch after the fact.
     *
     * Deliberately NOT a reason to find something: gap_found=false with a real one-sentence
     * assessment is a complete, expected answer. The threshold for what qualifies as best practice
     * is unchanged.
     *
     * @param  list<string>  $reviewTopics
     */
    private static function bestPracticeReviewSchema(array $reviewTopics): array
    {
        return [
            'type' => 'array',
            'minItems' => count($reviewTopics),
            'maxItems' => count($reviewTopics),
            'items' => [
                'type' => 'object',
                'properties' => [
                    'planned_topic' => ['type' => 'string', 'enum' => array_values($reviewTopics)],
                    'gap_found' => ['type' => 'boolean'],
                    'assessment' => ['type' => 'string'],
                ],
                'required' => ['planned_topic', 'gap_found', 'assessment'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Wiki run-593: one entry per requested planned_topic — "blocks" is body content only (no
     * heading), the exact same per-block shape schema() uses. The caller
     * (EnterpriseWikiGenerateAppliedPagesService) prepends the literal planned_topic text as the
     * `## ` heading itself; the model is never asked to produce a heading at all.
     */
    private static function repairSectionsSchema(array $plannedTopics, array $linkCatalog = []): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'minItems' => count($plannedTopics),
                    'maxItems' => count($plannedTopics),
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'planned_topic' => [
                                'type' => 'string',
                                'enum' => array_values($plannedTopics),
                            ],
                            'blocks' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => self::blockItemSchema($linkCatalog),
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

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @return list<string>
     */
    private function requestedPlannedTopics(array $issues): array
    {
        $plannedTopics = [];

        foreach ($issues as $issue) {
            $plannedTopic = trim((string) ($issue['planned_topic'] ?? ''));

            if ($plannedTopic === '') {
                throw new RuntimeException('WikiPageContentAiClient: repair issue had an empty planned_topic.');
            }

            $plannedTopics[] = $plannedTopic;
        }

        return $plannedTopics;
    }

    /** @param list<array<string, mixed>> $plannedSections @return list<string> */
    private function plannedTopicsFromContract(array $plannedSections): array
    {
        $topics = [];

        foreach ($plannedSections as $section) {
            $topic = trim((string) ($section['planned_topic'] ?? ''));

            if ($topic === '') {
                throw new RuntimeException('WikiPageContentAiClient: planned section contract had an empty planned_topic.');
            }

            $topics[] = $topic;
        }

        return $topics;
    }

    /** @param list<array<string, mixed>> $plannedSections @return list<string> */
    private function assignedSourceElementKeys(array $plannedSections, int $sectionIndex): array
    {
        if ($plannedSections === []) {
            return [];
        }

        return array_values(array_unique(array_filter(
            (array) ($plannedSections[$sectionIndex]['source_element_keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        )));
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
