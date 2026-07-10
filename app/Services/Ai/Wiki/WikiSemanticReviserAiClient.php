<?php

namespace App\Services\Ai\Wiki;

use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Targeted semantic reviser: improves wiki article content based on a concrete QA diagnosis.
 *
 * Unlike the generator (WikiPageContentAiClient), this reviser:
 * - Receives the existing generated content alongside the source document
 * - Receives the specific semantic QA critique (missing topics, unsupported claims, etc.)
 * - Produces a targeted revision that fixes documented deficiencies without altering correct content
 *
 * Output: revised content_markdown wrapped in a JSON schema (same pattern as WikiPageContentAiClient).
 * Uses gpt-5 for content generation quality, matching the original generator model.
 */
class WikiSemanticReviserAiClient
{
    public const MODEL = 'gpt-5';

    public const PROMPT_VERSION = '1.0';

    private const MAX_OUTPUT_TOKENS = 4000;

    private const MAX_SOURCE_CHARS = 15000;

    private const MAX_CONTENT_CHARS = 10000;

    private const PROMPT_NAME = 'wiki_semantic_revision';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Produce a targeted revision of existing wiki content based on a semantic QA diagnosis.
     *
     * The revision must fix only documented deficiencies:
     * - Cover missing topics from the source
     * - Remove or correct unsupported claims
     * - Preserve correct existing content
     *
     * @param  array  $diagnosis  Semantic QA result containing critique, missing_topics,
     *                            missing_key_facts, unsupported_claims, recommended_repair_action
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function revise(
        string $sourceText,
        string $existingContent,
        string $pageType,
        array $diagnosis,
        string $languageCode,
    ): string {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiSemanticReviserAiClient: wiki AI is not enabled.');
        }

        $truncatedSource = mb_substr(trim($sourceText), 0, self::MAX_SOURCE_CHARS);
        $truncatedContent = mb_substr(trim($existingContent), 0, self::MAX_CONTENT_CHARS);

        $payload = $this->buildPayload(
            $truncatedSource,
            $truncatedContent,
            $pageType,
            $diagnosis,
            $this->languageName($languageCode),
        );

        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $rawText = $this->extractOutputText($response);

        if ($rawText === '') {
            throw new RuntimeException('WikiSemanticReviserAiClient: OpenAI returned an empty response.');
        }

        $decoded = json_decode($rawText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('WikiSemanticReviserAiClient: OpenAI response was not valid JSON.');
        }

        $markdown = data_get($decoded, 'page.markdown', '');

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new RuntimeException('WikiSemanticReviserAiClient: revised content was empty.');
        }

        $this->validateMarkdown($markdown);

        return $markdown;
    }

    private function validateMarkdown(string $markdown): void
    {
        if (str_contains($markdown, '<!--')) {
            throw new RuntimeException('WikiSemanticReviserAiClient: revised content contains HTML comments — rejected.');
        }

        if (preg_match_all('/^(Kilde|Source|Ref)\s*:/im', $markdown) >= 2) {
            throw new RuntimeException('WikiSemanticReviserAiClient: revised content contains source citation lines — rejected.');
        }

        if (preg_match_all('/^>/m', $markdown) >= 3) {
            throw new RuntimeException('WikiSemanticReviserAiClient: revised content contains blockquote lines — rejected.');
        }
    }

    private function buildPayload(
        string $sourceText,
        string $existingContent,
        string $pageType,
        array $diagnosis,
        string $languageName,
    ): array {
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
                            'text' => $this->userPrompt($sourceText, $existingContent, $diagnosis),
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
        return implode("\n", [
            "You are a targeted wiki content reviser. The content language is {$languageName}. Page type: {$pageType}.",
            '',
            'You are given:',
            '1. A SOURCE DOCUMENT (the authoritative original text)',
            '2. EXISTING WIKI CONTENT (the current article — already written, needs improvement)',
            '3. A QA DIAGNOSIS (specific documented deficiencies that must be fixed)',
            '',
            'Your task: produce a revised version of the wiki content that fixes the documented deficiencies.',
            '',
            'REVISION RULES (follow strictly):',
            '- Fix only what the diagnosis identifies: add missing topics, add missing key facts, remove or correct unsupported claims',
            '- Preserve all correct existing content — do not rewrite sections with no documented deficiencies',
            '- Every new or modified claim must be supported by the SOURCE DOCUMENT',
            '- Do not add information not found in the source document',
            '- Maintain the existing markdown structure (headings, prose paragraphs)',
            '- Do not change the page title (first # heading)',
            '- Do not restructure or rearrange sections without cause from the diagnosis',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, or internal technical identifiers',
            '- No mention of AI generation, QA scores, or approval status',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $sourceText, string $existingContent, array $diagnosis): string
    {
        $critique = $diagnosis['critique'] ?? '';
        $missingTopics = $diagnosis['missing_topics'] ?? [];
        $missingKeyFacts = $diagnosis['missing_key_facts'] ?? [];
        $unsupportedClaims = $diagnosis['unsupported_claims'] ?? [];
        $repairAction = $diagnosis['recommended_repair_action'] ?? '';

        $diagnosisParts = ['--- QA DIAGNOSIS ---', ''];
        $diagnosisParts[] = "Recommended repair action: {$repairAction}";

        if ($critique !== '') {
            $diagnosisParts[] = '';
            $diagnosisParts[] = "Overall critique: {$critique}";
        }

        if (! empty($missingTopics)) {
            $diagnosisParts[] = '';
            $diagnosisParts[] = 'Missing topics (must be added from the source):';

            foreach ($missingTopics as $topic) {
                $diagnosisParts[] = "  - {$topic}";
            }
        }

        if (! empty($missingKeyFacts)) {
            $diagnosisParts[] = '';
            $diagnosisParts[] = 'Missing key facts (must be added from the source):';

            foreach ($missingKeyFacts as $fact) {
                $diagnosisParts[] = "  - {$fact}";
            }
        }

        if (! empty($unsupportedClaims)) {
            $diagnosisParts[] = '';
            $diagnosisParts[] = 'Unsupported claims (must be removed or corrected based on the source):';

            foreach ($unsupportedClaims as $claim) {
                $diagnosisParts[] = "  - {$claim}";
            }
        }

        return implode("\n", [
            '--- SOURCE DOCUMENT (authoritative original) ---',
            '',
            $sourceText,
            '',
            '--- EXISTING WIKI CONTENT (to be revised) ---',
            '',
            $existingContent,
            '',
            implode("\n", $diagnosisParts),
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
