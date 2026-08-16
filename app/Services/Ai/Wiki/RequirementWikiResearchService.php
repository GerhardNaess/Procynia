<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Controlled, agent-like Wiki-research flow for one requirement (Fase 9 correction — Karpathy
 * query pattern): search the customer's approved Wiki for relevant pages, read their actual
 * content_markdown, follow their real wikilinks/backlinks to discover further candidates, read
 * more when that genuinely helps, and stop when the knowledge gathered is sufficient or a fixed
 * safety limit is reached. Never touches an unbounded amount of the Wiki, never recurses without
 * limit, and never reads the same page twice in one run.
 *
 * This service does not write the final answer — see RequirementWikiAnswerService, which consumes
 * the structured context this class returns.
 */
class RequirementWikiResearchService
{
    /** Hard ceiling on AI-driven research rounds (each round = one navigation decision). */
    public const MAX_RESEARCH_ROUNDS = 3;

    /** Hard ceiling on total distinct pages ever read in one research run. */
    public const MAX_PAGES_READ = 8;

    /** Hard ceiling on how many pages may be read in a single round, and on how many newly
     *  discovered link/backlink candidates are carried into the next round. */
    public const MAX_NEW_PAGES_PER_ROUND = 4;

    /** Hard ceiling on the combined character size of all read pages' content. */
    public const MAX_CONTEXT_SIZE = 24000;

    /** Minimum shared normalized tokens for a claim to be recorded as "supporting" a read page. */
    private const MIN_CLAIM_TOKEN_OVERLAP = 2;

    public function __construct(
        private readonly RequirementWikiCatalogBuilder $catalogBuilder,
        private readonly RequirementWikiPageRanker $ranker,
        private readonly RequirementWikiLinkNavigator $linkNavigator,
        private readonly RequirementWikiPageReader $pageReader,
        private readonly RequirementWikiResearchAiClient $researchAiClient,
        private readonly EnterpriseWikiSemanticRetrievalService $semanticRetrieval,
    ) {}

