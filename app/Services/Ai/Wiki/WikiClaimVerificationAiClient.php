<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Verifies whether a single claim is supported by a source document and returns
 * a verbatim excerpt as evidence.
 *
 * Input:  claim text + source document text
 * Output: {supported: bool, excerpt: string}
 *
 * A claim is "supported" when a verbatim passage in the source document directly
 * backs the claim. If no passage is found, supported = false and excerpt = ''.
 * This client never invents excerpts; it only locates existing text.
 */
class WikiClaimVerificationAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 500;

    private const MAX_SOURCE_CHARS = 8000;

    private const PROMPT_NAME = 'wiki_claim_verification';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Verify that $claimText is supported by $sourceText.
     *
     * @return array{supported: bool, excerpt: string}
     * @throws RuntimeException on API error, empty or invalid response
     */
    public function verifyClaim(
        string $claimText,
        string $sourceText,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiClaimVerificationAiClient: wiki AI generation is not enabled.');
        }

        $trimmedSource = mb_substr(trim($sourceText), 0, self::MAX_SOURCE_CHARS);
        $payload       = $this->buildPayload($claimText, $trimmedSource, $this->languageName($languageCode));
        $response      = $this->openAiClient->createResponse($payload);
        $rawText       = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiClaimVerificationAiClient: OpenAI returned an empty text response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: OpenAI response was not valid JSON.');
        }

        if (! array_key_exists('supported', $decoded) || ! array_key_exists('excerpt', $decoded)) {
            throw new RuntimeException('WikiClaimVerificationAiClient: response is missing required fields.');
        }

        return [
            'supported' => (bool) $decoded['supported'],
            'excerpt'   => (string) ($decoded['excerpt'] ?? ''),
        ];
    }

    private function buildPayload(string $claimText, string $sourceText, string $languageName): array
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
                            'text' => $this->userPrompt($claimText, $sourceText),
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
            'You are a fact-checking model. You are given a claim and a source document.',
            "Source language: {$languageName}.",
            'Your task: determine whether the claim is directly supported by the source document.',
            '',
            'Rules:',
            '- If the claim is supported, set supported to true and provide a short verbatim excerpt',
            '  (max ~200 characters) from the source document that directly supports the claim.',
            '- If the claim cannot be verified from the source document, set supported to false',
            '  and excerpt to an empty string.',
            '- Do not use external knowledge. Only use what appears in the source document.',
            '- Excerpt must be an exact quote from the source document — do not paraphrase.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $claimText, string $sourceText): string
    {
        return implode("\n", [
            "Claim: {$claimText}",
            '',
            'Source document:',
            '',
            $sourceText,
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
                'supported' => ['type' => 'boolean'],
                'excerpt'   => ['type' => 'string'],
            ],
            'required'             => ['supported', 'excerpt'],
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
