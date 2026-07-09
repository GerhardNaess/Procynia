<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

class WikiPageContentAiClient
{
    public const MODEL = 'gpt-5';

    private const MAX_OUTPUT_TOKENS = 4000;

    private const PROMPT_NAME = 'wiki_page_content_generation';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Generate markdown content for an article or summary page from a source document.
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function generateFromSource(
        string $pageTitle,
        string $pageType,
        string $sourceText,
        string $languageCode,
    ): string {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($pageTitle, $pageType, $sourceText, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $rawText = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiPageContentAiClient: OpenAI returned an empty text response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiPageContentAiClient: OpenAI response was not valid JSON.');
        }

        $markdown = data_get($decoded, 'page.markdown', '');

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new RuntimeException('WikiPageContentAiClient: generated page content was empty.');
        }

        $this->validateMarkdown($markdown);

        return $markdown;
    }

    private function validateMarkdown(string $markdown): void
    {
        if (str_contains($markdown, '<!--')) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains HTML comments — rejected.');
        }

        if (preg_match_all('/^(Kilde|Source|Ref)\s*:/im', $markdown) >= 2) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains source citation lines — rejected.');
        }

        if (preg_match_all('/^>/m', $markdown) >= 3) {
            throw new RuntimeException('WikiPageContentAiClient: generated content contains blockquote lines — rejected.');
        }
    }

    private function buildPayload(string $pageTitle, string $pageType, string $sourceText, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role'    => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($pageTitle, $sourceText),
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
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $pageType, string $languageName): string
    {
        if ($pageType === 'summary') {
            return implode("\n", [
                "You are an editorial wiki writer. Write a concise professional summary page in {$languageName} based on the provided source document.",
                '',
                'SUMMARY STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with 2-4 paragraphs summarising the key points of the source document',
                '- Write flowing prose — no bullet lists, no headings beyond the title',
                '',
                'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
                '- No HTML comments (<!-- ... -->)',
                '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
                '- No quoted source excerpts or blockquote lines (lines starting with >)',
                '- No filenames, document IDs, run IDs, or internal technical identifiers',
                '- No mention of AI generation, confidence levels, or approval status',
                '',
                'Return only JSON matching the schema. No text before or after JSON.',
            ]);
        }

        return implode("\n", [
            "You are an editorial wiki article writer. Write a professional, readable internal wiki article in {$languageName} based on the provided source document.",
            '',
            'ARTICLE STRUCTURE (mandatory):',
            '- First line must be a # heading containing the page title',
            '- Follow with a short introductory paragraph (2-4 sentences) summarising the topic',
            '- Organise the body using ## subheadings for logical sections',
            '- Write flowing prose paragraphs within each section — no bullet lists',
            '- End the article naturally, without a separate summary or conclusion heading',
            '',
            'SYNTHESIS RULES:',
            '- Synthesise the source material into coherent prose',
            '- Do not repeat the same fact across multiple sections',
            '- Do not invent facts not present in the source document',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $pageTitle, string $sourceText): string
    {
        return implode("\n", [
            "Page title: {$pageTitle}",
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
            'type'       => 'object',
            'properties' => [
                'page' => [
                    'type'       => 'object',
                    'properties' => [
                        'markdown' => ['type' => 'string'],
                    ],
                    'required'             => ['markdown'],
                    'additionalProperties' => false,
                ],
            ],
            'required'             => ['page'],
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
