<?php

namespace App\Services\Ai\Wiki;

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

    private const MAX_OUTPUT_TOKENS = 2000;

    private const MAX_INPUT_CHARS = 6000;

    private const PROMPT_NAME = 'wiki_page_claim_extraction';

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

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