    /**
     * Purpose: Run one bounded research pass for a requirement.
     * Inputs: The requirement, its customer id, and the answer language (used only for the
     *         navigation client's "reason" field — the research decisions themselves are
     *         structural, not language-dependent).
     * Returns: A structured research context — requirement echo, initial ranked candidates
     *          (diagnostics), per-round decisions, every page actually read (with its content,
     *          content_mode, selected_headings, discovery provenance, and supporting claim ids),
     *          and the limits/stop reason. See RequirementWikiAnswerService for how this is
     *          persisted/presented.
     * Side effects: None beyond the OpenAI calls made by the navigation client (no writes).
     *
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when candidate pages exist but Enterprise Wiki AI generation is disabled
     */
    public function research(SavedNoticeAiRequirement $requirement, int $customerId, string $languageCode): array
    {
        $researchInput = trim(($requirement->requirement_identifier ?? '').' '.$requirement->requirement_text);
        $semanticRetrieval = $this->semanticRetrieval->retrieve($researchInput, $customerId, $languageCode);
        $catalog = $semanticRetrieval['catalog'];
        $catalogByPageId = [];

        foreach ($catalog as $entry) {
            $catalogByPageId[$entry['page_id']] = $entry;
        }

        $requirementTokens = RequirementWikiTermNormalizer::tokenize($researchInput);

        $initialCandidates = $semanticRetrieval['candidate_pool'];

        if ($initialCandidates === []) {
            $context = $this->buildContext($requirement, [], [], [], [], 'no_relevant_candidates', 0);
            $this->logRetrieval($customerId, $semanticRetrieval['telemetry'], $context);

            return $context;
        }

        if (! RequirementWikiResearchAiClient::isAvailable()) {
            throw new RuntimeException('RequirementWikiResearchService: Enterprise Wiki AI generation is not enabled for this environment.');
        }

        $currentCandidates = $this->tagSelectionType($initialCandidates, 'direct_search');
        $activeQueryTokens = $requirementTokens;

        $readPageIds = [];
        $readPages = [];
        $rounds = [];
        $contextSize = 0;
        $stopReason = null;
        $roundNumber = 0;

        while (true) {
            $roundNumber++;

            if ($roundNumber > self::MAX_RESEARCH_ROUNDS) {
                $stopReason = 'max_rounds_reached';
                break;
            }

            if (count($readPageIds) >= self::MAX_PAGES_READ) {
                $stopReason = 'max_pages_reached';
                break;
            }

            if ($contextSize >= self::MAX_CONTEXT_SIZE) {
                $stopReason = 'max_context_reached';
                break;
            }

            if ($currentCandidates === []) {
                $stopReason = 'no_more_candidates';
                break;
            }

            $budget = [
                'round_number' => $roundNumber,
                'remaining_rounds' => self::MAX_RESEARCH_ROUNDS - $roundNumber + 1,
                'remaining_pages' => self::MAX_PAGES_READ - count($readPageIds),
                'remaining_context_chars' => self::MAX_CONTEXT_SIZE - $contextSize,
            ];

            $decision = $this->researchAiClient->selectNextAction(
                (string) ($requirement->requirement_identifier ?? ''),
                (string) $requirement->requirement_text,
                $this->candidatePayload($currentCandidates),
                $this->alreadyReadPayload($readPages),
                $budget,
                $languageCode,
            );

            if ($decision['action'] === RequirementWikiResearchAiClient::ACTION_ENOUGH_CONTEXT) {
                $rounds[] = ['round' => $roundNumber, 'action' => 'enough_context', 'selected_page_ids' => [], 'reason' => $decision['reason']];
                $stopReason = 'enough_context';
                break;
            }

            if ($decision['action'] === RequirementWikiResearchAiClient::ACTION_INSUFFICIENT) {
                $rounds[] = ['round' => $roundNumber, 'action' => 'insufficient', 'selected_page_ids' => [], 'reason' => $decision['reason']];
                $stopReason = 'insufficient';
                break;
            }

            if ($decision['action'] === RequirementWikiResearchAiClient::ACTION_SEARCH_MORE) {
                $searchTokens = RequirementWikiTermNormalizer::tokenize(implode(' ', $decision['search_terms']));
                $activeQueryTokens = array_values(array_unique([...$requirementTokens, ...$searchTokens]));
                $currentCandidates = $this->tagSelectionType(
                    $this->ranker->rank($catalog, $activeQueryTokens, $customerId, $readPageIds),
                    'direct_search',
                );
                $rounds[] = [
                    'round' => $roundNumber,
                    'action' => 'search_more',
                    'selected_page_ids' => [],
                    'search_terms' => $decision['search_terms'],
                    'reason' => $decision['reason'],
                ];

                continue;
            }

            // action === read_pages — service-side authoritative validation, never trusting the
            // AI client's own filtering alone.
            $allowedPageIds = array_column($currentCandidates, 'page_id');
            $requestedPageIds = array_slice(
                array_values(array_intersect($decision['page_ids'], $allowedPageIds)),
                0,
                self::MAX_NEW_PAGES_PER_ROUND,
            );

            if ($requestedPageIds === []) {
                $rounds[] = ['round' => $roundNumber, 'action' => 'read_pages', 'selected_page_ids' => [], 'reason' => $decision['reason']];
                $stopReason = 'no_valid_pages_selected';
                break;
            }

            $candidatesByPageId = [];

            foreach ($currentCandidates as $candidate) {
                $candidatesByPageId[$candidate['page_id']] = $candidate;
            }

            $roundReadPageIds = [];
            $contextExhausted = false;

            foreach ($requestedPageIds as $pageId) {
                if (count($readPageIds) >= self::MAX_PAGES_READ) {
                    break;
                }

                $catalogEntry = $catalogByPageId[$pageId] ?? null;

                if ($catalogEntry === null) {
                    continue;
                }

                $read = $this->pageReader->read($catalogEntry, $activeQueryTokens);
                $contentLength = mb_strlen($read['content_markdown'], 'UTF-8');

                if ($contextSize + $contentLength > self::MAX_CONTEXT_SIZE) {
                    $contextExhausted = true;

                    break;
                }

                $candidate = $candidatesByPageId[$pageId] ?? null;
                $contextSize += $contentLength;
                $readPageIds[] = $pageId;
                $roundReadPageIds[] = $pageId;
                $claimsByOrigin = $this->supportingClaimsByOrigin($pageId, $activeQueryTokens);

                $readPages[] = [
                    'page_id' => $pageId,
                    'title' => $catalogEntry['title'],
                    'page_type' => $catalogEntry['page_type'],
                    'slug' => $catalogEntry['slug'],
                    'selection_type' => $candidate['selection_type'] ?? 'direct_search',
                    'discovered_from_page_id' => $candidate['discovered_from_page_id'] ?? null,
                    'discovered_from_title' => $candidate['discovered_from_title'] ?? null,
                    'link_direction' => $candidate['link_direction'] ?? null,
                    'content_mode' => $read['content_mode'],
                    'content_markdown' => $read['content_markdown'],
                    'selected_headings' => $read['selected_headings'],
                    // Only figures this read actually covered. A figure the answer may use must
                    // come from a page that was read, not merely from a page that exists.
                    'figures' => $read['figures'],
                    // 'all' is kept for backward compatibility with older persisted research_trace
                    // readers; source_based/best_practice are the new origin-scoped buckets that
                    // let downstream steps (RequirementWikiAnswerService) know WHICH claims may be
                    // presented as documented customer fact vs. an undocumented suggestion.
                    'supporting_claim_ids' => $claimsByOrigin['all'],
                    'source_based_claim_ids' => $claimsByOrigin['source_based'],
                    'best_practice_claim_ids' => $claimsByOrigin['best_practice'],
                    'round_read' => $roundNumber,
                ];
            }

            $rounds[] = ['round' => $roundNumber, 'action' => 'read_pages', 'selected_page_ids' => $roundReadPageIds, 'reason' => $decision['reason']];

            if ($contextExhausted) {
                $stopReason = 'max_context_reached';
                break;
            }

            if (count($readPageIds) >= self::MAX_PAGES_READ) {
                $stopReason = 'max_pages_reached';
                break;
            }

            // Navigate: discover neighbors of the pages just read via real wikilinks/backlinks.
            $justReadModels = $roundReadPageIds === []
                ? []
                : EnterpriseWikiPage::query()->whereIn('id', $roundReadPageIds)->get()->all();

            $discovered = $justReadModels === []
                ? []
                : array_slice(
                    $this->linkNavigator->discoverNeighbors($justReadModels, $customerId, $readPageIds),
                    0,
                    self::MAX_NEW_PAGES_PER_ROUND,
                );
            $discovered = $this->tagSelectionType($discovered, 'wikilink');

            $unreadFromPreviousRound = array_values(array_filter(
                $currentCandidates,
                fn (array $candidate): bool => ! in_array($candidate['page_id'], $readPageIds, true),
            ));

            // direct_search precedence: inserted first so a page discovered both ways keeps its
            // original selection_type rather than being relabeled 'wikilink'.
            $merged = [];

            foreach ([...$unreadFromPreviousRound, ...$discovered] as $candidate) {
                $merged[$candidate['page_id']] ??= $candidate;
            }

            $currentCandidates = array_values($merged);
        }

        $context = $this->buildContext($requirement, $initialCandidates, $rounds, $readPages, $catalog, $stopReason, $contextSize);
        $this->logRetrieval($customerId, $semanticRetrieval['telemetry'], $context);

        return $context;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function tagSelectionType(array $candidates, string $selectionType): array
    {
        return array_map(
            static function (array $candidate) use ($selectionType): array {
                $candidate['selection_type'] ??= $selectionType;

                return $candidate;
            },
            $candidates,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{page_id: int, title: string, page_type: string, headings: list<string>, excerpt: string, discovered_from_title: ?string, link_direction: ?string}>
     */
    private function candidatePayload(array $candidates): array
    {
        return array_map(
            static fn (array $candidate): array => [
                'page_id' => $candidate['page_id'],
                'title' => $candidate['title'],
                'page_type' => $candidate['page_type'],
                'headings' => $candidate['headings'] ?? [],
                'excerpt' => $candidate['excerpt'] ?? '',
                'discovered_from_title' => $candidate['discovered_from_title'] ?? null,
                'link_direction' => $candidate['link_direction'] ?? null,
            ],
            $candidates,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $readPages
     * @return list<array{page_id: int, title: string, selected_headings: list<string>}>
     */
    private function alreadyReadPayload(array $readPages): array
    {
        return array_map(
            static fn (array $page): array => [
                'page_id' => $page['page_id'],
                'title' => $page['title'],
                'selected_headings' => $page['selected_headings'],
            ],
            $readPages,
        );
    }

    /**
     * Purpose: Find claims that support a read page, split by content_origin — the provenance
     *          signal downstream Wiki-answer generation needs to keep source-documented knowledge
     *          and best-practice suggestions visibly distinct (see docs/enterprise-llm-wiki-plan.md,
     *          "Arkitekturnotat — v0.9").
     * Inputs: The page id and the currently active query tokens.
     * Returns: 'all' (both origins together, kept for backward-compatible persisted-data readers),
     *          'source_based', and 'best_practice' claim id lists. Claims whose content_origin is
     *          unclassified/unsupported_generated_content/internal_error are deliberately excluded
     *          from every bucket — those are QA-flagged states, never a valid grounding for a
     *          customer-facing answer, whether as fact or as suggestion.
     * Side effects: None (one query per page).
     *
     * @param  list<string>  $queryTokens
     * @return array{all: list<int>, source_based: list<int>, best_practice: list<int>}
     */
    private function supportingClaimsByOrigin(int $pageId, array $queryTokens): array
    {
        $empty = ['all' => [], 'source_based' => [], 'best_practice' => []];

        if ($queryTokens === []) {
            return $empty;
        }

        $matches = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('conflict_flag', false)
            ->whereIn('content_origin', [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            ])
            ->whereHas('version', function ($query): void {
                $query->where('is_current', true);
            })
            ->get(['id', 'claim_text', 'content_origin'])
            ->filter(function (EnterpriseWikiClaim $claim) use ($queryTokens): bool {
                $claimTokens = RequirementWikiTermNormalizer::tokenize((string) $claim->claim_text);
                [$overlap] = RequirementWikiTermNormalizer::overlap($queryTokens, $claimTokens);

                return $overlap >= self::MIN_CLAIM_TOKEN_OVERLAP;
            });

        return [
            'all' => $matches->pluck('id')->values()->all(),
            'source_based' => $matches->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED)->pluck('id')->values()->all(),
            'best_practice' => $matches->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)->pluck('id')->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $initialCandidates
     * @param  list<array<string, mixed>>  $rounds
     * @param  list<array<string, mixed>>  $readPages
     * @param  list<array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    private function buildContext(
        SavedNoticeAiRequirement $requirement,
        array $initialCandidates,
        array $rounds,
        array $readPages,
        array $catalog,
        ?string $stopReason,
        int $contextSize,
    ): array {
        return [
            'requirement' => [
                'id' => $requirement->id,
                'text' => $requirement->requirement_text,
            ],
            'initial_candidates' => array_map(
                static fn (array $candidate): array => [
                    'page_id' => $candidate['page_id'],
                    'title' => $candidate['title'],
                    'score' => $candidate['score'],
                    'score_breakdown' => $candidate['score_breakdown'],
                ],
                $initialCandidates,
            ),
            'research_rounds' => $rounds,
            'pages' => $readPages,
            'limits' => [
                'catalog_size' => count($catalog),
                'rounds_used' => count($rounds),
                'pages_read' => count($readPages),
                'context_size' => $contextSize,
                'stop_reason' => $stopReason,
                'max_rounds' => self::MAX_RESEARCH_ROUNDS,
                'max_pages' => self::MAX_PAGES_READ,
                'max_context_size' => self::MAX_CONTEXT_SIZE,
            ],
        ];
    }

    /** @param array<string, mixed> $semanticTelemetry @param array<string, mixed> $context */
    private function logRetrieval(int $customerId, array $semanticTelemetry, array $context): void
    {
        Log::info('[WIKI_REQUIREMENT_RESEARCH] Retrieval completed.', [
            'customer_id' => $customerId,
            'semantic_navigation' => $semanticTelemetry,
            'initial_candidate_ids' => array_column($context['initial_candidates'], 'page_id'),
            'selected_page_ids_by_round' => array_map(static fn (array $round): array => [
                'round' => $round['round'],
                'action' => $round['action'],
                'page_ids' => $round['selected_page_ids'],
            ], $context['research_rounds']),
            'final_evidence_page_ids' => array_column($context['pages'], 'page_id'),
            'stop_reason' => $context['limits']['stop_reason'],
            'context_chars' => $context['limits']['context_size'],
        ]);
    }
}
