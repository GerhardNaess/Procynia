<?php

namespace App\Services\Ai\Retrieval;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class MetadataRetrievalPlanService
{
    private const MAX_OUTPUT_TOKENS = 1200;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Translate one requirement and a controlled metadata inventory into a structured retrieval plan.
     * Inputs: The requirement text and the customer-specific metadata map.
     * Returns: A raw retrieval plan that will be validated before database use.
     * Side effects: May call OpenAI and logs plan-generation failures.
     */
    public function buildPlan(string $requirementText, array $metadataMap): array
    {
        $requirementText = trim($requirementText);
        $availableFields = $this->metadataFieldsFromMap($metadataMap);

        if ($requirementText === '' || $availableFields === []) {
            Log::info('[PROCYNIA][METADATA_RETRIEVAL] Retrieval plan skipped because requirement text or metadata fields were unavailable.', [
                'requirement_text' => $requirementText,
                'metadata_field_count' => count($availableFields),
            ]);

            return $this->emptyPlan();
        }

        try {
            $response = $this->openAiClient->createResponse(
                $this->openAiRequestPayload($requirementText, $metadataMap),
            );
            $decoded = $this->decodePayload($response);
            $plan = $this->normalizePlanPayload($decoded);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][METADATA_RETRIEVAL] Retrieval plan generation failed; using an empty metadata selection.', [
                'requirement_text' => $requirementText,
                'metadata_field_count' => count($availableFields),
                'error' => $exception->getMessage(),
            ]);

            return $this->emptyPlan();
        }

        Log::info('[PROCYNIA][METADATA_RETRIEVAL] Retrieval plan built.', [
            'requirement_text' => $requirementText,
            'metadata_field_count' => count($availableFields),
            'selected_field_count' => count((array) data_get($plan, 'selected_metadata', [])),
            'selected_value_count' => $this->selectedValueCount($plan),
            'confidence' => data_get($plan, 'confidence'),
            'search_text' => data_get($plan, 'search_text'),
            'intent_summary' => data_get($plan, 'intent_summary'),
        ]);

        return $plan;
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one metadata retrieval plan.
     * Inputs: The requirement text and metadata map.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(string $requirementText, array $metadataMap): array
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
                            'text' => $this->userPrompt($requirementText, $metadataMap),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'metadata_retrieval_plan',
                    'description' => 'Structured metadata retrieval plan for one requirement.',
                    'strict' => true,
                    'schema' => $this->planSchema($metadataMap),
                ],
            ],
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];

        if ($this->openAiModelSupportsTemperature($payload['model'])) {
            $payload['temperature'] = self::TEMPERATURE;
        }
    }

    /**
     * Purpose: Build the developer instructions for the metadata planner.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You translate the requirement into a retrieval plan.',
            'You do not write SQL.',
            'You do not invent metadata fields.',
            'You do not invent metadata values.',
            'Select only exact values that already exist in the provided metadata map.',
            'If no metadata values clearly fit, return an empty selected_metadata object.',
            'Keep search_text short and focused on the user intent.',
            'Keep intent_summary short and explain the retrieval intent in Norwegian.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for the metadata planner.
     * Inputs: The requirement text and the metadata map.
     * Returns: A JSON string that the model can inspect deterministically.
     * Side effects: None.
     */
    private function userPrompt(string $requirementText, array $metadataMap): string
    {
        $payload = [
            'question' => $requirementText,
            'metadata_map' => [
                'fields' => data_get($metadataMap, 'fields', []),
                'field_counts' => data_get($metadataMap, 'field_counts', []),
            ],
            'instructions' => [
                'Choose only values that appear in metadata_map.fields.',
                'Do not create new fields.',
                'Do not create new values.',
                'Put short free text into search_text, not in selected_metadata.',
                'If nothing matches, leave selected_metadata empty.',
            ],
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the metadata retrieval prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Define the strict JSON schema for the metadata retrieval plan.
     * Inputs: The metadata map.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function planSchema(array $metadataMap): array
    {
        $selectedMetadataProperties = [];

        foreach ($this->metadataFieldsFromMap($metadataMap) as $field) {
            $selectedMetadataProperties[$field] = [
                'anyOf' => [
                    [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                    [
                        'type' => 'null',
                    ],
                ],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'selected_metadata' => [
                    'type' => 'object',
                    'properties' => $selectedMetadataProperties,
                    'required' => array_values(array_keys($selectedMetadataProperties)),
                    'additionalProperties' => false,
                ],
                'search_text' => [
                    'type' => 'string',
                ],
                'intent_summary' => [
                    'type' => 'string',
                ],
                'confidence' => [
                    'type' => 'number',
                ],
            ],
            'required' => [
                'selected_metadata',
                'search_text',
                'intent_summary',
                'confidence',
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
    private function decodePayload(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI metadata retrieval response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI metadata retrieval response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI metadata retrieval response did not decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * Purpose: Extract assistant text from a Responses API result.
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
                    throw new RuntimeException('OpenAI refused to return a metadata retrieval plan.');
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
     * Purpose: Normalize the JSON response into the stable plan structure.
     * Inputs: The decoded OpenAI output.
     * Returns: The raw metadata retrieval plan structure.
     * Side effects: Throws when required fields are missing or invalid.
     */
    private function normalizePlanPayload(array $payload): array
    {
        $selectedMetadata = data_get($payload, 'selected_metadata');

        if (! is_array($selectedMetadata)) {
            throw new RuntimeException('The selected_metadata field is required.');
        }

        return [
            'selected_metadata' => $selectedMetadata,
            'search_text' => $this->requiredStringFromPayload($payload, 'search_text', 500),
            'intent_summary' => $this->requiredStringFromPayload($payload, 'intent_summary', 500),
            'confidence' => $this->requiredFloatFromPayload($payload, 'confidence'),
        ];
    }

    /**
     * Purpose: Read and validate a required string field from the plan payload.
     * Inputs: The raw payload, the field name, and the maximum length.
     * Returns: The trimmed string value.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredStringFromPayload(array $payload, string $field, int $maxLength): string
    {
        $value = data_get($payload, $field);

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', str_replace('_', ' ', $field)));
        }

        return $value;
    }

    /**
     * Purpose: Read and validate a required float field from the plan payload.
     * Inputs: The raw payload and field name.
     * Returns: The floating-point value.
     * Side effects: Throws when the field is missing or invalid.
     */
    private function requiredFloatFromPayload(array $payload, string $field): float
    {
        $value = data_get($payload, $field);

        if (! is_numeric($value)) {
            throw new RuntimeException(sprintf('The %s field is required.', str_replace('_', ' ', $field)));
        }

        return (float) $value;
    }

    /**
     * Purpose: Return the configured OpenAI model for metadata retrieval requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = trim((string) config('services.openai.model', 'gpt-4.1-mini'));

        if ($model === '') {
            throw new RuntimeException('OpenAI metadata retrieval model is not configured.');
        }

        return $model;
    }

    private function openAiModelSupportsTemperature(string $model): bool
    {
        return ! Str::startsWith(Str::lower(trim($model)), 'gpt-5');
    }

    /**
     * Purpose: Return an empty metadata retrieval plan.
     * Inputs: None.
     * Returns: The stable empty plan structure.
     * Side effects: None.
     */
    private function emptyPlan(): array
    {
        return [
            'selected_metadata' => [],
            'search_text' => '',
            'intent_summary' => '',
            'confidence' => 0.0,
        ];
    }

    /**
     * Purpose: Resolve the metadata fields that exist in the supplied map.
     * Inputs: The metadata map returned by the map service.
     * Returns: A deterministic list of metadata field names.
     * Side effects: None.
     */
    private function metadataFieldsFromMap(array $metadataMap): array
    {
        $fields = data_get($metadataMap, 'fields', []);

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_keys($fields), static fn (string|int $field): bool => is_string($field) && trim($field) !== ''));
    }

    /**
     * Purpose: Count the selected metadata values in a raw or normalized plan.
     * Inputs: A retrieval plan array.
     * Returns: The total number of selected metadata values.
     * Side effects: None.
     */
    private function selectedValueCount(array $plan): int
    {
        $selectedMetadata = data_get($plan, 'selected_metadata', []);

        if (! is_array($selectedMetadata)) {
            return 0;
        }

        $count = 0;

        foreach ($selectedMetadata as $values) {
            if (! is_array($values)) {
                continue;
            }

            $count += count(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        }

        return $count;
    }
}
