<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Extracts factual claims from a generated wiki page's content_markdown.
 *
 * Distinct from WikiSectionAiClient (which extracts from raw source sections).
 * This client operates on already-compiled wiki articles/summaries/concept/entity pages.
 *
 * CONFIDENCE CONTRACT: confidence is a string enum — 'high', 'medium', 'low', 'uncertain' —
 * matching EnterpriseWikiClaim::CONFIDENCE_* constants and the enterprise_wiki_claims.confidence
 * string column. The 8E-14 spec showed confidence as 0.0 (illustrative); the canonical type is
 * the same string enum used by WikiSectionAiClient and enforced by the JSON schema here.
 * Changing to float would require a DB migration and is intentionally deferred.
 */
class WikiPageClaimExtractionAiClient
{
    public const MAX_CLAIMS = 20;

    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 4000;

    private const MAX_INPUT_CHARS = 6000;

    private const PROMPT_NAME = 'wiki_page_claim_extraction';

    private const MANUAL_MIXED_BLOCK_PROMPT_NAME = 'wiki_page_claim_extraction_manual_mixed_block';

    private const ALLOWED_MANUAL_MIXED_BLOCK_ORIGINS = [
        EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
    ];

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Extract factual claims from the content_markdown of a wiki page.
     *
     * Returns at most MAX_CLAIMS claims. Input is trimmed to MAX_INPUT_CHARS.
     *
     * @return array{claims: list<array{text: string, confidence: string, excerpt: string, conflict_note: string|null}>}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or missing claims key
     */
    public function extractClaims(
        string $pageTitle,
        string $pageType,
        string $contentMarkdown,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: wiki AI generation is not enabled.');
        }

        $trimmed = mb_substr(trim($contentMarkdown), 0, self::MAX_INPUT_CHARS);
        $payload = $this->buildPayload($pageTitle, $pageType, $trimmed, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'WikiPageClaimExtractionAiClient');

