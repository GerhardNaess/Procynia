<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Semantic QA reviewer for a wiki page's inline [[wikilinks]] (8I-6).
 *
 * Unlike WikiSemanticQaAiClient (which reviews content against the source document), this
 * reviewer only judges the page's wikilinking against an explicit allowed-target catalog:
 * are central concepts/entities linked, is the anchor text natural, is linking excessive, are
 * any important relations missing, does an existing link point to the wrong target.
 *
 * This reviewer does NOT modify any page version and does NOT invent targets — it may only
 * recommend adding a slug that is already present in the given catalog, or removing a slug that
 * is already present in the content. EnterpriseWikiLinkSemanticRepairService is responsible for
 * turning a repair-recommended diagnosis into an actual revision via WikiLinkRevisionAiClient.
 */
class WikiLinkSemanticQaAiClient
{
    public const MODEL = 'gpt-4.1-mini';

    public const PROMPT_VERSION = '1.0';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 1000;

    private const MAX_CONTENT_CHARS = 10000;

    private const PROMPT_NAME = 'wiki_link_semantic_qa';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @return array{
     *   assessment: string,
     *   missing_link_slugs: list<string>,
     *   remove_link_slugs: list<string>,
     *   critique: string,
     *   model: string,
     * }
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function review(
        string $content,
        string $pageType,
        array $linkCatalog,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiLinkSemanticQaAiClient: wiki AI is not enabled.');
        }

        $truncatedContent = mb_substr(trim($content), 0, self::MAX_CONTENT_CHARS);

        $payload = $this->buildPayload($truncatedContent, $pageType, $linkCatalog, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $decoded = $this->responsesDecoder->decode($response, 'WikiLinkSemanticQaAiClient');

        $this->validateResult($decoded, $linkCatalog);

        return [
            'assessment' => (string) $decoded['assessment'],
            'missing_link_slugs' => array_values((array) $decoded['missing_link_slugs']),
            'remove_link_slugs' => array_values((array) $decoded['remove_link_slugs']),
            'critique' => (string) $decoded['critique'],
            'model' => self::MODEL.'/'.self::PROMPT_VERSION,
        ];
    }

    private function validateResult(array $decoded, array $linkCatalog): void
    {
        foreach (['assessment', 'missing_link_slugs', 'remove_link_slugs', 'critique'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException("WikiLinkSemanticQaAiClient: response is missing required field [{$field}].");
            }
        }

        if (! in_array($decoded['assessment'], ['no_change', 'repair_recommended'], true)) {
            throw new RuntimeException("WikiLinkSemanticQaAiClient: invalid assessment [{$decoded['assessment']}].");
        }

        foreach (['missing_link_slugs', 'remove_link_slugs'] as $field) {
            if (! is_array($decoded[$field])
                || array_filter($decoded[$field], static fn (mixed $v): bool => ! is_string($v)) !== []) {
                throw new RuntimeException("WikiLinkSemanticQaAiClient: response field [{$field}] must be an array of strings.");
            }
        }

        $catalogSlugs = array_column($linkCatalog, 'slug');
        $invented = array_diff($decoded['missing_link_slugs'], $catalogSlugs);

        if ($invented !== []) {
            throw new RuntimeException(
                'WikiLinkSemanticQaAiClient: missing_link_slugs contains slug(s) outside the allowed catalog: '.implode(', ', $invented).'.'
            );
        }

        if (! is_string($decoded['critique'])) {
            throw new RuntimeException('WikiLinkSemanticQaAiClient: critique must be a string.');
        }
    }

    private function buildPayload(string $content, string $pageType, array $linkCatalog, string $languageName): array
    {
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
                            'text' => $this->userPrompt($content, $linkCatalog),
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

    private function developerPrompt(string $pageType, string $languageName): string
    {
        return implode("\n", [
            "You are a semantic QA reviewer for a wiki page's inline [[wikilinks]]. The content language is {$languageName}. Page type: {$pageType}.",
            '',
            'You are given WIKI CONTENT and an ALLOWED WIKILINK TARGETS catalog (pages this content is',
            'allowed to link to). Judge ONLY the wikilinking quality of this content — not its factual',
            'accuracy or completeness otherwise.',
            '',
            'Consider:',
            '- Are semantically central concepts or entities from the catalog left unlinked even though the',
            '  content clearly discusses them?',
            '- Is the anchor text of existing links natural, or arbitrary/awkward?',
            '- Is linking excessive (the same target linked many times, or trivial terms linked)?',
            '- Does an existing link appear to point to the wrong target for what the surrounding text means?',
            '',
            'assessment must be exactly one of:',
            '- "no_change" — wikilinking in this content is already appropriate',
            '- "repair_recommended" — specific wikilinks should be added and/or removed',
            '',
            'missing_link_slugs: catalog slugs that should be added as a new inline wikilink — ONLY slugs',
            'that appear in the ALLOWED WIKILINK TARGETS catalog below. Never invent a slug.',
            '',
            'remove_link_slugs: slugs of EXISTING wikilinks in the content that should be removed (wrong',
            'target, or trivial/over-linked). Leave empty if no existing link should be removed.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private function userPrompt(string $content, array $linkCatalog): string
    {
        $catalogText = empty($linkCatalog)
            ? 'No pages available to link to.'
            : json_encode(array_values($linkCatalog), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return implode("\n", [
            '--- WIKI CONTENT (to review) ---',
            '',
            $content,
            '',
            '--- ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' page(s)) ---',
            '',
            $catalogText,
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assessment' => ['type' => 'string'],
                'missing_link_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'remove_link_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'critique' => ['type' => 'string'],
            ],
            'required' => ['assessment', 'missing_link_slugs', 'remove_link_slugs', 'critique'],
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
