<?php

namespace App\Services;

use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use RuntimeException;
use Throwable;

class RequirementAssessmentService
{
    private const MAX_EVIDENCE_ROWS = 5;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Generate and persist one canonical assessment row for a requirement.
     * Inputs: The requirement to assess and an optional user id for audit traceability.
     * Returns: The persisted assessment row with a completed status.
     * Side effects: Calls OpenAI, validates the structured output, and upserts the assessment row.
     */
    public function assessRequirement(
        SavedNoticeAiRequirement $requirement,
        ?int $assessedByUserId = null,
        ?string $caseInstructions = null,
    ): SavedNoticeAiRequirementAssessment
    {
        $requirement->loadMissing([
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
            'assessment',
        ]);

        $evidenceRows = $this->assessmentEvidenceRows($requirement);
        $assessmentPayload = $this->requestAssessment($requirement, $evidenceRows, $caseInstructions);

        return DB::transaction(function () use ($requirement, $assessmentPayload, $evidenceRows, $assessedByUserId): SavedNoticeAiRequirementAssessment {
            return SavedNoticeAiRequirementAssessment::query()->updateOrCreate(
                [
                    'saved_notice_ai_requirement_id' => $requirement->id,
                ],
                [
                    'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
                    'coverage_status' => $assessmentPayload['coverage_status'],
                    'risk_level' => $assessmentPayload['risk_level'],
                    'requirement_summary' => $assessmentPayload['requirement_summary'],
                    'coverage_rationale' => $assessmentPayload['coverage_rationale'],
                    'missing_information' => $assessmentPayload['missing_information'],
                    'recommended_next_step' => $assessmentPayload['recommended_next_step'],
                    'source_evidence_snapshot' => $this->buildEvidenceSnapshot($evidenceRows),
                    'assessed_at' => now(),
                    'assessed_by_user_id' => $assessedByUserId,
                ],
            );
        });
    }

