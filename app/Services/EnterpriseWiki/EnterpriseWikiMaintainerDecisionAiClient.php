<?php

namespace App\Services\EnterpriseWiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * AI client for the Karpathy-style maintainer decision step.
 *
 * Receives a source document (metadata + extracted text) and the existing
 * wiki page index, then asks the AI what pages to create or update.
 *
 * Returns a validated decision array (see EnterpriseWikiMaintainerDecisionPrompt).
 * Does NOT generate page content and is NOT wired into the ingest pipeline yet.
 */
class EnterpriseWikiMaintainerDecisionAiClient
{
    private const MODEL = 'gpt-5';

    private const MAX_OUTPUT_TOKENS = 2000;

    // Keeps the source text well within token limits while leaving room for
    // the system prompt, index context, and the schema output.
    private const MAX_SOURCE_TEXT_CHARS = 12000;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Ask the AI maintainer to decide what wiki pages to create or update.
     *
     * @param  array{title: string, filename: string}       $sourceMeta   Cleaned title + original filename.
     * @param  string                                        $sourceText   Extracted text from the document.
     * @param  array<int, array<string, mixed>>              $indexContext From EnterpriseWikiIndexContextService::buildForCustomer().
     * @param  string                                        $languageCode 'no' | 'en'
     * @return array<string, mixed>  Validated maintainer decision.
     *
     * @throws RuntimeException when AI is disabled, the API fails, the response is empty or invalid.
     */
    public function decide(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.'
            );
        }

        $payload = $this->buildPayload($sourceMeta, $sourceText, $indexContext, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 60);
        $rawText = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: OpenAI returned an empty response.'
            );
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: response was not valid JSON.'
            );
        }

        try {
            return EnterpriseWikiMaintainerDecisionPrompt::parse($decoded);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: decision failed schema validation: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function buildPayload(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageName,
    ): array {
        $schemaBlock = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();

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
                            'text' => $this->userPrompt($sourceMeta, $sourceText, $indexContext),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => array_merge(
                    ['type' => $schemaBlock['type']],
                    $schemaBlock['json_schema'],
                ),
            ],
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'You are NOT generating article content — you decide what pages to create or update.',
            '',
            'DECISION RULES:',
            'source_article — one article page per source document:',
            '  action "create": no matching article for this source exists in the wiki index.',
            '  action "update": an article covering this source already exists in the index.',
            '',
            'source_summary — one summary page per source document:',
            '  Same create/update logic as source_article.',
            '',
            'concept_pages — shared concept pages (recurring topics, methodologies, frameworks):',
            '  Zero or more. action "create" + page_id null: concept does not exist yet.',
            '  action "update" + integer page_id: concept page exists; use its ID from the index.',
            '',
            'entity_pages — shared entity pages (organisations, clients, suppliers):',
            '  Zero or more. Same create/update logic as concept_pages.',
            '',
            'no_action_reason: set if the source is empty, a duplicate, or should not produce a page.',
            'warnings: non-blocking concerns (empty text, language mismatch, ambiguous title, etc.).',
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc.',
            '  source_article and source_summary slugs: append a short unique suffix (e.g. "tittel-ab1c2d").',
            '  concept/entity slugs: stable, no suffix — same page matched across sources.',
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    private function userPrompt(array $sourceMeta, string $sourceText, array $indexContext): string
    {
        $title    = (string) ($sourceMeta['title'] ?? '');
        $filename = (string) ($sourceMeta['filename'] ?? '');
        $text     = trim($sourceText);

        if (mb_strlen($text) > self::MAX_SOURCE_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_SOURCE_TEXT_CHARS) . "\n[... text truncated ...]";
        }

        $indexJson = $indexContext !== []
            ? (string) json_encode($indexContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'No pages yet.';

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            "Original file: {$filename}",
            '',
            'SOURCE TEXT:',
            $text !== '' ? $text : '(empty)',
            '',
            'EXISTING WIKI INDEX (' . count($indexContext) . ' pages):',
            $indexJson,
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

    private function languageName(string $code): string
    {
        return match ($code) {
            'en'    => 'English',
            default => 'Norwegian',
        };
    }
}
