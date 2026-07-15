<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Assesses how each section of an already-written expert answer (RequirementWikiAnswerAiClient) is
 * grounded in the customer's own Wiki — a separate operation with its own schema, run AFTER the
 * answer exists. This client never writes or edits the answer text itself; it only classifies.
 *
 * The critical distinction this client must enforce (per the Fase 9 alignment correction): a
 * missing Wiki detail is NOT the same as a conflict. Recommending an undocumented best-practice
 * method is 'best_practice'. Only a section that appears to actually contradict the Wiki's
 * documented model (opposite ownership, a "must" where the Wiki says optional, a different
 * responsibility split, a tool the Wiki says is not used, etc.) is 'possible_conflict'. This
 * assessment operates at the section/paragraph level — never a rigid word-for-word or
 * sentence-for-sentence check.
 */
class RequirementWikiAlignmentAiClient
{
    public const STATUS_ALIGNED = 'aligned';

    public const STATUS_PARTIALLY_ALIGNED = 'partially_aligned';

    public const STATUS_BEST_PRACTICE = 'best_practice';

    public const STATUS_POSSIBLE_CONFLICT = 'possible_conflict';

    public const STATUSES = [
        self::STATUS_ALIGNED,
        self::STATUS_PARTIALLY_ALIGNED,
        self::STATUS_BEST_PRACTICE,
        self::STATUS_POSSIBLE_CONFLICT,
    ];

    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 2000;

