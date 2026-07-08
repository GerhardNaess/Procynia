<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

class WikiArticleAiClient
{
    private const MODEL = 'gpt-5';

    private const TEMPERATURE = 0.3;

    private const MAX_OUTPUT_TOKENS = 4000;

    private const PROMPT_NAME = 'wiki_article_generation';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Generate a readable Markdown wiki article from claim evidence.
     *
     * @param  array<int, array{text: string, confidence: string, excerpt: string, source: string}>  $claims
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response yields no article text
     */
    public function generateArticle(string $pageTitle, array $claims, string $languageCode): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiArticleAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($pageTitle, $claims, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $rawText = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiArticleAiClient: OpenAI returned an empty text response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiArticleAiClient: OpenAI response was not valid JSON.');
        }

        $markdown = data_get($decoded, 'article.markdown', '');

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new RuntimeException('WikiArticleAiClient: generated article was empty.');
        }

        return $markdown;
    }

    private function buildPayload(string $pageTitle, array $claims, string $languageName): array
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
                            'text' => $this->userPrompt($pageTitle, $claims),
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
            'You are a wiki article writer.',
            "Write a coherent, readable wiki article in {$languageName} based on the provided claims and source excerpts.",
            'Rules:',
            '- Use ## headings for sections and write flowing prose paragraphs, not bullet lists.',
            '- Synthesise overlapping claims into coherent text — do not repeat the same fact twice.',
            '- Do not introduce facts not supported by the provided claims and excerpts.',
            '- Do not mention that the article is AI-generated within the article text itself.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $pageTitle, array $claims): string
    {
        $lines = [];
        $lines[] = "Page title: {$pageTitle}";
        $lines[] = '';
        $lines[] = 'Claims and source evidence:';
        $lines[] = '';

        foreach ($claims as $i => $claim) {
            $num = $i + 1;
            $confidence = $claim['confidence'] ?? 'uncertain';
            $excerpt = $claim['excerpt'] ?? '';
            $source = $claim['source'] ?? '';

            $lines[] = "{$num}. {$claim['text']} [confidence: {$confidence}]";

            if ($source !== '' && $excerpt !== '') {
                $lines[] = "   Source: {$source} — \"{$excerpt}\"";
            } elseif ($source !== '') {
                $lines[] = "   Source: {$source}";
            } elseif ($excerpt !== '') {
                $lines[] = "   Excerpt: \"{$excerpt}\"";
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
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
                'article' => [
                    'type' => 'object',
                    'properties' => [
                        'markdown' => ['type' => 'string'],
                    ],
                    'required' => ['markdown'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['article'],
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
