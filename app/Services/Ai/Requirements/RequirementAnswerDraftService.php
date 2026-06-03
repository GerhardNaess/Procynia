<?php

namespace App\Services\Ai\Requirements;

use App\Models\KnowledgeItem;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\AiTokenLogger;
use App\Services\Ai\AiUsageGuard;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementAnswerDraftService
{
    private const MAX_EVIDENCE_ROWS = 15;

    private const MAX_OUTPUT_TOKENS = 7000;

    private const LONG_FORM_TARGET_THRESHOLD = 800;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly AiTokenLogger $tokenLogger = new AiTokenLogger(),
    ) {
    }

    /**
     * Purpose: Generate and persist one canonical editable answer draft for a requirement.
     * Inputs: The requirement to draft.
     * Returns: The persisted requirement row with a canonical answer draft.
     * Side effects: May call OpenAI and updates the requirement row.
     */
    public function ensureAnswerDraft(
        SavedNoticeAiRequirement $requirement,
        Collection $answerBasisItems,
        bool $forceGenerate = false,
        ?string $caseInstructions = null,
        ?string $requirementUserPrompt = null,
        ?Collection $retrievedKnowledgeChunks = null,
        ?array $groundingJudge = null,
        string $languageCode = 'no',
        ?int $customerId = null,
        ?int $userId = null,
    ): SavedNoticeAiRequirement
    {
        $requirement->loadMissing([
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
        ]);

        $evidenceRows = $this->answerEvidenceRows($requirement);
        $answerBasisRows = $this->answerBasisRows($answerBasisItems);
        $retrievedKnowledgeRows = $this->retrievedKnowledgeRows($retrievedKnowledgeChunks);
        $answerDraftText = $this->requestAnswerDraft(
            $requirement,
            $evidenceRows,
            $answerBasisRows,
            $caseInstructions,
            $requirementUserPrompt,
            $retrievedKnowledgeRows,
            $groundingJudge,
            $languageCode,
            $customerId,
            $userId,
        );

        return DB::transaction(function () use ($requirement, $answerDraftText): SavedNoticeAiRequirement {
            $requirement->forceFill([
                'answer_draft_text' => $answerDraftText,
                'answer_draft_generated_at' => now(),
            ])->save();

            return $requirement->refresh();
        });
    }

    /**
     * Purpose: Persist an edited answer draft for a requirement.
     * Inputs: The requirement and the updated answer text.
     * Returns: The persisted requirement row with the edited draft.
     * Side effects: Updates the requirement row.
     */
    public function updateAnswerDraft(SavedNoticeAiRequirement $requirement, string $answerDraftText): SavedNoticeAiRequirement
    {
        $normalizedAnswerDraftText = $this->normalizeDraftText($answerDraftText);

        if ($normalizedAnswerDraftText === '') {
            throw new RuntimeException('Answer draft text cannot be empty.');
        }

        return DB::transaction(function () use ($requirement, $normalizedAnswerDraftText): SavedNoticeAiRequirement {
            $requirement->forceFill([
                'answer_draft_text' => $normalizedAnswerDraftText,
                'answer_draft_generated_at' => $requirement->answer_draft_generated_at ?? now(),
            ])->save();

            return $requirement->refresh();
        });
    }

    /**
     * Purpose: Send the requirement and its evidence context to OpenAI and parse the structured answer draft response.
     * Inputs: The requirement and the evidence rows that should ground the draft.
     * Returns: The validated answer draft text.
     * Side effects: Calls the OpenAI Responses API.
     */
    private function requestAnswerDraft(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
        string $languageCode = 'no',
        ?int $customerId = null,
        ?int $userId = null,
    ): string
    {
        $answerLengthGuidance = $this->extractAnswerLengthGuidance(implode("\n", array_filter([
            $requirement->requirement_text,
            $requirementUserPrompt,
        ], static fn (?string $value): bool => trim((string) $value) !== '')));

        if ($this->shouldUseSectionedLongFormDraft($answerLengthGuidance)) {
            return $this->requestSectionedLongFormAnswerDraft(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $requirementUserPrompt,
                $retrievedKnowledgeRows,
                $groundingJudge,
                $answerLengthGuidance,
                $languageCode,
                $customerId,
                $userId,
            );
        }

        $response = $this->openAiClient->createResponse(
            $this->openAiRequestPayload(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $requirementUserPrompt,
                $retrievedKnowledgeRows,
                $groundingJudge,
                $answerLengthGuidance,
                false,
                null,
                $languageCode,
            ),
            300,
        );

        $this->logTokenUsage($response, $requirement, $customerId, $userId);

        try {
            $answerDraftText = $this->validateAnswerDraftPayload(
                $this->decodeAnswerDraftPayload($response),
            );

            if ($this->shouldRetryShortTargetAnswer($answerDraftText, $answerLengthGuidance)) {
                Log::info('OpenAI requirement answer draft was shorter than the requested target length. Retrying once.', [
                    'saved_notice_ai_requirement_id' => $requirement->id,
                    'saved_notice_id' => $requirement->saved_notice_id,
                    'target_word_count' => $answerLengthGuidance['target_word_count'] ?? null,
                    'estimated_word_count' => $this->estimatedWordCount($answerDraftText),
                ]);

                $retryResponse = $this->openAiClient->createResponse(
                    $this->openAiRequestPayload(
                        $requirement,
                        $evidenceRows,
                        $answerBasisRows,
                        $caseInstructions,
                        $requirementUserPrompt,
                        $retrievedKnowledgeRows,
                        $groundingJudge,
                        $answerLengthGuidance,
                        true,
                        null,
                        $languageCode,
                    ),
                    300,
                );

                $this->logTokenUsage($retryResponse, $requirement, $customerId, $userId);

                return $this->validateAnswerDraftPayload(
                    $this->decodeAnswerDraftPayload($retryResponse),
                );
            }

            return $answerDraftText;
        } catch (Throwable $exception) {
            Log::warning('OpenAI requirement answer draft failed during response parsing.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'request_id' => data_get($response, '_meta.request_id'),
                'response_id' => data_get($response, 'id'),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Purpose: Generate long target-length answers as several grounded sections instead of one compressed answer.
     * Inputs: The requirement, grounded context rows, case instructions, grounding judge result, and extracted length guidance.
     * Returns: One combined editable answer draft assembled from generated sections.
     * Side effects: Calls the OpenAI Responses API once per planned section and may append one supplemental section.
     */
    private function requestSectionedLongFormAnswerDraft(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
        array $answerLengthGuidance,
        string $languageCode = 'no',
        ?int $customerId = null,
        ?int $userId = null,
    ): string
    {
        $draftSections = [];

        foreach ($this->longFormSections($answerLengthGuidance) as $section) {
            $draftSections[] = $this->requestLongFormSection(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $requirementUserPrompt,
                $retrievedKnowledgeRows,
                $groundingJudge,
                $answerLengthGuidance,
                $section,
                false,
                $languageCode,
                $customerId,
                $userId,
            );
        }

        $answerDraftText = $this->normalizeDraftText(implode("\n\n", array_filter(
            $draftSections,
            static fn (string $sectionText): bool => trim($sectionText) !== '',
        )));

        if ($this->shouldRetryShortTargetAnswer($answerDraftText, $answerLengthGuidance)) {
            $targetWordCount = (int) ($answerLengthGuidance['target_word_count'] ?? 0);
            $estimatedWordCount = $this->estimatedWordCount($answerDraftText);
            $remainingWordCount = max(180, $targetWordCount - $estimatedWordCount);
            $supplementalSection = [
                'section_number' => count($draftSections) + 1,
                'section_count' => count($draftSections) + 1,
                'section_title' => 'Utdypende beskrivelse og samlet kundeverdi',
                'section_focus' => 'Utdyp allerede støttede forhold som ikke er tilstrekkelig forklart i de foregående delene. Bruk bare dokumentert kunnskap, og legg særlig vekt på praktisk leveranse, kundeverdi, samhandling, oppfølging og dokumenterte begrensninger.',
                'target_word_count' => $remainingWordCount,
                'minimum_word_count' => max(140, (int) floor($remainingWordCount * 0.70)),
                'is_supplemental' => true,
            ];

            Log::info('Sectioned requirement answer draft was shorter than the requested target length. Adding one supplemental grounded section.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'target_word_count' => $targetWordCount,
                'estimated_word_count' => $estimatedWordCount,
                'remaining_word_count' => $remainingWordCount,
            ]);

            $draftSections[] = $this->requestLongFormSection(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $requirementUserPrompt,
                $retrievedKnowledgeRows,
                $groundingJudge,
                $answerLengthGuidance,
                $supplementalSection,
                true,
                $languageCode,
                $customerId,
                $userId,
            );

            $answerDraftText = $this->normalizeDraftText(implode("\n\n", array_filter(
                $draftSections,
                static fn (string $sectionText): bool => trim($sectionText) !== '',
            )));
        }

        if ($this->shouldRetryShortTargetAnswer($answerDraftText, $answerLengthGuidance)) {
            Log::warning('Sectioned requirement answer draft remained shorter than the requested target length.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'target_word_count' => $answerLengthGuidance['target_word_count'] ?? null,
                'minimum_target_word_count' => $answerLengthGuidance['minimum_target_word_count'] ?? null,
                'estimated_word_count' => $this->estimatedWordCount($answerDraftText),
            ]);
        }

        return $answerDraftText;
    }

    /**
     * Purpose: Generate one grounded long-form answer section with a local word target.
     * Inputs: The requirement, grounded context rows, case instructions, grounding judge result, length guidance, and section plan.
     * Returns: One validated section text.
     * Side effects: Calls the OpenAI Responses API and may retry the same section once if it is too short.
     */
    private function requestLongFormSection(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
        array $answerLengthGuidance,
        array $section,
        bool $isLengthRetry,
        string $languageCode = 'no',
        ?int $customerId = null,
        ?int $userId = null,
    ): string
    {
        $response = $this->openAiClient->createResponse(
            $this->openAiRequestPayload(
                $requirement,
                $evidenceRows,
                $answerBasisRows,
                $caseInstructions,
                $requirementUserPrompt,
                $retrievedKnowledgeRows,
                $groundingJudge,
                $answerLengthGuidance,
                $isLengthRetry,
                $section,
                $languageCode,
            ),
        );

        $this->logTokenUsage($response, $requirement, $customerId, $userId);

        try {
            $sectionText = $this->validateAnswerDraftPayload(
                $this->decodeAnswerDraftPayload($response),
            );

            if (! $isLengthRetry && $this->shouldRetryShortLongFormSection($sectionText, $section)) {
                Log::info('OpenAI requirement answer draft section was shorter than the section target. Retrying section once.', [
                    'saved_notice_ai_requirement_id' => $requirement->id,
                    'saved_notice_id' => $requirement->saved_notice_id,
                    'section_number' => $section['section_number'] ?? null,
                    'section_title' => $section['section_title'] ?? null,
                    'target_word_count' => $section['target_word_count'] ?? null,
                    'estimated_word_count' => $this->estimatedWordCount($sectionText),
                ]);

                return $this->requestLongFormSection(
                    $requirement,
                    $evidenceRows,
                    $answerBasisRows,
                    $caseInstructions,
                    $requirementUserPrompt,
                    $retrievedKnowledgeRows,
                    $groundingJudge,
                    $answerLengthGuidance,
                    $section,
                    true,
                    $languageCode,
                    $customerId,
                    $userId,
                );
            }

            return $sectionText;
        } catch (Throwable $exception) {
            Log::warning('OpenAI requirement answer draft section failed during response parsing.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'section_number' => $section['section_number'] ?? null,
                'section_title' => $section['section_title'] ?? null,
                'request_id' => data_get($response, '_meta.request_id'),
                'response_id' => data_get($response, 'id'),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one requirement answer draft.
     * Inputs: The requirement and the evidence rows used as input.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
        array $answerLengthGuidance,
        bool $isLengthRetry,
        ?array $longFormSection = null,
        string $languageCode = 'no',
    ): array
    {
        $model = $this->openAiModel();

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt($languageCode),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt(
                                $requirement,
                                $evidenceRows,
                                $answerBasisRows,
                                $caseInstructions,
                                $requirementUserPrompt,
                                $retrievedKnowledgeRows,
                                $groundingJudge,
                                $answerLengthGuidance,
                                $isLengthRetry,
                                $longFormSection,
                            ),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_answer_draft',
                    'description' => 'Editable supplier answer draft for one tender requirement.',
                    'strict' => true,
                    'schema' => $this->answerDraftSchema(),
                ],
            ],
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];

        if ($this->openAiModelSupportsTemperature($model)) {
            $payload['temperature'] = self::TEMPERATURE;
        }

        return $payload;
    }

    /**
     * Purpose: Build the developer instructions for the answer draft generator.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(string $languageCode = 'no'): string
    {
        return implode("\n", [
            'You draft one editable supplier answer for one tender requirement.',
            'Use the requirement text only to understand the customer request; it is not evidence.',
            'Use only the provided evidence, selected answer basis items, retrieved knowledge chunks, and grounding judge as factual basis.',
            'Use the selected answer basis items as the primary supplier-owned source material when drafting the answer.',
            'Use the retrieved knowledge chunks as the primary grounded source material for factual content.',
            'If a capability, system, tool, process, certification, role, or commitment is not documented in the supplied knowledge context, do not claim it.',
            'Use directly_supported_points from the grounding judge as the supported coverage map for the answer, not as the full wording limit.',
            'Each directly_supported_points item identifies a requirement area that has enough grounded support to answer.',
            'Within directly supported requirement areas, use the supplied evidence rows, selected answer basis items, retrieved knowledge chunks, evidence references, and evidence quotes as detailed factual material.',
            'Treat related_but_insufficient_points as context only, not as supplier evidence.',
            'Do not use unsupported_points or related_but_insufficient_points as supplier claims.',
            'Apply the case-specific AI instructions from the user payload for tone, terminology, style, and capitalization.',
            'Apply the requirement-specific user instructions from the user payload to this answer only, including requested focus, level of detail, formality, and length.',
            'If requirement-specific user instructions conflict with grounded facts, selected sources, or the JSON schema, keep the facts, selected sources, and schema.',
            'If the case-specific instructions conflict with grounded facts or the JSON schema, keep the facts and schema.',
            'Return only JSON that matches the schema.',
            'Write all string values in ' . $this->languageName($languageCode) . '.',
            'Do not write an assessment, critique, or coverage commentary.',
            'Do not invent facts that are not supported by the evidence.',
            'Do not use unselected answer basis items.',
            'Do not copy requirement wording into the answer unless it is also grounded in the supplied knowledge context.',
            'If the provided sources are thin, keep the answer factual and conservative, but do not confuse conservative with short.',
            'Conservative means factually restrained and evidence-backed; it does not mean compressed, generic, or artificially brief.',
            'Respect explicit answer length requirements supplied in answer_length_guidance.',
            'If answer_length_guidance.target_word_count is present, the requested word count is a binding drafting requirement, not an optional preference.',
            'If answer_length_guidance.max_word_count is present, do not exceed that word count.',
            'Do not silently produce a very short answer when the customer explicitly requests a long answer.',
            'For long target-length answers, the service may ask for one section at a time. In that case, write only the requested section and meet the local section target.',
            'Keep the answer practical, complete, and suitable for direct editing by the user.',
        ]);
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            'sv' => 'Swedish',
            'da' => 'Danish',
            default => 'Norwegian',
        };
    }

    /**
     * Purpose: Build the user-facing payload for the answer draft request.
     * Inputs: The requirement and its evidence rows.
     * Returns: A JSON string that is easy for the model to inspect.
     * Side effects: None.
     */
    private function userPrompt(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        Collection $answerBasisRows,
        ?string $caseInstructions,
        ?string $requirementUserPrompt,
        Collection $retrievedKnowledgeRows,
        ?array $groundingJudge,
        array $answerLengthGuidance,
        bool $isLengthRetry,
        ?array $longFormSection = null,
    ): string
    {
        $payload = [
            'instruction' => is_array($longFormSection)
                ? 'Generate exactly one section of a longer editable supplier answer draft for this requirement.'
                : 'Generate one editable supplier answer draft for this requirement.',
            'requirement' => [
                'id' => $requirement->id,
                'identifier' => $requirement->requirement_identifier,
                'text' => $requirement->requirement_text,
                'type' => $requirement->requirement_type,
                'approval_status' => $requirement->approval_status,
                'review_status' => $requirement->review_status,
            ],
            'answer_style' => 'Write a practical answer a supplier could submit after editing. Do not assess compliance.',
            'answer_length_guidance' => $answerLengthGuidance,
            'answer_length_strategy' => $this->answerLengthStrategy($answerLengthGuidance),
            'answer_basis_strategy' => $answerBasisRows->isEmpty()
                ? 'No answer basis items are selected. Use evidence and retrieved knowledge only; use the requirement text only to understand the customer request.'
                : 'Use the selected answer basis items as the primary supplier-owned source material. Keep the wording grounded in those items.',
            'answer_basis_items' => $this->promptAnswerBasisRows($answerBasisRows),
            'evidence_strategy' => $evidenceRows->isEmpty()
                ? 'No evidence rows are available. Use selected answer basis items and retrieved knowledge chunks only; do not treat requirement wording as evidence.'
                : 'Use the supplied evidence only. Selected evidence has priority over suggested evidence.',
            'evidence' => $this->promptEvidenceRows($evidenceRows),
            'retrieved_knowledge_strategy' => $retrievedKnowledgeRows->isEmpty()
                ? 'No relevant knowledge chunks were retrieved. Do not invent supplier claims.'
                : 'Use the retrieved knowledge chunks and grounding judge as the factual foundation. Requirement wording is not evidence.',
            'retrieved_knowledge_chunks' => $this->promptRetrievedKnowledgeRows($retrievedKnowledgeRows),
            'grounding_judge_strategy' => is_array($groundingJudge)
                ? 'The grounding judge is an internal guardrail. Use directly_supported_points as the supported coverage map for the answer, not as the full wording limit. Use evidence rows, selected answer basis items, retrieved knowledge chunks, evidence quotes, and evidence references to write detailed factual paragraphs within those supported areas. Treat related_but_insufficient_points as context only. Do not use related_but_insufficient_points or unsupported_points as supplier claims.'
                : 'No grounding judge payload was supplied.',
            'grounding_judge' => $this->promptGroundingJudge($groundingJudge),
        ];

        if (is_array($longFormSection)) {
            $payload['long_form_section'] = $longFormSection;
            $payload['section_length_strategy'] = $this->longFormSectionLengthStrategy($longFormSection, $isLengthRetry);
            $payload['section_output_contract'] = implode(' ', [
                'Return only this section inside answer_draft_text.',
                'Start with the exact section title as a Markdown heading.',
                'Do not write the other planned sections.',
                'Do not write a complete answer in this section call.',
                'Do not include coverage commentary or source analysis.',
                'Use connected paragraphs rather than a compressed bullet list.',
            ]);
        }

        if ($isLengthRetry) {
            $payload['answer_length_retry_instruction'] = implode(' ', [
                'The previous draft was shorter than the explicit word-count target.',
                'Expand supported details without adding unsupported claims.',
                'Use the supplied evidence, answer basis items, retrieved knowledge chunks, evidence quotes, and directly supported points more fully.',
            ]);
        }

        $normalizedCaseInstructions = $this->normalizeCaseInstructions($caseInstructions);

        if ($normalizedCaseInstructions !== null) {
            $payload['case_instructions'] = $normalizedCaseInstructions;
        }

        $normalizedRequirementUserPrompt = $this->normalizeCaseInstructions($requirementUserPrompt);

        if ($normalizedRequirementUserPrompt !== null) {
            $payload['requirement_user_instructions'] = [
                'scope' => 'Apply these instructions only to this requirement answer draft.',
                'priority' => 'Apply after case_instructions, unless they conflict with grounded facts, selected sources, or the required JSON schema.',
                'text' => $normalizedRequirementUserPrompt,
            ];
        }

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the requirement answer draft prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Extract explicit customer answer length guidance from the requirement text.
     * Inputs: The raw requirement text.
     * Returns: A deterministic prompt payload with target or maximum word count guidance.
     * Side effects: None.
     */
    private function extractAnswerLengthGuidance(?string $requirementText): array
    {
        $normalizedText = Str::squish((string) $requirementText);

        if ($normalizedText === '') {
            return [
                'target_word_count' => null,
                'minimum_target_word_count' => null,
                'max_word_count' => null,
                'length_instruction_type' => null,
                'source_phrase' => null,
            ];
        }

        if (preg_match('/\b(maks(?:imum)?|inntil)\s+(\d{2,5})\s+ord\b/iu', $normalizedText, $matches) === 1) {
            return [
                'target_word_count' => null,
                'minimum_target_word_count' => null,
                'max_word_count' => (int) $matches[2],
                'length_instruction_type' => 'maximum',
                'source_phrase' => $matches[0],
            ];
        }

        if (preg_match('/\b(på|ca\.?|cirka|omtrent)\s+(\d{2,5})\s+ord\b/iu', $normalizedText, $matches) === 1) {
            $targetWordCount = (int) $matches[2];

            return [
                'target_word_count' => $targetWordCount,
                'minimum_target_word_count' => (int) floor($targetWordCount * 0.85),
                'max_word_count' => null,
                'length_instruction_type' => 'target',
                'source_phrase' => $matches[0],
            ];
        }

        return [
            'target_word_count' => null,
            'minimum_target_word_count' => null,
            'max_word_count' => null,
            'length_instruction_type' => null,
            'source_phrase' => null,
        ];
    }

    /**
     * Purpose: Convert extracted answer length guidance into direct drafting instructions.
     * Inputs: The normalized answer length guidance payload.
     * Returns: A clear strategy string for the model prompt.
     * Side effects: None.
     */
    private function answerLengthStrategy(array $answerLengthGuidance): string
    {
        $targetWordCount = $answerLengthGuidance['target_word_count'] ?? null;
        $maxWordCount = $answerLengthGuidance['max_word_count'] ?? null;

        if (is_int($targetWordCount) && $targetWordCount > 0) {
            $minimumTargetWordCount = (int) ($answerLengthGuidance['minimum_target_word_count'] ?? floor($targetWordCount * 0.85));

            return implode(' ', [
                'The customer explicitly requested an answer close to ' . $targetWordCount . ' words.',
                'When enough grounded knowledge is available, the draft must be at least ' . $minimumTargetWordCount . ' words and should aim close to ' . $targetWordCount . ' words.',
                'A 250-600 word summary does not satisfy this requirement.',
                'Write a complete, structured supplier answer using only grounded knowledge.',
                'If this is a sectioned long-form request, meet the local section target because the sections are combined into the final answer.',
            ]);
        }

        if (is_int($maxWordCount) && $maxWordCount > 0) {
            return implode(' ', [
                'The customer explicitly requested a maximum answer length of ' . $maxWordCount . ' words.',
                'Do not exceed that limit.',
                'Use the available grounded knowledge efficiently and avoid unnecessary repetition.',
            ]);
        }

        return 'No explicit word-count instruction was detected. Write a complete answer that matches the requirement scope and available grounded knowledge.';
    }

    /**
     * Purpose: Decide whether explicit target-length answers should be generated section by section.
     * Inputs: The normalized answer length guidance payload.
     * Returns: True when the requirement asks for a long target answer.
     * Side effects: None.
     */
    private function shouldUseSectionedLongFormDraft(array $answerLengthGuidance): bool
    {
        $targetWordCount = $answerLengthGuidance['target_word_count'] ?? null;

        return is_int($targetWordCount) && $targetWordCount >= self::LONG_FORM_TARGET_THRESHOLD;
    }

    /**
     * Purpose: Build a deterministic section plan for long target-length answer drafting.
     * Inputs: The normalized answer length guidance payload.
     * Returns: Section definitions with local focus and word-count targets.
     * Side effects: None.
     */
    private function longFormSections(array $answerLengthGuidance): array
    {
        $targetWordCount = max(self::LONG_FORM_TARGET_THRESHOLD, (int) ($answerLengthGuidance['target_word_count'] ?? self::LONG_FORM_TARGET_THRESHOLD));
        $templates = [
            [
                'section_title' => 'Innledning og samlet forståelse',
                'section_focus' => 'Forklar leverandørens forståelse av kundens behov og hva den dokumenterte tjenesten samlet sett skal bidra med.',
            ],
            [
                'section_title' => 'Formål og omfang',
                'section_focus' => 'Beskriv dokumentert formål, omfang, rammer og hvilken verdi tjenesten eller leveransen gir kunden.',
            ],
            [
                'section_title' => 'Leveransemodell og arbeidsform',
                'section_focus' => 'Beskriv hvordan leveransen er organisert, hvordan arbeidet gjennomføres, og hvilke dokumenterte prosesser eller arbeidsformer som inngår.',
            ],
            [
                'section_title' => 'Operasjonell gjennomføring',
                'section_focus' => 'Utdyp dokumenterte aktiviteter, flyt, analyser, oppfølging, håndtering og praktisk gjennomføring innenfor de støttede områdene.',
            ],
            [
                'section_title' => 'Roller, ansvar og samhandling',
                'section_focus' => 'Beskriv dokumenterte roller, ansvar, kontaktflater, kundesamhandling og forventninger til samarbeid.',
            ],
            [
                'section_title' => 'Rapportering, oppfølging og styring',
                'section_focus' => 'Beskriv dokumentert rapportering, statusoppfølging, styring, kvalitetssikring og kundedialog.',
            ],
            [
                'section_title' => 'Forbedring, kvalitet og dokumenterte begrensninger',
                'section_focus' => 'Beskriv dokumentert kontinuerlig forbedring, kvalitetsarbeid, læring og eventuelle begrensninger som er uttrykkelig støttet i grunnlaget.',
            ],
            [
                'section_title' => 'Oppsummering av kundeverdi',
                'section_focus' => 'Oppsummer hvordan de støttede forholdene samlet dekker kundens behov, uten å introdusere nye udokumenterte påstander.',
            ],
        ];

        $sectionCount = min(count($templates), max(5, (int) ceil($targetWordCount / 150)));
        $baseWordCount = intdiv($targetWordCount, $sectionCount);
        $remainder = $targetWordCount - ($baseWordCount * $sectionCount);
        $sections = [];

        for ($index = 0; $index < $sectionCount; $index++) {
            $sectionTargetWordCount = $baseWordCount + ($index < $remainder ? 1 : 0);
            $sections[] = [
                'section_number' => $index + 1,
                'section_count' => $sectionCount,
                'section_title' => $templates[$index]['section_title'],
                'section_focus' => $templates[$index]['section_focus'],
                'target_word_count' => $sectionTargetWordCount,
                'minimum_word_count' => max(90, (int) floor($sectionTargetWordCount * 0.75)),
                'is_supplemental' => false,
            ];
        }

        return $sections;
    }

    /**
     * Purpose: Build direct local length instructions for one generated long-form section.
     * Inputs: The section plan row and whether this call is a retry.
     * Returns: A section-specific length strategy string.
     * Side effects: None.
     */
    private function longFormSectionLengthStrategy(array $section, bool $isLengthRetry): string
    {
        $targetWordCount = (int) ($section['target_word_count'] ?? 150);
        $minimumWordCount = (int) ($section['minimum_word_count'] ?? floor($targetWordCount * 0.75));

        return implode(' ', array_filter([
            'Write this section close to ' . $targetWordCount . ' words.',
            'The section must normally be at least ' . $minimumWordCount . ' words when the grounded context supports it.',
            'This local target is part of the total answer length requirement.',
            'Do not compress this section into a short summary.',
            $isLengthRetry ? 'This is a retry because the previous section draft was too short; expand supported details without adding unsupported claims.' : null,
        ]));
    }

    /**
     * Purpose: Decide whether one retry is needed because a target-length answer was far too short.
     * Inputs: The generated answer text and normalized answer length guidance.
     * Returns: True when a single length-focused retry should be attempted.
     * Side effects: None.
     */
    private function shouldRetryShortTargetAnswer(string $answerDraftText, array $answerLengthGuidance): bool
    {
        $targetWordCount = $answerLengthGuidance['target_word_count'] ?? null;

        if (! is_int($targetWordCount) || $targetWordCount < 300) {
            return false;
        }

        $estimatedWordCount = $this->estimatedWordCount($answerDraftText);
        $minimumAcceptableWordCount = (int) ($answerLengthGuidance['minimum_target_word_count'] ?? floor($targetWordCount * 0.85));

        return $estimatedWordCount > 0 && $estimatedWordCount < $minimumAcceptableWordCount;
    }

    /**
     * Purpose: Decide whether a generated long-form section needs one local retry.
     * Inputs: The generated section text and section plan row.
     * Returns: True when the section is materially shorter than its local target.
     * Side effects: None.
     */
    private function shouldRetryShortLongFormSection(string $sectionText, array $section): bool
    {
        $minimumWordCount = (int) ($section['minimum_word_count'] ?? 0);

        if ($minimumWordCount <= 0) {
            return false;
        }

        $estimatedWordCount = $this->estimatedWordCount($sectionText);

        return $estimatedWordCount > 0 && $estimatedWordCount < $minimumWordCount;
    }

    /**
     * Purpose: Estimate the word count of a generated answer without changing the answer text.
     * Inputs: The generated answer draft text.
     * Returns: A Unicode-aware estimated word count.
     * Side effects: None.
     */
    private function estimatedWordCount(string $answerDraftText): int
    {
        $normalizedText = Str::squish(strip_tags($answerDraftText));

        if ($normalizedText === '') {
            return 0;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $normalizedText, $matches);

        return count($matches[0] ?? []);
    }

    /**
     * Purpose: Select the persisted evidence rows that should ground the answer draft.
     * Inputs: The requirement with its evidence relation loaded.
     * Returns: A short, deterministic collection of selected or suggested evidence rows.
     * Side effects: None.
     */
    private function answerEvidenceRows(SavedNoticeAiRequirement $requirement): Collection
    {
        $selectedRows = $this->sortEvidenceRows($requirement->evidence
            ->filter(static fn (SavedNoticeAiEvidence $evidence): bool => $evidence->selection_status === SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED))
            ->take(self::MAX_EVIDENCE_ROWS)
            ->values();

        if ($selectedRows->isNotEmpty()) {
            return $selectedRows;
        }

        return $this->sortEvidenceRows($requirement->evidence
            ->filter(static fn (SavedNoticeAiEvidence $evidence): bool => $evidence->selection_status === SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED))
            ->take(self::MAX_EVIDENCE_ROWS)
            ->values();
    }

    /**
     * Purpose: Normalize the selected answer basis items for the prompt context.
     * Inputs: The selected basis item collection.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function answerBasisRows(Collection $answerBasisItems): Collection
    {
        return $answerBasisItems
            ->filter(static fn ($item): bool => $item instanceof SavedNoticeAiAnswerBasisItem)
            ->values();
    }

    /**
     * Purpose: Order evidence rows deterministically for prompt snapshots.
     * Inputs: A collection of evidence rows.
     * Returns: The same collection sorted by canonical evidence priority.
     * Side effects: None.
     */
    private function sortEvidenceRows(Collection $evidenceRows): Collection
    {
        return $evidenceRows
            ->sort(function (SavedNoticeAiEvidence $left, SavedNoticeAiEvidence $right): int {
                if ($left->is_primary !== $right->is_primary) {
                    return $right->is_primary <=> $left->is_primary;
                }

                if ($left->match_rank !== $right->match_rank) {
                    return $left->match_rank <=> $right->match_rank;
                }

                return $left->id <=> $right->id;
            })
            ->values();
    }

    /**
     * Purpose: Convert evidence rows into the prompt context structure.
     * Inputs: The answer draft evidence rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptEvidenceRows(Collection $evidenceRows): array
    {
        return $evidenceRows
            ->map(static function (SavedNoticeAiEvidence $evidence): array {
                $knowledgeItem = $evidence->knowledgeItem;
                $knowledgeChunk = $evidence->knowledgeItemChunk;
                $knowledgeDocumentType = $knowledgeItem?->document_type ?? $knowledgeItem?->content_type;

                return [
                    'id' => $evidence->id,
                    'selection_status' => $evidence->selection_status,
                    'match_type' => $evidence->match_type,
                    'match_score' => $evidence->match_score,
                    'match_rank' => $evidence->match_rank,
                    'is_primary' => $evidence->is_primary,
                    'knowledge_item' => [
                        'id' => $knowledgeItem?->id,
                        'title' => $knowledgeItem?->title,
                        'original_filename' => $knowledgeItem?->original_filename,
                        'document_type' => $knowledgeDocumentType,
                        'document_type_label' => filled($knowledgeDocumentType)
                            ? (KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocumentType] ?? $knowledgeDocumentType)
                            : null,
                    ],
                    'knowledge_chunk' => [
                        'id' => $knowledgeChunk?->id,
                        'chunk_index' => $knowledgeChunk?->chunk_index,
                        'chunk_type' => $knowledgeChunk?->chunk_type,
                        'content' => $knowledgeChunk?->content,
                        'summary_for_retrieval' => $knowledgeChunk?->summary_for_retrieval,
                        'table_text' => $knowledgeChunk?->table_text,
                        'start_offset' => $knowledgeChunk?->start_offset,
                        'end_offset' => $knowledgeChunk?->end_offset,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert answer basis rows into the prompt context structure.
     * Inputs: The selected answer basis rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptAnswerBasisRows(Collection $answerBasisRows): array
    {
        return $answerBasisRows
            ->map(static function (SavedNoticeAiAnswerBasisItem $answerBasisItem): array {
                return [
                    'id' => $answerBasisItem->id,
                    'type' => $answerBasisItem->answer_basis_type,
                    'type_label' => $answerBasisItem->answer_basis_type_label,
                    'title' => $answerBasisItem->title,
                    'original_filename' => $answerBasisItem->original_filename,
                    'body_text' => $answerBasisItem->body_text,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert retrieved knowledge chunks into compact prompt context.
     * Inputs: The retrieved knowledge chunk rows.
     * Returns: A deterministic array with the minimum context needed by the model.
     * Side effects: None.
     */
    private function promptRetrievedKnowledgeRows(Collection $retrievedKnowledgeRows): array
    {
        $promptRows = $retrievedKnowledgeRows
            ->map(function (array $retrievalRow): array {
                $content = trim((string) data_get($retrievalRow, 'content_preview', data_get($retrievalRow, 'content', '')));
                $headingPath = trim((string) data_get($retrievalRow, 'heading_path', ''));
                $knowledgeItemTitle = trim((string) data_get($retrievalRow, 'document_title', data_get($retrievalRow, 'knowledge_item_title', '')));

                return [
                    'score' => (float) data_get($retrievalRow, 'score', 0),
                    'knowledge_item_id' => (int) data_get($retrievalRow, 'knowledge_item_id', 0),
                    'document_title' => $knowledgeItemTitle !== '' ? $knowledgeItemTitle : null,
                    'knowledge_item_summary' => $this->normalizeNullableString(data_get($retrievalRow, 'knowledge_item_summary')),
                    'chunk_id' => (int) data_get($retrievalRow, 'chunk_id', 0),
                    'chunk_index' => (int) data_get($retrievalRow, 'chunk_index', 0),
                    'chunk_type' => $this->normalizeNullableString(data_get($retrievalRow, 'chunk_type')),
                    'heading_path' => $headingPath !== '' ? $headingPath : null,
                    'topic' => $this->normalizeNullableString(data_get($retrievalRow, 'topic')),
                    'sub_topic' => $this->normalizeNullableString(data_get($retrievalRow, 'sub_topic')),
                    'summary_for_retrieval' => $this->normalizeNullableString(data_get($retrievalRow, 'summary_for_retrieval')),
                    'table_text' => $this->normalizeNullableString(data_get($retrievalRow, 'table_text')),
                    'keywords' => $this->normalizeStringList((array) data_get($retrievalRow, 'keywords', [])),
                    'section_title' => $this->normalizeNullableString(data_get($retrievalRow, 'section_title')),
                    'section_path' => $this->normalizeNullableString(data_get($retrievalRow, 'section_path')),
                    'content_preview' => Str::limit(Str::squish($content), 4000, '...'),
                ];
            })
            ->values();

        return $promptRows->all();
    }

    /**
     * Purpose: Convert the grounding judge result into compact prompt context.
     * Inputs: The grounding judge payload or null.
     * Returns: A deterministic array for the prompt.
     * Side effects: None.
     */
    private function promptGroundingJudge(?array $groundingJudge): ?array
    {
        if (! is_array($groundingJudge)) {
            return null;
        }

        $directlySupportedPoints = $this->normalizeSupportedPointObjects(
            data_get($groundingJudge, 'directly_supported_points', data_get($groundingJudge, 'supported_points', [])),
        );
        $relatedButInsufficientPoints = $this->normalizeStringList((array) data_get($groundingJudge, 'related_but_insufficient_points', []));
        $unsupportedPoints = $this->normalizeStringList((array) data_get($groundingJudge, 'unsupported_points', []));

        return [
            'status' => in_array(data_get($groundingJudge, 'status'), ['supported', 'partial', 'unsupported'], true)
                ? data_get($groundingJudge, 'status')
                : null,
            'can_generate_answer' => (bool) data_get($groundingJudge, 'can_generate_answer', false),
            'directly_supported_points' => $directlySupportedPoints,
            'related_but_insufficient_points' => $relatedButInsufficientPoints,
            'unsupported_points' => $unsupportedPoints,
            'missing_knowledge_summary' => $this->normalizeNullableString(data_get($groundingJudge, 'missing_knowledge_summary')),
            'recommended_document_title' => $this->normalizeNullableString(data_get($groundingJudge, 'recommended_document_title')),
            'suggested_filename' => $this->normalizeNullableString(data_get($groundingJudge, 'suggested_filename')),
            'reasoning_summary' => $this->normalizeNullableString(data_get($groundingJudge, 'reasoning_summary')),
            'supported_points' => $this->supportedPointRequirementPoints($directlySupportedPoints),
        ];
    }

    /**
     * Purpose: Normalize the directly supported points into stable objects for the answer prompt.
     * Inputs: Raw supported point payload.
     * Returns: Deterministic supported point objects.
     * Side effects: None.
     */
    private function normalizeSupportedPointObjects(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $normalizedValue = trim((string) $value);

                if ($normalizedValue === '') {
                    continue;
                }

                $normalized[] = [
                    'requirement_point' => $normalizedValue,
                    'support_summary' => $normalizedValue,
                    'evidence_reference' => null,
                    'evidence_quote' => null,
                ];

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $requirementPoint = $this->normalizeNullableString(data_get($value, 'requirement_point'));
            $supportSummary = $this->normalizeNullableString(data_get($value, 'support_summary'));
            $evidenceReference = $this->normalizeNullableString(data_get($value, 'evidence_reference'));
            $evidenceQuote = $this->normalizeNullableString(data_get($value, 'evidence_quote'));

            if ($requirementPoint === null || $supportSummary === null) {
                continue;
            }

            $normalized[] = [
                'requirement_point' => $requirementPoint,
                'support_summary' => $supportSummary,
                'evidence_reference' => $evidenceReference,
                'evidence_quote' => $evidenceQuote,
            ];
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize retrieved knowledge rows before prompt composition.
     * Inputs: The retrieved knowledge collection or null.
     * Returns: A deterministic collection with array rows only.
     * Side effects: None.
     */
    private function retrievedKnowledgeRows(?Collection $retrievedKnowledgeChunks): Collection
    {
        if (! $retrievedKnowledgeChunks instanceof Collection) {
            return collect();
        }

        return $retrievedKnowledgeChunks
            ->filter(static fn ($row): bool => is_array($row))
            ->values();
    }

    /**
     * Purpose: Define the strict JSON schema for the answer draft response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function answerDraftSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer_draft_text' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'answer_draft_text',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Decode the model output from the OpenAI Responses API.
     * Inputs: The raw OpenAI response payload.
     * Returns: The JSON-decoded assistant output.
     * Side effects: Throws when no usable JSON payload is present.
     */
    private function decodeAnswerDraftPayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI answer draft response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI answer draft response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI answer draft response did not decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * Purpose: Extract the assistant text payload from a Responses API result.
     * Inputs: The raw OpenAI response payload.
     * Returns: The concatenated response text.
     * Side effects: None.
     */
    private function responseTextFromOpenAi(array $response): string
    {
        $topLevelText = trim((string) data_get($response, 'output_text', ''));

        if ($topLevelText !== '') {
            return $topLevelText;
        }

        $segments = [];
        $outputItems = data_get($response, 'output', []);

        if (! is_array($outputItems)) {
            return '';
        }

        foreach ($outputItems as $outputItem) {
            if (data_get($outputItem, 'type') !== 'message' || data_get($outputItem, 'role') !== 'assistant') {
                continue;
            }

            $contentItems = data_get($outputItem, 'content', []);

            if (! is_array($contentItems)) {
                continue;
            }

            foreach ($contentItems as $contentItem) {
                $contentType = data_get($contentItem, 'type');

                if ($contentType === 'refusal') {
                    throw new RuntimeException('OpenAI refused to return an answer draft.');
                }

                if (in_array($contentType, ['output_text', 'text'], true)) {
                    $segment = trim((string) data_get($contentItem, 'text', ''));

                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                }
            }
        }

        return trim(implode('', $segments));
    }

    /**
     * Purpose: Remove Markdown code fences if the model wrapped the JSON payload.
     * Inputs: Raw model text.
     * Returns: The cleaned text.
     * Side effects: None.
     */
    private function stripCodeFences(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Validate the structured OpenAI payload before persisting it.
     * Inputs: The decoded OpenAI output.
     * Returns: The validated answer draft text.
     * Side effects: Throws when the payload violates the contract.
     */
    private function validateAnswerDraftPayload(array $payload): string
    {
        try {
            $validated = Validator::make($payload, [
                'answer_draft_text' => ['required', 'string', 'min:1', 'max:20000'],
            ])->validate();
        } catch (Throwable $exception) {
            throw new RuntimeException('OpenAI answer draft response did not match the required contract.', 0, $exception);
        }

        $answerDraftText = $this->normalizeDraftText((string) $validated['answer_draft_text']);

        if ($answerDraftText === '') {
            throw new RuntimeException('OpenAI answer draft text was empty after trimming.');
        }

        return $answerDraftText;
    }

    /**
     * Purpose: Normalize persisted answer draft text without changing its content meaning.
     * Inputs: Raw draft text.
     * Returns: Trimmed text with normalized line endings.
     * Side effects: None.
     */
    private function normalizeDraftText(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($normalized);
    }

    /**
     * Purpose: Normalize optional case-level instructions before they are added to the prompt.
     * Inputs: Raw instructions text.
     * Returns: A trimmed string or null when no instructions are set.
     * Side effects: None.
     */
    private function normalizeCaseInstructions(?string $value): ?string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Normalize an optional string value for prompt context.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Normalize a list of strings for prompt context.
     * Inputs: A mixed array payload.
     * Returns: A trimmed unique list of non-empty strings.
     * Side effects: None.
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            $item = trim((string) $value);

            if ($item === '') {
                continue;
            }

            $key = mb_strtolower($item, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Purpose: Convert supported point objects to a compatibility string list.
     * Inputs: The supported point objects from the judge.
     * Returns: A trimmed unique list of requirement point strings.
     * Side effects: None.
     */
    private function supportedPointRequirementPoints(array $supportedPoints): array
    {
        $normalized = [];
        $seen = [];

        foreach ($supportedPoints as $supportedPoint) {
            $requirementPoint = is_string($supportedPoint)
                ? trim($supportedPoint)
                : trim((string) data_get($supportedPoint, 'requirement_point', data_get($supportedPoint, 'support_summary', '')));

            if ($requirementPoint === '') {
                continue;
            }

            $key = mb_strtolower($requirementPoint, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $requirementPoint;
        }

        return $normalized;
    }

    /**
     * Purpose: Determine whether the selected OpenAI model accepts the temperature parameter.
     * Inputs: The resolved model name.
     * Returns: True when temperature can be sent in the Responses API payload.
     * Side effects: None.
     */
    private function openAiModelSupportsTemperature(string $model): bool
    {
        return ! Str::startsWith(Str::lower(trim($model)), 'gpt-5');
    }

    /**
     * Purpose: Return the configured OpenAI model for answer draft requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    /**
     * Purpose: Record token usage for one AI call without blocking the answer draft generation on failure.
     * Inputs: The raw OpenAI response, the requirement that triggered the call, and optional customer/user context.
     * Returns: None.
     * Side effects: Delegates to AiTokenLogger which swallows all persistence failures safely.
     */
    private function logTokenUsage(array $response, SavedNoticeAiRequirement $requirement, ?int $customerId, ?int $userId): void
    {
        try {
            $this->tokenLogger->record([
                'customer_id'     => $customerId,
                'user_id'         => $userId,
                'operation_key'   => AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
                'model'           => $this->openAiModel(),
                'provider'        => data_get($response, '_meta.provider'),
                'deployment_name' => data_get($response, '_meta.deployment_name'),
                'provider_region' => data_get($response, '_meta.provider_region'),
                'input_tokens'    => data_get($response, 'usage.input_tokens', 0),
                'output_tokens'   => data_get($response, 'usage.output_tokens', 0),
                'total_tokens'    => data_get($response, 'usage.total_tokens', 0),
                'saved_notice_id' => $requirement->saved_notice_id,
                'request_id'      => data_get($response, '_meta.request_id'),
            ]);
        } catch (Throwable) {
            // Token logging failures must never block answer draft generation.
        }
    }

    private function openAiModel(): string
    {
        $model = trim((string) config(
            'services.openai.requirement_answer_model',
            config('services.openai.requirement_extraction_model', config('services.openai.model', 'gpt-4.1-mini')),
        ));

        if ($model === '') {
            throw new RuntimeException('OpenAI answer draft model is not configured.');
        }

        return $model;
    }
}
