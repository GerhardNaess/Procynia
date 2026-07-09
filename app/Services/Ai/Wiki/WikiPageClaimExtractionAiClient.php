<?php

namespace App\Services\Ai\Wiki;

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

    private const MAX_OUTPUT_TOKENS = 2000;

    private const MAX_INPUT_CHARS = 6000;

    private const PROMPT_NAME = 'wiki_page_claim_extraction';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
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

        $trimmed  = mb_substr(trim($contentMarkdown), 0, self::MAX_INPUT_CHARS);
        $payload  = $this->buildPayload($pageTitle, $pageType, $trimmed, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $rawText  = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: OpenAI returned an empty text response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: OpenAI response was not valid JSON.');
        }

        if (! array_key_exists('claims', $decoded) || ! is_array($decoded['claims'])) {
            throw new RuntimeException('WikiPageClaimExtractionAiClient: response did not include a claims array.');
        }

        $claims     = array_slice($decoded['claims'], 0, self::MAX_CLAIMS);
        $normalized = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $normalized[] = $claim;
        }

        return ['claims' => $normalized];
    }

    private function buildPayload(string $pageTitle, string $pageType, string $content, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role'    => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role'    => 'user',
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
                    'type'   => 'json_schema',
                    'name'   => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'temperature'       => self::TEMPERATURE,
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
            '- Set conflict_note only when the page text itself contains a genuine contradiction.',
            '- If the page contains insufficient basis for any claim, return an empty claims array.',
            '- Do not use external knowledge or assumptions beyond what the text states.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function extractOutputText(array $response): string
    {
        $topLevel = trim((string) data_get($response, 'output_text', ''));

        if ($topLevel !== '') {
            return $topLevel;
        }

        $parts       = [];
        $outputItems = data_get($response, 'output', []);

        if (! is_array($outputItems)) {
            return '';
        }

        foreach ($outputItems as $item) {
            if (data_get($item, 'type') !== 'message' || data_get($item, 'role') !== 'assistant') {
                continue;
            }

            $contentItems = data_get($item, 'content', []);

            if (! is_array($contentItems)) {
                continue;
            }

            foreach ($contentItems as $contentItem) {
                if (in_array(data_get($contentItem, 'type'), ['output_text', 'text'], true)) {
                    $text = trim((string) data_get($contentItem, 'text', ''));

                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
        }

        return trim(implode('', $parts));
    }

    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'claims' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'text'       => ['type' => 'string'],
                            'confidence' => [
                                'type' => 'string',
                                'enum' => ['high', 'medium', 'low', 'uncertain'],
                            ],
                            'excerpt'       => ['type' => 'string'],
                            'conflict_note' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                        'required'             => ['text', 'confidence', 'excerpt', 'conflict_note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['claims'],
            'additionalProperties' => false,
        ];
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en'    => 'English',
            default => 'Norwegian',
        };
    }
}