        if (! array_key_exists('claims', $decoded) || ! is_array($decoded['claims'])) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: response did not include a claims array.');
        }

        $claims = array_slice($decoded['claims'], 0, self::MAX_CLAIMS);
        $normalized = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            if (! is_string($claim['text'] ?? null)
                || trim($claim['text']) === ''
                || ! is_string($claim['confidence'] ?? null)
                || ! in_array($claim['confidence'], ['high', 'medium', 'low', 'uncertain'], true)
                || ! is_string($claim['excerpt'] ?? null)
                || ! (is_string($claim['conflict_note'] ?? null) || ($claim['conflict_note'] ?? null) === null)) {
                continue;
            }

            $normalized[] = $claim;
        }

        return ['claims' => $normalized];
    }

    /**
     * Extract claims from one manually edited mixed-provenance block. Unlike extractClaims(),
     * this contract requires an explicit, validated content_origin for every returned claim.
     *
     * @param  list<array{key: string, type: string|null, text: string}>  $sourceElements
     * @return array{claims: list<array{text: string, confidence: string, excerpt: string, content_origin: string, source_element_keys: list<string>, best_practice_reason: string|null, conflict_note: string|null}>}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, missing claims key, or any invalid claim
     */
    public function extractClaimsForManualMixedBlock(
        string $pageTitle,
        string $pageType,
        string $blockMarkdown,
        string $contentBlockKey,
        array $sourceElements,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: wiki AI generation is not enabled.');
        }

        $normalizedSourceElements = $this->normalizeManualMixedBlockSourceElements($sourceElements);
        $allowedSourceKeys = array_column($normalizedSourceElements, 'key');
        $trimmed = mb_substr(trim($blockMarkdown), 0, self::MAX_INPUT_CHARS);
        $payload = $this->buildManualMixedBlockPayload(
            pageTitle: $pageTitle,
            pageType: $pageType,
            content: $trimmed,
            contentBlockKey: $contentBlockKey,
            sourceElements: $normalizedSourceElements,
            languageName: $this->languageName($languageCode),
        );
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'WikiPageClaimExtractionAiClient');

        if (! array_key_exists('claims', $decoded) || ! is_array($decoded['claims'])) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: response did not include a claims array.');
        }

        $claims = array_slice($decoded['claims'], 0, self::MAX_CLAIMS);
        $normalized = [];

        foreach ($claims as $index => $claim) {
            $normalized[] = $this->normalizeManualMixedBlockClaim($claim, $allowedSourceKeys, $index);
        }

        return ['claims' => $normalized];
    }

    private function buildPayload(string $pageTitle, string $pageType, string $content, string $languageName): array
    {
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
                            'text' => "Page title: {$pageTitle}\nPage type: {$pageType}\n\n{$content}",
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

    /**
     * @param  list<array{key: string, type: string|null, text: string}>  $sourceElements
     */
    private function buildManualMixedBlockPayload(
        string $pageTitle,
        string $pageType,
        string $content,
        string $contentBlockKey,
        array $sourceElements,
        string $languageName,
    ): array {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->manualMixedBlockDeveloperPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->manualMixedBlockUserPrompt($pageTitle, $pageType, $contentBlockKey, $content, $sourceElements),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::MANUAL_MIXED_BLOCK_PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::manualMixedBlockSchema(),
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
            'You are an extract-only model. Extract factual claims from the provided wiki page content.',
            "Source language: {$languageName}.",
            'Rules:',
            '- Extract only claims directly and explicitly supported by the page text.',
            '- Each claim must be a short, verifiable statement of fact.',
            '- For each claim, provide a verbatim excerpt from the page as supporting evidence.',
            '- One sentence is one claim: do not split a single sentence into two or more claims at a conjunction ("and", "og") or a relative clause ("which", "som", "der"). Only split a sentence into multiple claims when it states two genuinely independent facts.',
            '- Do not extract a heading or title on its own as a claim — only extract from body sentences.',
            '- Do not extract navigation, cross-reference, or "see also" text as a claim.',
            '- Do not return two claims that state substantially the same fact — if the page repeats a fact, extract it only once.',
            '- Set conflict_note only when the page text itself contains a genuine contradiction.',
            '- If the page contains insufficient basis for any claim, return an empty claims array.',
            '- Do not use external knowledge or assumptions beyond what the text states.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function manualMixedBlockDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            'You are an extract-and-classify model for exactly one manually edited mixed-provenance wiki content block.',
            "Source language: {$languageName}.",
            'Rules:',
            '- Extract only short, verifiable claims from the provided block markdown.',
            '- For each claim, return the exact excerpt from the block markdown that anchors the claim.',
            '- Classify each claim using content_origin only from: source_based, best_practice, unsupported_generated_content.',
            '- Never return mixed or unclassified as a claim content_origin; mixed is only block provenance.',
            '- Use source_based only when the claim is supported by one or more of the allowed source elements and cite those exact source_element_keys.',
            '- Use best_practice only for normative recommendations or practice advice, never for concrete customer facts. Include best_practice_reason.',
            '- Use unsupported_generated_content for concrete factual claims that are not supported by the provided source elements.',
            '- best_practice and unsupported_generated_content claims must have an empty source_element_keys array.',
            '- Do not use external knowledge or assumptions beyond the block text and allowed source elements.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array{key: string, type: string|null, text: string}>  $sourceElements
     */
    private function manualMixedBlockUserPrompt(string $pageTitle, string $pageType, string $contentBlockKey, string $content, array $sourceElements): string
    {
        $sourceElementText = collect($sourceElements)
            ->map(function (array $sourceElement): string {
                $type = $sourceElement['type'] ?? 'unknown';

                return "[{$sourceElement['key']}] ({$type}) {$sourceElement['text']}";
            })
            ->implode("\n");

        if ($sourceElementText === '') {
            $sourceElementText = '(none)';
        }

        return implode("\n", [
            "Page title: {$pageTitle}",
            "Page type: {$pageType}",
            "Content block key: {$contentBlockKey}",
            '',
            'Allowed source elements for this block:',
            $sourceElementText,
            '',
            'Block markdown:',
            $content,
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'confidence' => [
                                'type' => 'string',
                                'enum' => ['high', 'medium', 'low', 'uncertain'],
                            ],
                            'excerpt' => ['type' => 'string'],
                            'conflict_note' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                        'required' => ['text', 'confidence', 'excerpt', 'conflict_note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['claims'],
            'additionalProperties' => false,
        ];
    }

    private static function manualMixedBlockSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'confidence' => [
                                'type' => 'string',
                                'enum' => ['high', 'medium', 'low', 'uncertain'],
                            ],
                            'excerpt' => ['type' => 'string'],
                            'content_origin' => [
                                'type' => 'string',
                                'enum' => self::ALLOWED_MANUAL_MIXED_BLOCK_ORIGINS,
                            ],
                            'source_element_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'best_practice_reason' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                            'conflict_note' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                        'required' => [
                            'text',
                            'confidence',
                            'excerpt',
                            'content_origin',
                            'source_element_keys',
                            'best_practice_reason',
                            'conflict_note',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['claims'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  list<array{key: string, type: string|null, text: string}>  $sourceElements
     * @return list<array{key: string, type: string|null, text: string}>
     */
    private function normalizeManualMixedBlockSourceElements(array $sourceElements): array
    {
        $normalized = [];
        $seen = [];

        foreach ($sourceElements as $sourceElement) {
            if (! is_array($sourceElement)) {
                throw new RuntimeException('WikiPageClaimExtractionAiClient: manual mixed block source element was not an object.');
            }

            $key = trim((string) ($sourceElement['key'] ?? ''));
            $text = trim((string) ($sourceElement['text'] ?? ''));

            if ($key === '' || $text === '') {
                throw new RuntimeException('WikiPageClaimExtractionAiClient: manual mixed block source element requires key and text.');
            }

            if (in_array($key, $seen, true)) {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: duplicate source element key [{$key}].");
            }

            $seen[] = $key;
            $normalized[] = [
                'key' => $key,
                'type' => is_string($sourceElement['type'] ?? null) ? $sourceElement['type'] : null,
                'text' => mb_substr($text, 0, self::MAX_INPUT_CHARS),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $allowedSourceKeys
     * @return array{text: string, confidence: string, excerpt: string, content_origin: string, source_element_keys: list<string>, best_practice_reason: string|null, conflict_note: string|null}
     */
    private function normalizeManualMixedBlockClaim(mixed $claim, array $allowedSourceKeys, int $index): array
    {
        if (! is_array($claim)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] was not an object.");
        }

        $text = is_string($claim['text'] ?? null) ? trim($claim['text']) : '';
        $confidence = $claim['confidence'] ?? null;
        $excerpt = is_string($claim['excerpt'] ?? null) ? trim($claim['excerpt']) : '';
        $contentOrigin = $claim['content_origin'] ?? null;
        $bestPracticeReason = $claim['best_practice_reason'] ?? null;
        $conflictNote = $claim['conflict_note'] ?? null;

        if ($text === '') {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] is missing text.");
        }

        if (! is_string($confidence) || ! in_array($confidence, ['high', 'medium', 'low', 'uncertain'], true)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid confidence.");
        }

        if ($excerpt === '') {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] is missing excerpt.");
        }

        if (! is_string($contentOrigin) || ! in_array($contentOrigin, self::ALLOWED_MANUAL_MIXED_BLOCK_ORIGINS, true)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid content_origin.");
        }

        if (! is_array($claim['source_element_keys'] ?? null)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid source_element_keys.");
        }

        $sourceElementKeys = $this->normalizeManualMixedBlockClaimSourceKeys($claim['source_element_keys'], $allowedSourceKeys, $index);
        $bestPracticeReason = is_string($bestPracticeReason) ? trim($bestPracticeReason) : $bestPracticeReason;
        $conflictNote = is_string($conflictNote) ? trim($conflictNote) : $conflictNote;

        if (! (is_string($bestPracticeReason) || $bestPracticeReason === null)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid best_practice_reason.");
        }

        if (! (is_string($conflictNote) || $conflictNote === null)) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid conflict_note.");
        }

        if ($contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
            if ($sourceElementKeys === []) {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: source_based claim [{$index}] requires source_element_keys.");
            }

            if ($bestPracticeReason !== null && $bestPracticeReason !== '') {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: source_based claim [{$index}] cannot include best_practice_reason.");
            }
        } elseif ($contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            if ($sourceElementKeys !== []) {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: best_practice claim [{$index}] cannot include source_element_keys.");
            }

            if ($bestPracticeReason === null || $bestPracticeReason === '') {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: best_practice claim [{$index}] requires best_practice_reason.");
            }
        } elseif ($sourceElementKeys !== []) {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: unsupported_generated_content claim [{$index}] cannot include source_element_keys.");
        } elseif ($bestPracticeReason !== null && $bestPracticeReason !== '') {
            throw new RuntimeException("WikiPageClaimExtractionAiClient: unsupported_generated_content claim [{$index}] cannot include best_practice_reason.");
        }

        return [
            'text' => $text,
            'confidence' => $confidence,
            'excerpt' => $excerpt,
            'content_origin' => $contentOrigin,
            'source_element_keys' => $sourceElementKeys,
            'best_practice_reason' => $bestPracticeReason === '' ? null : $bestPracticeReason,
            'conflict_note' => $conflictNote === '' ? null : $conflictNote,
        ];
    }

    /**
     * @param  list<mixed>  $sourceElementKeys
     * @param  array<int, string>  $allowedSourceKeys
     * @return list<string>
     */
    private function normalizeManualMixedBlockClaimSourceKeys(array $sourceElementKeys, array $allowedSourceKeys, int $index): array
    {
        $normalized = [];

        foreach ($sourceElementKeys as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] has invalid source_element_key.");
            }

            $key = trim($key);

            if (! in_array($key, $allowedSourceKeys, true)) {
                throw new RuntimeException("WikiPageClaimExtractionAiClient: claim [{$index}] references unknown source_element_key [{$key}].");
            }

            if (! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
