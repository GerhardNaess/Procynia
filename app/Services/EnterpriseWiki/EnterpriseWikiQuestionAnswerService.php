<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Models\EnterpriseWikiPage;
use App\Services\Ai\AiUsageMeter;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticRetrievalService;
use App\Services\Ai\Wiki\RequirementWikiPageReader;
use App\Services\Ai\Wiki\RequirementWikiTermNormalizer;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use Illuminate\Support\Facades\Log;

/**
 * "Spør Wiki" — bounded retrieval over one customer's CURRENT Enterprise Wiki, then one grounded
 * answer.
 *
 * Read-only by construction: it creates no page version, no claim, no link, no finding and no run.
 * Nothing in this class writes to the Wiki at all.
 *
 * Retrieval deliberately reuses the existing Wiki retrieval stack rather than inventing a second
 * one — RequirementWikiCatalogBuilder / RequirementWikiTermNormalizer / RequirementWikiPageRanker /
 * RequirementWikiPageReader are all generic (token overlap over title, headings, content and
 * claims) with no domain vocabulary, and their bounds were already calibrated against real customer
 * Wiki data. What is NOT reused is the answering prompt: tender answer generation and Q&A want
 * opposite things from the model, so WikiQuestionAnswerAiClient is separate.
 *
 * Note it does NOT reuse EnterpriseWikiPatchCandidateService: that ranker is tuned for patching
 * (cap 3, numeric-anchored substance matching, one-hop graph from named pages) and would be the
 * wrong instrument for a natural-language question.
 *
 * The whole pipeline is a single turn: question in, answer out. No conversation state is kept.
 */
class EnterpriseWikiQuestionAnswerService
{
    /**
     * Pages whose content is actually sent to the model. Deliberately below the ranker's own
     * MAX_CANDIDATES (15): ranking may consider fifteen pages, but a factual question is answered
     * from a handful. Matches RequirementWikiResearchService::MAX_PAGES_READ, which was calibrated
     * against a real customer catalog of 31 approved pages.
     */
    public const MAX_CONTEXT_PAGES = 6;

    /**
     * Total context characters. RequirementWikiResearchService uses 24000 for a multi-round research
     * loop; a single-turn question needs less, and a smaller budget keeps the answer focused on the
     * genuinely top-ranked pages instead of diluting it with marginal ones.
     */
    public const MAX_CONTEXT_CHARS = 16000;

    public const MAX_QUESTION_CHARS = 500;

    public function __construct(
        private readonly EnterpriseWikiSemanticRetrievalService $semanticRetrieval,
        private readonly RequirementWikiPageReader $pageReader,
        private readonly WikiQuestionAnswerAiClient $aiClient,
        private readonly AiUsageMeter $usageMeter,
    ) {}

