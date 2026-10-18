<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the EnterpriseWikiPageLink graph for Enterprise Wiki pages.
 *
 * Two distinct, independently-idempotent responsibilities live here:
 *
 * 1. build() — the original structural link builder. It links pages by page-type
 *    co-membership within an applied maintainer decision run (article<->summary,
 *    article<->concept, etc). It never reads content_markdown and never deletes a
 *    row once created. This is NOT a canonical wikilink graph — see the class docs
 *    on EnterpriseWikiPageLink::LINK_TYPE_WIKILINK.
 *
 * 2. materializeWikilinksForPage()/materializeWikilinksForRun() — the canonical
 *    projection (8I-1/8I-2): parses a page's CURRENT content_markdown for inline
 *    [[wikilinks]], resolves them against the customer's page catalog, and makes
 *    the link_type=wikilink rows for that source page match the text exactly —
 *    creating, updating, and deleting rows as needed. Unlike build(), this is not
 *    "create once and never touch again": every call is authoritative for that
 *    source page's wikilink-type outgoing edges.
 *
 * Does not call OpenAI. Does not touch claims, source references, lint, or
 * ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiBuildPageLinksService
{
    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
    ) {}

    /**
     * Narrow, automatic-flow counterpart to build(): only the article<->summary structural pair
     * (the article_to_summary/summary_to_article types EnterpriseWikiAppliedRunLintService::
     * checkArticleLinks()/checkSummaryLinks() require) — never the article/summary<->concept/
     * entity combinatoric types, which remain an explicit, opt-in operation via build() itself
     * (wiki:build-page-links / EnterpriseWikiDeepRepairService) so as not to change that
     * feature's existing "never automatic" behavior.
     *
     * Idempotent (firstOrCreate) and a safe no-op when the run does not have exactly the pages
     * needed — an ambiguous run (0, or 2+, of either type) simply creates 0 or more links per
     * pairing, same as build()'s existing cross-join behavior.
     *
     * @return array{links_created: int, links_skipped: int}
     *
     * @throws \InvalidArgumentException if the run is not applied
     */
    public function buildArticleSummaryLinks(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have page links built."
            );
        }

        [$articles, $summaries] = $this->articlesAndSummariesForRun($run);

        // A page whose generation failed in a partially completed run is an empty shell, not an
        // article or summary: pairing it would record a structural relation to content that does
        // not exist. Its pair is built when a retry gives it a version.
        $hasVersion = fn (array $entry): bool => $entry['version'] !== null;
        $articles = array_values(array_filter($articles, $hasVersion));
        $summaries = array_values(array_filter($summaries, $hasVersion));

        [$created, $skipped] = $this->buildBidirectional(
            $run, $articles, $summaries,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE,
        );

        return ['links_created' => $created, 'links_skipped' => $skipped];
    }

    /**
     * @return array{0: list<array{page: EnterpriseWikiPage, version: ?EnterpriseWikiPageVersion}>, 1: list<array{page: EnterpriseWikiPage, version: ?EnterpriseWikiPageVersion}>}
     */
    private function articlesAndSummariesForRun(EnterpriseWikiIngestRun $run): array
    {
        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $articles = [];
        $summaries = [];

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            $entry = ['page' => $page, 'version' => $version];

            match ($page->page_type) {
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $articles[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $summaries[] = $entry,
                default => null,
            };
        }

        return [$articles, $summaries];
    }

    /**
     * @return array{pages_checked: int, links_created: int, links_skipped: int, missing_versions: int, failed: int}
     *
     * @throws \InvalidArgumentException if the run is not applied
     */
    public function build(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have page links built."
            );
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $articles = [];
        $summaries = [];
        $concepts = [];
        $entities = [];
        $pagesChecked = 0;
        $missingVersions = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $pagesChecked++;

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                $missingVersions++;
            }

            $entry = ['page' => $page, 'version' => $version];

            match ($page->page_type) {
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE => $articles[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_SUMMARY => $summaries[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_CONCEPT => $concepts[] = $entry,
                EnterpriseWikiPage::PAGE_TYPE_ENTITY => $entities[] = $entry,
                default => null,
            };
        }

        $linksCreated = 0;
        $linksSkipped = 0;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $summaries,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $concepts,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
            EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $articles, $entities,
            EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
            EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $summaries, $concepts,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
            EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        [$c, $s] = $this->buildBidirectional(
            $run, $summaries, $entities,
            EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
            EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY,
        );
        $linksCreated += $c;
        $linksSkipped += $s;

        return [
            'pages_checked' => $pagesChecked,
            'links_created' => $linksCreated,
            'links_skipped' => $linksSkipped,
            'missing_versions' => $missingVersions,
            'failed' => 0,
        ];
    }

    // =========================================================================
    // Canonical wikilink materialization (8I-1/8I-2)
    // =========================================================================

    /**
     * Materialize every page linked to a run's applied pages: parse each page's
     * current content_markdown for inline [[wikilinks]] and make its
     * link_type=wikilink outgoing rows match the text exactly.
     *
     * @return array{
     *     pages_processed: int,
     *     occurrences_found: int,
     *     valid_links: int,
     *     broken_slugs: int,
     *     self_links: int,
     *     created: int,
     *     updated: int,
     *     stale_links_removed: int,
     * }
     */
    public function materializeWikilinksForRun(EnterpriseWikiIngestRun $run): array
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return [
                'pages_processed' => 0,
                'occurrences_found' => 0,
                'valid_links' => 0,
                'broken_slugs' => 0,
                'self_links' => 0,
                'created' => 0,
                'updated' => 0,
                'stale_links_removed' => 0,
            ];
        }

        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get()
            ->filter(fn (EnterpriseWikiIngestRunPage $pivot): bool => $pivot->page !== null);

        $aggregate = [
            'pages_processed' => 0,
            'occurrences_found' => 0,
            'valid_links' => 0,
            'broken_slugs' => 0,
            'self_links' => 0,
            'created' => 0,
            'updated' => 0,
            'stale_links_removed' => 0,
        ];

        foreach ($pivots as $pivot) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $page = $pivot->page;
            $result = $this->materializeWikilinksForPage($page, $run->id);
            $this->markLinksAssessed($pivot);

            $aggregate['pages_processed']++;
            $aggregate['occurrences_found'] += $result['occurrences_found'];
            $aggregate['valid_links'] += $result['valid_links'];
            $aggregate['broken_slugs'] += count($result['broken_slugs']);
            $aggregate['self_links'] += count($result['self_link_slugs']);
            $aggregate['created'] += $result['created'];
            $aggregate['updated'] += $result['updated'];
            $aggregate['stale_links_removed'] += $result['stale_links_removed'];
        }

        return $aggregate;
    }

    /**
     * Materialize link_type=wikilink rows for a single source page from its current
     * content_markdown. Safe to call repeatedly for the same page (idempotent) and
     * independently of any run — the $ingestRunId is stored only as provenance.
     *
     * current content_markdown = complete truth for this page's wikilink relations:
     * links no longer present in the text are removed; links newly present are
     * created; links whose target's current version changed are updated in place.
     * Rows with any other link_type are never touched.
     *
     * @return array{
     *     page_id: int,
     *     occurrences_found: int,
     *     valid_links: int,
     *     broken_slugs: list<string>,
     *     self_link_slugs: list<string>,
     *     created: int,
     *     updated: int,
     *     stale_links_removed: int,
     * }
     */
    public function materializeWikilinksForPage(EnterpriseWikiPage $page, ?int $ingestRunId = null): array
    {
        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        $markdown = (string) ($currentVersion?->content_markdown ?? '');

        $parsed = $this->linkParser->parse($markdown);
        $resolution = $this->linkResolver->resolve($page->customer_id, $page, $parsed);
        $resolution['resolved'] = $this->linkResolver->materializableTargets($resolution['resolved']);

        $result = DB::transaction(function () use ($page, $currentVersion, $ingestRunId, $resolution) {
            if ($ingestRunId !== null) {
                $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($ingestRunId);

                if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                    return [
                        'page_id' => $page->id,
                        'occurrences_found' => 0,
                        'valid_links' => 0,
                        'broken_slugs' => [],
                        'self_link_slugs' => [],
                        'created' => 0,
                        'updated' => 0,
                        'stale_links_removed' => 0,
                    ];
                }
            }

            $targetPageIds = array_map(
                fn (array $target) => $target['to_page']->id,
                $resolution['resolved'],
            );

            $targetCurrentVersionsByPageId = empty($targetPageIds)
                ? collect()
                : EnterpriseWikiPageVersion::query()
                    ->where('is_current', true)
                    ->whereIn('enterprise_wiki_page_id', $targetPageIds)
                    ->get()
                    ->keyBy('enterprise_wiki_page_id');

            $created = 0;
            $updated = 0;

            foreach ($resolution['resolved'] as $target) {
                $toPage = $target['to_page'];

                $link = EnterpriseWikiPageLink::query()->updateOrCreate(
                    [
                        'customer_id' => $page->customer_id,
                        'from_page_id' => $page->id,
                        'to_page_id' => $toPage->id,
                        'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
                    ],
                    [
                        'enterprise_wiki_ingest_run_id' => $ingestRunId,
                        'from_page_version_id' => $currentVersion?->id,
                        'to_page_version_id' => $targetCurrentVersionsByPageId->get($toPage->id)?->id,
                        'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                        'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
                        'metadata' => ['anchor_text' => $target['anchor_text']],
                    ],
                );

                $link->wasRecentlyCreated ? $created++ : $updated++;
            }

            $staleQuery = EnterpriseWikiPageLink::query()
                ->where('customer_id', $page->customer_id)
                ->where('from_page_id', $page->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK);

            if (! empty($targetPageIds)) {
                $staleQuery->whereNotIn('to_page_id', $targetPageIds);
            }

            $staleRemoved = $staleQuery->delete();

            return [
                'page_id' => $page->id,
                'occurrences_found' => $resolution['occurrences_found'],
                'valid_links' => count($resolution['resolved']),
                'broken_slugs' => $resolution['broken_slugs'],
                'self_link_slugs' => $resolution['self_link_slugs'],
                'created' => $created,
                'updated' => $updated,
                'stale_links_removed' => $staleRemoved,
            ];
        });

        $this->dispatchGraphProjectionAfterCommit($page->id);

        return $result;
    }

    /**
     * The links_assessed_at checkpoint (docs/enterprise-wiki-knowledge-integration-plan.md §6.3):
     * set only for a page whose generation completed, whose current version exists, and whose rows
     * were just made to match that version. A page whose generation failed, is still pending, or was
     * skipped because the run turned terminal is never marked — its links were not processed.
     *
     * Idempotent: a repeat materialization (a retried continuation) refreshes the timestamp but
     * never touches link_assessment, so nothing is counted twice.
     */
    private function markLinksAssessed(EnterpriseWikiIngestRunPage $pivot): void
    {
        if ($pivot->generation_status !== EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED) {
            return;
        }

        $hasCurrentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pivot->enterprise_wiki_page_id)
            ->where('is_current', true)
            ->exists();

        if (! $hasCurrentVersion) {
            return;
        }

        DB::transaction(function () use ($pivot): void {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($pivot->enterprise_wiki_ingest_run_id);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                return;
            }

            EnterpriseWikiIngestRunPage::query()
                ->whereKey($pivot->id)
                ->where('generation_status', EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED)
                ->update(['links_assessed_at' => now()]);
        });
    }

    private function dispatchGraphProjectionAfterCommit(int $pageId): void
    {
        try {
            DB::afterCommit(function () use ($pageId): void {
                try {
                    ProjectEnterpriseWikiPageToGraph::dispatch($pageId)->afterCommit();
                } catch (Throwable $e) {
                    Log::error('[WIKI_GRAPH_PROJECTION] Failed to dispatch page projection job after SQL commit.', [
                        'page_id' => $pageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('[WIKI_GRAPH_PROJECTION] Failed to register page projection dispatch callback.', [
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build forward and reverse links between every item in $fromSet and every
     * item in $toSet.
     *
     * @return array{int, int} [created, skipped]
     */
    private function buildBidirectional(
        EnterpriseWikiIngestRun $run,
        array $fromSet,
        array $toSet,
        string $forwardType,
        string $reverseType,
    ): array {
        $created = 0;
        $skipped = 0;

        foreach ($fromSet as $from) {
            foreach ($toSet as $to) {
                [$c, $s] = $this->upsertLink($run, $from, $to, $forwardType);
                $created += $c;
                $skipped += $s;

                [$c, $s] = $this->upsertLink($run, $to, $from, $reverseType);
                $created += $c;
                $skipped += $s;
            }
        }

        return [$created, $skipped];
    }

    /**
     * Create the link if it does not exist; skip silently if it does.
     *
     * @return array{int, int} [1, 0] if created, [0, 1] if already existed
     */
    private function upsertLink(
        EnterpriseWikiIngestRun $run,
        array $from,
        array $to,
        string $linkType,
    ): array {
        $link = EnterpriseWikiPageLink::firstOrCreate(
            [
                'customer_id' => $run->customer_id,
                'from_page_id' => $from['page']->id,
                'to_page_id' => $to['page']->id,
                'link_type' => $linkType,
            ],
            [
                'enterprise_wiki_ingest_run_id' => $run->id,
                'from_page_version_id' => $from['version']?->id,
                'to_page_version_id' => $to['version']?->id,
                'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
            ]
        );

        return $link->wasRecentlyCreated ? [1, 0] : [0, 1];
    }
}
