<?php

namespace App\Services\Knowledge;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class AiKnowledgeChunkBoundaryService
{
    private const MAX_OUTPUT_TOKENS = 1800;

    private const TEMPERATURE = 0;

    private const PREFERRED_GROUP_MIN_WORDS = 300;

    private const PREFERRED_GROUP_TARGET_WORDS = 700;

    private const PREFERRED_GROUP_MAX_WORDS = 1200;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Suggest semantic chunk boundaries for a parsed knowledge document.
     * Inputs: The customer id, a compact document context, and the parsed structural elements.
     * Returns: Ordered analysis groups with raw AI suggestions attached to each group.
     * Side effects: May call OpenAI and may log request failures.
     *
     * @param array<string, mixed> $documentContext
     * @param array{source_text: string, elements: array<int, array<string, mixed>>} $structure
     * @return array{
     *     model: string,
     *     analysis_groups: array<int, array{
     *         group_index: int,
     *         start_offset: int,
     *         end_offset: int,
     *         text: string,
     *         word_count: int,
     *         elements: array<int, array<string, mixed>>,
     *         previous_group_tail: ?string,
     *         next_group_head: ?string,
     *         suggested_chunks: array<int, array<string, mixed>>,
     *         request_id: ?string,
     *         response_id: ?string
     *     }>
     * }
     */
    public function suggestBoundaries(int $customerId, array $documentContext, array $structure): array
    {
        $sourceText = trim((string) ($structure['source_text'] ?? ''));
        $elements = array_values(array_filter(
            (array) ($structure['elements'] ?? []),
            static fn ($element): bool => is_array($element),
        ));

        $analysisGroups = $this->buildAnalysisGroups($sourceText, $elements);
        $model = $this->openAiModel();

        if ($analysisGroups === []) {
            return [
                'model' => $model,
                'analysis_groups' => [],
            ];
        }

        $documentContext['customer_id'] = $customerId;

        $groupCount = count($analysisGroups);

        for ($index = 0; $index < $groupCount; $index++) {
            $group = $analysisGroups[$index];
            $payload = $this->openAiRequestPayload($documentContext, $group);
            $requestId = null;

            try {
                $response = $this->openAiClient->createResponse($payload);
                $requestId = data_get($response, '_meta.request_id');
                $decoded = $this->decodeResponse($response);
                $analysisGroups[$index]['suggested_chunks'] = $this->normalizeChunkSuggestions($decoded);
                $analysisGroups[$index]['request_id'] = is_string($requestId) ? $requestId : null;
                $analysisGroups[$index]['response_id'] = is_string(data_get($response, 'id')) ? (string) data_get($response, 'id') : null;
            } catch (Throwable $exception) {
                $analysisGroups[$index]['suggested_chunks'] = [];
                $analysisGroups[$index]['request_id'] = is_string($requestId) ? $requestId : null;
                $analysisGroups[$index]['response_id'] = null;

                \Log::warning('[PROCYNIA][KNOWLEDGE_CHUNKING] Boundary suggestion failed; continuing with deterministic validation.', [
                    'customer_id' => $customerId,
                    'document_title' => (string) data_get($documentContext, 'document_title', ''),
                    'group_index' => $group['group_index'] ?? $index,
                    'group_word_count' => $group['word_count'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'model' => $model,
            'analysis_groups' => $analysisGroups,
        ];
    }

    /**
     * Purpose: Build deterministic analysis groups from ordered structural elements.
     * Inputs: The canonical source text and ordered structural elements.
     * Returns: Ordered groups sized for AI analysis without splitting structural elements.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array{
     *     group_index: int,
     *     start_offset: int,
     *     end_offset: int,
     *     text: string,
     *     word_count: int,
     *     elements: array<int, array<string, mixed>>,
     *     previous_group_tail: ?string,
     *     next_group_head: ?string,
     *     suggested_chunks: array<int, array<string, mixed>>,
     *     request_id: ?string,
     *     response_id: ?string
     * }>
     */
    private function buildAnalysisGroups(string $sourceText, array $elements): array
    {
        if ($elements === []) {
            return [];
        }

        $groups = [];
        $currentElements = [];
        $currentStartOffset = null;
        $currentEndOffset = null;
        $currentWordCount = 0;

        foreach ($elements as $element) {
            $elementStart = (int) data_get($element, 'start_offset', 0);
            $elementEnd = (int) data_get($element, 'end_offset', $elementStart);
            $elementText = trim((string) data_get($element, 'text', ''));
            $elementWordCount = $this->wordCount($elementText);

            if ($elementText === '') {
                continue;
            }

            if ($currentElements !== [] && $currentWordCount >= self::PREFERRED_GROUP_MIN_WORDS) {
                $candidateWordCount = $currentWordCount + $elementWordCount;

                if ($candidateWordCount > self::PREFERRED_GROUP_MAX_WORDS || $currentWordCount >= self::PREFERRED_GROUP_TARGET_WORDS) {
                    $groups[] = $this->makeAnalysisGroup(
                        $sourceText,
                        $currentElements,
                        (int) $currentStartOffset,
                        (int) $currentEndOffset,
                        count($groups),
                    );

                    $currentElements = [];
                    $currentStartOffset = null;
                    $currentEndOffset = null;
                    $currentWordCount = 0;
                }
            }

            $currentElements[] = $element;
            $currentStartOffset = $currentStartOffset ?? $elementStart;
            $currentEndOffset = $elementEnd;
            $currentWordCount += $elementWordCount;

            if ($currentWordCount >= self::PREFERRED_GROUP_MAX_WORDS) {
                $groups[] = $this->makeAnalysisGroup(
                    $sourceText,
                    $currentElements,
                    (int) $currentStartOffset,
                    (int) $currentEndOffset,
                    count($groups),
                );

                $currentElements = [];
                $currentStartOffset = null;
                $currentEndOffset = null;
                $currentWordCount = 0;
            }
        }

        if ($currentElements !== [] && $currentStartOffset !== null && $currentEndOffset !== null) {
            $groups[] = $this->makeAnalysisGroup(
                $sourceText,
                $currentElements,
                (int) $currentStartOffset,
                (int) $currentEndOffset,
                count($groups),
            );
        }

        $groupCount = count($groups);

        for ($index = 0; $index < $groupCount; $index++) {
            $groups[$index]['previous_group_tail'] = $index > 0
                ? Str::limit(trim((string) data_get($groups[$index - 1], 'text', '')), 180, '')
                : null;
            $groups[$index]['next_group_head'] = $index < $groupCount - 1
                ? Str::limit(trim((string) data_get($groups[$index + 1], 'text', '')), 180, '')
                : null;
        }

        return $groups;
    }

    /**
     * Purpose: Build one analysis group from a contiguous element range.
     * Inputs: The canonical source text, the element subset, and the absolute offsets of the group.
     * Returns: A single analysis group record.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array{
     *     group_index: int,
     *     start_offset: int,
     *     end_offset: int,
     *     text: string,
     *     word_count: int,
     *     elements: array<int, array<string, mixed>>,
     *     previous_group_tail: ?string,
     *     next_group_head: ?string,
     *     suggested_chunks: array<int, array<string, mixed>>,
     *     request_id: ?string,
     *     response_id: ?string
     * }
     */
    private function makeAnalysisGroup(string $sourceText, array $elements, int $startOffset, int $endOffset, int $groupIndex): array
    {
        $length = max(0, $endOffset - $startOffset);
        $text = trim(mb_substr($sourceText, $startOffset, $length, 'UTF-8'));

        return [
            'group_index' => $groupIndex,
            'start_offset' => $startOffset,
            'end_offset' => $endOffset,
            'text' => $text,
            'word_count' => $this->wordCount($text),
            'elements' => array_values($elements),
            'previous_group_tail' => null,
            'next_group_head' => null,
            'suggested_chunks' => [],
            'request_id' => null,
            'response_id' => null,
        ];
    }

    /**
     * Purpose: Build the OpenAI Responses API payload for one analysis group.
     * Inputs: The document context and one analysis group.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     *
     * @param array<string, mixed> $documentContext
     * @param array<string, mixed> $analysisGroup
     */
    private function openAiRequestPayload(array $documentContext, array $analysisGroup): array
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
                            'text' => $this->userPrompt($documentContext, $analysisGroup),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'knowledge_chunk_boundary_plan',
                    'description' => 'Semantic chunk boundary suggestions for one knowledge analysis group.',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for semantic chunk boundaries.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You propose semantic chunk boundaries for knowledge documents.',
            'You must not write chunk text.',
            'You must not invent facts.',
            'You must not split tables.',
            'You must keep intro text together with its directly related list when they belong together.',
            'You must not split a list group unless the text is too large and the split keeps list items intact.',
            'You must not mix unrelated main topics in one chunk.',
            'Prefer chunks around 300 to 700 words.',
            'Avoid chunks smaller than 100 words unless the analyzed group is naturally short.',
            'Avoid chunks larger than 1200 words unless a table or unavoidable structure requires it.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for chunk boundary analysis.
     * Inputs: The document context and one analysis group.
     * Returns: A JSON string that the model can inspect deterministically.
     * Side effects: None.
     *
     * @param array<string, mixed> $documentContext
     * @param array<string, mixed> $analysisGroup
     */
    private function userPrompt(array $documentContext, array $analysisGroup): string
    {
        $payload = [
            'document' => [
                'id' => data_get($documentContext, 'document_id'),
                'title' => data_get($documentContext, 'document_title'),
                'original_filename' => data_get($documentContext, 'original_filename'),
                'content_type' => data_get($documentContext, 'content_type'),
                'document_type' => data_get($documentContext, 'document_type'),
                'summary' => data_get($documentContext, 'summary'),
            ],
            'analysis_group' => [
                'group_index' => data_get($analysisGroup, 'group_index'),
                'start_offset' => data_get($analysisGroup, 'start_offset'),
                'end_offset' => data_get($analysisGroup, 'end_offset'),
                'word_count' => data_get($analysisGroup, 'word_count'),
                'previous_group_tail' => data_get($analysisGroup, 'previous_group_tail'),
                'next_group_head' => data_get($analysisGroup, 'next_group_head'),
                'elements' => array_map(
                    static function (array $element): array {
                        return [
                            'id' => data_get($element, 'id'),
                            'type' => data_get($element, 'type'),
                            'heading_path' => data_get($element, 'heading_path'),
                            'text' => data_get($element, 'text'),
                            'start_offset' => data_get($element, 'start_offset'),
                            'end_offset' => data_get($element, 'end_offset'),
                            'order_index' => data_get($element, 'order_index'),
                            'relation_hint' => data_get($element, 'relation_hint'),
                        ];
                    },
                    (array) data_get($analysisGroup, 'elements', []),
                ),
            ],
            'rules' => [
                'Use the element order exactly as provided.',
                'Do not create overlapping chunks.',
                'Do not leave gaps inside the analysis group.',
                'Do not split a table element.',
                'Do not separate intro text from a directly related list unless necessary.',
                'Return chunk offsets relative to the analysis group text.',
                'Keep chunk bodies semantically coherent.',
            ],
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the knowledge chunk boundary prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Define the strict JSON schema for the boundary suggestion response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chunks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'start_offset_relative' => [
                                'type' => 'integer',
                            ],
                            'end_offset_relative' => [
                                'type' => 'integer',
                            ],
                            'short_reason' => [
                                'type' => 'string',
                            ],
                            'topic' => [
                                'type' => ['string', 'null'],
                            ],
                            'sub_topic' => [
                                'type' => ['string', 'null'],
                            ],
                            'keywords' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                        'required' => [
                            'start_offset_relative',
                            'end_offset_relative',
                            'short_reason',
                            'topic',
                            'sub_topic',
                            'keywords',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'chunks',
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
    private function decodeResponse(array $response): array
    {
        $text = $this->responseTextFromOpenAi($response);
        $text = $this->stripCodeFences($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI knowledge chunk boundary response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI knowledge chunk boundary response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI knowledge chunk boundary response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return chunk boundary suggestions.');
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
     * Purpose: Normalize chunk suggestions from the AI response.
     * Inputs: The decoded assistant payload.
     * Returns: An ordered list of chunk suggestion payloads.
     * Side effects: None.
     *
     * @param array<string, mixed> $decoded
     * @return array<int, array<string, mixed>>
     */
    private function normalizeChunkSuggestions(array $decoded): array
    {
        $chunks = data_get($decoded, 'chunks', []);

        if (! is_array($chunks)) {
            return [];
        }

        $normalized = [];

        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $start = (int) data_get($chunk, 'start_offset_relative', 0);
            $end = (int) data_get($chunk, 'end_offset_relative', 0);

            if ($end <= $start) {
                continue;
            }

            $normalized[] = [
                'start_offset_relative' => $start,
                'end_offset_relative' => $end,
                'short_reason' => trim((string) data_get($chunk, 'short_reason', '')),
                'topic' => $this->normalizeNullableString(data_get($chunk, 'topic')),
                'sub_topic' => $this->normalizeNullableString(data_get($chunk, 'sub_topic')),
                'keywords' => $this->normalizeKeywords(data_get($chunk, 'keywords')),
            ];
        }

        usort(
            $normalized,
            static function (array $left, array $right): int {
                if ($left['start_offset_relative'] !== $right['start_offset_relative']) {
                    return $left['start_offset_relative'] <=> $right['start_offset_relative'];
                }

                return $left['end_offset_relative'] <=> $right['end_offset_relative'];
            },
        );

        return array_values($normalized);
    }

    /**
     * Purpose: Normalize a nullable string value to a trimmed nullable string.
     * Inputs: A raw scalar value or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Normalize a keyword input into a stable list of strings.
     * Inputs: A keyword array, comma-separated string, or null.
     * Returns: A de-duplicated keyword list.
     * Side effects: None.
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n;]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $text = trim((string) $keyword);

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * Purpose: Return the configured OpenAI model for boundary requests.
     * Inputs: None.
     * Returns: The configured model name.
     * Side effects: None.
     */
    private function openAiModel(): string
    {
        $model = 'gpt-4.1-mini';

        try {
            if (function_exists('app')) {
                $container = app();

                if (method_exists($container, 'bound') && $container->bound('config')) {
                    $config = $container->make('config');

                    $model = trim((string) $config->get(
                        'services.openai.requirement_answer_model',
                        $config->get('services.openai.model', $model),
                    ));
                }
            }
        } catch (Throwable) {
            $model = 'gpt-4.1-mini';
        }

        if ($model === '') {
            throw new RuntimeException('OpenAI knowledge chunk boundary model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Count the approximate number of words in a text block.
     * Inputs: Raw text.
     * Returns: The word count.
     * Side effects: None.
     */
    private function wordCount(string $text): int
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($normalized === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }
}