    /**
     * @param  list<string>  $visibleStatuses  The asking user's readable page statuses.
     * @return array{
     *   answer_status: string, answer: string, citations: list<array<string, mixed>>,
     *   retrieval: array{pages_considered: int, pages_used: int, context_chars: int, question_understanding: array<string, mixed>, ranking: list<array<string, mixed>>}
     * }
     */
    public function ask(string $question, int $customerId, array $visibleStatuses, string $languageCode, ?int $userId = null, ?string $requestCorrelationId = null): array
    {
        $question = trim($question);
        $queryTokens = RequirementWikiTermNormalizer::tokenize($question);

        // $visibleStatuses still gates whether the user may ASK at all, but no longer what may be
        // answered from: Spør Wiki grounds only in published versions. A reviewer who may read a
        // draft page still gets no answers out of it — reading unreviewed content is one thing,
        // having the AI present it as documented fact is another.
        $semanticRetrieval = $this->semanticRetrieval->retrieve($question, $customerId, $languageCode);
        $catalog = $semanticRetrieval['catalog'];
        $ranked = $semanticRetrieval['candidate_pool'];
        $candidateCountIn = count($ranked);
        $rerankStartedAt = microtime(true);
        $retrievalPlan = $ranked === []
            ? $this->emptyRetrievalPlan()
            : $this->usageMeter->within(
                $this->usageContext($customerId, $userId, 'wiki.ask.retrieval_plan', $requestCorrelationId),
                fn (): array => $this->aiClient->planRetrieval($question, $this->candidateSummaries($ranked), $languageCode),
            );
        $rerankLatencyMs = (int) round((microtime(true) - $rerankStartedAt) * 1000);
        $selectedPageIds = array_values(array_map(
            static fn (array $assessment): int => (int) $assessment['page_id'],
            $retrievalPlan['ranked_pages'],
        ));
        $ranked = $this->applyRetrievalPlan($ranked, $retrievalPlan);

        [$context, $ranking, $contextChars] = $this->buildContext($ranked, $catalog, $queryTokens);

        if ($context === []) {
            // Nothing relevant was retrieved, so there is nothing to ground an answer in. Answered
            // here rather than by the model: asking it to confirm an empty context wastes a call and
            // invites it to answer from general knowledge instead.
            $this->logRetrieval(
                $customerId,
                $question,
                count($catalog),
                $candidateCountIn,
                $selectedPageIds,
                $retrievalPlan['question_understanding'],
                $ranking,
                $contextChars,
                $semanticRetrieval['telemetry'],
                ['rerank_latency_ms' => $rerankLatencyMs, 'answer_latency_ms' => null, 'answer_status' => WikiQuestionAnswerAiClient::STATUS_INSUFFICIENT_EVIDENCE],
            );

            return [
                'answer_status' => WikiQuestionAnswerAiClient::STATUS_INSUFFICIENT_EVIDENCE,
                'answer' => '',
                'citations' => [],
                'retrieval' => $this->retrievalSummary(count($catalog), 0, 0, $retrievalPlan['question_understanding'], $ranking),
            ];
        }

        $answerStartedAt = microtime(true);
        $result = $this->usageMeter->within(
            $this->usageContext($customerId, $userId, 'wiki.ask.answer', $requestCorrelationId),
            fn (): array => $this->aiClient->answer($question, $context, $languageCode),
        );
        $answerLatencyMs = (int) round((microtime(true) - $answerStartedAt) * 1000);

        $this->logRetrieval(
            $customerId,
            $question,
            count($catalog),
            $candidateCountIn,
            $selectedPageIds,
            $retrievalPlan['question_understanding'],
            $ranking,
            $contextChars,
            $semanticRetrieval['telemetry'],
            ['rerank_latency_ms' => $rerankLatencyMs, 'answer_latency_ms' => $answerLatencyMs, 'answer_status' => $result['answer_status']],
        );

        return [
            'answer_status' => $result['answer_status'],
            'answer' => $result['answer'],
            'citations' => $this->resolveCitations($result['citations'], $context),
            'retrieval' => $this->retrievalSummary(count($catalog), count($context), $contextChars, $retrievalPlan['question_understanding'], $ranking),
        ];
    }

    private function usageContext(int $customerId, ?int $userId, string $operation, ?string $requestCorrelationId): AiCallContext
    {
        return new AiCallContext(
            customerId: $customerId,
            userId: $userId,
            feature: 'wiki',
            operation: $operation,
            resourceType: 'enterprise_wiki',
            requestCorrelationId: $requestCorrelationId,
        );
    }