    /**
     * Purpose: Select the persisted evidence rows that should ground the assessment.
     * Inputs: The requirement with its evidence relation loaded.
     * Returns: A short, deterministic collection of selected or suggested evidence rows.
     * Side effects: None.
     */
    private function assessmentEvidenceRows(SavedNoticeAiRequirement $requirement): Collection
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
     * Purpose: Order evidence rows deterministically for assessment and snapshot storage.
     * Inputs: A collection of evidence rows.
     * Returns: The same collection sorted by the canonical evidence priority.
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
     * Purpose: Build the evidence snapshot stored alongside the persisted assessment.
     * Inputs: The evidence rows that grounded the assessment.
     * Returns: A compact JSON-serialisable snapshot.
     * Side effects: None.
     */
    private function buildEvidenceSnapshot(Collection $evidenceRows): array
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
                    ],
                    'knowledge_chunk' => [
                        'id' => $knowledgeChunk?->id,
                        'chunk_index' => $knowledgeChunk?->chunk_index,
                        'content' => $knowledgeChunk?->content,
                        'start_offset' => $knowledgeChunk?->start_offset,
                        'end_offset' => $knowledgeChunk?->end_offset,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Send the requirement and evidence context to OpenAI and parse the structured assessment response.
     * Inputs: The requirement and the evidence rows that ground the assessment.
     * Returns: A validated associative array matching the persisted assessment contract.
     * Side effects: Calls the OpenAI Responses API.
     */
    private function requestAssessment(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        ?string $caseInstructions,
    ): array
    {
        $response = $this->openAiClient->createResponse($this->openAiRequestPayload($requirement, $evidenceRows, $caseInstructions));

        try {
            return $this->validateAssessmentPayload(
                $this->decodeAssessmentPayload($response),
            );
        } catch (Throwable $exception) {
            Log::warning('OpenAI requirement assessment failed during response parsing.', [
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
     * Purpose: Build the OpenAI Responses API payload for one requirement assessment.
     * Inputs: The requirement and the evidence rows used as input.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        ?string $caseInstructions,
    ): array
    {
        return [
            'model' => $this->openAiModel(),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt(),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($requirement, $evidenceRows, $caseInstructions),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'requirement_assessment',
                    'description' => 'Structured requirement coverage assessment for a tender bid case.',
                    'strict' => true,
                    'schema' => $this->assessmentSchema(),
                ],
            ],
            'temperature' => 0,
            'max_output_tokens' => 800,
        ];
    }

    /**
     * Purpose: Build the developer instructions for the requirement assessment.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You analyse one confirmed tender requirement for a bid case.',
            'Use only the provided requirement text and evidence.',
            'Apply the case-specific AI instructions from the user payload for tone, terminology, style, and capitalization when phrasing the summary and rationale.',
            'If the case-specific instructions conflict with factual evidence or the JSON schema, keep the facts and schema.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
            'Do not generate proposal text, marketing language, or draft wording.',
            'Do not invent facts that are not supported by the evidence.',
            'If the evidence is weak or missing, say so clearly and reduce coverage accordingly.',
            'Keep every field concise and operational.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for the OpenAI request.
     * Inputs: The requirement and its evidence rows.
     * Returns: A JSON string that is easy for the model to inspect.
     * Side effects: None.
     */
    private function userPrompt(
        SavedNoticeAiRequirement $requirement,
        Collection $evidenceRows,
        ?string $caseInstructions,
    ): string
    {
        $payload = [
            'instruction' => 'Assess coverage of the requirement based only on the supplied evidence.',
            'requirement' => [
                'id' => $requirement->id,
                'text' => (string) $requirement->requirement_text,
                'review_status' => $requirement->review_status,
                'work_status' => $requirement->work_status,
            ],
            'evidence_strategy' => $evidenceRows->isEmpty()
                ? 'No evidence is available for this requirement.'
                : 'Use the supplied evidence only. Selected evidence has priority over suggested evidence.',
            'evidence' => $this->promptEvidenceRows($evidenceRows),
        ];

        $normalizedCaseInstructions = $this->normalizeCaseInstructions($caseInstructions);

        if ($normalizedCaseInstructions !== null) {
            $payload['case_instructions'] = $normalizedCaseInstructions;
        }

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the OpenAI assessment prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Convert evidence rows into the prompt context structure.
     * Inputs: The assessment evidence rows.
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
                    ],
                    'knowledge_chunk' => [
                        'id' => $knowledgeChunk?->id,
                        'chunk_index' => $knowledgeChunk?->chunk_index,
                        'content' => $knowledgeChunk?->content,
                        'start_offset' => $knowledgeChunk?->start_offset,
                        'end_offset' => $knowledgeChunk?->end_offset,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Return the configured OpenAI model for assessment requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config('services.openai.model', 'gpt-4.1-mini'));

        if ($model === '') {
            throw new RuntimeException('OpenAI model is not configured.');
        }

        return $model;
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
     * Purpose: Define the strict JSON schema for the assessment response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function assessmentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'coverage_status' => [
                    'type' => 'string',
                    'enum' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUSES,
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => SavedNoticeAiRequirementAssessment::RISK_LEVELS,
                ],
                'requirement_summary' => [
                    'type' => 'string',
                ],
                'coverage_rationale' => [
                    'type' => 'string',
                ],
                'missing_information' => [
                    'type' => 'string',
                ],
                'recommended_next_step' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'coverage_status',
                'risk_level',
                'requirement_summary',
                'coverage_rationale',
                'missing_information',
                'recommended_next_step',
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
    private function decodeAssessmentPayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI assessment response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI assessment response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI assessment response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return an assessment.');
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
     * Returns: A validated payload with the canonical assessment fields.
     * Side effects: Throws when the payload violates the contract.
     */
    private function validateAssessmentPayload(array $payload): array
    {
        try {
            $validated = Validator::make($payload, [
                'coverage_status' => ['required', 'string', Rule::in(SavedNoticeAiRequirementAssessment::COVERAGE_STATUSES)],
                'risk_level' => ['required', 'string', Rule::in(SavedNoticeAiRequirementAssessment::RISK_LEVELS)],
                'requirement_summary' => ['required', 'string', 'min:1', 'max:1000'],
                'coverage_rationale' => ['required', 'string', 'min:1', 'max:2000'],
                'missing_information' => ['required', 'string', 'min:1', 'max:2000'],
                'recommended_next_step' => ['required', 'string', 'min:1', 'max:1000'],
            ])->validate();
        } catch (Throwable $exception) {
            throw new RuntimeException('OpenAI assessment response did not match the required contract.', 0, $exception);
        }

        foreach ($validated as $field => $value) {
            if (trim((string) $value) === '') {
                throw new RuntimeException(sprintf('OpenAI assessment field [%s] was empty after trimming.', $field));
            }
        }

        return [
            'coverage_status' => trim((string) $validated['coverage_status']),
            'risk_level' => trim((string) $validated['risk_level']),
            'requirement_summary' => trim((string) $validated['requirement_summary']),
            'coverage_rationale' => trim((string) $validated['coverage_rationale']),
            'missing_information' => trim((string) $validated['missing_information']),
            'recommended_next_step' => trim((string) $validated['recommended_next_step']),
        ];
    }
}
