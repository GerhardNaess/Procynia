<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Targeted wikilink reviser: given existing wiki page content, an explicit allowed-target
 * catalog, and a task-specific instruction, decides whether the content's inline
 * [[wikilinks]] should change — and if so, produces the revised markdown.
 *
 * Shared by two callers with different instructions but an identical contract:
 * - EnterpriseWikiIncrementalRelinkService (8I-5): "a new/updated concept or entity page now
 *   exists — link to it here if this content naturally refers to it."
 * - EnterpriseWikiLinkSemanticRepairService (8I-6): "these specific link defects were found —
 *   fix only these."
 *
 * The model must decide `changed` itself — this client never assumes a change was made.
 * The caller is responsible for validating any returned markdown's wikilinks against the
 * same catalog before persisting anything; this client only produces a candidate revision.
 */
class WikiLinkRevisionAiClient
{
    public const MODEL = 'gpt-5';

    public const PROMPT_VERSION = '1.0';

    private const MAX_OUTPUT_TOKENS = 3000;

    private const REASONING_EFFORT = 'low';

    private const MAX_CONTENT_CHARS = 10000;

    private const PROMPT_NAME = 'wiki_link_revision';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog  the exact
     *         set of pages this content is allowed to link to — the model must never invent a slug
     *         or use one outside this list
     * @param  string  $instructions  task-specific guidance (relinking vs. lint repair)
     * @return array{changed: bool, markdown: string}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function reviseLinks(
        string $existingContent,
        string $pageType,
        array $linkCatalog,
        string $instructions,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiLinkRevisionAiClient: wiki AI is not enabled.');
        }

        $truncatedContent = mb_substr(trim($existingContent), 0, self::MAX_CONTENT_CHARS);

        $payload = $this->buildPayload(
            $truncatedContent,
            $pageType,
            $linkCatalog,
            $instructions,
            $this->languageName($languageCode),
        );

        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $decoded = $this->responsesDecoder->decode($response, 'WikiLinkRevisionAiClient');

        $changed = data_get($decoded, 'changed');
        $markdown = data_get($decoded, 'markdown', '');

        if (! is_bool($changed)) {
            throw new RuntimeException('WikiLinkRevisionAiClient: response is missing the changed flag.');
        }

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new RuntimeException('WikiLinkRevisionAiClient: response markdown was empty.');
        }

        if ($changed) {
            $this->validateMarkdown($markdown);
        }

        return ['changed' => $changed, 'markdown' => $markdown];
    }

    private function validateMarkdown(string $markdown): void
    {
        if (str_contains($markdown, '<!--')) {
            throw new RuntimeException('WikiLinkRevisionAiClient: revised content contains HTML comments — rejected.');
        }

        if (preg_match_all('/^(Kilde|Source|Ref)\s*:/im', $markdown) >= 2) {
            throw new RuntimeException('WikiLinkRevisionAiClient: revised content contains source citation lines — rejected.');
        }
    }

    private function buildPayload(
        string $existingContent,
        string $pageType,
        array $linkCatalog,
        string $instructions,
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
                            'text' => $this->developerPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($existingContent, $linkCatalog, $instructions),
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
            'reasoning' => ['effort' => self::REASONING_EFFORT],
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $pageType, string $languageName): string
    {
        return implode("\n", [
            "You are a targeted wiki wikilink reviser. The content language is {$languageName}. Page type: {$pageType}.",
            '',
            'You are given EXISTING WIKI CONTENT, an ALLOWED WIKILINK TARGETS catalog, and a TASK.',
            '',
            'Your only job is to decide whether the inline [[wikilinks]] in this content should change to',
            'satisfy the task, and if so, produce the smallest natural revision that does it.',
            '',
            'RULES (follow strictly):',
            '- Use [[target-slug|natural visible text]] or [[target-slug]] only for slugs present in the',
            '  ALLOWED WIKILINK TARGETS catalog. Never invent a slug. Never use a slug not in the catalog.',
            '- Never link the page to itself.',
            '- Preserve every existing valid [[wikilink]] already in the content — do not remove or alter one',
            '  unless the task explicitly asks you to remove that specific link.',
            '- Do not link every occurrence of a term — link the first or most natural occurrence only.',
            '- Do not restructure, rewrite, or reformat any content beyond the specific wikilink change(s)',
            '  the task calls for. Preserve the existing markdown structure and prose exactly otherwise.',
            '- If, after considering the task, no change is semantically justified, set changed=false and',
            '  return the original content unchanged in markdown.',
            '- If you do make a change, set changed=true and return the FULL revised content in markdown.',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No commentary, explanations, or text outside the requested content',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $existingContent, array $linkCatalog, string $instructions): string
    {
        $catalogText = empty($linkCatalog)
            ? 'No pages available to link to.'
            : json_encode(array_values($linkCatalog), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return implode("\n", [
            '--- EXISTING WIKI CONTENT ---',
            '',
            $existingContent,
            '',
            '--- ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' page(s)) ---',
            '',
            $catalogText,
            '',
            '--- TASK ---',
            '',
            $instructions,
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'changed' => ['type' => 'boolean'],
                'markdown' => ['type' => 'string'],
            ],
            'required' => ['changed', 'markdown'],
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