    /**
     * Deterministic truncation: highest score first, page_id ascending as tiebreak (the ranker's own
     * order), stopping at whichever bound is reached first. A page that would overflow the character
     * budget is skipped rather than partially included, so an excerpt is never cut mid-sentence in a
     * way that changes its meaning.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<string>  $queryTokens
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    private function buildContext(array $ranked, array $catalog, array $queryTokens): array
    {
        $catalogByPageId = [];

        foreach ($catalog as $entry) {
            $catalogByPageId[(int) $entry['page_id']] = $entry;
        }

        $context = [];
        $ranking = [];
        $contextChars = 0;

        foreach ($ranked as $index => $candidate) {
            $pageId = (int) $candidate['page_id'];
            $catalogEntry = $catalogByPageId[$pageId] ?? null;
            $included = false;
            $reason = 'not_ranked';

            if ($catalogEntry === null) {
                $reason = 'missing_from_catalog';
            } elseif (($candidate['semantic_retrieval_fit'] ?? null) === 'wrong_scope') {
                $reason = 'semantic_wrong_scope';
            } elseif (($candidate['semantic_retrieval_fit'] ?? null) === 'unrelated') {
                $reason = 'semantic_unrelated';
            } elseif (count($context) >= self::MAX_CONTEXT_PAGES) {
                $reason = 'page_cap_reached';
            } else {
                $read = $this->pageReader->read($catalogEntry, $queryTokens);
                $length = mb_strlen($read['content_markdown'], 'UTF-8');

                if ($contextChars + $length > self::MAX_CONTEXT_CHARS) {
                    $reason = 'context_budget_reached';
                } else {
                    $contextChars += $length;
                    $included = true;
                    $reason = 'included';

                    $context[] = [
                        'page_id' => $pageId,
                        'page_title' => (string) $candidate['title'],
                        'page_slug' => (string) $candidate['slug'],
                        'page_type' => (string) $candidate['page_type'],
                        'page_scope' => (string) $candidate['scope'],
                        'page_version_id' => $this->currentVersionIdFor($pageId),
                        'content_markdown' => $read['content_markdown'],
                        'content_mode' => $read['content_mode'],
                        'selected_headings' => $read['selected_headings'],
                    ];
                }
            }

            $ranking[] = [
                'rank' => $index + 1,
                'page_id' => $pageId,
                'title' => (string) $candidate['title'],
                'page_type' => (string) $candidate['page_type'],
                'scope' => (string) $candidate['scope'],
                'score' => (int) $candidate['score'],
                'signals' => array_filter([
                    'deterministic' => $candidate['score_breakdown'] ?? [],
                    'semantic' => $candidate['semantic_scope'] ?? null,
                    'candidate_sources' => $candidate['retrieval_sources'] ?? [],
                    'semantic_intents' => $candidate['semantic_intents'] ?? [],
                ]),
                'included' => $included,
                'reason' => $reason,
            ];
        }

        return [$context, $ranking, $contextChars];
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function candidateSummaries(array $ranked): array
    {
        return array_map(static fn (array $candidate): array => [
            'page_id' => (int) $candidate['page_id'],
            'title' => (string) $candidate['title'],
            'page_type' => (string) $candidate['page_type'],
            'scope' => (string) $candidate['scope'],
            'headings' => array_values((array) $candidate['headings']),
            'excerpt' => (string) $candidate['excerpt'],
            'outgoing_link_count' => (int) $candidate['outgoing_link_count'],
            'backlink_count' => (int) $candidate['backlink_count'],
            'deterministic_score' => (int) $candidate['score'],
            'deterministic_signals' => $candidate['score_breakdown'] ?? [],
        ], $ranked);
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @param  array{question_understanding: array<string, mixed>, ranked_pages: list<array<string, mixed>>}  $retrievalPlan
     * @return list<array<string, mixed>>
     */
    private function applyRetrievalPlan(array $ranked, array $retrievalPlan): array
    {
        $rankedByPageId = [];

        foreach ($ranked as $candidate) {
            $rankedByPageId[(int) $candidate['page_id']] = $candidate;
        }

        $planned = [];
        $seen = [];

        foreach ($retrievalPlan['ranked_pages'] as $assessment) {
            $pageId = (int) ($assessment['page_id'] ?? 0);

            if (! isset($rankedByPageId[$pageId]) || isset($seen[$pageId])) {
                continue;
            }

            $seen[$pageId] = true;
            $planned[] = array_merge($rankedByPageId[$pageId], [
                'semantic_retrieval_fit' => (string) ($assessment['retrieval_fit'] ?? 'background'),
                'semantic_scope' => [
                    'page_scope' => (string) ($assessment['page_scope'] ?? 'unknown'),
                    'entities' => array_values((array) ($assessment['entities'] ?? [])),
                    'services_or_systems' => array_values((array) ($assessment['services_or_systems'] ?? [])),
                    'is_general' => (bool) ($assessment['is_general'] ?? false),
                    'is_specific' => (bool) ($assessment['is_specific'] ?? false),
                    'retrieval_fit' => (string) ($assessment['retrieval_fit'] ?? 'background'),
                    'reason' => (string) ($assessment['reason'] ?? ''),
                ],
            ]);
        }

        return $planned;
    }

    /** @return array{question_understanding: array<string, mixed>, ranked_pages: list<array<string, mixed>>} */
    private function emptyRetrievalPlan(): array
    {
        return [
            'question_understanding' => [
                'topic' => '',
                'question_scope' => 'unknown',
                'explicit_entities' => [],
                'explicit_services_or_systems' => [],
                'question_intent' => 'unknown',
            ],
            'ranked_pages' => [],
        ];
    }

