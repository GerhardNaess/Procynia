<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use Illuminate\Support\Facades\Log;

/**
 * Shared, bounded Wiki navigation for Q&A and tender requirements. A navigation model chooses
 * reading seeds from a compact, current customer Wiki index; lexical ranking is retained only as
 * metadata for later semantic selection and never determines which pages the model may choose.
 */
class EnterpriseWikiSemanticRetrievalService
{
    public const MAX_INDEX_PAGES = 100;

    public const MAX_INDEX_HEADINGS_PER_PAGE = 6;

    public const MAX_INDEX_LINKS_PER_PAGE = 4;

    public const MAX_GRAPH_SEEDS = 8;

    public const MAX_GRAPH_CANDIDATES = 8;

    public const MAX_CANDIDATE_POOL = 16;

    public function __construct(
        private readonly RequirementWikiCatalogBuilder $catalogBuilder,
        private readonly RequirementWikiPageRanker $ranker,
        private readonly RequirementWikiLinkNavigator $linkNavigator,
        private readonly EnterpriseWikiSemanticSearchPlanAiClient $searchPlanAiClient,
    ) {}

    /**
     * @param  list<string>|null  $statuses
     * @return array{catalog: list<array<string, mixed>>, navigation_plan: array<string, mixed>, candidate_pool: list<array<string, mixed>>, telemetry: array<string, mixed>}
     */
    public function retrieve(string $input, int $customerId, string $languageCode, ?array $statuses = null, bool $requireCurrentVersionApproval = false): array
    {
        $input = trim($input);
        $catalog = $this->catalogBuilder->build($customerId, $statuses, $requireCurrentVersionApproval);
        [$wikiIndex, $indexOmittedCount] = $this->buildWikiIndex($catalog, $customerId);

        if ($wikiIndex === []) {
            return [
                'catalog' => $catalog,
                'navigation_plan' => $this->emptyNavigationPlan(),
                'candidate_pool' => [],
                'telemetry' => $this->telemetry($input, $wikiIndex, $indexOmittedCount, [], [], [], []),
            ];
        }

        $navigationPlan = $this->searchPlanAiClient->planWikiReading($input, $wikiIndex, $languageCode);
        $lexicalScores = $this->lexicalScores($catalog, $input, $customerId);
        $seedCandidates = $this->seedCandidates($navigationPlan, $catalog, $lexicalScores);
        $graphCandidates = $this->graphCandidates($seedCandidates, $catalog, $customerId);
        $pool = array_slice($this->mergeCandidates($seedCandidates, $graphCandidates), 0, self::MAX_CANDIDATE_POOL);
        $telemetry = $this->telemetry($input, $wikiIndex, $indexOmittedCount, $navigationPlan, $seedCandidates, $graphCandidates, $pool);

        Log::info('[WIKI_SEMANTIC_RETRIEVAL] Navigation completed.', array_merge(['customer_id' => $customerId], $telemetry));

        return [
            'catalog' => $catalog,
            'navigation_plan' => $navigationPlan,
            'candidate_pool' => $pool,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildWikiIndex(array $catalog, int $customerId): array
    {
        usort($catalog, static fn (array $left, array $right): int => $left['page_id'] <=> $right['page_id']);
        $indexCatalog = array_slice($catalog, 0, self::MAX_INDEX_PAGES);
        $indexPageIds = array_column($indexCatalog, 'page_id');
        $titlesByPageId = [];

        foreach ($indexCatalog as $entry) {
            $titlesByPageId[(int) $entry['page_id']] = (string) $entry['title'];
        }

        $linksByPageId = [];

        if ($indexPageIds !== []) {
            $links = EnterpriseWikiPageLink::query()
                ->where('customer_id', $customerId)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->whereIn('from_page_id', $indexPageIds)
                ->whereIn('to_page_id', $indexPageIds)
                ->orderBy('from_page_id')
                ->orderBy('to_page_id')
                ->get(['from_page_id', 'to_page_id']);

            foreach ($links as $link) {
                $fromPageId = (int) $link->from_page_id;

                if (count($linksByPageId[$fromPageId] ?? []) >= self::MAX_INDEX_LINKS_PER_PAGE) {
                    continue;
                }

                $linksByPageId[$fromPageId][] = [
                    'page_id' => (int) $link->to_page_id,
                    'title' => $titlesByPageId[(int) $link->to_page_id] ?? '',
                ];
            }
        }

        $wikiIndex = array_map(static fn (array $entry): array => [
            'page_id' => (int) $entry['page_id'],
            'title' => (string) $entry['title'],
            'page_type' => (string) $entry['page_type'],
            'slug' => (string) $entry['slug'],
            'scope' => (string) $entry['scope'],
            'summary' => (string) $entry['excerpt'],
            'headings' => array_slice(array_values((array) $entry['headings']), 0, self::MAX_INDEX_HEADINGS_PER_PAGE),
            'outgoing_wiki_links' => $linksByPageId[(int) $entry['page_id']] ?? [],
        ], $indexCatalog);

        return [$wikiIndex, max(0, count($catalog) - count($wikiIndex))];
    }

    /** @param list<array<string, mixed>> $catalog @return array<int, array{score: int, score_breakdown: array<string, mixed>}> */
    private function lexicalScores(array $catalog, string $input, int $customerId): array
    {
        $tokens = RequirementWikiTermNormalizer::tokenize($input);
        $ranked = $tokens === [] ? [] : $this->ranker->rank($catalog, $tokens, $customerId);
        $scores = [];

        foreach ($ranked as $candidate) {
            $scores[(int) $candidate['page_id']] = [
                'score' => (int) $candidate['score'],
                'score_breakdown' => (array) $candidate['score_breakdown'],
            ];
        }

        return $scores;
    }

    /** @param array<string, mixed> $navigationPlan @param list<array<string, mixed>> $catalog @param array<int, array{score: int, score_breakdown: array<string, mixed>}> $lexicalScores @return list<array<string, mixed>> */
    private function seedCandidates(array $navigationPlan, array $catalog, array $lexicalScores): array
    {
        $catalogByPageId = [];

        foreach ($catalog as $entry) {
            $catalogByPageId[(int) $entry['page_id']] = $entry;
        }

        $candidates = [];

        foreach ($navigationPlan['selected_pages'] as $selection) {
            $pageId = (int) $selection['page_id'];
            $entry = $catalogByPageId[$pageId] ?? null;

            if ($entry === null) {
                continue;
            }

            $lexical = $lexicalScores[$pageId] ?? ['score' => 0, 'score_breakdown' => []];
            $candidates[] = [
                ...$entry,
                'score' => $lexical['score'],
                'score_breakdown' => $lexical['score_breakdown'],
                'retrieval_sources' => ['wiki_index_navigation'],
                'navigation_intended_use' => $selection['intended_use'],
                'navigation_reason' => $selection['reason'],
            ];
        }

        return $candidates;
    }

    /** @param list<array<string, mixed>> $seedCandidates @param list<array<string, mixed>> $catalog @return list<array<string, mixed>> */
    private function graphCandidates(array $seedCandidates, array $catalog, int $customerId): array
    {
        $seedIds = array_slice(array_column($seedCandidates, 'page_id'), 0, self::MAX_GRAPH_SEEDS);

        if ($seedIds === []) {
            return [];
        }

        $seeds = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $seedIds)
            ->where('status', EnterpriseWikiPage::STATUS_APPROVED)
            ->get()
            ->all();
        $catalogByPageId = [];
        $seedsByPageId = [];

        foreach ($catalog as $entry) {
            $catalogByPageId[(int) $entry['page_id']] = $entry;
        }

        foreach ($seedCandidates as $candidate) {
            $seedsByPageId[(int) $candidate['page_id']] = $candidate;
        }

        $neighbors = $this->linkNavigator->discoverNeighbors($seeds, $customerId, $seedIds);
        $candidates = [];

        foreach (array_slice($neighbors, 0, self::MAX_GRAPH_CANDIDATES) as $neighbor) {
            $pageId = (int) $neighbor['page_id'];
            $entry = $catalogByPageId[$pageId] ?? null;
            $parent = $seedsByPageId[(int) $neighbor['discovered_from_page_id']] ?? null;

            if ($entry === null || $parent === null) {
                continue;
            }

            $candidates[] = [
                ...$entry,
                'score' => 0,
                'score_breakdown' => ['graph_neighbor_of_page_id' => (int) $parent['page_id']],
                'retrieval_sources' => ['wiki_graph'],
                'navigation_intended_use' => 'supporting_context',
                'navigation_reason' => 'One-hop Wiki neighbour of navigation seed.',
                'discovered_from_page_id' => (int) $neighbor['discovered_from_page_id'],
                'discovered_from_title' => $neighbor['discovered_from_title'],
                'link_direction' => $neighbor['link_direction'],
            ];
        }

        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    private function mergeCandidates(array $seedCandidates, array $graphCandidates): array
    {
        $merged = [];

        foreach ([...$seedCandidates, ...$graphCandidates] as $candidate) {
            $merged[(int) $candidate['page_id']] ??= $candidate;
        }

        return array_values($merged);
    }

    /** @return array<string, mixed> */
    private function emptyNavigationPlan(): array
    {
        return [
            'query_understanding' => ['topic' => '', 'scope' => 'unknown', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'intent' => ''],
            'selected_pages' => [],
            'model' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function telemetry(string $input, array $wikiIndex, int $indexOmittedCount, array $navigationPlan, array $seedCandidates, array $graphCandidates, array $pool): array
    {
        return [
            'input_sha256' => hash('sha256', $input),
            'wiki_index_page_count' => count($wikiIndex),
            'wiki_index_chars' => mb_strlen((string) json_encode($wikiIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'UTF-8'),
            'wiki_index_estimated_tokens' => (int) ceil(mb_strlen((string) json_encode($wikiIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'UTF-8') / 4),
            'wiki_index_omitted_page_count' => $indexOmittedCount,
            'navigation_understanding' => $navigationPlan['query_understanding'] ?? [],
            'navigation_metrics' => $navigationPlan['metrics'] ?? [],
            'selected_seed_pages' => array_map(static fn (array $candidate): array => [
                'page_id' => $candidate['page_id'],
                'intended_use' => $candidate['navigation_intended_use'],
                'reason' => $candidate['navigation_reason'],
                'lexical_score' => $candidate['score'],
            ], $seedCandidates),
            'traversed_graph_pages' => array_map(static fn (array $candidate): array => [
                'page_id' => $candidate['page_id'],
                'from_page_id' => $candidate['discovered_from_page_id'],
                'direction' => $candidate['link_direction'],
            ], $graphCandidates),
            'candidate_pool_ids' => array_column($pool, 'page_id'),
        ];
    }
}
