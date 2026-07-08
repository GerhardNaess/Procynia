<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

class WikiArticleAiClient
{
    private const MODEL = 'gpt-5';

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
     * @throws RuntimeException when AI is disabled, the API fails, the response yields no article text,
     *                          or the generated article fails format validation
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

        $this->validateArticle($markdown);

        return $markdown;
    }

    /**
     * Reject output that looks like an internal claim/source dump rather than a wiki article.
     *
     * @throws RuntimeException if the markdown contains forbidden patterns
     */
    private function validateArticle(string $markdown): void
    {
        // HTML comments are ingest artifacts — never valid in a wiki article
        if (str_contains($markdown, '<!--')) {
            throw new RuntimeException('WikiArticleAiClient: generated article contains HTML comments — rejected.');
        }

        // Two or more citation lines (Kilde:/Source:/Ref: at line start) indicate a source dump
        if (preg_match_all('/^(Kilde|Source|Ref)\s*:/im', $markdown) >= 2) {
            throw new RuntimeException('WikiArticleAiClient: generated article contains source citation lines — rejected.');
        }

        // Three or more blockquote lines indicate an excerpt dump
        if (preg_match_all('/^>/m', $markdown) >= 3) {
            throw new RuntimeException('WikiArticleAiClient: generated article contains blockquote lines — rejected.');
        }
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
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an editorial wiki article writer. Write a professional, readable internal wiki article in {$languageName}.",
            '',
            'ARTICLE STRUCTURE (mandatory):',
            '- First line must be a # heading containing the page title',
            '- Follow with a short introductory paragraph (2-4 sentences) summarising the topic',
            '- Organise the body using ## subheadings for logical sections',
            '- Write flowing prose paragraphs within each section — no bullet lists',
            '- End the article naturally, without a separate summary or conclusion heading',
            '',
            'SYNTHESIS RULES:',
            '- Synthesise overlapping claims into coherent prose — do not list or copy claims verbatim',
            '- Do not repeat the same fact across multiple sections',
            '- Do not invent facts not supported by the provided claims and evidence',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '- No claim lists, numbered fact lists, or verification sections',
            '- No metadata lines (Version:, Status:, Ingest:, etc.)',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
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