    private function currentVersionIdFor(int $pageId): ?int
    {
        $page = EnterpriseWikiPage::query()->with('currentVersion')->find($pageId);

        return $page?->currentVersion?->id !== null ? (int) $page->currentVersion->id : null;
    }

    /**
     * Rebuilds every citation from the retrieval context rather than trusting the model's own
     * metadata. The model may only name a page_id; the title, slug and version come from what we
     * actually sent. A citation naming a page that was never in the context is DROPPED — that is the
     * hard guarantee that a citation can never point somewhere the answer was not grounded in, and
     * it also means a hallucinated page id cannot become a link.
     *
     * @param  list<array<string, mixed>>  $citations
     * @param  list<array<string, mixed>>  $context
     * @return list<array<string, mixed>>
     */
    private function resolveCitations(array $citations, array $context): array
    {
        $contextByPageId = [];

        foreach ($context as $entry) {
            $contextByPageId[(int) $entry['page_id']] = $entry;
        }

        $resolved = [];
        $seen = [];

        foreach ($citations as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $pageId = (int) ($citation['page_id'] ?? 0);
            $entry = $contextByPageId[$pageId] ?? null;

            if ($entry === null) {
                Log::warning('[WIKI_ASK] Dropped a citation naming a page that was not in the retrieval context.', [
                    'page_id' => $pageId,
                ]);

                continue;
            }

            $heading = trim((string) ($citation['heading'] ?? ''));
            $excerpt = trim((string) ($citation['excerpt'] ?? ''));
            $key = $pageId.'|'.$heading.'|'.$excerpt;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $resolved[] = [
                'page_id' => $pageId,
                'page_title' => $entry['page_title'],
                'page_slug' => $entry['page_slug'],
                'page_version_id' => $entry['page_version_id'],
                'heading' => $heading !== '' ? $heading : null,
                'excerpt' => $excerpt,
            ];
        }

        return $resolved;
    }

    /** @param  list<array<string, mixed>>  $ranking */
    private function retrievalSummary(int $pagesConsidered, int $pagesUsed, int $contextChars, array $questionUnderstanding, array $ranking): array
    {
        return [
            'pages_considered' => $pagesConsidered,
            'pages_used' => $pagesUsed,
            'context_chars' => $contextChars,
            'question_understanding' => $questionUnderstanding,
            'ranking' => $ranking,
        ];
    }

    /**
     * Observability the patch-candidate work taught us to build in from the start: after a question
     * it must be answerable why a page was or was not used. Logged rather than persisted — a Q&A
     * query is not durable state, and no table exists (or is needed) to hold it.
     *
     * @param  list<array<string, mixed>>  $ranking
     */
    private function logRetrieval(
        int $customerId,
        string $question,
        int $pagesConsidered,
        int $candidateCountIn,
        array $selectedPageIds,
        array $questionUnderstanding,
        array $ranking,
        int $contextChars,
        array $semanticTelemetry,
        array $executionMetrics,
    ): void {
        Log::info('[WIKI_ASK] Retrieval completed.', [
            'customer_id' => $customerId,
            'question_chars' => mb_strlen($question, 'UTF-8'),
            'question_understanding' => $questionUnderstanding,
            'pages_considered' => $pagesConsidered,
            'candidate_count_in' => $candidateCountIn,
            'selected_count' => count($selectedPageIds),
            'selected_page_ids' => $selectedPageIds,
            'omitted_count' => max(0, $candidateCountIn - count($selectedPageIds)),
            'pages_ranked' => count($ranking),
            'pages_used' => count(array_filter($ranking, static fn (array $row): bool => $row['included'] === true)),
            'context_chars' => $contextChars,
            'max_context_pages' => self::MAX_CONTEXT_PAGES,
            'max_context_chars' => self::MAX_CONTEXT_CHARS,
            'ranking' => array_map(static fn (array $row): array => [
                'rank' => $row['rank'],
                'page_id' => $row['page_id'],
                'title' => $row['title'],
                'scope' => $row['scope'],
                'score' => $row['score'],
                'signals' => $row['signals'],
                'included' => $row['included'],
                'reason' => $row['reason'],
            ], $ranking),
            'semantic_navigation' => $semanticTelemetry,
            'execution_metrics' => $executionMetrics,
        ]);
    }
}
