<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiFigureMaterializationException;
use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Exceptions\EnterpriseWikiPageGenerationIncompleteException;
use App\Exceptions\EnterpriseWikiPatchTargetRegenerationBlockedException;
use App\Exceptions\EnterpriseWikiPlannedSectionEvidenceMissingException;
use App\Exceptions\EnterpriseWikiPlannedSectionRepairShapeException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates content_markdown and EnterpriseWikiPageVersion records for all four wiki page types
 * (article, summary, concept, entity) linked to an applied maintainer decision run.
 *
 * Processing order: article, summary, and independently-scoped concept pages first; concept
 * pages without explicit owned_topics, and entity pages, run afterward so article/summary
 * content is available as context when they need it.
 * Idempotent: pages that already have any version record are skipped.
 * Does not touch claims, source references, or ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiGenerateAppliedPagesService
{
    private const ARTICLE_SUMMARY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
    ];

    private const CONCEPT_ENTITY_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    /** Page types whose prompt maps owned_topics onto `## ` sections — see WikiPageContentAiClient. */
    private const SECTION_COVERAGE_CHECKED_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    /**
     * Execution-lease duration for a page-generation reservation (generation_claimed_at) —
     * comfortably above GenerateEnterpriseWikiAppliedPage::timeout (420s) so a live worker mid-AI
     * call is never mistaken for a crashed one, mirroring
     * EnterpriseWikiVerifyPageClaimsService::LEASE_SECONDS' rationale.
     */
    private const GENERATION_LEASE_SECONDS = 420 + 300;

    /**
     * How long a page may sit dispatched-but-not-yet-leased (generation_status=dispatched) before
     * a redispatch attempt is allowed to treat it as lost rather than merely queued — see
     * EnterpriseWikiVerifyPageClaimsService::DISPATCH_STALE_SECONDS for the same rationale.
     */
    private const DISPATCH_STALE_SECONDS = 90;

    public function __construct(
        private readonly WikiPageContentAiClient $aiClient,
        private readonly EnterpriseWikiLinkCatalogService $linkCatalogService,
        private readonly EnterpriseWikiLinkIntentMaterializer $linkIntentMaterializer,
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiWikilinkCanonicalizer $wikilinkCanonicalizer,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
        private readonly EnterpriseWikiArticleSummaryLinkService $articleSummaryLinkService,
        private readonly EnterpriseWikiTableBlockBuilder $tableBlockBuilder,
        private readonly EnterpriseWikiImageBlockBuilder $imageBlockBuilder,
        private readonly EnterpriseWikiDuplicateContentRemover $duplicateContentRemover,
        private readonly EnterpriseWikiPlannedSectionCoverageValidator $sectionCoverageValidator,
        private readonly EnterpriseWikiPlannedSectionEvidenceResolver $plannedSectionEvidenceResolver,
        private readonly EnterpriseWikiPlannedFigureCoverageValidator $figureCoverageValidator,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
        private readonly EnterpriseWikiBestPracticeReviewReconciler $reviewReconciler,
    ) {}

    /**
     * Appends a genuine, deterministic "table" block (never AI-authored) for each Word table
     * whose rows the AI-generated blocks actually cited — only for article/summary pages, the
     * "ordinary Wiki articles" a table is meant to appear in (concept/entity pages are abstract
     * synthesis, not the table's home). See EnterpriseWikiTableBlockBuilder for why attachment is
     * keyed off citation rather than "every table in the document".
     *
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array{0: string, 1: list<array<string, mixed>>} [markdown, contentBlocks] with any
     *                                                         table blocks appended
     */
    private function appendTableBlocksIfRelevant(EnterpriseWikiDocument $document, EnterpriseWikiPage $page, string $markdown, array $contentBlocks): array
    {
        if (! in_array($page->page_type, self::ARTICLE_SUMMARY_TYPES, true)) {
            return [$markdown, $contentBlocks];
        }

        $tableIndexes = $this->tableBlockBuilder->referencedTableIndexes($contentBlocks);

        if ($tableIndexes === []) {
            return [$markdown, $contentBlocks];
        }

        $tables = $this->sourceElementService->tablesForDocument($document);
        $tableBlocks = $this->tableBlockBuilder->buildTableBlocks($document, $tables, $tableIndexes, count($contentBlocks));

        if ($tableBlocks === []) {
            return [$markdown, $contentBlocks];
        }

        $contentBlocks = [...$contentBlocks, ...$tableBlocks];
        $markdown = trim($markdown."\n\n".implode("\n\n", array_column($tableBlocks, 'markdown')));

        return [$markdown, $contentBlocks];
    }

    /**
     * Appends a genuine, deterministic "image" figure block (never AI-authored/interpreted) for
     * each Word image whose citable source element the AI-generated blocks actually referenced —
     * mirroring appendTableBlocksIfRelevant() (see EnterpriseWikiImageBlockBuilder for why
     * attachment is keyed off citation rather than "every image in the document").
     *
     * Wiki run-587: article/summary pages keep the pre-existing, unrestricted behavior (any cited
     * image is materialized, for full backward compatibility with documents predating figure
     * planning). Concept/entity pages are abstract synthesis, not a figure's default home — an
     * image is only materialized there when THIS page's own maintainer-decision planned_figures
     * explicitly assigned it here; a concept/entity page citing an image the maintainer decision
     * never planned onto it is silently dropped rather than materialized.
     *
     * Placement is deterministic (see placeImageBlocksMarkdown()): a planned figure is inserted
     * under its planned `## ` section (fuzzy-matched) or right after the page introduction when no
     * section was given; only an image with no planned_figures entry at all (a legacy/unplanned
     * citation) keeps the old "always appended at the very end" placement.
     *
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array{0: string, 1: list<array<string, mixed>>} [markdown, contentBlocks] with any
     *                                                         image blocks appended
     */
    private function appendImageBlocksIfRelevant(EnterpriseWikiIngestRun $run, EnterpriseWikiDocument $document, EnterpriseWikiPage $page, string $markdown, array $contentBlocks): array
    {
        $plannedFigures = $this->plannedFiguresForPage($run, $page);
        $citedIndexes = $this->imageBlockBuilder->referencedImageIndexes($contentBlocks);
        $imageIndexes = $citedIndexes;
        $skippedNotPlanned = [];

        if (! in_array($page->page_type, self::ARTICLE_SUMMARY_TYPES, true)) {
            $plannedKeys = array_column($plannedFigures, 'source_element_key');

            foreach ($citedIndexes as $index) {
                if (! in_array(sprintf('img%d', $index), $plannedKeys, true)) {
                    $skippedNotPlanned[] = sprintf('img%d', $index);
                }
            }

            $imageIndexes = array_values(array_filter(
                $citedIndexes,
                fn (int $index): bool => in_array(sprintf('img%d', $index), $plannedKeys, true),
            ));
        }

        // A PLANNED figure is materialized whether or not the model happened to cite it. The plan
        // is a backend decision about a real, extracted source element (validated at decision time),
        // so making its materialization depend on the model mentioning it in prose means an
        // ordinary omission silently drops a figure the plan promised — run 54, page 193: img1 was
        // planned onto the page, never cited, never materialized, and only QA at the end of the run
        // noticed. Citation still governs UNPLANNED images exactly as before.
        $imageIndexes = array_values(array_unique(array_merge(
            $imageIndexes,
            $this->plannedImageIndexes($plannedFigures),
        )));
        sort($imageIndexes);

        $imageBlocks = [];

        if ($imageIndexes !== []) {
            $images = $this->sourceElementService->imagesForDocument($document);
            $imageBlocks = $this->imageBlockBuilder->buildImageBlocks($document, $images, $imageIndexes, count($contentBlocks));

            if ($imageBlocks !== []) {
                $contentBlocks = [...$contentBlocks, ...$imageBlocks];
                $markdown = $this->placeImageBlocksMarkdown($markdown, $imageBlocks, $plannedFigures);
            }
        }

        if ($plannedFigures !== []) {
            $this->logPlannedFigureMaterialization($run, $page, $plannedFigures, $imageBlocks, $skippedNotPlanned);
        }

        return [$markdown, $contentBlocks];
    }

    /**
     * The image indexes this page's own planned_figures name — `img{N}` is the extraction's own
     * stable key shape (EnterpriseWikiDocumentSourceElementService), so the mapping is exact and
     * never guessed. A key that is not of that shape is left to the decision-time validators, which
     * already reject a planned figure that no extracted figure matches.
     *
     * @param  list<array<string, mixed>>  $plannedFigures
     * @return list<int>
     */
    private function plannedImageIndexes(array $plannedFigures): array
    {
        $indexes = [];

        foreach ($plannedFigures as $figure) {
            $key = trim((string) ($figure['source_element_key'] ?? ''));

            if (preg_match('/^img(\d+)$/', $key, $matches) === 1) {
                $indexes[] = (int) $matches[1];
            }
        }

        return array_values(array_unique($indexes));
    }

    /**
     * Observability (Wiki run-587 OBSERVABILITY requirement): structured, per-page log of what the
     * maintainer decision planned vs. what actually got materialized — run_id, page_id, page_type,
     * planned/required counts, the planned source_element_keys, how many were materialized vs.
     * skipped, and a human-readable skip reason per skipped figure. Never logs raw image bytes, the
     * full prompt, or the full AI response — only the same small metadata fields already present in
     * maintainer_decision_json's planned_figures.
     *
     * @param  list<array<string, mixed>>  $plannedFigures
     * @param  list<array<string, mixed>>  $imageBlocks  Blocks actually materialized this call.
     * @param  list<string>  $skippedNotPlanned  Cited by the AI but excluded by the concept/entity
     *                                           planned-figures gate (appendImageBlocksIfRelevant()'s own filter) — reported for
     *                                           visibility even though these were never planned onto this page in the first place.
     */
    private function logPlannedFigureMaterialization(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array $plannedFigures,
        array $imageBlocks,
        array $skippedNotPlanned,
    ): void {
        $plannedKeys = array_column($plannedFigures, 'source_element_key');
        $materializedKeys = array_column($imageBlocks, 'source_element_key');
        $notMaterialized = array_values(array_diff($plannedKeys, $materializedKeys));

        $skipReasons = array_merge(
            array_map(fn (string $key): string => "{$key}: planned but not cited/materialized on this page", $notMaterialized),
            array_map(fn (string $key): string => "{$key}: cited by the AI but not planned onto this concept/entity page", $skippedNotPlanned),
        );

        $selectedPlacements = array_map(fn (array $figure): array => [
            'source_element_key' => $figure['source_element_key'] ?? '',
            'section' => trim((string) ($figure['section_placement'] ?? '')) !== '' ? $figure['section_placement'] : 'after introduction',
        ], $plannedFigures);

        Log::info('[WIKI_PAGE_GENERATION] Planned figure materialization.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'planned_figure_count' => count($plannedFigures),
            'required_figure_count' => count(array_filter($plannedFigures, fn (array $f): bool => (bool) ($f['required'] ?? false))),
            'source_element_keys' => $plannedKeys,
            'selected_placements' => $selectedPlacements,
            'materialized_count' => count($imageBlocks),
            'materialized_source_element_keys' => $materializedKeys,
            'skipped_count' => count($notMaterialized) + count($skippedNotPlanned),
            'skip_reasons' => $skipReasons,
        ]);
    }

    /**
     * Deterministic figure placement (Wiki run-587 PLASSERING requirement): a planned figure's
     * markdown is inserted directly under its planned section's `## ` heading (fuzzy-matched, same
     * normalization convention as EnterpriseWikiPlannedFigureCoverageValidator), or right after the
     * page introduction when the figure was planned with no section_placement. A figure with no
     * planned_figures entry at all (legacy/unplanned citation) keeps the pre-existing "always
     * appended at the end" placement — this only changes placement for EXPLICITLY planned figures.
     *
     * Falls back to appending at the end when a section was named but does not actually exist in
     * this markdown; EnterpriseWikiPlannedFigureCoverageValidator's planned_figure_wrong_section
     * check then catches a REQUIRED figure left in that state and triggers the bounded repair, so
     * this fallback never silently hides a required figure without a QA-visible finding.
     *
     * @param  list<array<string, mixed>>  $imageBlocks
     * @param  list<array<string, mixed>>  $plannedFigures
     */
    private function placeImageBlocksMarkdown(string $markdown, array $imageBlocks, array $plannedFigures): string
    {
        $plannedByKey = [];

        foreach ($plannedFigures as $figure) {
            $key = (string) ($figure['source_element_key'] ?? '');

            if ($key !== '') {
                $plannedByKey[$key] = $figure;
            }
        }

        $appendAtEnd = [];

        foreach ($imageBlocks as $imageBlock) {
            $key = (string) ($imageBlock['source_element_key'] ?? '');
            $figure = $plannedByKey[$key] ?? null;

            if ($figure === null) {
                $appendAtEnd[] = $imageBlock['markdown'];

                continue;
            }

            $section = trim((string) ($figure['section_placement'] ?? ''));

            $inserted = $section !== ''
                ? $this->insertMarkdownUnderHeading($markdown, $section, $imageBlock['markdown'])
                : $this->insertMarkdownAfterIntroduction($markdown, $imageBlock['markdown']);

            if ($inserted !== null) {
                $markdown = $inserted;

                continue;
            }

            $appendAtEnd[] = $imageBlock['markdown'];
        }

        if ($appendAtEnd !== []) {
            $markdown = trim($markdown."\n\n".implode("\n\n", $appendAtEnd));
        }

        return trim($markdown);
    }

    /**
     * Inserts $blockMarkdown directly under the first `## ` heading whose text fuzzy-matches
     * $sectionPlacement, right before the next `#`/`##` heading (or at the end of the document when
     * the matched section is the last one). Returns null when no heading matches at all.
     */
    private function insertMarkdownUnderHeading(string $markdown, string $sectionPlacement, string $blockMarkdown): ?string
    {
        $normalizedTarget = $this->normalizeHeadingText($sectionPlacement);
        $lines = preg_split('/\R/', $markdown) ?: [];
        $headingIndex = null;
        $nextBoundaryIndex = null;

        foreach ($lines as $i => $line) {
            if ($headingIndex === null && preg_match('/^##\s+(.+?)\s*$/', $line, $matches) === 1) {
                $normalizedHeading = $this->normalizeHeadingText($matches[1]);

                if ($normalizedHeading !== '' && (str_contains($normalizedTarget, $normalizedHeading) || str_contains($normalizedHeading, $normalizedTarget))) {
                    $headingIndex = $i;
                }

                continue;
            }

            if ($headingIndex !== null && preg_match('/^#{1,2}\s+/', $line) === 1) {
                $nextBoundaryIndex = $i;

                break;
            }
        }

        if ($headingIndex === null) {
            return null;
        }

        $insertAt = $nextBoundaryIndex ?? count($lines);

        return $this->spliceMarkdownAt($lines, $insertAt, $blockMarkdown);
    }

    /**
     * Inserts $blockMarkdown right after the page's introduction — i.e. right before the second
     * heading (`#` or `##`) found in the document, skipping the page's own H1 title. When there is
     * no second heading (a very short page, or one with only its title), the block is appended at
     * the end, which for a heading-less document is exactly the same place appendAtEnd would put it.
     */
    private function insertMarkdownAfterIntroduction(string $markdown, string $blockMarkdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $boundaryIndex = null;

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue; // the page's own H1 title is never itself a boundary
            }

            if (preg_match('/^#{1,2}\s+/', $line) === 1) {
                $boundaryIndex = $i;

                break;
            }
        }

        return $this->spliceMarkdownAt($lines, $boundaryIndex ?? count($lines), $blockMarkdown);
    }

    /** @param  list<string>  $lines */
    private function spliceMarkdownAt(array $lines, int $insertAt, string $blockMarkdown): string
    {
        $before = array_slice($lines, 0, $insertAt);

        while ($before !== [] && trim((string) end($before)) === '') {
            array_pop($before);
        }

        $after = array_slice($lines, $insertAt);

        return trim(implode("\n", array_merge($before, ['', $blockMarkdown, ''], $after)));
    }

    private function normalizeHeadingText(string $text): string
    {
        $withoutParens = preg_replace('/\([^)]*\)/', '', $text) ?? $text;
        $lower = mb_strtolower($withoutParens);
        $lettersOnly = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $lettersOnly) ?? $lettersOnly);
    }

    /**
     * @return array{article: int, summary: int, concept: int, entity: int, skipped: int}
     *
     * @throws InvalidArgumentException if the run is not in a state that permits generation
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generate(EnterpriseWikiIngestRun $run): array
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return ['article' => 0, 'summary' => 0, 'concept' => 0, 'entity' => 0, 'skipped' => 0];
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have pages generated."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] source_type is not enterprise_wiki_document."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new InvalidArgumentException(
                "Source document [{$run->source_id}] not found for customer [{$run->customer_id}]."
            );
        }

        $sourceText = (string) ($document->extracted_text ?? '');
        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $counts = [
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 0,
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => 0,
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT => 0,
            EnterpriseWikiPage::PAGE_TYPE_ENTITY => 0,
        ];
        $skipped = 0;
        $articleSummaryPageIds = [];

        // --- Pass 1: article and summary ---
        // Article sorted before summary (stable otherwise) so that, when a summary page is
        // generated in the same call, buildArticleSummaryContextForRun() can already read the
        // article's just-written version instead of falling back to the raw source document.
        $articleFirstPivotRows = $pivotRows->sortBy(
            fn (EnterpriseWikiIngestRunPage $row): int => $row->page?->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE ? 0 : 1,
        )->values();

        foreach ($articleFirstPivotRows as $row) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $page = $row->page;

            if ($page === null || ! in_array($page->page_type, self::ARTICLE_SUMMARY_TYPES, true)) {
                continue;
            }

            $articleSummaryPageIds[] = $page->id;

            if ($this->pageHasVersion($page->id)) {
                $skipped++;

                continue;
            }

            $sourceElements = $this->contentBlockService->sourceElementsForGeneration(
                $document,
                $this->sourceElementService->inspect($document)['elements'],
            );
            $catalogResult = $this->linkCatalogService->buildForPage($run, $page);
            $plannedSections = $this->plannedSectionContractForGeneration($run, $page, $sourceElements);

            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $generated = $this->aiClient->generatePageFromSource(
                pageTitle: $page->title,
                pageType: $page->page_type,
                sourceText: $sourceText,
                languageCode: $languageCode,
                additionalContext: $this->buildArticleSummaryContextForRun($run, $page),
                linkCatalog: $catalogResult['catalog'],
                sourceElements: $sourceElements,
                plannedSections: $plannedSections,
            );

            $generated['blocks'] = $this->linkIntentMaterializer->materializeBlocks($run, $page, $generated['blocks'], $catalogResult['catalog']);
            $generated['blocks'] = array_map(function (array $block) use ($catalogResult): array {
                $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

                return $block;
            }, $generated['blocks']);
            $generated['blocks'] = $this->duplicateContentRemover->removeVerbatimDuplicates($generated['blocks']);
            $generated['markdown'] = trim(implode("\n\n", array_column($generated['blocks'], 'markdown')));
            $this->validateWikilinks($run, $page, $generated['markdown'], $catalogResult['run_page_count']);

            if (in_array($page->page_type, self::SECTION_COVERAGE_CHECKED_TYPES, true)) {
                [$generated['markdown'], $generated['blocks']] = $this->ensurePlannedSectionCoverage(
                    $run,
                    $page,
                    $generated['markdown'],
                    $generated['blocks'],
                    $sourceText,
                    $languageCode,
                    $this->buildArticleSummaryContextForRun($run, $page),
                    $catalogResult,
                    $sourceElements,
                    $plannedSections,
                );
            }

            $contentBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
                $document,
                $generated['blocks'],
                $sourceElements,
            );

            [$markdown, $contentBlocks] = $this->appendTableBlocksIfRelevant($document, $page, $generated['markdown'], $contentBlocks);
            [$markdown, $contentBlocks] = $this->appendImageBlocksIfRelevant($run, $document, $page, $markdown, $contentBlocks);
            [$markdown, $contentBlocks] = $this->appendMutualLinkIfPaired($run, $page, $markdown, $contentBlocks, $languageCode);

            $this->writeVersion($run->id, $page->id, $markdown, $contentBlocks, $this->reviewReconciler->reconcile(
                $generated['best_practice_review'] ?? [],
                $contentBlocks,
                (string) $page->page_type,
            ));
            $counts[$page->page_type]++;
        }

        // Load article/summary content to use as context in concept/entity generation
        $sharedContext = $this->loadSharedContext($articleSummaryPageIds);

        // --- Pass 2: concept and entity ---
        foreach ($pivotRows as $row) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $page = $row->page;

            if ($page === null || ! in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)) {
                continue;
            }

            if ($this->pageHasVersion($page->id)) {
                $skipped++;

                continue;
            }

            $additionalContext = $this->buildConceptEntityContext($page, $decisionJson, $sharedContext);

            $sourceElements = $this->contentBlockService->sourceElementsForGeneration(
                $document,
                $this->sourceElementService->inspect($document)['elements'],
            );
            $catalogResult = $this->linkCatalogService->buildForPage($run, $page);
            $plannedSections = $this->plannedSectionContractForGeneration($run, $page, $sourceElements);

            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $generated = $this->aiClient->generatePageFromSource(
                pageTitle: $page->title,
                pageType: $page->page_type,
                sourceText: $sourceText,
                languageCode: $languageCode,
                additionalContext: $additionalContext,
                linkCatalog: $catalogResult['catalog'],
                sourceElements: $sourceElements,
                plannedSections: $plannedSections,
            );

            $generated['blocks'] = $this->linkIntentMaterializer->materializeBlocks($run, $page, $generated['blocks'], $catalogResult['catalog']);
            $generated['blocks'] = array_map(function (array $block) use ($catalogResult): array {
                $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

                return $block;
            }, $generated['blocks']);
            $generated['blocks'] = $this->duplicateContentRemover->removeVerbatimDuplicates($generated['blocks']);
            $generated['markdown'] = trim(implode("\n\n", array_column($generated['blocks'], 'markdown')));
            $this->validateWikilinks($run, $page, $generated['markdown'], $catalogResult['run_page_count']);

            if (in_array($page->page_type, self::SECTION_COVERAGE_CHECKED_TYPES, true)) {
                [$generated['markdown'], $generated['blocks']] = $this->ensurePlannedSectionCoverage(
                    $run,
                    $page,
                    $generated['markdown'],
                    $generated['blocks'],
                    $sourceText,
                    $languageCode,
                    $additionalContext,
                    $catalogResult,
                    $sourceElements,
                    $plannedSections,
                );
            }

            $conceptBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
                $document,
                $generated['blocks'],
                $sourceElements,
            );

            $this->writeVersion($run->id, $page->id, $generated['markdown'], $conceptBlocks, $this->reviewReconciler->reconcile(
                $generated['best_practice_review'] ?? [],
                $conceptBlocks,
                (string) $page->page_type,
            ));
            $counts[$page->page_type]++;
        }

        return array_merge($counts, ['skipped' => $skipped]);
    }

    /**
     * Generate content_markdown and an EnterpriseWikiPageVersion for a single applied page
     * belonging to a run, without touching any other page.
     *
     * Idempotent per run/page pair via the enterprise_wiki_ingest_run_pages pivot: once
     * generated_page_version_id is set for this run/page, subsequent calls are a no-op. This
     * intentionally differs from generate()'s pageHasVersion() check (any version, any run) so
     * that a new run can regenerate a page even though an older run already produced a version.
     *
     * Execution lease: before calling AI, this claims the pivot with a fresh
     * generation_claim_token (generation_status -> running), the same compare-and-swap shape as
     * EnterpriseWikiVerifyPageClaimsService's claim lease. A pivot already running with a live,
     * non-stale lease belongs to another worker genuinely in flight right now — this call
     * silently no-ops before the AI call. Persisting the result re-checks the token is still
     * owned by this worker (not reclaimed as stale by someone else in the meantime) before
     * writing anything, exactly like EnterpriseWikiVerifyPageClaimsService::persist().
     *
     * @throws InvalidArgumentException if the run is not in a state that permits generation,
     *                                  or the page is not linked to the run
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generatePageForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return;
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have pages generated."
            );
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] source_type is not enterprise_wiki_document."
            );
        }

        // Fase 8K-2 destructive-update guard — BEFORE the lease is claimed and before any AI call,
        // so a blocked page is never marked running and no content is ever produced for it. This
        // method regenerates a page from the new source document alone; for a page the decision
        // patches, that is a full-page rewrite that discards exactly what the patch preserves.
        // Structurally unreachable in the normal flow (a patch target gets no pivot row, so no job
        // is dispatched) — this is the backstop for a pivot that exists anyway.
        $this->guardAgainstPatchTargetRegeneration($run, $page);

        $token = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($run, $page, $token): ?EnterpriseWikiIngestRunPage {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return null;
            }

            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw new InvalidArgumentException(
                    "Page [{$page->id}] is not linked to run [{$run->id}]."
                );
            }

            if ($pivot->generated_page_version_id !== null) {
                return null;
            }

            // Execution lease: only a pivot that is pending/dispatched (never leased, or a fresh
            // reset by EnterpriseWikiPageGenerationFailureRecoveryService), or whose previous
            // lease has gone stale (a genuinely crashed worker), may be claimed here. A pivot
            // still generation_status=running with a live, non-stale generation_claimed_at is
            // owned by another worker actually in flight right now — this invocation is a stale
            // or duplicate job and must no-op before ever calling AI.
            $leaseStaleThreshold = now()->subSeconds(self::GENERATION_LEASE_SECONDS);
            $eligible = in_array($pivot->generation_status, [
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING,
                EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED,
            ], true) || (
                $pivot->generation_status === EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING
                && ($pivot->generation_claimed_at === null || $pivot->generation_claimed_at->lt($leaseStaleThreshold))
            );

            if (! $eligible) {
                return null;
            }

            $pivot->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
                'generation_claimed_at' => now(),
                'generation_claim_token' => $token,
                'generation_started_at' => now(),
                'generation_error' => null,
            ]);

            return $pivot;
        });

        if ($claimed === null) {
            return;
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new InvalidArgumentException(
                "Source document [{$run->source_id}] not found for customer [{$run->customer_id}]."
            );
        }

        $sourceText = (string) ($document->extracted_text ?? '');
        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $additionalContext = in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)
            ? $this->buildConceptEntityContextForRun($run, $page)
            : $this->buildArticleSummaryContextForRun($run, $page);

        $catalogResult = $this->linkCatalogService->buildForPage($run, $page);

        $sourceElements = $this->contentBlockService->sourceElementsForGeneration(
            $document,
            $this->sourceElementService->inspect($document)['elements'],
        );
        $plannedSections = $this->plannedSectionContractForGeneration($run, $page, $sourceElements);

        if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
            return;
        }

        $generated = $this->aiClient->generatePageFromSource(
            pageTitle: $page->title,
            pageType: $page->page_type,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $catalogResult['catalog'],
            sourceElements: $sourceElements,
            plannedSections: $plannedSections,
        );

        $generated['blocks'] = $this->linkIntentMaterializer->materializeBlocks($run, $page, $generated['blocks'], $catalogResult['catalog']);

        // Deterministically rewrite unambiguous near-miss wikilinks (e.g. the model writing a
        // page's title instead of its differently-cased slug) to their canonical form before
        // final validation — see EnterpriseWikiWikilinkCanonicalizer for the exact, narrow rules.
        $generated['blocks'] = array_map(function (array $block) use ($catalogResult): array {
            $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

            return $block;
        }, $generated['blocks']);

        // Removes a verbatim-repeated sentence or paragraph anywhere earlier in this same page
        // (run 574's finding #5560) before block metadata is built — see
        // EnterpriseWikiDuplicateContentRemover for the exact, narrow rules (first occurrence
        // always kept, only later identical text dropped, never a semantic/fuzzy match).
        $generated['blocks'] = $this->duplicateContentRemover->removeVerbatimDuplicates($generated['blocks']);

        $markdown = trim(implode("\n\n", array_column($generated['blocks'], 'markdown')));

        $this->validateWikilinks($run, $page, $markdown, $catalogResult['run_page_count']);

        if (in_array($page->page_type, self::SECTION_COVERAGE_CHECKED_TYPES, true)) {
            [$markdown, $generated['blocks']] = $this->ensurePlannedSectionCoverage(
                $run,
                $page,
                $markdown,
                $generated['blocks'],
                $sourceText,
                $languageCode,
                $additionalContext,
                $catalogResult,
                $sourceElements,
                $plannedSections,
            );
        }

        $contentBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
            $document,
            $generated['blocks'],
            $sourceElements,
        );

        [$markdown, $contentBlocks] = $this->appendTableBlocksIfRelevant($document, $page, $markdown, $contentBlocks);
        [$markdown, $contentBlocks] = $this->appendImageBlocksIfRelevant($run, $document, $page, $markdown, $contentBlocks);

        [$markdown, $contentBlocks] = $this->ensurePlannedFigureCoverage(
            $run,
            $page,
            $document,
            $markdown,
            $contentBlocks,
            $sourceText,
            $languageCode,
            $additionalContext,
            $catalogResult,
            $sourceElements,
        );

        [$markdown, $contentBlocks] = $this->appendMutualLinkIfPaired($run, $page, $markdown, $contentBlocks, $languageCode);

        $bestPracticeReview = $this->reviewReconciler->reconcile(
            $generated['best_practice_review'] ?? [],
            $contentBlocks,
            (string) $page->page_type,
        );

        DB::transaction(function () use ($run, $page, $token, $markdown, $contentBlocks, $bestPracticeReview): void {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return;
            }

            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null
                || $pivot->generated_page_version_id !== null
                || $pivot->generation_claim_token !== $token
            ) {
                // Another worker already registered a version for this run/page, or this
                // worker's lease was reclaimed as stale and a different token now owns the
                // pivot — discard this result rather than overwrite whatever that worker writes.
                return;
            }

            $version = $this->writeNewCurrentVersion($page->id, $markdown, $contentBlocks, $bestPracticeReview);
            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($page->id);

            $pivot->update([
                'generated_page_version_id' => $version->id,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'generation_dispatched_at' => null,
                'generation_claimed_at' => null,
                'generation_claim_token' => null,
                'generation_completed_at' => now(),
                'generation_error' => null,
            ]);
        });
    }

    /**
     * Atomic dispatch-side compare-and-swap for one run/page pivot — the page-generation
     * equivalent of EnterpriseWikiVerifyPageClaimsService::reserveClaimForDispatch(). Marks the
     * pivot generation_status=dispatched (recording generation_dispatched_at) only if it is not
     * completed and not currently owned by a live worker, so two concurrent dispatchers (or a
     * fresh dispatch racing a recovery redispatch) can never both enqueue
     * GenerateEnterpriseWikiAppliedPage for the same pivot — the UPDATE...WHERE is a single
     * atomic statement, so only one caller's row-count is > 0.
     *
     * Eligible predecessor states: pending (never dispatched, or reset by
     * EnterpriseWikiPageGenerationFailureRecoveryService), dispatched-but-stale (a dispatch whose
     * Redis enqueue never actually happened, or whose job never started within
     * DISPATCH_STALE_SECONDS), or running-but-stale (a worker that crashed mid-generation without
     * ever reaching its own failure handling). A pivot genuinely dispatched within its wait
     * window, or genuinely running with a live lease, fails this CAS and is left untouched.
     */
    public function reservePageForDispatch(int $runId, int $pageId): bool
    {
        $dispatchStaleThreshold = now()->subSeconds(self::DISPATCH_STALE_SECONDS);
        $leaseStaleThreshold = now()->subSeconds(self::GENERATION_LEASE_SECONDS);

        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->where('enterprise_wiki_page_id', $pageId)
            ->whereNull('generated_page_version_id')
            ->where(function ($query) use ($dispatchStaleThreshold, $leaseStaleThreshold): void {
                $query->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING)
                    ->orWhere(function ($q) use ($dispatchStaleThreshold): void {
                        $q->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED)
                            ->where('generation_dispatched_at', '<', $dispatchStaleThreshold);
                    })
                    ->orWhere(function ($q) use ($leaseStaleThreshold): void {
                        $q->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING)
                            ->where(function ($q2) use ($leaseStaleThreshold): void {
                                $q2->whereNull('generation_claimed_at')->orWhere('generation_claimed_at', '<', $leaseStaleThreshold);
                            });
                    });
            })
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED,
                'generation_dispatched_at' => now(),
            ]) > 0;
    }

    /**
     * @return list<int> enterprise_wiki_page_id for every pivot on this run that
     *                   reservePageForDispatch() would currently accept — i.e. genuinely lost
     *                   work (stale dispatched or stale running), never work still legitimately
     *                   queued or in flight. Used by the page-generation recovery sentinel to
     *                   decide whether a redispatch attempt is even worth making.
     */
    public function redispatchablePageIdsForRun(int $runId): array
    {
        $dispatchStaleThreshold = now()->subSeconds(self::DISPATCH_STALE_SECONDS);
        $leaseStaleThreshold = now()->subSeconds(self::GENERATION_LEASE_SECONDS);

        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runId)
            ->whereNull('generated_page_version_id')
            ->where(function ($query) use ($dispatchStaleThreshold, $leaseStaleThreshold): void {
                $query->where(function ($q) use ($dispatchStaleThreshold): void {
                    $q->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED)
                        ->where('generation_dispatched_at', '<', $dispatchStaleThreshold);
                })
                    ->orWhere(function ($q) use ($leaseStaleThreshold): void {
                        $q->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING)
                            ->where(function ($q2) use ($leaseStaleThreshold): void {
                                $q2->whereNull('generation_claimed_at')->orWhere('generation_claimed_at', '<', $leaseStaleThreshold);
                            });
                    });
            })
            ->orderBy('id')
            ->pluck('enterprise_wiki_page_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Enforces the planned-section-coverage contract (Wiki run-586 incident): validates the
     * generated markdown's `## ` sections against the page's own owned_topics, attempts exactly
     * ONE bounded AI repair if any planned section is missing (with source grounding), empty, or
     * link-only, re-validates the repair, and throws EnterpriseWikiPageGenerationIncompleteException
     * if problems remain — the job's existing exception handling
     * (GenerateEnterpriseWikiAppliedPage::markPivotFailed()) then marks the pivot failed without
     * any further wiring here, so the run never reaches qa_status=passed with an incomplete page
     * (EnterpriseWikiPostIngestQaService::findIncompleteSteps()'s page_generation_incomplete).
     *
     * A no-op (no repair call, no exception) when the page has no owned_topics at all — a page
     * with no planned sections was never going to be checked in the first place.
     *
     * Wiki run-593 (precision repair): WikiPageContentAiClient::repairPlannedSections() now
     * returns ONLY the body content for each blocking section, never the whole page — this method
     * prepends the EXACT planned_topic text as that section's `## ` heading itself (never left to
     * the model) and splices the result into the EXISTING blocks via spliceSectionBlocks(), which
     * replaces only the matched section's own block span (or appends at the end when the heading
     * never existed) — every other already-generated block is passed through completely untouched.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: string, 1: list<array<string, mixed>>} [markdown, blocks]
     *
     * @throws EnterpriseWikiPageGenerationIncompleteException
     */
    private function ensurePlannedSectionCoverage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $markdown,
        array $blocks,
        string $sourceText,
        string $languageCode,
        string $additionalContext,
        array $catalogResult,
        array $sourceElements,
        array $plannedSections,
    ): array {
        $plannedTopics = array_values(array_map(
            static fn (array $section): string => trim((string) ($section['planned_topic'] ?? '')),
            $plannedSections,
        ));

        if ($plannedTopics !== $this->plannedOwnedTopicNamesForPage($run, $page)) {
            throw new \RuntimeException('Enterprise Wiki planned section contract drifted from the maintainer decision.');
        }

        if ($plannedTopics === []) {
            return [$markdown, $blocks];
        }

        $issues = $this->sectionCoverageValidator->validate($plannedTopics, $markdown, $page->page_type, $sourceText);
        $blocking = array_values(array_filter($issues, [EnterpriseWikiPlannedSectionCoverageValidator::class, 'isBlocking']));

        if ($blocking === []) {
            Log::info('[WIKI_PAGE_GENERATION] Planned section coverage check completed.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'page_type' => $page->page_type,
                'planned_section_count' => count($plannedTopics),
                'coverage_result' => 'valid_first_pass',
                'blocking_issues' => 0,
            ]);

            return [$markdown, $blocks];
        }

        [$normalizedMarkdown, $normalizedBlocks, $normalizedTopics] = $this->normalizeConceptPlannedSectionHeadingStructure(
            $page,
            $plannedTopics,
            $markdown,
            $blocks,
        );

        if ($normalizedTopics !== []) {
            $normalizedIssues = $this->sectionCoverageValidator->validate($plannedTopics, $normalizedMarkdown, $page->page_type, $sourceText);
            $normalizedBlocking = array_values(array_filter($normalizedIssues, [EnterpriseWikiPlannedSectionCoverageValidator::class, 'isBlocking']));

            if ($normalizedBlocking === []) {
                Log::info('[WIKI_PAGE_GENERATION] Planned section coverage check completed.', [
                    'run_id' => $run->id,
                    'page_id' => $page->id,
                    'page_type' => $page->page_type,
                    'planned_section_count' => count($plannedTopics),
                    'coverage_result' => 'deterministic_normalization',
                    'normalized_planned_topics' => $normalizedTopics,
                    'blocking_issues_before' => count($blocking),
                    'blocking_issues_after' => 0,
                ]);

                return [$normalizedMarkdown, $normalizedBlocks];
            }

            $markdown = $normalizedMarkdown;
            $blocks = $normalizedBlocks;
            $blocking = $normalizedBlocking;
        }

        Log::info('[WIKI_PAGE_GENERATION] Planned section coverage issue(s) detected — attempting one bounded repair.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'planned_section_count' => count($plannedTopics),
            'coverage_result' => 'ai_repair_required',
            'normalized_planned_topics' => $normalizedTopics,
            'issues' => array_map(fn (array $i): array => ['type' => $i['type'], 'planned_topic' => $i['planned_topic']], $blocking),
            'sections' => $this->plannedSectionObservability($plannedSections),
        ]);

        if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
            return [$markdown, $blocks];
        }

        $repairIssues = $this->repairIssuesWithContext($blocking, $plannedSections, $markdown);

        try {
            $repairedSections = $this->aiClient->repairPlannedSections(
                pageTitle: $page->title,
                pageType: $page->page_type,
                existingMarkdown: $markdown,
                issues: $repairIssues,
                sourceText: $sourceText,
                languageCode: $languageCode,
                additionalContext: $additionalContext,
                linkCatalog: $catalogResult['catalog'],
                sourceElements: $sourceElements,
                plannedSections: $plannedSections,
                sectionStatuses: $this->sectionStatuses($plannedTopics, $issues, $blocking),
            );
        } catch (EnterpriseWikiPlannedSectionRepairShapeException $e) {
            Log::warning('[WIKI_PAGE_GENERATION] Planned section repair shape rejected.', [
                'issue_code' => 'repair_section_count_mismatch',
                'run_id' => $run->id,
                'page_id' => $page->id,
                'page_type' => $page->page_type,
                'expected_section_count' => $e->expectedSectionCount,
                'returned_section_count' => $e->returnedSectionCount,
            ]);

            throw $e;
        }

        $repairedBlocks = $blocks;
        $blockingTopics = array_column($blocking, 'planned_topic');

        foreach ($repairedSections as $section) {
            // The repair response contains the complete plan so it can prove it understood every
            // required identity. Valid sections are nevertheless preserved deterministically from
            // the first output; only a section that failed validation may replace its own span.
            if (! in_array($section['planned_topic'], $blockingTopics, true)) {
                continue;
            }

            $sectionBlocks = $this->linkIntentMaterializer->materializeBlocks($run, $page, $section['blocks'], $catalogResult['catalog']);
            $sectionBlocks = array_map(function (array $block) use ($catalogResult): array {
                $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

                return $block;
            }, $sectionBlocks);

            // The model was never asked for a heading — prepend the EXACT planned_topic text onto
            // the first returned block's own markdown, so the persisted heading can never drift
            // from the maintainer decision's own owned_topics wording.
            $sectionBlocks[0]['markdown'] = '## '.trim($section['planned_topic'])."\n\n".$sectionBlocks[0]['markdown'];

            $repairedBlocks = $this->spliceSectionBlocks($repairedBlocks, $section['planned_topic'], $sectionBlocks);
        }

        $repairedMarkdown = trim(implode("\n\n", array_column($repairedBlocks, 'markdown')));

        $this->validateWikilinks($run, $page, $repairedMarkdown, $catalogResult['run_page_count']);

        $issuesAfterRepair = $this->sectionCoverageValidator->validate($plannedTopics, $repairedMarkdown, $page->page_type, $sourceText);
        $blockingAfterRepair = array_values(array_filter($issuesAfterRepair, [EnterpriseWikiPlannedSectionCoverageValidator::class, 'isBlocking']));

        $this->logOnlyLinksRepairObservability($run, $page, $plannedSections, $repairIssues, $repairedMarkdown, $blockingAfterRepair);

        Log::info('[WIKI_PAGE_GENERATION] Planned section coverage repair attempted.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'issues_before' => count($blocking),
            'issues_resolved' => count($blocking) - count($blockingAfterRepair),
            'issues_remaining' => count($blockingAfterRepair),
            'issues_remaining_by_type' => array_count_values(array_column($blockingAfterRepair, 'type')),
            'expected_section_count' => count($plannedTopics),
            'returned_section_count' => count($repairedSections),
            'invalid_section_indices' => array_values(array_keys(array_filter(
                $this->sectionStatuses($plannedTopics, $issues, $blocking),
                static fn (array $status): bool => $status['status'] !== 'valid',
            ))),
            'repaired_section_indices' => array_values(array_filter(
                array_keys($plannedTopics),
                fn (int $index): bool => in_array($plannedTopics[$index], $blockingTopics, true),
            )),
            'final_valid_count' => count($plannedTopics) - count($blockingAfterRepair),
            'sections' => $this->plannedSectionObservability($plannedSections),
        ]);

        if ($blockingAfterRepair !== []) {
            throw new EnterpriseWikiPageGenerationIncompleteException(
                runId: $run->id,
                pageId: $page->id,
                pageType: $page->page_type,
                missingOrEmptySections: array_map(fn (array $i): string => $i['planned_topic'], $blockingAfterRepair),
                repairAttempted: true,
                issues: $blockingAfterRepair,
            );
        }

        return [$repairedMarkdown, $repairedBlocks];
    }

    /**
     * Adds repair-only context without placing generated page bodies into logs or exceptions.
     * The validator supplies the body using its own matching rule, keeping detection and repair
     * context on one contract.
     *
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $plannedSections
     * @return list<array<string, mixed>>
     */
    private function repairIssuesWithContext(array $issues, array $plannedSections, string $markdown): array
    {
        $sectionByTopic = [];

        foreach ($plannedSections as $section) {
            $sectionByTopic[(string) ($section['planned_topic'] ?? '')] = $section;
        }

        return array_map(function (array $issue) use ($sectionByTopic, $markdown): array {
            $plannedTopic = (string) ($issue['planned_topic'] ?? '');
            $section = $sectionByTopic[$plannedTopic] ?? [];

            return [
                ...$issue,
                'issue_code' => (string) ($issue['type'] ?? 'planned_section_invalid'),
                'section_index' => (int) ($section['section_index'] ?? -1),
                'current_invalid_body' => $this->sectionCoverageValidator->sectionBodyForPlannedTopic($markdown, $plannedTopic),
                'assigned_source_element_keys' => array_values((array) ($section['source_element_keys'] ?? [])),
                'assigned_source_evidence' => array_values((array) ($section['source_evidence'] ?? [])),
            ];
        }, $issues);
    }

    /**
     * @param  list<array<string, mixed>>  $plannedSections
     * @param  list<array<string, mixed>>  $repairIssues
     * @param  list<array<string, mixed>>  $finalIssues
     */
    private function logOnlyLinksRepairObservability(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        array $plannedSections,
        array $repairIssues,
        string $repairedMarkdown,
        array $finalIssues,
    ): void {
        $evidenceCharsByTopic = [];
        foreach ($plannedSections as $section) {
            $evidenceCharsByTopic[(string) ($section['planned_topic'] ?? '')] = (int) ($section['evidence_char_count'] ?? 0);
        }

        foreach ($repairIssues as $issue) {
            if (($issue['issue_code'] ?? $issue['type'] ?? null) !== EnterpriseWikiPlannedSectionCoverageValidator::TYPE_ONLY_LINKS) {
                continue;
            }

            $plannedTopic = (string) ($issue['planned_topic'] ?? '');
            $finalIssue = collect($finalIssues)->firstWhere('planned_topic', $plannedTopic);

            Log::info('[WIKI_PAGE_GENERATION] Planned section only-links repair observability.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'page_type' => $page->page_type,
                'section_index' => (int) ($issue['section_index'] ?? -1),
                'planned_topic' => $plannedTopic,
                'evidence_char_count' => $evidenceCharsByTopic[$plannedTopic] ?? 0,
                'first_body_char_count' => mb_strlen((string) ($issue['current_invalid_body'] ?? '')),
                'repair_body_char_count' => mb_strlen((string) ($this->sectionCoverageValidator->sectionBodyForPlannedTopic($repairedMarkdown, $plannedTopic) ?? '')),
                'final_issue_code' => $finalIssue['type'] ?? 'resolved',
            ]);
        }
    }

    /**
     * Concept pages sometimes contain the planned topic label, but as `###`, bold text, or a
     * plain label instead of the required `##` heading. That is a structural markdown mismatch,
     * not missing subject-matter content, so it is safe to normalize before deciding AI repair is
     * needed. Body prose and provenance metadata remain unchanged.
     *
     * @param  list<string>  $plannedTopics
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function normalizeConceptPlannedSectionHeadingStructure(
        EnterpriseWikiPage $page,
        array $plannedTopics,
        string $markdown,
        array $blocks,
    ): array {
        if ($page->page_type !== EnterpriseWikiPage::PAGE_TYPE_CONCEPT || $plannedTopics === []) {
            return [$markdown, $blocks, []];
        }

        $topicByNormalizedLabel = [];

        foreach ($plannedTopics as $topic) {
            $normalized = EnterpriseWikiPlannedSectionCoverageValidator::normalize($topic);

            if ($normalized !== '') {
                $topicByNormalizedLabel[$normalized] = $topic;
            }
        }

        if ($topicByNormalizedLabel === []) {
            return [$markdown, $blocks, []];
        }

        $normalizedTopics = [];

        foreach ($blocks as $index => $block) {
            [$normalizedBlockMarkdown, $blockTopics] = $this->normalizePlannedSectionHeadingLines(
                (string) ($block['markdown'] ?? ''),
                $topicByNormalizedLabel,
            );

            if ($blockTopics === []) {
                continue;
            }

            $blocks[$index]['markdown'] = $normalizedBlockMarkdown;
            $normalizedTopics = [...$normalizedTopics, ...$blockTopics];
        }

        if ($normalizedTopics === []) {
            return [$markdown, $blocks, []];
        }

        return [
            trim(implode("\n\n", array_column($blocks, 'markdown'))),
            $blocks,
            array_values(array_unique($normalizedTopics)),
        ];
    }

    /**
     * @param  array<string, string>  $topicByNormalizedLabel
     * @return array{0: string, 1: list<string>}
     */
    private function normalizePlannedSectionHeadingLines(string $markdown, array $topicByNormalizedLabel): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $normalizedTopics = [];

        foreach ($lines as $lineIndex => $line) {
            $label = $this->plannedTopicLabelFromLine($line, $lineIndex === 0);

            if ($label === null) {
                continue;
            }

            $normalizedLabel = EnterpriseWikiPlannedSectionCoverageValidator::normalize($label);
            $plannedTopic = $topicByNormalizedLabel[$normalizedLabel] ?? null;

            if ($plannedTopic === null) {
                continue;
            }

            $lines[$lineIndex] = '## '.$plannedTopic;
            $normalizedTopics[] = $plannedTopic;
        }

        if ($normalizedTopics === []) {
            return [$markdown, []];
        }

        return [trim(implode("\n", $lines)), $normalizedTopics];
    }

    private function plannedTopicLabelFromLine(string $line, bool $isFirstLine): ?string
    {
        $trimmed = trim($line);

        if (preg_match('/^#{3,6}\s+(.+?)\s*$/u', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/^\*\*(.+?)\*\*:?\s*$/u', $trimmed, $matches) === 1) {
            return rtrim(trim($matches[1]), " \t:");
        }

        if ($isFirstLine && preg_match('/^[\p{L}\p{N}][^.!?]{2,160}:?$/u', $trimmed) === 1) {
            return rtrim($trimmed, " \t:");
        }

        return null;
    }

    /**
     * Replaces the block span belonging to the `## ` heading matching $plannedTopic with
     * $newSectionBlocks — or appends $newSectionBlocks at the end when no existing block starts a
     * matching heading (the planned_section_missing case). Every block outside the matched span is
     * returned completely unchanged, in its original position — this is the one thing that makes
     * "settes inn i eksisterende markdown uten å overskrive ferdig innhold" true structurally
     * rather than by prompt instruction alone.
     *
     * Uses EnterpriseWikiPlannedSectionCoverageValidator::normalize()'s own fuzzy matching rule
     * (unchanged) — the same heading this splice targets is exactly the one the validator will
     * re-check afterward, so the two can never disagree on which section is which.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $newSectionBlocks
     * @return list<array<string, mixed>>
     */
    private function spliceSectionBlocks(array $blocks, string $plannedTopic, array $newSectionBlocks): array
    {
        $headingIndexes = [];

        foreach ($blocks as $i => $block) {
            if (preg_match('/^##\s+(.+?)\s*$/m', (string) ($block['markdown'] ?? ''), $matches) === 1) {
                $headingIndexes[$i] = $matches[1];
            }
        }

        $normalizedTopic = EnterpriseWikiPlannedSectionCoverageValidator::normalize($plannedTopic);
        $matchedStart = null;

        foreach ($headingIndexes as $i => $heading) {
            $normalizedHeading = EnterpriseWikiPlannedSectionCoverageValidator::normalize($heading);

            if ($normalizedHeading === '') {
                continue;
            }

            if (str_contains($normalizedTopic, $normalizedHeading) || str_contains($normalizedHeading, $normalizedTopic)) {
                $matchedStart = $i;

                break;
            }
        }

        if ($matchedStart === null) {
            return [...$blocks, ...$newSectionBlocks];
        }

        $matchedEnd = count($blocks);

        foreach ($headingIndexes as $i => $heading) {
            if ($i > $matchedStart) {
                $matchedEnd = $i;

                break;
            }
        }

        array_splice($blocks, $matchedStart, $matchedEnd - $matchedStart, $newSectionBlocks);

        return $blocks;
    }

    /**
     * Enforces the planned-figure-coverage contract (Wiki run-587 incident): validates the page's
     * actually-persisted image blocks against its own planned_figures, attempts exactly ONE bounded
     * AI repair when a REQUIRED figure is missing/duplicated/wrongly-sectioned/missing its
     * caption or alt-text, re-validates the repair, and throws
     * EnterpriseWikiFigureMaterializationException if problems remain — the job's existing
     * exception handling (GenerateEnterpriseWikiAppliedPage::markPivotFailed()) then marks the
     * pivot failed without any further wiring here, so the run never reaches qa_status=passed with
     * a missing required figure (EnterpriseWikiAppliedRunLintService's planned_figure_* findings).
     *
     * A no-op (no repair call, no exception) when the page has no planned_figures at all — mirrors
     * ensurePlannedSectionCoverage()'s same "nothing planned, nothing to check" rule, so documents
     * without figures and pages the maintainer decision never assigned any figure to are completely
     * unaffected (backward compatibility).
     *
     * @param  list<array<string, mixed>>  $contentBlocks  The page's FINAL content blocks (i.e.
     *                                                     already including appendTableBlocksIfRelevant()/appendImageBlocksIfRelevant()).
     * @return array{0: string, 1: list<array<string, mixed>>} [markdown, contentBlocks]
     *
     * @throws EnterpriseWikiFigureMaterializationException
     */
    private function ensurePlannedFigureCoverage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiDocument $document,
        string $markdown,
        array $contentBlocks,
        string $sourceText,
        string $languageCode,
        string $additionalContext,
        array $catalogResult,
        array $sourceElements,
    ): array {
        $plannedFigures = $this->plannedFiguresForPage($run, $page);

        if ($plannedFigures === []) {
            return [$markdown, $contentBlocks];
        }

        $validFigureKeys = $this->imageSourceElementKeysForDocument($document);

        $issues = $this->figureCoverageValidator->validate($plannedFigures, $markdown, $contentBlocks, $validFigureKeys);
        $blocking = array_values(array_filter($issues, [EnterpriseWikiPlannedFigureCoverageValidator::class, 'isBlocking']));

        if ($blocking === []) {
            return [$markdown, $contentBlocks];
        }

        Log::info('[WIKI_PAGE_GENERATION] Planned figure coverage issue(s) detected — attempting one bounded repair.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'planned_figure_count' => count($plannedFigures),
            'required_figure_count' => count(array_filter($plannedFigures, fn (array $f): bool => (bool) ($f['required'] ?? false))),
            'source_element_keys' => array_column($plannedFigures, 'source_element_key'),
            'issues' => array_map(fn (array $i): array => ['type' => $i['type'], 'source_element_key' => $i['source_element_key'], 'required' => $i['required']], $blocking),
        ]);

        if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
            return [$markdown, $contentBlocks];
        }

        $repaired = $this->aiClient->repairPlannedFigures(
            pageTitle: $page->title,
            pageType: $page->page_type,
            existingMarkdown: $markdown,
            issues: $blocking,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $catalogResult['catalog'],
            sourceElements: $sourceElements,
        );

        $repaired['blocks'] = $this->linkIntentMaterializer->materializeBlocks($run, $page, $repaired['blocks'], $catalogResult['catalog']);
        $repaired['blocks'] = array_map(function (array $block) use ($catalogResult): array {
            $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

            return $block;
        }, $repaired['blocks']);
        $repaired['blocks'] = $this->duplicateContentRemover->removeVerbatimDuplicates($repaired['blocks']);

        $repairedMarkdown = trim(implode("\n\n", array_column($repaired['blocks'], 'markdown')));

        $this->validateWikilinks($run, $page, $repairedMarkdown, $catalogResult['run_page_count']);

        $repairedContentBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
            $document,
            $repaired['blocks'],
            $sourceElements,
        );

        [$repairedMarkdown, $repairedContentBlocks] = $this->appendTableBlocksIfRelevant($document, $page, $repairedMarkdown, $repairedContentBlocks);
        [$repairedMarkdown, $repairedContentBlocks] = $this->appendImageBlocksIfRelevant($run, $document, $page, $repairedMarkdown, $repairedContentBlocks);

        $issuesAfterRepair = $this->figureCoverageValidator->validate($plannedFigures, $repairedMarkdown, $repairedContentBlocks, $validFigureKeys);
        $blockingAfterRepair = array_values(array_filter($issuesAfterRepair, [EnterpriseWikiPlannedFigureCoverageValidator::class, 'isBlocking']));

        Log::info('[WIKI_PAGE_GENERATION] Planned figure coverage repair attempted.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'issues_before' => count($blocking),
            'issues_resolved' => count($blocking) - count($blockingAfterRepair),
            'issues_remaining' => count($blockingAfterRepair),
            'repair_attempted' => true,
        ]);

        if ($blockingAfterRepair !== []) {
            throw new EnterpriseWikiFigureMaterializationException(
                runId: $run->id,
                pageId: $page->id,
                pageType: $page->page_type,
                failedSourceElementKeys: array_values(array_unique(array_column($blockingAfterRepair, 'source_element_key'))),
                repairAttempted: true,
                reason: implode('; ', array_unique(array_map(fn (array $i): string => $i['type'], $blockingAfterRepair))),
            );
        }

        return [$repairedMarkdown, $repairedContentBlocks];
    }

    /**
     * Owned topics for a page from maintainer_decision_json, as evidence-bound entries
     * (topic + the source_element_keys the planner assigned to it). The same lookup
     * responsibilityGuidance()'s callers already perform, but returning the planning contract
     * rather than formatted prompt text.
     *
     * @return list<array{topic: string, source_element_keys: list<string>}>
     */
    private function plannedOwnedTopicEntriesForPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): array
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        if (in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)) {
            $entries = array_merge(
                (array) data_get($decisionJson, 'concept_pages', []),
                (array) data_get($decisionJson, 'entity_pages', []),
            );

            $match = collect($entries)->firstWhere('title', $page->title);

            return $match !== null ? EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries($match['owned_topics'] ?? []) : [];
        }

        $decisionKey = $page->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE ? 'source_article' : null;

        if ($decisionKey === null) {
            return [];
        }

        $entry = (array) data_get($decisionJson, $decisionKey, []);

        return EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries($entry['owned_topics'] ?? []);
    }

    /**
     * Just the topic names, in planning order — what the section-coverage validator and the
     * plan-to-markdown drift guard compare against.
     *
     * @return list<string>
     */
    private function plannedOwnedTopicNamesForPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['topic'],
            $this->plannedOwnedTopicEntriesForPage($run, $page),
        ));
    }

    /**
     * Builds and validates the authoritative plan-to-evidence contract immediately before the
     * first generation call. A planner may name the section, but generation is not allowed to
     * pretend it is grounded until a real extracted source element is bound to it.
     *
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<array<string, mixed>>
     */
    private function plannedSectionContractForGeneration(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, array $sourceElements): array
    {
        if (! in_array($page->page_type, self::SECTION_COVERAGE_CHECKED_TYPES, true)) {
            return [];
        }

        $plannedTopicEntries = $this->plannedOwnedTopicEntriesForPage($run, $page);

        if ($plannedTopicEntries === []) {
            return [];
        }

        $sections = $this->plannedSectionEvidenceResolver->resolve($plannedTopicEntries, $sourceElements);
        $withoutEvidence = $this->plannedSectionEvidenceResolver->topicsWithoutEvidence($sections);

        Log::info('[WIKI_PAGE_GENERATION] Planned section evidence contract.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'planned_section_count' => count($sections),
            'total_evidence_chars' => array_sum(array_column($sections, 'evidence_char_count')),
            'evidence_binding' => array_values(array_unique(array_column($sections, 'evidence_binding'))),
            'sections' => $this->plannedSectionObservability($sections),
            'result' => $withoutEvidence === [] ? 'valid' : 'planned_section_no_evidence',
        ]);

        if ($withoutEvidence !== []) {
            throw new EnterpriseWikiPlannedSectionEvidenceMissingException(
                runId: $run->id,
                pageId: $page->id,
                pageType: $page->page_type,
                plannedTopics: $withoutEvidence,
            );
        }

        return $sections;
    }

    /** @param list<array<string, mixed>> $sections @return list<array<string, mixed>> */
    private function plannedSectionObservability(array $sections): array
    {
        return array_map(static fn (array $section): array => [
            'section_index' => $section['section_index'] ?? null,
            'planned_topic' => $section['planned_topic'] ?? '',
            'source_element_count' => (int) ($section['source_element_count'] ?? 0),
            'evidence_chars' => (int) ($section['evidence_char_count'] ?? 0),
            'evidence_binding' => (string) ($section['evidence_binding'] ?? ''),
        ], $sections);
    }

    /**
     * @param  list<string>  $plannedTopics
     * @param  list<array<string, mixed>>  $allIssues
     * @param  list<array<string, mixed>>  $blockingIssues
     * @return list<array<string, mixed>>
     */
    private function sectionStatuses(array $plannedTopics, array $allIssues, array $blockingIssues): array
    {
        return array_map(static function (string $topic, int $index) use ($allIssues, $blockingIssues): array {
            $issue = collect($allIssues)->firstWhere('planned_topic', $topic);
            $blocking = collect($blockingIssues)->firstWhere('planned_topic', $topic);

            return [
                'section_index' => $index,
                'planned_topic' => $topic,
                'status' => $blocking !== null ? (string) $blocking['type'] : ($issue !== null ? (string) $issue['type'] : 'valid'),
            ];
        }, $plannedTopics, array_keys($plannedTopics));
    }

    /**
     * This page's own planned_figures list from maintainer_decision_json — same page-entry lookup
     * pattern as plannedOwnedTopicEntriesForPage(), but returning the raw planned_figures entries (each
     * with source_element_key/classification/section_placement/purpose/required/caption_hint)
     * rather than formatted prompt text, for appendImageBlocksIfRelevant()'s per-page materialization
     * gate/placement and ensurePlannedFigureCoverage()'s validator input.
     *
     * Unlike plannedOwnedTopicEntriesForPage() (article-only for the source_* branch, since only article
     * is in SECTION_COVERAGE_CHECKED_TYPES), this covers BOTH source_article and source_summary —
     * a figure can be planned onto either.
     *
     * @return list<array<string, mixed>>
     */
    private function plannedFiguresForPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): array
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        if (in_array($page->page_type, self::CONCEPT_ENTITY_TYPES, true)) {
            $entries = array_merge(
                (array) data_get($decisionJson, 'concept_pages', []),
                (array) data_get($decisionJson, 'entity_pages', []),
            );

            $match = collect($entries)->firstWhere('title', $page->title);

            return $match !== null ? $this->validPlannedFigureList($match['planned_figures'] ?? []) : [];
        }

        $decisionKey = match ($page->page_type) {
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE => 'source_article',
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY => 'source_summary',
            default => null,
        };

        if ($decisionKey === null) {
            return [];
        }

        $entry = (array) data_get($decisionJson, $decisionKey, []);

        return $this->validPlannedFigureList($entry['planned_figures'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validPlannedFigureList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn (mixed $item): bool => is_array($item) && trim((string) ($item['source_element_key'] ?? '')) !== '',
        ));
    }

    /**
     * Every real, currently-extractable image source_element_key for this document — matches
     * EnterpriseWikiMaintainerDecisionService::figureCandidatesForDocument()'s same filter, so a
     * planned_figures entry pointing at a stale/nonexistent key is told apart from a genuinely
     * missing one (EnterpriseWikiPlannedFigureCoverageValidator's planned_figure_source_missing).
     *
     * @return list<string>
     */
    private function imageSourceElementKeysForDocument(EnterpriseWikiDocument $document): array
    {
        $elements = $this->sourceElementService->inspect($document)['elements'];

        return array_values(array_filter(array_map(
            fn (array $element): string => ($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE
                ? (string) ($element['source_element_key'] ?? '')
                : '',
            $elements,
        )));
    }

    /**
     * Deterministically validate a generated page's inline wikilinks against the exact
     * allowed catalog before the page version is written (8I-4). Rejects unknown/broken
     * slugs, self-links (cross-customer targets are indistinguishable from unknown slugs,
     * since resolving is customer-scoped), and malformed-but-attempted wikilink syntax.
     * Never repairs anything — an invalid generation is rejected outright and surfaces as
     * a generation failure for this page (see GenerateEnterpriseWikiAppliedPage).
     *
     * A page may legitimately have no useful link. Links are semantic navigation enrichment,
     * not a quota; every link that is present remains strictly validated.
     *
     * @throws EnterpriseWikiInvalidWikilinksException
     */
    private function validateWikilinks(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $markdown, int $runPageCount): void
    {
        $parsed = $this->linkParser->parse($markdown);
        $rawOccurrences = $this->linkParser->countRawOccurrences($markdown);

        if ($rawOccurrences > count($parsed)) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] page [%d] (%s): generated content contains %d malformed wikilink attempt(s).',
                $run->id,
                $page->id,
                $page->page_type,
                $rawOccurrences - count($parsed),
            ));
        }

        $occurrences = $this->linkResolver->resolveOccurrences($run->customer_id, $page, $parsed);

        $invalidSlugs = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence['status'] === EnterpriseWikiLinkResolver::STATUS_VALID) {
                continue;
            } else {
                $invalidSlugs[] = $occurrence['link']['target_slug'];
            }
        }

        if ($invalidSlugs !== []) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] page [%d] (%s): %d invalid wikilink slug(s): %s.',
                $run->id,
                $page->id,
                $page->page_type,
                count($invalidSlugs),
                implode(', ', array_values(array_unique($invalidSlugs))),
            ));
        }

    }

    private function buildConceptEntityContextForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): string
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $sharedContext = '';

        if (! $this->conceptCanGenerateWithoutArticleSummaryContext($run, $page)) {
            $articleSummaryPageIds = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->whereHas('page', fn ($query) => $query->whereIn('page_type', self::ARTICLE_SUMMARY_TYPES))
                ->pluck('enterprise_wiki_page_id');

            $sharedContext = $this->loadSharedContext($articleSummaryPageIds->all());
        }

        return $this->buildConceptEntityContext($page, $decisionJson, $sharedContext);
    }

    private function conceptCanGenerateWithoutArticleSummaryContext(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): bool
    {
        return $page->page_type === EnterpriseWikiPage::PAGE_TYPE_CONCEPT
            && $this->plannedOwnedTopicEntriesForPage($run, $page) !== [];
    }

    /**
     * Context for article/summary generation — previously always empty (see class history):
     * neither page ever received its own maintainer-assigned responsibility, and summary was
     * generated independently from the raw source text rather than from the finished article.
     *
     * source_article/source_summary are matched by page_type, not by title lookup — unlike
     * concept/entity pages there is exactly one of each per run, and the decision key is fixed.
     *
     * For a summary page specifically, also includes the sibling article's CURRENT version
     * content_markdown (when one already exists) so the summary condenses what the article
     * actually says instead of re-deriving independently from the same raw source document —
     * see WikiPageContentAiClient's summary prompt branch for the matching instruction. A summary
     * generated before its sibling article exists (or has no article in this run — should not
     * normally happen, but defensively handled) falls back to the source document exactly as
     * before; this is a best-effort improvement, not a hard dependency.
     */
    private function buildArticleSummaryContextForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): string
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);
        $decisionKey = $page->page_type === EnterpriseWikiPage::PAGE_TYPE_ARTICLE ? 'source_article' : 'source_summary';
        $entry = (array) data_get($decisionJson, $decisionKey, []);

        $parts = array_filter([$this->responsibilityGuidance($entry)], fn (string $part): bool => $part !== '');

        if ($page->page_type === EnterpriseWikiPage::PAGE_TYPE_SUMMARY) {
            $articleMarkdown = $this->finishedArticleMarkdownForRun($run);

            if ($articleMarkdown !== '') {
                $parts[] = "Finished article to summarize (base this summary on the article's actual content and structure below — do not independently re-derive it from the raw source document; condense what the article covers and link to it and other detail pages rather than repeating them):\n\n{$articleMarkdown}";
            }
        }

        return implode("\n\n", $parts);
    }

    private function finishedArticleMarkdownForRun(EnterpriseWikiIngestRun $run): string
    {
        $articlePageId = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereHas('page', fn ($query) => $query->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE))
            ->value('enterprise_wiki_page_id');

        if ($articlePageId === null) {
            return '';
        }

        return (string) (EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $articlePageId)
            ->where('is_current', true)
            ->value('content_markdown') ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $bestPracticeReview  Reconciled review, see
     *                                                          EnterpriseWikiBestPracticeReviewReconciler. Stored with the version it assessed.
     */
    private function writeNewCurrentVersion(int $pageId, string $markdown, array $contentBlocks = [], array $bestPracticeReview = []): EnterpriseWikiPageVersion
    {
        return $this->versionWriter->writeNewCurrentVersion($pageId, [
            'content_markdown' => $markdown,
            'content_blocks_json' => $contentBlocks,
            'best_practice_review_json' => $bestPracticeReview !== [] ? $bestPracticeReview : null,
            'generated_by_model' => WikiPageContentAiClient::MODEL,
        ]);
    }

    private function pageHasVersion(int $pageId): bool
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->exists();
    }

    /**
     * Deterministically ensures a freshly-generated article/summary page links to its paired
     * summary/article page, without relying on the AI to reliably do so on its own (it is only
     * ever required to link to *something* in the run — see validateWikilinks()). A no-op when
     * the pair is ambiguous (not exactly one page of the opposite type in this run — never
     * guessed) or the generated content already contains a link to the pair.
     *
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function appendMutualLinkIfPaired(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $markdown,
        array $contentBlocks,
        string $languageCode,
    ): array {
        $pairedPage = $this->articleSummaryLinkService->findPairedPage($run, $page);

        if ($pairedPage === null || $this->articleSummaryLinkService->hasLinkToPage($page, $markdown, $pairedPage)) {
            return [$markdown, $contentBlocks];
        }

        $linkBlock = $this->articleSummaryLinkService->buildLinkBlock($pairedPage, count($contentBlocks), $languageCode);
        $contentBlocks[] = $linkBlock;

        return [trim($markdown."\n\n".$linkBlock['markdown']), $contentBlocks];
    }

    /**
     * Fase 8K-2: refuse to regenerate a page the run's maintainer decision names as a patch target.
     *
     * @throws EnterpriseWikiPatchTargetRegenerationBlockedException
     */
    private function guardAgainstPatchTargetRegeneration(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        $targetPageIds = EnterpriseWikiPatchTargetResolver::targetPageIds(
            (array) ($run->maintainer_decision_json ?? []),
        );

        if (! in_array((int) $page->id, $targetPageIds, true)) {
            return;
        }

        Log::error('[WIKI_PAGE_GENERATION] Blocked full-page regeneration of a patch target.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'reason' => 'structured_patch_intent_pending_8k3',
        ]);

        throw new EnterpriseWikiPatchTargetRegenerationBlockedException((int) $run->id, (int) $page->id);
    }

    /** @param list<array<string, mixed>> $bestPracticeReview */
    private function writeVersion(int $runId, int $pageId, string $markdown, array $contentBlocks = [], array $bestPracticeReview = []): void
    {
        DB::transaction(function () use ($runId, $pageId, $markdown, $contentBlocks, $bestPracticeReview): void {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                return;
            }

            $this->versionWriter->writeNewCurrentVersion($pageId, [
                'content_markdown' => $markdown,
                'content_blocks_json' => $contentBlocks,
                'best_practice_review_json' => $bestPracticeReview !== [] ? $bestPracticeReview : null,
                'generated_by_model' => WikiPageContentAiClient::MODEL,
            ]);
        });
    }

    private function loadSharedContext(array $pageIds): string
    {
        if (empty($pageIds)) {
            return '';
        }

        return EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('content_markdown')
            ->filter()
            ->implode("\n\n---\n\n");
    }

    private function buildConceptEntityContext(EnterpriseWikiPage $page, array $decisionJson, string $sharedContext): string
    {
        $parts = [];

        $entries = array_merge(
            (array) data_get($decisionJson, 'concept_pages', []),
            (array) data_get($decisionJson, 'entity_pages', []),
        );

        $match = collect($entries)->firstWhere('title', $page->title);

        if ($match !== null) {
            $guidance = $this->responsibilityGuidance($match);

            if ($guidance !== '') {
                $parts[] = $guidance;
            }
        }

        if ($sharedContext !== '') {
            $parts[] = "Content from related article and summary pages:\n\n{$sharedContext}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Formats one planned page's maintainer-decision entry (reason, plus the responsibility
     * fields added to reduce cross-page repetition — see EnterpriseWikiMaintainerDecisionPrompt)
     * into readable prompt text. Shared by article/summary and concept/entity context building so
     * the format is identical everywhere; gracefully produces '' when the entry has none of these
     * fields (a legacy stored decision predating this feature, or a hand-built test fixture),
     * which is exactly the pre-existing behavior for a page with no maintainer note.
     *
     * @param  array<string, mixed>  $entry
     */
    private function responsibilityGuidance(array $entry): string
    {
        $lines = [];

        $reason = trim((string) ($entry['reason'] ?? ''));

        if ($reason !== '') {
            $lines[] = "Maintainer note for this page: {$reason}";
        }

        $ownedTopics = EnterpriseWikiMaintainerDecisionPrompt::ownedTopicNames($entry['owned_topics'] ?? []);

        if ($ownedTopics !== []) {
            $lines[] = "This page's own content responsibility — explain ONLY these in depth, nothing beyond them:\n".implode("\n", array_map(
                fn (string $item): string => "- {$item}",
                $ownedTopics,
            ));
        }

        $referenceOnlyTopics = $this->nonEmptyStringList($entry['reference_only_topics'] ?? []);

        if ($referenceOnlyTopics !== []) {
            $lines[] = "Reference only — at most one short sentence plus a link, never an explanation or a subsection:\n".implode("\n", array_map(
                fn (string $item): string => "- {$item}",
                $referenceOnlyTopics,
            ));
        }

        $excludedTopics = $this->nonEmptyStringList($entry['excluded_topics'] ?? []);

        if ($excludedTopics !== []) {
            $lines[] = "EXCLUDED — do not mention these at all on this page, in any depth:\n".implode("\n", array_map(
                fn (string $item): string => "- {$item}",
                $excludedTopics,
            ));
        }

        $relatedGuidance = array_values(array_filter(
            (array) ($entry['related_page_guidance'] ?? []),
            fn (mixed $item): bool => is_array($item)
                && trim((string) ($item['page_title'] ?? '')) !== ''
                && trim((string) ($item['relationship'] ?? '')) !== '',
        ));

        if ($relatedGuidance !== []) {
            $lines[] = "Related pages and how to reference them:\n".implode("\n", array_map(
                fn (array $item): string => sprintf('- %s: %s', $item['page_title'], $item['relationship']),
                $relatedGuidance,
            ));
        }

        $plannedFigures = $this->validPlannedFigureList($entry['planned_figures'] ?? []);

        if ($plannedFigures !== []) {
            $lines[] = "PLANNED FIGURES FOR THIS PAGE — cite each source_element_key in a source_based block per the figure rules in your instructions:\n".implode("\n", array_map(
                function (array $figure): string {
                    $required = ($figure['required'] ?? false) ? 'required' : 'optional';
                    $section = trim((string) ($figure['section_placement'] ?? '')) !== ''
                        ? (string) $figure['section_placement']
                        : 'no specific section — place right after the introduction';
                    $captionHint = trim((string) ($figure['caption_hint'] ?? ''));

                    return sprintf(
                        '- %s (%s, %s): %s — section: %s%s',
                        $figure['source_element_key'] ?? '',
                        $figure['classification'] ?? 'figure',
                        $required,
                        $figure['purpose'] ?? '',
                        $section,
                        $captionHint !== '' ? "; caption hint: {$captionHint}" : '',
                    );
                },
                $plannedFigures,
            ));
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function nonEmptyStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
