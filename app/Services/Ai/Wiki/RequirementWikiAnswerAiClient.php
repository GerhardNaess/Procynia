<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Writes the final requirement answer from an already-validated Wiki research context (see
 * RequirementWikiResearchService) — never finds pages itself, never introduces content outside
 * what it was actually given. Research (page discovery/reading) and answer-writing are
 * deliberately separate operations with separate schemas: this client only ever sees pages that
 * were ALREADY read, and cites them by page_id, never by claim_id.
 *
 * Follows the same established Wiki AI client conventions as WikiPageClaimExtractionAiClient:
 * fixed model, ENTERPRISE_WIKI_AI_ENABLED gate, shared EnterpriseWikiResponsesDecoder.
 *
 * COVERAGE CONTRACT (unchanged from the original Fase 9 design, now scoped to pages instead of
 * claims): coverage_status is one of 'full' (the read pages fully answer the requirement),
 * 'partial' (they address part of it — missing_summary must describe the gap), or 'none' (they do
 * not provide enough to answer at all — answer_sections/used_page_ids must then be empty; the
 * model must never invent an answer when coverage is 'none', and this rule is enforced in
 * normalize(), never left to the model to honor on its own).
 */
class RequirementWikiAnswerAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 2000;

    private const PROMPT_NAME = 'requirement_wiki_answer';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Write the requirement answer from the pages actually read during research.
     * Inputs: The requirement identifier/text and the pages that were read (each carrying its own
     *         content_markdown/content_mode/selected_headings, and the approved, non-conflicting
     *         claim texts that ground any concrete commitments on that page — see
     *         RequirementWikiResearchService's supporting_claim_ids).
     * Returns: {coverage_status, answer_sections, missing_summary, used_page_ids} — every
     *          answer_sections[].page_ids is guaranteed to be a subset of the page_ids actually
     *          passed in, coverage 'none' is guaranteed to carry no sections/used_page_ids, and
     *          'full'/'partial' are guaranteed to have at least one valid cited page.
     * Side effects: None (one OpenAI call).
     *
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     * @return array{coverage_status: string, answer_sections: list<array{text: string, page_ids: list<int>}>, missing_summary: ?string, used_page_ids: list<int>}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed/inconsistent schema result
     */
    public function generateAnswer(
        string $requirementIdentifier,
        string $requirementText,
        array $pages,
        string $languageCode,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: wiki AI generation is not enabled.');
        }

        if ($pages === []) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: no pages were provided to answer from.');
        }

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $pages, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiAnswerAiClient');

        return $this->normalize($decoded, array_column($pages, 'page_id'));
    }

    /**
     * @param  list<int>  $allowedPageIds
     * @return array{coverage_status: string, answer_sections: list<array{text: string, page_ids: list<int>}>, missing_summary: ?string, used_page_ids: list<int>}
     */
    private function normalize(array $decoded, array $allowedPageIds): array
    {
        $coverageStatus = $decoded['coverage_status'] ?? null;

        if (! is_string($coverageStatus) || ! in_array($coverageStatus, ['full', 'partial', 'none'], true)) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: response coverage_status was missing or invalid.');
        }

        $missingSummary = $decoded['missing_summary'] ?? null;
        $missingSummary = is_string($missingSummary) ? trim($missingSummary) : null;
        $missingSummary = $missingSummary !== '' ? $missingSummary : null;

        // The anti-fabrication guarantee: 'none' can never carry sections or cited pages, no
        // matter what the model returned — this is enforced here, not left as a model instruction.
        if ($coverageStatus === 'none') {
            return [
                'coverage_status' => 'none',
                'answer_sections' => [],
                'missing_summary' => $missingSummary,
                'used_page_ids' => [],
            ];
        }

        $rawSections = $decoded['answer_sections'] ?? [];
        $rawSections = is_array($rawSections) ? $rawSections : [];

        $validSections = [];

        foreach ($rawSections as $rawSection) {
            if (! is_array($rawSection)) {
                continue;
            }

            $text = is_string($rawSection['text'] ?? null) ? trim($rawSection['text']) : '';

            if ($text === '') {
                continue;
            }

            $sectionPageIds = $rawSection['page_ids'] ?? [];
            $sectionPageIds = is_array($sectionPageIds) ? $sectionPageIds : [];
            $sectionPageIds = array_values(array_unique(array_intersect(
                array_map(static fn (mixed $id): int => (int) $id, $sectionPageIds),
                $allowedPageIds,
            )));

            // A section citing no page that was actually read cannot be kept — every claim in the
            // answer must trace back to a page the research step actually validated and read.
            if ($sectionPageIds === []) {
                continue;
            }

            $validSections[] = ['text' => $text, 'page_ids' => $sectionPageIds];
        }

        if ($validSections === []) {
            throw new RuntimeException('RequirementWikiAnswerAiClient: response reported coverage but produced no section citing an actually-read page.');
        }

        // used_page_ids is derived from the validated sections, never trusted verbatim from the
        // model's own output — this is the authoritative citation set.
        $usedPageIds = [];

        foreach ($validSections as $section) {
            foreach ($section['page_ids'] as $pageId) {
                $usedPageIds[$pageId] = true;
            }
        }

        return [
            'coverage_status' => $coverageStatus,
            'answer_sections' => $validSections,
            'missing_summary' => $missingSummary,
            'used_page_ids' => array_values(array_keys($usedPageIds)),
        ];
    }

    /**
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pages
     */
    private function buildPayload(string $requirementIdentifier, string $requirementText, array $pages, string $languageName): array
    {
        $pagesBlock = implode("\n\n---\n\n", array_map(
            static function (array $page): string {
                $lines = [
                    sprintf('PAGE_ID: %d', $page['page_id']),
                    sprintf('TITLE: %s', $page['title']),
                    sprintf('TYPE: %s', $page['page_type']),
                    sprintf('CONTENT (%s):', $page['content_mode'] === 'full' ? 'full page' : 'selected sections: '.implode(', ', $page['selected_headings'])),
                    $page['content_markdown'],
                ];

                if ($page['claim_texts'] !== []) {
                    $lines[] = 'VERIFIED FACTS for this page (use these — and only these — for any concrete SLA, response time, metric, frequency, role, tool, certification, guarantee, or commitment you state):';
                    $lines[] = implode("\n", array_map(static fn (string $claim): string => '- '.$claim, $page['claim_texts']));
                }

                return implode("\n", $lines);
            },
            $pages,
        ));

        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            "WIKI PAGES READ:\n".$pagesBlock,
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
            'You write the supplier\'s (leverandørens) tender response to a single procurement requirement. Your ONLY source of knowledge is the Wiki pages below — read each one carefully before writing.',
            "Answer language: {$languageName}.",
            '',
            'How to use the pages:',
            '- Read every page provided in full and use its actual content_markdown — its headings, paragraphs and explanations — as your basis, not just isolated facts.',
            '- Synthesize across ALL relevant pages into one coherent answer. When pages describe connected process steps (e.g. one page hands off to another), explain that connection if the pages document it.',
            '- Use knowledge discovered by following the pages\' own Wiki links/backlinks exactly the same way as pages found directly — a page is a page, however it was found.',
            '- Every answer_sections entry must list the page_ids of the pages that actually support that section. Never cite a page_id that was not provided to you.',
            '',
            'Voice and content:',
            '- Write as the supplier answering the tender requirement directly — a real bid response in flowing, professional paragraphs, not a list, not a description of the pages.',
            '- Never mention that you are an AI, that this is a Wiki, a database, retrieval, claims, or any internal process — write only the tender response text itself.',
            '- Use only information stated in the provided pages. Never use general/external ITIL or industry knowledge to fill a gap the pages do not cover.',
            '- Never turn a general process description into a specific delivery commitment the pages do not state.',
            '- Never add advisory services, ownership, reporting duties, roles, or responsibilities unless the pages document them.',
            '- Never use words like "guarantees", "always", or "ensures" unless the pages explicitly support that certainty.',
            '- Concrete specifics — SLAs, response times, metrics, frequencies, named roles, tools, certifications, guarantees, or contractual commitments — must be grounded in the VERIFIED FACTS listed under a page. If a page has no VERIFIED FACTS for something specific, do not state it as a specific.',
            '- Do not pad the answer with marketing filler to make it longer. Length should follow from how much the pages actually document, not from a target word count.',
            '',
            'Coverage rules:',
            '- Set coverage_status to "full" only when the pages together fully answer the requirement, with no necessary assumptions. Use as many short paragraphs (answer_sections) as the material naturally supports — typically 2 to 4 when the basis is rich, fewer when it is not.',
            '- Set coverage_status to "partial" when the pages give a real, useful answer but one or more essential parts of the requirement are not documented. Write the section(s) for what IS documented first, then set missing_summary to a concrete, specific description of what is missing. Never present partial coverage as full compliance.',
            '- Set coverage_status to "none" when the pages do not provide enough to answer the requirement at all. In this case return an empty answer_sections array and leave used_page_ids empty — never invent or guess an answer.',
            '- Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'coverage_status' => [
                    'type' => 'string',
                    'enum' => ['full', 'partial', 'none'],
                ],
                'answer_sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'page_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'required' => ['text', 'page_ids'],
                        'additionalProperties' => false,
                    ],
                ],
                'missing_summary' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'used_page_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['coverage_status', 'answer_sections', 'missing_summary', 'used_page_ids'],
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