    private const PROMPT_NAME = 'requirement_wiki_alignment';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Classify how each answer section is grounded in the Wiki pages actually read.
     * Inputs: The requirement, the answer's sections (key/heading/text/used_page_ids), and the
     *         Wiki pages read during research (content + verified-fact claim texts).
     * Returns: One assessment per input section, in the same order, each carrying its alignment
     *          status, the page_ids that actually support it (validated against the pages given),
     *          what is supported, what is undocumented, an optional conflict summary, and a review
     *          note for human quality assurance.
     * Side effects: None (one OpenAI call).
     *
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>}>  $answerSections
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     * @return list<array{section_key: string, alignment_status: string, supporting_page_ids: list<int>, supported_points: list<string>, uncovered_points: list<string>, conflict_summary: ?string, review_note: ?string}>
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed/incomplete schema result
     */
    public function assessAlignment(
        string $requirementIdentifier,
        string $requirementText,
        array $answerSections,
        array $pages,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiAlignmentAiClient: wiki AI generation is not enabled.');
        }

        if ($answerSections === []) {
            throw new RuntimeException('RequirementWikiAlignmentAiClient: no answer sections were provided to assess.');
        }

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $answerSections, $pages, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiAlignmentAiClient');

        return $this->normalize($decoded, $answerSections, array_column($pages, 'page_id'));
    }

    /**
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>}>  $answerSections
     * @param  list<int>  $allowedPageIds
     * @return list<array{section_key: string, alignment_status: string, supporting_page_ids: list<int>, supported_points: list<string>, uncovered_points: list<string>, conflict_summary: ?string, review_note: ?string}>
     */
    private function normalize(array $decoded, array $answerSections, array $allowedPageIds): array
    {
        $rawSections = $decoded['sections'] ?? [];
        $rawSections = is_array($rawSections) ? $rawSections : [];

        $bySectionKey = [];

        foreach ($rawSections as $rawSection) {
            if (! is_array($rawSection)) {
                continue;
            }

            $sectionKey = is_string($rawSection['section_key'] ?? null) ? trim($rawSection['section_key']) : '';

            if ($sectionKey === '' || isset($bySectionKey[$sectionKey])) {
                continue;
            }

            $status = $rawSection['alignment_status'] ?? null;

            if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
                continue;
            }

            $supportingPageIds = $rawSection['supporting_page_ids'] ?? [];
            $supportingPageIds = is_array($supportingPageIds) ? $supportingPageIds : [];
            $supportingPageIds = array_values(array_unique(array_intersect(
                array_map(static fn (mixed $id): int => (int) $id, $supportingPageIds),
                $allowedPageIds,
            )));

            $conflictSummary = $rawSection['conflict_summary'] ?? null;
            $conflictSummary = is_string($conflictSummary) ? trim($conflictSummary) : null;
            $conflictSummary = $conflictSummary !== '' ? $conflictSummary : null;

            // Consistency guard: a conflict summary only ever makes sense alongside the conflict
            // status itself — enforced here, not left to the model to keep in sync on its own.
            if ($status !== self::STATUS_POSSIBLE_CONFLICT) {
                $conflictSummary = null;
            }

            $reviewNote = $rawSection['review_note'] ?? null;
            $reviewNote = is_string($reviewNote) ? trim($reviewNote) : null;
            $reviewNote = $reviewNote !== '' ? $reviewNote : null;

            $bySectionKey[$sectionKey] = [
                'section_key' => $sectionKey,
                'alignment_status' => $status,
                'supporting_page_ids' => $supportingPageIds,
                'supported_points' => $this->stringList($rawSection['supported_points'] ?? []),
                'uncovered_points' => $this->stringList($rawSection['uncovered_points'] ?? []),
                'conflict_summary' => $conflictSummary,
                'review_note' => $reviewNote,
            ];
        }

        $result = [];

        foreach ($answerSections as $section) {
            $assessment = $bySectionKey[$section['key']] ?? null;

            if ($assessment === null) {
                throw new RuntimeException("RequirementWikiAlignmentAiClient: response is missing an alignment assessment for section [{$section['key']}].");
            }

            $result[] = $assessment;
        }

        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>}>  $answerSections
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     */
    private function buildPayload(
        string $requirementIdentifier,
        string $requirementText,
        array $answerSections,
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
                sprintf('SECTION_KEY: %s', $section['key']),
                sprintf('HEADING: %s', $section['heading']),
                'TEXT:',
                $section['text'],
                'PAGE_IDS THE ANSWER ITSELF CITED FOR THIS SECTION: '.($section['used_page_ids'] === [] ? '(none)' : implode(', ', $section['used_page_ids'])),
            ]),
            $answerSections,
        ));

        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            "ANSWER SECTIONS TO ASSESS:\n".$sectionsBlock,
            "WIKI PAGES ACTUALLY READ (the only source of truth about this vendor):\n".($pagesBlock !== '' ? $pagesBlock : '(none — no Wiki pages were read for this requirement.)'),
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
            'You are the quality-assurance alignment step for an already-written expert tender answer. You do NOT rewrite the answer — you classify how each section relates to the vendor\'s own documented Wiki knowledge, for a human reviewer.',
            "Respond in {$languageName} for supported_points/uncovered_points/conflict_summary/review_note; section_key/alignment_status/supporting_page_ids are structural.",
            '',
            'Return exactly one assessment per given section, using its exact section_key.',
            '',
            'Choose alignment_status per section as one of:',
            '- "aligned": the section\'s substantive content is supported by the Wiki pages. Minor phrasing differences or small non-essential elaborations do not break this.',
            '- "partially_aligned": the main direction/substance is supported by the Wiki, but the section also contains real details or extensions that are not documented there.',
            '- "best_practice": the section is professionally relevant content, but the Wiki gives no meaningful support for it at all.',
            '- "possible_conflict": the section appears to actually contradict or be incompatible with what the Wiki documents — e.g. it assigns ownership/responsibility the opposite way from the Wiki, states something is mandatory when the Wiki says it is optional (or vice versa), names a tool or method the Wiki says is NOT used, or otherwise describes a materially different model than the Wiki\'s own documented one.',
            '',
            'CRITICAL — do not confuse a knowledge gap with a conflict:',
            '- A section recommending a method, tool, or step the Wiki simply does not mention is "best_practice", never "possible_conflict". Silence in the Wiki is not disagreement.',
            '- Only classify "possible_conflict" when the section states something that actually looks incompatible with what the Wiki explicitly documents — not merely something additional or unmentioned.',
            '- When in doubt between best_practice and possible_conflict, choose best_practice.',
            '',
            'For each section also provide:',
            '- supporting_page_ids: page_ids from the Wiki pages given whose content genuinely supports this section (may be empty for best_practice).',
            '- supported_points: short bullet-style statements of what IS backed by the Wiki for this section (may be empty).',
            '- uncovered_points: short bullet-style statements of what the section states or implies that the Wiki does NOT document (may be empty — leave empty for a fully aligned section).',
            '- conflict_summary: only when alignment_status is "possible_conflict", a precise one- or two-sentence description of the actual contradiction (what the section says vs. what the Wiki documents). Null otherwise.',
            '- review_note: a brief, human-readable note for a quality reviewer, or null if there is nothing notable to add beyond the above.',
            '',
            'This is a section-level, meaning-level judgment — never a rigid word-for-word or sentence-for-sentence comparison.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'section_key' => ['type' => 'string'],
                            'alignment_status' => [
                                'type' => 'string',
                                'enum' => self::STATUSES,
                            ],
                            'supporting_page_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                            'supported_points' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'uncovered_points' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'conflict_summary' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                            'review_note' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                        'required' => [
                            'section_key', 'alignment_status', 'supporting_page_ids',
                            'supported_points', 'uncovered_points', 'conflict_summary', 'review_note',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['sections'],
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
