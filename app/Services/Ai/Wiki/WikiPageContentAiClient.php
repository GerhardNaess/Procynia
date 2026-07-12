<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

class WikiPageContentAiClient
{
    public const MODEL = 'gpt-5';

    private const MAX_OUTPUT_TOKENS = 6000;

    private const REASONING_EFFORT = 'low';

    private const PROMPT_NAME = 'wiki_page_content_generation';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Generate markdown content for any wiki page type from a source document.
     * Pass $additionalContext for concept/entity pages to include article/summary content
     * and maintainer notes alongside the source text.
     * Pass $linkCatalog (see EnterpriseWikiLinkCatalogService) so the model can reference
     * other wiki pages via inline [[slug]]/[[slug|anchor]] wikilinks without inventing slugs.
     *
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function generateFromSource(
        string $pageTitle,
        string $pageType,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
    ): string {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($pageTitle, $pageType, $sourceText, $additionalContext, $this->languageName($languageCode), $linkCatalog);
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 300);
        $decoded = $this->responsesDecoder->decode($response, 'WikiPageContentAiClient');

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

    private function buildPayload(string $pageTitle, string $pageType, string $sourceText, string $additionalContext, string $languageName, array $linkCatalog = []): array
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
                            'text' => $this->userPrompt($pageTitle, $sourceText, $additionalContext, $linkCatalog),
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
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    private function developerPrompt(string $pageType, string $languageName): string
    {
        $prohibitions = implode("\n", [
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);

        $wikilinkRules = implode("\n", [
            'INLINE WIKILINKS:',
            '- content_markdown is wiki content — reference other pages inline the way a wiki article does.',
            '- Use [[target-slug|natural visible text]] inside ordinary prose to reference a page from the "ALLOWED WIKILINK TARGETS" list provided in the user message.',
            '- Use [[target-slug]] only when the slug itself already reads naturally as the visible text.',
            '- The "slug" field is the exact, literal identifier you must copy into the [[...]] markup — it is frequently lowercase or hyphenated and different from the page\'s "title" field. Never substitute the title text where the slug is expected, and never change the slug\'s case, spelling, or hyphenation.',
            '- The user message includes ready-to-copy [[slug|Title]] markup for every allowed target — copy that markup verbatim rather than typing the slug from memory.',
            '- Only link to slugs that appear in the allowed wikilink target list — never invent a slug, and never link to this page\'s own slug.',
            '- Link the first or most natural occurrence of a concept or entity — do not repeat the same link for every mention.',
            '- Only link semantically important concepts and entities that the text is actually about — do not link generic words.',
            '- Place links inside normal flowing prose. Never replace natural inline linking with a separate "Related pages" list or section.',
            '- Do not create a link for every allowed target just to reach a number — link only where it reads naturally.',
        ]);

        return match ($pageType) {
            'summary' => implode("\n", [
                "You are an editorial wiki writer. Write a concise professional summary page in {$languageName} based on the provided source document.",
                '',
                'SUMMARY STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with 2-4 paragraphs summarising the key points of the source document',
                '- Write flowing prose — no bullet lists, no headings beyond the title',
                '- Inline-link the most important concept/entity pages from the allowed targets; keep the summary short and natural',
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            'concept' => implode("\n", [
                "You are an editorial wiki writer. Write a professional concept page in {$languageName} explaining the given concept as it appears in the source material.",
                '',
                'CONCEPT PAGE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the concept name',
                '- Follow with a definition paragraph (2-4 sentences) explaining what the concept is',
                '- Add one or two ## sections describing how the concept is used or relevant in context',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article, concept, and entity pages from the allowed targets',
                '',
                'SYNTHESIS RULES:',
                '- Derive meaning from the provided source text and related page content',
                '- Do not invent facts not supported by the provided material',
                '- If related article or summary content is provided, use it to enrich the explanation',
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            'entity' => implode("\n", [
                "You are an editorial wiki writer. Write a professional entity page in {$languageName} describing the given entity (person, organisation, product, system, or place) as it appears in the source material.",
                '',
                'ENTITY PAGE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the entity name',
                '- Follow with an identification paragraph (2-4 sentences) stating what the entity is',
                '- Add one or two ## sections covering relevant roles, relationships, or context',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article and concept pages from the allowed targets; avoid speculative relationships',
                '',
                'SYNTHESIS RULES:',
                '- Derive all facts from the provided source text and related page content',
                '- Do not invent roles, relationships, or attributes not present in the material',
                '- If related article or summary content is provided, use it to enrich the description',
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
            default => implode("\n", [
                "You are an editorial wiki article writer. Write a professional, readable internal wiki article in {$languageName} based on the provided source document.",
                '',
                'ARTICLE STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with a short introductory paragraph (2-4 sentences) summarising the topic',
                '- Organise the body using ## subheadings for logical sections',
                '- Write flowing prose paragraphs within each section — no bullet lists',
                '- End the article naturally, without a separate summary or conclusion heading',
                '- Inline-link central concept and entity pages, and any other allowed target the text clearly relates to',
                '',
                'SYNTHESIS RULES:',
                '- Synthesise the source material into coherent prose',
                '- Do not repeat the same fact across multiple sections',
                '- Do not invent facts not present in the source document',
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
        };
    }

    private function userPrompt(string $pageTitle, string $sourceText, string $additionalContext, array $linkCatalog = []): string
    {
        $parts = [
            "Page title: {$pageTitle}",
            '',
            'Source document:',
            '',
            $sourceText,
        ];

        if (trim($additionalContext) !== '') {
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '';
            $parts[] = 'Additional context:';
            $parts[] = '';
            $parts[] = $additionalContext;
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'ALLOWED WIKILINK TARGETS ('.count($linkCatalog).' pages):';
        $parts[] = '';

        if ($linkCatalog !== []) {
            $parts[] = 'Copy the exact markup below when linking to a page — do not retype the slug from the title, and do not change its case or spelling:';
            $parts[] = '';

            foreach ($linkCatalog as $entry) {
                $parts[] = sprintf('[[%s|%s]]', $entry['slug'], $entry['title']);
            }

            $parts[] = '';
            $parts[] = 'Full catalog (slug, title, page_type):';
            $parts[] = '';
            $parts[] = (string) json_encode($linkCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $parts[] = 'No other pages available to link to.';
        }

        return implode("\n", $parts);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'object',
                    'properties' => [
                        'markdown' => ['type' => 'string'],
                    ],
                    'required' => ['markdown'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['page'],
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
