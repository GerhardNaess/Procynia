<?php

namespace App\Services\Ai\Wiki;

use App\Models\SavedNoticeAiRequirementAssessment;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Writes the "Vurdering" (assessment) judgment for one requirement from an already-validated Wiki
 * research context (see RequirementWikiResearchService) — how well the customer's own approved Wiki
 * knowledge covers the requirement, the risk of leaving it as-is, and what to do next. A single AI
 * call producing the full judgment directly (coverage_status, has_possible_conflict, risk_level, and
 * the four free-text fields), matching the shape the pre-existing Knowledge-Base-grounded
 * RequirementAssessmentService already established — this is a judgment task, not a multi-section
 * synthesis, so it does not need a separate alignment/revision pass the way
 * RequirementWikiAnswerAiClient does.
 *
 * Same anti-fabrication and case-instructions boundaries as RequirementWikiAnswerAiClient: never
 * invent vendor-specific facts beyond what the given pages document, and case_instructions govern
 * tone/terminology/style only — never the judgment itself.
 */
class RequirementWikiAssessmentAiClient
{
    private const MODEL = 'gpt-4.1-mini';

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 800;

    private const PROMPT_NAME = 'requirement_wiki_assessment';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Purpose: Judge how well the given Wiki pages cover one requirement.
     * Inputs: The requirement identifier/text, the pages read during research (may be empty — the
     *         model still writes a valid "missing" assessment using only the requirement text), the
     *         answer language, the owning customer's shared case_instructions, and this generation's
     *         one-off requirementUserPrompt — both subordinate, style-only directives, applied in
     *         that priority order, exactly as in RequirementWikiAnswerAiClient.
     * Returns: The full assessment judgment.
     * Side effects: None (one OpenAI call).
     *
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, source_based_claim_texts: list<string>, best_practice_claim_texts: list<string>}>  $pages
     * @return array{coverage_status: string, has_possible_conflict: bool, risk_level: string, requirement_summary: string, coverage_rationale: string, missing_information: string, recommended_next_step: string}
     *
     * @throws RuntimeException on API error, empty response, invalid JSON, or a malformed/incomplete schema result
     */
    public function assessRequirement(
        string $requirementIdentifier,
        string $requirementText,
        array $pages,
        string $languageCode,
        ?string $caseInstructions = null,
        ?string $requirementUserPrompt = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException('RequirementWikiAssessmentAiClient: wiki AI generation is not enabled.');
        }

        $payload = $this->buildPayload($requirementIdentifier, $requirementText, $pages, $this->languageName($languageCode), $caseInstructions, $requirementUserPrompt);
        $response = $this->openAiClient->createResponse($payload);
        $decoded = $this->responsesDecoder->decode($response, 'RequirementWikiAssessmentAiClient');

        return $this->normalize($decoded);
    }

    /** @return array{coverage_status: string, has_possible_conflict: bool, risk_level: string, requirement_summary: string, coverage_rationale: string, missing_information: string, recommended_next_step: string} */
    private function normalize(array $decoded): array
    {
        $coverageStatus = $decoded['coverage_status'] ?? null;

        if (! is_string($coverageStatus) || ! in_array($coverageStatus, SavedNoticeAiRequirementAssessment::COVERAGE_STATUSES, true)) {
            throw new RuntimeException('RequirementWikiAssessmentAiClient: response has an invalid or missing coverage_status.');
        }

        $riskLevel = $decoded['risk_level'] ?? null;

        if (! is_string($riskLevel) || ! in_array($riskLevel, SavedNoticeAiRequirementAssessment::RISK_LEVELS, true)) {
            throw new RuntimeException('RequirementWikiAssessmentAiClient: response has an invalid or missing risk_level.');
        }

        $requirementSummary = $this->requiredString($decoded, 'requirement_summary');
        $coverageRationale = $this->requiredString($decoded, 'coverage_rationale');
        $missingInformation = $this->requiredString($decoded, 'missing_information');
        $recommendedNextStep = $this->requiredString($decoded, 'recommended_next_step');

        return [
            'coverage_status' => $coverageStatus,
            'has_possible_conflict' => (bool) ($decoded['has_possible_conflict'] ?? false),
            'risk_level' => $riskLevel,
            'requirement_summary' => $requirementSummary,
            'coverage_rationale' => $coverageRationale,
            'missing_information' => $missingInformation,
            'recommended_next_step' => $recommendedNextStep,
        ];
    }

    private function requiredString(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new RuntimeException("RequirementWikiAssessmentAiClient: response is missing a usable [{$key}].");
        }

        return $value;
    }

    /**
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, source_based_claim_texts: list<string>, best_practice_claim_texts: list<string>}>  $pages
     */
    private function buildPayload(
        string $requirementIdentifier,
        string $requirementText,
        array $pages,
        string $languageName,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
    ): array {
        $pagesBlock = $pages === []
            ? '(none — the Wiki had no relevant approved pages for this requirement.)'
            : implode("\n\n---\n\n", array_map(
                static function (array $page): string {
                    $lines = [
                        sprintf('PAGE_ID: %d', $page['page_id']),
                        sprintf('TITLE: %s', $page['title']),
                        'CONTENT:',
                        $page['content_markdown'],
                    ];

                    if ($page['source_based_claim_texts'] !== []) {
                        $lines[] = 'SOURCE-DOCUMENTED FACTS for this page (documented in the customer\'s own sources):';
                        $lines[] = implode("\n", array_map(static fn (string $claim): string => '- '.$claim, $page['source_based_claim_texts']));
                    }

                    if ($page['best_practice_claim_texts'] !== []) {
                        $lines[] = 'BEST-PRACTICE SUGGESTIONS for this page (NOT documented in the customer\'s own sources):';
                        $lines[] = implode("\n", array_map(static fn (string $claim): string => '- '.$claim, $page['best_practice_claim_texts']));
                    }

                    return implode("\n", $lines);
                },
                $pages,
            ));

        $trimmedCaseInstructions = trim((string) $caseInstructions);
        $trimmedRequirementUserPrompt = trim((string) $requirementUserPrompt);

        $userText = implode("\n\n", array_filter([
            'REQUIREMENT IDENTIFIER: '.($requirementIdentifier !== '' ? $requirementIdentifier : '(none)'),
            'REQUIREMENT TEXT: '.$requirementText,
            "WIKI PAGES READ:\n".$pagesBlock,
            $trimmedCaseInstructions !== ''
                ? "CASE INSTRUCTIONS (tone, terminology, style, capitalization only — never a source of facts, never permitted to override a page's SOURCE-DOCUMENTED FACTS or weaken the anti-fabrication rules below):\n".$trimmedCaseInstructions
                : '',
            $trimmedRequirementUserPrompt !== ''
                ? "REQUIREMENT-SPECIFIC INSTRUCTIONS (applies only to this assessment, same subordinate style-only status as CASE INSTRUCTIONS above):\n".$trimmedRequirementUserPrompt
                : '',
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
            'You are assessing how well one procurement requirement is covered by the vendor\'s own Enterprise Wiki knowledge, for an internal bid reviewer — not writing a tender answer.',
            "Write requirement_summary/coverage_rationale/missing_information/recommended_next_step in {$languageName}.",
            '',
            'Base the assessment ONLY on the requirement text and the Wiki pages given below — never on general assumptions about what a typical vendor might do.',
            '',
            'Choose coverage_status as one of:',
            '- "covered": the Wiki pages given substantively document how this requirement is met.',
            '- "partial": the Wiki pages give some real, relevant grounding, but meaningful parts of the requirement are not documented.',
            '- "missing": the Wiki pages given (if any) give no meaningful grounding for this requirement at all.',
            '',
            'has_possible_conflict: true only when the Wiki pages appear to actually contradict what the requirement needs (e.g. the Wiki documents the opposite responsibility split, a tool/process the requirement implies is not used, or a "must" where the Wiki says something is optional). A Wiki that simply does not mention something is a coverage gap, never a conflict — when in doubt, false.',
            '',
            'Choose risk_level as one of:',
            '- "low": well covered, or a gap with no material bid/delivery risk.',
            '- "medium": a real gap that should be closed before submission but is not likely to disqualify the bid on its own.',
            '- "high": missing or conflicting coverage on a requirement that could materially harm the bid or the delivery if left unaddressed.',
            '',
            'requirement_summary: a short, neutral restatement of what the requirement actually asks for.',
            'coverage_rationale: a precise explanation of why this coverage_status was chosen, referencing what the Wiki pages do or do not document.',
            'missing_information: what documented knowledge is missing (or "" only when coverage_status is "covered" and truly nothing is missing) — never invent what the missing content should say.',
            'recommended_next_step: one concrete, actionable next step (e.g. "Dokumenter rutinen for X i Enterprise Wiki", "Bekreft med fagansvarlig om Y", or, if fully covered, a short confirmation that no action is needed).',
            '',
            'Case instructions and requirement-specific instructions (if provided in the user message):',
            '- Apply them only for tone, terminology, style, and capitalization.',
            '- They never change the coverage_status, has_possible_conflict, or risk_level judgment, and never override a Wiki page\'s SOURCE-DOCUMENTED FACTS — if either instruction would conflict with a documented fact, keep the fact and ignore the conflicting part of the instruction.',
            '',
            'Protecting against invented company facts:',
            '- Never state as an existing fact about the vendor anything not supported by a page\'s SOURCE-DOCUMENTED FACTS or its own documented content.',
            '- A page\'s BEST-PRACTICE SUGGESTIONS are not documented customer fact — treat them the same as no coverage when judging coverage_status, since they document a professional recommendation, not what the vendor actually does.',
            '',
            'Return only JSON matching the schema. No text before or after JSON.',
        ]);
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'coverage_status' => [
                    'type' => 'string',
                    'enum' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUSES,
                ],
                'has_possible_conflict' => ['type' => 'boolean'],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => SavedNoticeAiRequirementAssessment::RISK_LEVELS,
                ],
                'requirement_summary' => ['type' => 'string'],
                'coverage_rationale' => ['type' => 'string'],
                'missing_information' => ['type' => 'string'],
                'recommended_next_step' => ['type' => 'string'],
            ],
            'required' => [
                'coverage_status', 'has_possible_conflict', 'risk_level',
                'requirement_summary', 'coverage_rationale', 'missing_information', 'recommended_next_step',
            ],
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
