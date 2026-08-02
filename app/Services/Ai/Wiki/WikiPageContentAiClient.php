<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
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
     *
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
        return $this->generatePageFromSource(
            pageTitle: $pageTitle,
            pageType: $pageType,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $linkCatalog,
        )['markdown'];
    }

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function generatePageFromSource(
        string $pageTitle,
        string $pageType,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($pageTitle, $pageType, $sourceText, $additionalContext, $this->languageName($languageCode), $linkCatalog, $sourceElements);
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 300);
        $decoded = $this->responsesDecoder->decode($response, 'WikiPageContentAiClient');

        return $this->parseBlocksResponse($decoded);
    }

    /**
     * Single bounded repair attempt for a page whose planned/owned-topic sections were found
     * missing, empty, or link-only by EnterpriseWikiPlannedSectionCoverageValidator (Wiki run-586
     * incident). Receives the already-generated markdown as explicit context — unlike
     * generatePageFromSource(), this is a targeted correction of specific named sections, not an
     * independent regeneration, so everything already good in $existingMarkdown is preserved by
     * instruction rather than re-derived from scratch.
     *
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is empty/invalid
     */
    public function repairPlannedSections(
        string $pageTitle,
        string $pageType,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $languageCode,
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiPageContentAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildRepairPayload(
            $pageTitle,
            $pageType,
            $existingMarkdown,
            $issues,
            $sourceText,
            $additionalContext,
            $this->languageName($languageCode),
            $linkCatalog,
            $sourceElements,
        );
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 300);
        $decoded = $this->responsesDecoder->decode($response, 'WikiPageContentAiClient(repair)');

        return $this->parseBlocksResponse($decoded);
    }

    /**
     * @return array{markdown: string, blocks: list<array<string, mixed>>}
     */
    private function parseBlocksResponse(array $decoded): array
    {
        $blocks = data_get($decoded, 'page.blocks', []);

        if (! is_array($blocks) || $blocks === []) {
            throw new RuntimeException('WikiPageContentAiClient: generated page blocks were empty.');
        }

        $markdownParts = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] was invalid.");
            }

            $blockMarkdown = trim((string) ($block['markdown'] ?? ''));

            if ($blockMarkdown === '') {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] markdown was empty.");
            }

            $origin = (string) ($block['content_origin'] ?? '');
            if (! in_array($origin, [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            ], true)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] has invalid content_origin.");
            }

            $sourceElementKeys = $block['source_element_keys'] ?? [];
            if (! is_array($sourceElementKeys)) {
                throw new RuntimeException("WikiPageContentAiClient: generated page block [{$index}] source_element_keys was invalid.");
            }

            if ($origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED && $sourceElementKeys === []) {
                throw new RuntimeException("WikiPageContentAiClient: source-based block [{$index}] has no source_element_keys.");
            }

            $blocks[$index]['markdown'] = $blockMarkdown;
            $markdownParts[] = $blockMarkdown;
        }

        $markdown = trim(implode("\n\n", $markdownParts));

        if ($markdown === '') {
            throw new RuntimeException('WikiPageContentAiClient: generated page content was empty.');
        }

        $this->validateMarkdown($markdown);

        return [
            'markdown' => $markdown,
            'blocks' => array_values($blocks),
        ];
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

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function buildRepairPayload(
        string $pageTitle,
        string $pageType,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $additionalContext,
        string $languageName,
        array $linkCatalog = [],
        array $sourceElements = [],
    ): array {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->repairDeveloperPrompt($pageType, $languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->repairUserPrompt($pageTitle, $existingMarkdown, $issues, $sourceText, $additionalContext, $linkCatalog, $sourceElements),
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

    private function repairDeveloperPrompt(string $pageType, string $languageName): string
    {
        return implode("\n", [
            "You are an editorial wiki writer repairing a previously generated {$pageType} page in {$languageName} that has one or more planned sections left empty, missing real content, or containing only a link.",
            '',
            'REPAIR RULES (mandatory):',
            '- Return the FULL corrected page as page.blocks, using the exact same block schema as ordinary generation (one block per heading, paragraph, or heading+paragraph group).',
            '- Keep everything from the previously generated page that is already good — do not rewrite or remove content that is not part of a listed broken section.',
            '- For each broken section named in "SECTIONS TO REPAIR" below, add a real, substantial paragraph of flowing prose under its heading — grounded in the source document. Never leave a listed heading empty, and never leave it with only a wikilink or a bare punctuation mark.',
            '- Preserve concrete details the source document actually states for each repaired section — roles, responsibilities, agenda items, participants, frequency, and decision flow — when the source material describes them.',
            '- Do not invent facts not supported by the source document; if the source genuinely says nothing more about a listed section, write the fullest accurate paragraph the source material supports rather than leaving the heading empty.',
            '- Do not add any new heading beyond what the previously generated page already had.',
            '',
            'STRICT PROHIBITIONS — any violation causes the response to be rejected:',
            '- No HTML comments (<!-- ... -->)',
            '- No lines starting with "Kilde:", "Source:", "Ref:" or any citation marker',
            '- No quoted source excerpts or blockquote lines (lines starting with >)',
            '- No filenames, document IDs, run IDs, or internal technical identifiers',
            '- No mention of AI generation, confidence levels, or approval status',
            '',
            'Every ordinary content block must explicitly choose content_origin: source_based or best_practice — for source_based blocks, copy exact source_element_keys from SOURCE ELEMENTS.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    /**
     * @param  list<array{type: string, planned_topic: string, heading: ?string}>  $issues
     * @param  list<array{slug: string, title: string, page_type: string}>  $linkCatalog
     * @param  list<array<string, mixed>>  $sourceElements
     */
    private function repairUserPrompt(
        string $pageTitle,
        string $existingMarkdown,
        array $issues,
        string $sourceText,
        string $additionalContext,
        array $linkCatalog = [],
        array $sourceElements = [],
    ): string {
        $parts = [
            "Page title: {$pageTitle}",
            '',
            'PREVIOUSLY GENERATED PAGE (needs repair — keep what is good, fix only the sections listed below):',
            '',
            $existingMarkdown,
            '',
            '---',
            '',
            'SECTIONS TO REPAIR:',
            '',
        ];

        foreach ($issues as $issue) {
            $heading = $issue['heading'] ?? $issue['planned_topic'];
            $parts[] = sprintf('- "%s" — planned topic: %s (%s)', $heading, $issue['planned_topic'], $issue['type']);
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'Source document:';
        $parts[] = '';
        $parts[] = $sourceText;

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
            foreach ($linkCatalog as $entry) {
                $parts[] = sprintf('[[%s|%s]]', $entry['slug'], $entry['title']);
            }
        } else {
            $parts[] = 'No other pages available to link to.';
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'SOURCE ELEMENTS ('.count($sourceElements).' elements):';
        $parts[] = '';
        $parts[] = 'Every source_based block must cite one or more source_element_key values from this list.';
        $parts[] = 'Do not invent source_element_key values.';
        $parts[] = '';
        $parts[] = (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $parts);
    }

    private function buildPayload(string $pageTitle, string $pageType, string $sourceText, string $additionalContext, string $languageName, array $linkCatalog = [], array $sourceElements = []): array
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
                            'text' => $this->userPrompt($pageTitle, $sourceText, $additionalContext, $linkCatalog, $sourceElements),
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

        $blockRules = implode("\n", [
            'STRUCTURED BLOCK OUTPUT:',
            '- Return page.blocks as the only content-bearing output. Do not return a single free-form markdown field.',
            '- Each block must be one independently traceable heading, paragraph, or heading+paragraph group.',
            '- Every ordinary content block must explicitly choose content_origin: source_based or best_practice. There is no third option and no default — choose deliberately for each block.',
            '- For source_based blocks, copy one or more exact source_element_keys from SOURCE ELEMENTS and include the corresponding source_element_types.',
            '- A source_based block without source_element_keys is invalid. Never mark a block source_based just because it sounds plausible or reads like something the source document would say — only when you can cite the specific source_element_keys it is drawn from.',
            '- A block is best_practice when it deliberately goes beyond the source document with general professional/industry knowledge or an established practice — and does NOT claim the named customer or supplier already has, does, or follows the thing described, and does NOT state a fact specific to this particular customer\'s/supplier\'s actual agreement, system, or process.',
            '- Write best_practice sentences in the SAME formal, neutral, declarative register as ordinary Wiki text — a plain statement of professional fact, not a suggestion. Do NOT open or mark a best_practice sentence with an advisory/recommendation phrase — never "bør", "kan", "anbefales", "det anbefales", "som beste praksis", "en vanlig tilnærming er", "faglig anbefaling", "it is recommended", "as a best practice", "a common approach is", "could", "should consider", or any similar hedging/advisory wording. For example, write "Tydelig rolle- og ansvarsfordeling gir klare eskaleringslinjer og konsistent prosessutførelse." — never "Det anbefales å ha tydelig rolle- og ansvarsfordeling...". The reader must be able to tell best_practice content apart from source_based content ONLY through the page\'s own content_origin/UI labeling, never through a difference in wording, tone, or confidence.',
            '- Do not soften a best_practice sentence into advice or add a hedging opener just because it goes beyond the source document — state the professional fact directly and confidently, exactly as you would phrase a source_based sentence.',
            '- General industry/framework knowledge that elaborates on a concept beyond what the source document literally states (e.g. explaining what a standard practice typically involves) is best_practice, not source_based — even if it uses similar terminology to the source. Only mark it source_based if you can cite the exact source_element_keys that state those specific facts.',
            '- For best_practice blocks, explain the positive basis in best_practice_reason and leave source_element_keys/source_element_types empty.',
            '- Do not classify factual statements, uncertain source facts, rewritten source facts, statements about the customer\'s or supplier\'s current/actual state, or likely hallucinations as best_practice — those must be source_based (if genuinely grounded, with real source_element_keys) or omitted.',
            '- A best_practice block must be necessary to understand or use this page\'s own owned topics (see PAGE RESPONSIBILITY below) for what the source material actually describes — never add general professional/industry elaboration of the wider subject area just because it is true and related. A thin source document justifies a thin page: when in doubt whether a piece of best_practice content is actually necessary here, leave it out rather than include it because it is technically accurate.',
            '- link_intents must list only useful visible Wiki links the block should contain; use an empty list when no visible link is useful.',
        ]);

        $responsibilityRules = implode("\n", [
            'PAGE RESPONSIBILITY:',
            '- "Additional context" (if present) states this page\'s own content responsibility in three tiers: what to explain in depth ("own content responsibility"), what to mention only briefly ("Reference only"), and what to never mention ("EXCLUDED").',
            '- Treat every EXCLUDED topic as a hard, binding boundary: do not mention it, allude to it, or give it a section, list, or even one sentence — not even to note that it exists or that it is covered elsewhere.',
            '- For a "Reference only" topic, write AT MOST one short sentence plus an inline [[wikilink]] to the page that owns it — never its own heading, paragraph, or list of sub-points.',
            '- Stay strictly inside this page\'s own content responsibility for everything else. The number of sections this page contains should track directly with the number of items in its own content responsibility — do not add or expand a section to cover something that is not one of those items, even if it is topically related.',
            '- When no such guidance is given at all, fall back to writing only what the source material actually supports for this specific page — a short, thin source document justifies a short, thin page; do not expand into a comprehensive treatment of the wider subject just because more could technically be said about it.',
            '- Never restate the same sentence, fact, or near-identical wording more than once on this page, and never copy wording verbatim from another page\'s content given as context — express a shared idea once, in your own words, in the place it actually belongs.',
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
                "You are an editorial wiki writer. Write a concise professional summary page in {$languageName}.",
                '',
                'SUMMARY STRUCTURE (mandatory):',
                '- First line must be a # heading containing the page title',
                '- Follow with 2-4 paragraphs summarising the key points',
                '- Write flowing prose — no bullet lists, no headings beyond the title',
                '- Inline-link the most important concept/entity pages from the allowed targets; keep the summary short and natural',
                '',
                'SOURCE FOR THIS SUMMARY:',
                '- If "Additional context" provides the finished article for this same source document, base this summary on that article\'s actual content and structure — condense what the article covers and link to it and other detail pages, rather than independently re-deriving the summary from the raw source document.',
                '- If no finished article is provided, summarise the source document directly.',
                '',
                $responsibilityRules,
                '',
                $blockRules,
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
                '- Add AT MOST one ## section per item in this page\'s own content responsibility (see PAGE RESPONSIBILITY below) — never a section for a reference-only or excluded topic, and never more sections than that list has items. When no content-responsibility guidance is given, add at most one or two sections and only for what the source material itself supports.',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article, concept, and entity pages from the allowed targets',
                '',
                'SYNTHESIS RULES:',
                '- Derive meaning from the provided source text and related page content',
                '- Do not invent facts not supported by the provided material',
                '- Related article or summary content, when provided, is background for understanding context ONLY — it is never itself a license to explain a topic in depth. Use it to decide what this page is responsible for, then write strictly within that responsibility (see PAGE RESPONSIBILITY below); a topic another page owns, or that is not one of this page\'s own responsibility items, belongs behind a short mention and a link at most, never a repeated or newly-invented explanation',
                '',
                $responsibilityRules,
                '',
                $blockRules,
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
                '- Add AT MOST one ## section per item in this page\'s own content responsibility (see PAGE RESPONSIBILITY below) — never a section for a reference-only or excluded topic, and never more sections than that list has items. When no content-responsibility guidance is given, add at most one or two sections and only for what the source material itself supports.',
                '- Write flowing prose — no bullet lists',
                '- Inline-link relevant article and concept pages from the allowed targets; avoid speculative relationships',
                '',
                'SYNTHESIS RULES:',
                '- Derive all facts from the provided source text and related page content',
                '- Do not invent roles, relationships, or attributes not present in the material',
                '- Related article or summary content, when provided, is background for understanding context ONLY — it is never itself a license to explain a topic in depth. Use it to decide what this page is responsible for, then write strictly within that responsibility (see PAGE RESPONSIBILITY below); a topic another page owns, or that is not one of this page\'s own responsibility items, belongs behind a short mention and a link at most, never a repeated or newly-invented explanation',
                '',
                $responsibilityRules,
                '',
                $blockRules,
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
                '- Organise the body using ## subheadings for logical sections. When this page\'s own content responsibility is given (see PAGE RESPONSIBILITY below), add at most one subheading per item in it — do not add a subheading for a reference-only or excluded topic',
                '- Write flowing prose paragraphs within each section — no bullet lists',
                '- End the article naturally, without a separate summary or conclusion heading',
                '- Inline-link central concept and entity pages, and any other allowed target the text clearly relates to',
                '',
                'SYNTHESIS RULES:',
                '- Synthesise the source material into coherent prose',
                '- Do not repeat the same fact across multiple sections',
                '- Do not invent facts not present in the source document',
                '- Do not expand into general background on the wider subject beyond what this page\'s own content responsibility calls for — a short source document justifies a short article',
                '',
                $responsibilityRules,
                '',
                $blockRules,
                '',
                $wikilinkRules,
                '',
                $prohibitions,
            ]),
        };
    }

    private function userPrompt(string $pageTitle, string $sourceText, string $additionalContext, array $linkCatalog = [], array $sourceElements = []): string
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

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = 'SOURCE ELEMENTS ('.count($sourceElements).' elements):';
        $parts[] = '';
        $parts[] = 'Every source_based block must cite one or more source_element_key values from this list.';
        $parts[] = 'Do not invent source_element_key values.';
        $parts[] = '';
        $parts[] = (string) json_encode($sourceElements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
                        'blocks' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'markdown' => ['type' => 'string'],
                                    'content_origin' => [
                                        'type' => 'string',
                                        'enum' => [
                                            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                                            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                                        ],
                                    ],
                                    'source_element_keys' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                    'source_element_types' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                    'best_practice_reason' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'link_intents' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'target_slug' => ['type' => 'string'],
                                                'reason' => ['type' => 'string'],
                                            ],
                                            'required' => ['target_slug', 'reason'],
                                            'additionalProperties' => false,
                                        ],
                                    ],
                                ],
                                'required' => [
                                    'markdown',
                                    'content_origin',
                                    'source_element_keys',
                                    'source_element_types',
                                    'best_practice_reason',
                                    'link_intents',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['blocks'],
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
