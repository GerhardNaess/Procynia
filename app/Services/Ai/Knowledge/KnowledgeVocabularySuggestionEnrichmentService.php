<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class KnowledgeVocabularySuggestionEnrichmentService
{
    private const MAX_OUTPUT_TOKENS = 1200;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Enrich one pending vocabulary suggestion with AI-generated helper fields.
     * Inputs: The parent document, the source chunk, the candidate field, and the candidate term.
     * Returns: A normalized suggestion enrichment payload.
     * Side effects: May call OpenAI and logs only if the upstream request fails.
     */
    public function enrichSuggestion(
        KnowledgeItem $document,
        KnowledgeItemChunk $chunk,
        string $field,
        string $term,
    ): array {
        try {
            $payload = $this->openAiRequestPayload($document, $chunk, $field, $term);
            $response = $this->openAiClient->createResponse($payload);
            $decoded = $this->decodePayload($response);

            return $this->normalizeEnrichment($chunk, $field, $term, $decoded);
        } catch (Throwable) {
            return $this->fallbackEnrichment($chunk, $field, $term);
        }
    }

    /**
     * Purpose: Build the OpenAI request payload for suggestion enrichment.
     * Inputs: The document, the chunk, the candidate field, and the candidate term.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        KnowledgeItem $document,
        KnowledgeItemChunk $chunk,
        string $field,
        string $term,
    ): array {
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
                            'text' => $this->userPrompt($document, $chunk, $field, $term),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'knowledge_vocabulary_suggestion_enrichment',
                    'description' => 'Structured enrichment for one pending knowledge vocabulary suggestion.',
                    'strict' => true,
                    'schema' => $this->enrichmentSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for suggestion enrichment.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You enrich one pending vocabulary suggestion that already comes from persisted chunk metadata.',
            'You do not approve vocabulary.',
            'You do not create new authoritative terms.',
            'You do not change chunk boundaries or chunk content.',
            'You must return only JSON that matches the schema.',
            'canonical_name should be short and meaningful.',
            'synonyms should contain at most five concise alternatives and must not repeat canonical_name.',
            'description should be short, concrete, and useful for reviewers.',
            'reason should briefly explain why the suggestion is useful for the chunk.',
            'Write all string values in Norwegian.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for suggestion enrichment.
     * Inputs: The document, the chunk, the candidate field, and the candidate term.
     * Returns: A JSON string the model can inspect deterministically.
     * Side effects: None.
     */
    private function userPrompt(
        KnowledgeItem $document,
        KnowledgeItemChunk $chunk,
        string $field,
        string $term,
    ): string {
        $payload = [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->resolvedOriginalFilename(),
                'summary' => $document->summary,
            ],
            'chunk' => [
                'id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'heading_path' => $chunk->heading_path,
                'section_title' => $chunk->section_title,
                'section_path' => $chunk->section_path,
                'topic' => $chunk->topic,
                'sub_topic' => $chunk->sub_topic,
                'keywords' => $chunk->keywords,
                'summary_for_retrieval' => $chunk->summary_for_retrieval,
                'content_excerpt' => Str::limit(trim((string) $chunk->content), 1000, ''),
            ],
            'candidate' => [
                'field' => $field,
                'term' => $term,
            ],
            'instructions' => [
                'Return a compact canonical_name for the suggestion.',
                'Return up to five relevant synonyms or close variants.',
                'Do not repeat canonical_name inside synonyms.',
                'Use description to help a human reviewer understand the suggestion quickly.',
                'Use reason to explain why the suggestion was created from this chunk.',
                'Keep description and reason short.',
                'Do not return chunk boundaries or rewritten chunk text.',
            ],
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the knowledge vocabulary enrichment prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Define the strict JSON schema for the suggestion enrichment response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function enrichmentSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'canonical_name' => [
                    'type' => 'string',
                ],
                'synonyms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'description' => [
                    'type' => ['string', 'null'],
                ],
                'reason' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => [
                'canonical_name',
                'synonyms',
                'description',
                'reason',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Purpose: Normalize the AI response into an enrichment payload.
     * Inputs: The chunk, the field, the original term, and the decoded response payload.
     * Returns: A safe enrichment payload for downstream persistence.
     * Side effects: None.
     */
    private function normalizeEnrichment(
        KnowledgeItemChunk $chunk,
        string $field,
        string $term,
        array $payload,
    ): array {
        $canonicalName = $this->normalizeText(data_get($payload, 'canonical_name'), $term, 191) ?? $term;
        $synonyms = $this->normalizeSynonyms(data_get($payload, 'synonyms'), $canonicalName);
        $description = $this->normalizeText(
            data_get($payload, 'description'),
            $this->fallbackDescription($chunk, $field),
            300,
        );
        $reason = $this->normalizeText(
            data_get($payload, 'reason'),
            $this->fallbackReason($chunk),
            300,
        );

        return [
            'canonical_name' => $canonicalName,
            'synonyms' => $synonyms,
            'description' => $description,
            'reason' => $reason,
        ];
    }

    /**
     * Purpose: Return a deterministic fallback enrichment when AI fails or returns unusable data.
     * Inputs: The chunk, the candidate field, and the candidate term.
     * Returns: A safe enrichment payload.
     * Side effects: None.
     */
    private function fallbackEnrichment(KnowledgeItemChunk $chunk, string $field, string $term): array
    {
        return [
            'canonical_name' => $this->normalizeText($term, $term, 191) ?? $term,
            'synonyms' => [],
            'description' => $this->fallbackDescription($chunk, $field),
            'reason' => $this->fallbackReason($chunk),
        ];
    }

    /**
     * Purpose: Build a short fallback description from the persisted chunk context.
     * Inputs: The chunk context and the candidate field.
     * Returns: A short descriptive sentence or null.
     * Side effects: None.
     */
    private function fallbackDescription(KnowledgeItemChunk $chunk, string $field): ?string
    {
        $summary = trim((string) ($chunk->summary_for_retrieval ?? ''));

        if ($summary !== '') {
            return Str::limit(Str::squish($summary), 300, '');
        }

        $heading = $this->preferredHeadingLabel($chunk);
        $label = KnowledgeMetadataTerm::TYPE_LABELS[$field] ?? $field;

        if ($heading !== null) {
            return Str::limit('Kort beskrivelse av '.$label.' under '.$heading.'.', 300, '');
        }

        return Str::limit('Kort beskrivelse av '.$label.' i denne seksjonen.', 300, '');
    }

    /**
     * Purpose: Build a short fallback reason from the persisted chunk context.
     * Inputs: The chunk context.
     * Returns: A short Norwegian reason string.
     * Side effects: None.
     */
    private function fallbackReason(KnowledgeItemChunk $chunk): string
    {
        $heading = $this->preferredHeadingLabel($chunk);

        if ($heading !== null) {
            return Str::limit('Foreslått fra chunk-metadata basert på innhold under '.$heading.'.', 300, '');
        }

        return 'Foreslått fra chunk-metadata basert på innhold i denne seksjonen.';
    }

    /**
     * Purpose: Prefer the most precise heading label available on the chunk.
     * Inputs: The persisted chunk.
     * Returns: The leaf heading label, or the section title, or null.
     * Side effects: None.
     */
    private function preferredHeadingLabel(KnowledgeItemChunk $chunk): ?string
    {
        $heading = trim((string) ($chunk->heading_path ?? ''));

        if ($heading !== '') {
            $parts = preg_split('/\s*>\s*/u', $heading) ?: [$heading];
            $heading = trim((string) end($parts));
        }

        if ($heading === '') {
            $heading = trim((string) ($chunk->section_title ?? ''));
        }

        return $heading !== '' ? $heading : null;
    }

    /**
     * Purpose: Normalize a free-text value with an optional fallback.
     * Inputs: A raw value, a fallback value, and the maximum length.
     * Returns: A trimmed string capped to the requested length, or null.
     * Side effects: None.
     */
    private function normalizeText(mixed $value, mixed $fallbackValue = null, int $maxLength = 300): ?string
    {
        foreach ([$value, $fallbackValue] as $candidate) {
            $cleanValue = trim(Str::squish((string) ($candidate ?? '')));

            if ($cleanValue === '') {
                continue;
            }

            return Str::limit($cleanValue, $maxLength, '');
        }

        return null;
    }

    /**
     * Purpose: Normalize enriched synonyms and remove the canonical name from the list.
     * Inputs: Raw synonym data and the canonical name.
     * Returns: A trimmed, de-duplicated synonym list.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function normalizeSynonyms(mixed $synonyms, string $canonicalName): array
    {
        if (is_string($synonyms)) {
            $synonyms = preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $synonyms), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (! is_array($synonyms)) {
            $synonyms = $synonyms === null ? [] : [(string) $synonyms];
        }

        $normalized = [];
        $seen = [];
        $canonicalKey = $this->comparisonKey($canonicalName);

        foreach ($synonyms as $synonym) {
            $cleanValue = trim(Str::squish((string) $synonym));

            if ($cleanValue === '') {
                continue;
            }

            $comparisonKey = $this->comparisonKey($cleanValue);

            if ($comparisonKey === $canonicalKey) {
                continue;
            }

            if (isset($seen[$comparisonKey])) {
                continue;
            }

            $seen[$comparisonKey] = true;
            $normalized[] = $cleanValue;

            if (count($normalized) >= 5) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Purpose: Produce a stable comparison key for canonical and synonym matching.
     * Inputs: Raw text.
     * Returns: A lowercased comparison key stripped of excess whitespace and accents.
     * Side effects: None.
     */
    private function comparisonKey(string $value): string
    {
        $value = trim(Str::squish($value));

        if ($value === '') {
            return '';
        }

        return trim(Str::ascii(mb_strtolower($value, 'UTF-8')));
    }

    /**
     * Purpose: Resolve the configured OpenAI model for enrichment requests.
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
            throw new RuntimeException('OpenAI knowledge vocabulary enrichment model is not configured.');
        }

        return $model;
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
            throw new RuntimeException('OpenAI knowledge vocabulary enrichment response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI knowledge vocabulary enrichment response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI knowledge vocabulary enrichment response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return knowledge vocabulary enrichment.');
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
}
