<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

class WikiSectionAiClient
{
    public const MAX_INPUT_CHARS = 3000;

    public const MAX_CLAIMS = 15;

    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 2000;

    private const PROMPT_NAME = 'wiki_claim_extraction';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    /**
     * Whether wiki generation is available.
     *
     * Returns false until Phase 1F-C (enabled after manual verification).
     * Controllers and the frontend use this to block ingest attempts.
     */
    public static function isAvailable(): bool
    {
        return false;
    }

    /**
     * Fetch AI-extracted claims for a single document section.
     *
     * Input text is trimmed to MAX_INPUT_CHARS before the request. Returns at most
     * MAX_CLAIMS claims. Each excerpt is trimmed to EnterpriseWikiSectionParser::MAX_EXCERPT_CHARS.
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or missing claims array
     */
    public function fetchClaims(string $sectionText, ?string $heading, string $languageCode): array
    {
        $trimmedText = mb_substr(trim($sectionText), 0, self::MAX_INPUT_CHARS);
        $payload = $this->buildPayload($trimmedText, $heading, $this->languageName($languageCode));

        $response = $this->openAiClient->createResponse($payload);

        $rawText = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiSectionAiClient: OpenAI returned an empty text response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiSectionAiClient: OpenAI response was not valid JSON.');
        }

        if (! array_key_exists('claims', $decoded) || ! is_array($decoded['claims'])) {
            throw new RuntimeException('WikiSectionAiClient: OpenAI response did not include a claims array.');
        }

        $claims = array_slice($decoded['claims'], 0, self::MAX_CLAIMS);
        $normalized = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            if (isset($claim['excerpt']) && is_string($claim['excerpt'])) {
                $claim['excerpt'] = mb_substr($claim['excerpt'], 0, EnterpriseWikiSectionParser::MAX_EXCERPT_CHARS);
            }

            $normalized[] = $claim;
        }

        return ['claims' => $normalized];
    }

    private function buildPayload(string $sectionText, ?string $heading, string $languageName): array
    {
        $headingLine = $heading !== null ? "Heading: {$heading}\n\n" : '';

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
                            'text' => "{$headingLine}{$sectionText}",
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
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            'You are an extract-only model. Extract factual claims from the provided document section.',
            "Source document language: {$languageName}.",
            'Rules:',
            '- Extract only claims directly and verbatim supported by the section text.',
            '- Do not use external knowledge or assumptions.',
            '- For each claim, provide a verbatim excerpt from the source text as evidence.',
            '- Set conflict_note only when the section text itself contains a genuine contradiction.',
            '- If the section provides insufficient basis for any claim, return an empty claims array.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function extractOutputText(array $response): string
    {
        $topLevel = trim((string) data_get($response, 'output_text', ''));

        if ($topLevel !== '') {
            return $topLevel;
        }

        $parts = [];
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
