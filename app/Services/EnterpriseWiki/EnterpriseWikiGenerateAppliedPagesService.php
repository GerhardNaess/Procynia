<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generates content_markdown and EnterpriseWikiPageVersion records for all four wiki page types
 * (article, summary, concept, entity) linked to an applied maintainer decision run.
 *
 * Processing order: article and summary first, then concept and entity — so article/summary
 * content is available as context when generating concept/entity pages.
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

    public function __construct(
        private readonly WikiPageContentAiClient $aiClient,
        private readonly EnterpriseWikiLinkCatalogService $linkCatalogService,
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiWikilinkCanonicalizer $wikilinkCanonicalizer,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
        private readonly EnterpriseWikiArticleSummaryLinkService $articleSummaryLinkService,
        private readonly EnterpriseWikiTableBlockBuilder $tableBlockBuilder,
        private readonly EnterpriseWikiImageBlockBuilder $imageBlockBuilder,
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
     * only for article/summary pages, mirroring appendTableBlocksIfRelevant() exactly (see
     * EnterpriseWikiImageBlockBuilder for why attachment is keyed off citation rather than "every
     * image in the document").
     *
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array{0: string, 1: list<array<string, mixed>>} [markdown, contentBlocks] with any
     *                                                         image blocks appended
     */
    private function appendImageBlocksIfRelevant(EnterpriseWikiDocument $document, EnterpriseWikiPage $page, string $markdown, array $contentBlocks): array
    {
        if (! in_array($page->page_type, self::ARTICLE_SUMMARY_TYPES, true)) {
            return [$markdown, $contentBlocks];
        }

        $imageIndexes = $this->imageBlockBuilder->referencedImageIndexes($contentBlocks);

        if ($imageIndexes === []) {
            return [$markdown, $contentBlocks];
        }

        $images = $this->sourceElementService->imagesForDocument($document);
        $imageBlocks = $this->imageBlockBuilder->buildImageBlocks($document, $images, $imageIndexes, count($contentBlocks));

        if ($imageBlocks === []) {
            return [$markdown, $contentBlocks];
        }

        $contentBlocks = [...$contentBlocks, ...$imageBlocks];
        $markdown = trim($markdown."\n\n".implode("\n\n", array_column($imageBlocks, 'markdown')));

        return [$markdown, $contentBlocks];
    }

    /**
     * @return array{article: int, summary: int, concept: int, entity: int, skipped: int}
     *
     * @throws InvalidArgumentException if the run is not in a state that permits generation
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generate(EnterpriseWikiIngestRun $run): array
    {
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

            $generated = $this->aiClient->generatePageFromSource(
                pageTitle: $page->title,
                pageType: $page->page_type,
                sourceText: $sourceText,
                languageCode: $languageCode,
                additionalContext: $this->buildArticleSummaryContextForRun($run, $page),
                sourceElements: $sourceElements,
            );

            $contentBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
                $document,
                $generated['blocks'],
                $sourceElements,
            );

            [$markdown, $contentBlocks] = $this->appendTableBlocksIfRelevant($document, $page, $generated['markdown'], $contentBlocks);
            [$markdown, $contentBlocks] = $this->appendImageBlocksIfRelevant($document, $page, $markdown, $contentBlocks);
            [$markdown, $contentBlocks] = $this->appendMutualLinkIfPaired($run, $page, $markdown, $contentBlocks, $languageCode);

            $this->writeVersion($page->id, $markdown, $contentBlocks);
            $counts[$page->page_type]++;
        }

        // Load article/summary content to use as context in concept/entity generation
        $sharedContext = $this->loadSharedContext($articleSummaryPageIds);

        // --- Pass 2: concept and entity ---
        foreach ($pivotRows as $row) {
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

            $generated = $this->aiClient->generatePageFromSource(
                pageTitle: $page->title,
                pageType: $page->page_type,
                sourceText: $sourceText,
                languageCode: $languageCode,
                additionalContext: $additionalContext,
                sourceElements: $sourceElements,
            );

            $this->writeVersion($page->id, $generated['markdown'], $this->contentBlockService->buildBlocksFromStructuredResult(
                $document,
                $generated['blocks'],
                $sourceElements,
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
     * @throws InvalidArgumentException if the run is not in a state that permits generation,
     *                                  or the page is not linked to the run
     * @throws \RuntimeException if AI is unavailable or generation fails
     */
    public function generatePageForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
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

        $claimed = DB::transaction(function () use ($run, $page): ?EnterpriseWikiIngestRunPage {
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

            $pivot->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_RUNNING,
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

        $generated = $this->aiClient->generatePageFromSource(
            pageTitle: $page->title,
            pageType: $page->page_type,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $catalogResult['catalog'],
            sourceElements: $sourceElements,
        );

        // Deterministically rewrite unambiguous near-miss wikilinks (e.g. the model writing a
        // page's title instead of its differently-cased slug) to their canonical form before
        // final validation — see EnterpriseWikiWikilinkCanonicalizer for the exact, narrow rules.
        $generated['blocks'] = array_map(function (array $block) use ($catalogResult): array {
            $block['markdown'] = $this->wikilinkCanonicalizer->canonicalize((string) $block['markdown'], $catalogResult['catalog']);

            return $block;
        }, $generated['blocks']);
        $markdown = trim(implode("\n\n", array_column($generated['blocks'], 'markdown')));

        $this->validateWikilinks($run, $page, $markdown, $catalogResult['run_page_count']);

        $contentBlocks = $this->contentBlockService->buildBlocksFromStructuredResult(
            $document,
            $generated['blocks'],
            $sourceElements,
        );

        [$markdown, $contentBlocks] = $this->appendTableBlocksIfRelevant($document, $page, $markdown, $contentBlocks);
        [$markdown, $contentBlocks] = $this->appendImageBlocksIfRelevant($document, $page, $markdown, $contentBlocks);
        [$markdown, $contentBlocks] = $this->appendMutualLinkIfPaired($run, $page, $markdown, $contentBlocks, $languageCode);

        DB::transaction(function () use ($run, $page, $markdown, $contentBlocks): void {
            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot === null || $pivot->generated_page_version_id !== null) {
                // Another worker already registered a version for this run/page — discard this result.
                return;
            }

            $version = $this->writeNewCurrentVersion($page->id, $markdown, $contentBlocks);
            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($page->id);

            $pivot->update([
                'generated_page_version_id' => $version->id,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'generation_completed_at' => now(),
                'generation_error' => null,
            ]);
        });
    }

    /**
     * Deterministically validate a generated page's inline wikilinks against the exact
     * allowed catalog before the page version is written (8I-4). Rejects unknown/broken
     * slugs, self-links (cross-customer targets are indistinguishable from unknown slugs,
     * since resolving is customer-scoped), and malformed-but-attempted wikilink syntax.
     * Never repairs anything — an invalid generation is rejected outright and surfaces as
     * a generation failure for this page (see GenerateEnterpriseWikiAppliedPage).
     *
     * Minimum-links domain rule: if this run has other applied pages available to link to
     * (run_page_count > 0), the generated page must contain at least one valid inline
     * wikilink — a run must not complete with a page left completely isolated from
     * everything else the maintainer decision created. A page whose run has no other pages
     * (run_page_count === 0) is never required to contain a link.
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
        $validCount = 0;

        foreach ($occurrences as $occurrence) {
            if ($occurrence['status'] === EnterpriseWikiLinkResolver::STATUS_VALID) {
                $validCount++;
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

        if ($validCount === 0 && $runPageCount > 0) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] page [%d] (%s): generated content contains no valid inline wikilinks, but %d other applied page(s) exist in this run.',
                $run->id,
                $page->id,
                $page->page_type,
                $runPageCount,
            ));
        }
    }

    private function buildConceptEntityContextForRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): string
    {
        $decisionJson = (array) ($run->maintainer_decision_json ?? []);

        $articleSummaryPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereHas('page', fn ($query) => $query->whereIn('page_type', self::ARTICLE_SUMMARY_TYPES))
            ->pluck('enterprise_wiki_page_id');

        $sharedContext = $this->loadSharedContext($articleSummaryPageIds->all());

        return $this->buildConceptEntityContext($page, $decisionJson, $sharedContext);
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

    private function writeNewCurrentVersion(int $pageId, string $markdown, array $contentBlocks = []): EnterpriseWikiPageVersion
    {
        $next = ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;

        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pageId,
            'version_number' => $next,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $contentBlocks,
            'generated_by_model' => WikiPageContentAiClient::MODEL,
        ]);

        return $version;
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

    private function writeVersion(int $pageId, string $markdown, array $contentBlocks = []): void
    {
        $next = ((int) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->max('version_number')) + 1;

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pageId,
            'version_number' => $next,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => $contentBlocks,
            'generated_by_model' => WikiPageContentAiClient::MODEL,
        ]);
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

        $responsibility = $this->nonEmptyStringList($entry['content_responsibility'] ?? []);

        if ($responsibility !== []) {
            $lines[] = "This page's own content responsibility:\n".implode("\n", array_map(
                fn (string $item): string => "- {$item}",
                $responsibility,
            ));
        }

        $mustNotRepeat = $this->nonEmptyStringList($entry['must_not_repeat'] ?? []);

        if ($mustNotRepeat !== []) {
            $lines[] = "Do NOT explain these in full — another page already owns them (give at most a short mention and link there instead):\n".implode("\n", array_map(
                fn (string $item): string => "- {$item}",
                $mustNotRepeat,
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
