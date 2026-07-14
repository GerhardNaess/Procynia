<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Chooses which Wiki pages to read next during a Wiki-research run (Fase 9 correction — Karpathy
 * query flow: search → read → navigate → read more → stop). Never writes the final answer — see
 * RequirementWikiAnswerAiClient for that, entirely separate operation/schema.
 *
 * Follows the established Wiki AI client conventions (WikiPageClaimExtractionAiClient): fixed
 * model, ENTERPRISE_WIKI_AI_ENABLED gate, shared EnterpriseWikiResponsesDecoder.
 *
 * SAFETY CONTRACT: this client only ever sees page ids that RequirementWikiResearchService has
 * already determined are legal candidates for this round (same customer, approved, not yet read).
 * It filters its own output down to that same candidate set before returning — belt-and-braces;
 * the service performs its own authoritative validation independently (never trusts this client's
 * filtering alone) since the service, not the client, is the source of truth for what is allowed.
 */
class RequirementWikiResearchAiClient
{
    public const ACTION_READ_PAGES = 'read_pages';

    public const ACTION_SEARCH_MORE = 'search_more';

    public const ACTION_ENOUGH_CONTEXT = 'enough_context';

    public const ACTION_INSUFFICIENT = 'insufficient';

    public const ACTIONS = [
        self::ACTION_READ_PAGES,
        self::ACTION_SEARCH_MORE,
        self::ACTION_ENOUGH_CONTEXT,
        self::ACTION_INSUFFICIENT,
    ];

    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 500;

    private const PROMPT_NAME = 'requirement_wiki_research_navigation';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Decide the next research action for one round.
     * Inputs: The requirement identifier/text, this round's candidate pages (direct-search hits
     *         plus link/backlink-discovered pages — the full legal set for this round), the pages
     *         already read so far (id/title/headings only — enough to avoid re-requesting them or
     *         re-covering the same ground), and the remaining research budget.
     * Returns: {action, page_ids, search_terms, reason} — page_ids/search_terms filtered to be
     *          internally consistent with the requested action (see normalize()).
     * Side effects: None (one OpenAI call).
     *
     * @param  list<array{page_id: int, title: string, page_type: string, headings: list<string>, excerpt: string, discovered_from_title: ?string, link_direction: ?string}>  $candidatePages
     * @param  list<array{page_id: int, title: string, selected_headings: list<string>}>  $alreadyReadPages
     * @param  array{round_number: int, remaining_rounds: int, remaining_pages: int, remaining_context_chars: int}  $budget
     * @return array{action: string, page_ids: list<int>, search_terms: list<string>, reason: string}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed schema result
     */
    public function selectNextAction(
        string $requirementIdentifier,
        string $requirementText,
        array $candidatePages,
        array $alreadyReadPages,
        array $budget,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiResearchAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $candidatePages, $alreadyReadPages, $budget, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiResearchAiClient');

        return $this->normalize($decoded, $candidatePages);
    }

    /**
     * @param  list<array{page_id: int, title: string, page_type: string, headings: list<string>, excerpt: string, discovered_from_title: ?string, link_direction: ?string}>  $candidatePages
     * @return array{action: string, page_ids: list<int>, search_terms: list<string>, reason: string}
     */
    private function normalize(array $decoded, array $candidatePages): array
    {
        $action = $decoded['action'] ?? null;

        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('RequirementWikiResearchAiClient: response action was missing or invalid.');
        }

        $allowedPageIds = array_column($candidatePages, 'page_id');
        $pageIds = $decoded['page_ids'] ?? [];
        $pageIds = is_array($pageIds) ? $pageIds : [];
        $pageIds = array_values(array_unique(array_intersect(
            array_map(static fn (mixed $id): int => (int) $id, $pageIds),
            $allowedPageIds,
        )));

        $searchTerms = $decoded['search_terms'] ?? [];
        $searchTerms = is_array($searchTerms) ? $searchTerms : [];
        $searchTerms = array_values(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            $searchTerms,
        ), static fn (string $term): bool => $term !== ''));

        $reason = $decoded['reason'] ?? null;
        $reason = is_string($reason) ? trim($reason) : '';

        // action-specific consistency: an action that doesn't request pages/search never carries
        // leftover ids/terms from a schema-compliant but semantically inconsistent response.
        if ($action !== self::ACTION_READ_PAGES) {
            $pageIds = [];
        }

        if ($action !== self::ACTION_SEARCH_MORE) {
            $searchTerms = [];
        }

        return [
            'action' => $action,
            'page_ids' => $pageIds,
            'search_terms' => $searchTerms,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{page_id: int, title: string, page_type: string, headings: list<string>, excerpt: string, discovered_from_title: ?string, link_direction: ?string}>  $candidatePages
     * @param  list<array{page_id: int, title: string, selected_headings: list<string>}>  $alreadyReadPages
     * @param  array{round_number: int, remaining_rounds: int, remaining_pages: int, remaining_context_chars: int}  $budget
     */
    private function buildPayload(
        string $requirementIdentifier,
        string $requirementText,
        array $candidatePages,
        array $alreadyReadPages,
        array $budget,
        string $languageName,
    ): array {
        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            'ALREADY READ PAGES (do not request these again): '.($alreadyReadPages === [] ? '(none yet)' : json_encode(array_values($alreadyReadPages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'CANDIDATE PAGES AVAILABLE THIS ROUND: '.json_encode(array_values($candidatePages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'RESEARCH BUDGET: '.json_encode($budget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $userText,
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
            'temperature' => self::TEMPERATURE,
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            'You are the research-navigation step of a Wiki-reading agent. You decide which pages to read next — you never write the final answer.',
            "Respond in {$languageName} only for the \"reason\" field; page_ids/search_terms/action are structural.",
            'Rules:',
            '- Choose "read_pages" and list page_ids from CANDIDATE PAGES that can genuinely help answer the requirement — not pages that are merely in the same general topic area.',
            '- Prefer pages that add NEW knowledge not already covered by ALREADY READ PAGES. Do not re-request an already read page.',
            '- Choose "search_more" with concrete search_terms (used only in this customer\'s own Wiki, never external search or a general knowledge base) when the candidate pages are clearly insufficient but you have a concrete idea of what term might find better pages.',
            '- Choose "enough_context" when the pages already read are sufficient to answer the requirement well — stop before using the whole budget if you already have what is needed.',
            '- Choose "insufficient" when neither the candidates nor further search look likely to find anything useful for this requirement.',
            '- Respect the research budget — do not request more pages than remaining_pages, and be aware there are only remaining_rounds rounds left.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => self::ACTIONS,
                ],
                'page_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'search_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['action', 'page_ids', 'search_terms', 'reason'],
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
