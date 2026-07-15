<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Rewrites ONLY the specific answer sections that RequirementWikiAlignmentAiClient flagged as
 * 'possible_conflict' — a real apparent contradiction with the customer's documented Wiki
 * knowledge, never a mere knowledge gap (see RequirementWikiAlignmentAiClient's docblock for that
 * distinction). Used by RequirementWikiAnswerService for at most one automatic revision pass per
 * generation; there is no repair loop here or in the caller.
 *
 * The Wiki pages are the fasit (source of truth) for what is actually true about the vendor — this
 * client corrects the specific contradiction described while deliberately preserving the section's
 * professional quality, level of detail, and any non-conflicting best-practice content. It must
 * never be used to turn a section generic or overly cautious.
 */
class RequirementWikiAnswerRevisionAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 1200;

    private const PROMPT_NAME = 'requirement_wiki_answer_revision';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Revise exactly the flagged sections to remove an apparent contradiction with the
     *          Wiki, keeping everything else about the answer untouched.
     * Inputs: The requirement, the sections to revise (key/heading/text/used_page_ids plus the
     *         alignment step's conflict_summary describing what is wrong), and the Wiki pages read
     *         during research (the fasit for the revision).
     * Returns: Revised sections keyed by their original section key. A key may legitimately be
     *          absent from the result if the model could not produce a usable revision for it — the
     *          caller keeps the original text for any key not returned here (no retry loop).
     * Side effects: None (one OpenAI call).
     *
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>, conflict_summary: string}>  $sectionsToRevise
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     * @return array<string, array{heading: string, text: string, used_page_ids: list<int>}>
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed schema result
     */
    public function reviseSections(
        string $requirementIdentifier,
        string $requirementText,
        array $sectionsToRevise,
        array $pages,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiAnswerRevisionAiClient: wiki AI generation is not enabled.');
        }

        if ($sectionsToRevise === []) {
            throw new RuntimeException('RequirementWikiAnswerRevisionAiClient: no sections were provided to revise.');
        }

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $sectionsToRevise, $pages, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiAnswerRevisionAiClient');

        return $this->normalize($decoded, $sectionsToRevise, array_column($pages, 'page_id'));
    }

    /**
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>, conflict_summary: string}>  $sectionsToRevise
     * @param  list<int>  $allowedPageIds
     * @return array<string, array{heading: string, text: string, used_page_ids: list<int>}>
     */
    private function normalize(array $decoded, array $sectionsToRevise, array $allowedPageIds): array
    {
        $allowedKeys = array_column($sectionsToRevise, 'key');
        $rawSections = $decoded['revised_sections'] ?? [];
        $rawSections = is_array($rawSections) ? $rawSections : [];

        $result = [];

        foreach ($rawSections as $rawSection) {
            if (! is_array($rawSection)) {
                continue;
            }

            $key = is_string($rawSection['key'] ?? null) ? trim($rawSection['key']) : '';

            // Only a key that was actually requested for revision may be returned — a hallucinated
            // or unrequested key is silently dropped, never merged into the answer.
            if ($key === '' || ! in_array($key, $allowedKeys, true) || isset($result[$key])) {
                continue;
            }

            $text = is_string($rawSection['text'] ?? null) ? trim($rawSection['text']) : '';

            if ($text === '') {
                continue;
            }

            $heading = is_string($rawSection['heading'] ?? null) ? trim($rawSection['heading']) : '';

            $usedPageIds = $rawSection['used_page_ids'] ?? [];
            $usedPageIds = is_array($usedPageIds) ? $usedPageIds : [];
            $usedPageIds = array_values(array_unique(array_intersect(
                array_map(static fn (mixed $id): int => (int) $id, $usedPageIds),
                $allowedPageIds,
            )));

            $result[$key] = ['heading' => $heading, 'text' => $text, 'used_page_ids' => $usedPageIds];
        }

        return $result;
    }

    /**
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>, conflict_summary: string}>  $sectionsToRevise
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     */
    private function buildPayload(
        string $requirementIdentifier,
        string $requirementText,
        array $sectionsToRevise,
        array $pages,
        string $languageName,
    ): array {
        $pagesBlock = implode("\n\n---\n\n", array_map(
            static function (array $page): string {
                $lines = [
                    sprintf('PAGE_ID: %d', $page['page_id']),
                    sprintf('TITLE: %s', $page['title']),
                    'CONTENT:',
                    $page['content_markdown'],
                ];

                if ($page['claim_texts'] !== []) {
                    $lines[] = 'VERIFIED FACTS for this page:';
                    $lines[] = implode("\n", array_map(static fn (string $claim): string => '- '.$claim, $page['claim_texts']));
                }

                return implode("\n", $lines);
            },
            $pages,
        ));

        $sectionsBlock = implode("\n\n---\n\n", array_map(
            static fn (array $section): string => implode("\n", [
                sprintf('KEY: %s', $section['key']),
                sprintf('HEADING: %s', $section['heading']),
                'CURRENT TEXT:',
                $section['text'],
                'WHAT IS WRONG (from quality assurance): '.$section['conflict_summary'],
            ]),
            $sectionsToRevise,
        ));

        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            "SECTIONS TO REVISE:\n".$sectionsBlock,
            "WIKI PAGES (source of truth for this revision):\n".($pagesBlock !== '' ? $pagesBlock : '(none)'),
        ]));

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
                            'text' => $userText,
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

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            'You correct specific sections of an already-written expert tender answer because quality assurance found each one may contradict the vendor\'s own documented Wiki knowledge.',
            "Answer language: {$languageName}.",
            '',
            'For each section given:',
            '- Use the Wiki pages as the sole source of truth for what is actually true about this vendor.',
            '- Fix ONLY the specific contradiction described in "WHAT IS WRONG" — do not rewrite unrelated parts of the section.',
            '- Preserve the section\'s professional quality, level of detail, and any best-practice content that is NOT part of the contradiction. Do not make the section generic, vague, or overly cautious.',
            '- Update used_page_ids to the page_ids that actually ground the corrected text (may be empty if the corrected content is best practice with no Wiki support).',
            '- Return every section you were given — using its exact key — even if, after review, only a small wording change was needed.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'revised_sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'heading' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'used_page_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'required' => ['key', 'heading', 'text', 'used_page_ids'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['revised_sections'],
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
