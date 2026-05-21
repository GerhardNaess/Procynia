<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Services\Ai\Contracts\AiTextGenerationClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class KnowledgeVocabularyExtractionService
{
    private const MAX_OUTPUT_TOKENS = 2200;

    private const TEMPERATURE = 0;

    public function __construct(
        private readonly AiTextGenerationClient $textGenerationClient,
    ) {
    }

    /**
     * Purpose: Analyze a representative document set and return structured vocabulary suggestions.
     * Inputs: The analysis batch, the selected documents, and the approved vocabulary catalog.
     * Returns: A decoded JSON payload from the AI model.
     * Side effects: May call OpenAI and logs the analysis lifecycle.
     */
    public function extract(
        KnowledgeVocabularyAnalysisBatch $batch,
        Collection $documents,
        array $approvedVocabularyCatalog,
    ): array {
        $payload = $this->openAiRequestPayload($batch, $documents, $approvedVocabularyCatalog);
        $startedAt = microtime(true);

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary extraction starting.', [
            'customer_id' => (int) $batch->customer_id,
            'batch_id' => $batch->id,
            'document_count' => $documents->count(),
            'approved_type_count' => count((array) data_get($approvedVocabularyCatalog, 'types', [])),
            'approved_term_count' => array_sum((array) data_get($approvedVocabularyCatalog, 'type_counts', [])),
        ]);

        $response = $this->textGenerationClient->createResponse($payload);
        $decoded = $this->decodePayload($response);

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary extraction completed.', [
            'customer_id' => (int) $batch->customer_id,
            'batch_id' => $batch->id,
            'suggestion_count' => count((array) data_get($decoded, 'suggestions', [])),
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]);

        return $decoded;
    }

    /**
     * Purpose: Build the OpenAI request payload for vocabulary extraction.
     * Inputs: The batch, the selected documents, and the approved vocabulary catalog.
     * Returns: The exact request payload sent to OpenAI.
     * Side effects: None.
     */
    private function openAiRequestPayload(
        KnowledgeVocabularyAnalysisBatch $batch,
        Collection $documents,
        array $approvedVocabularyCatalog,
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
                            'text' => $this->userPrompt($batch, $documents, $approvedVocabularyCatalog),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'knowledge_vocabulary_analysis',
                    'description' => 'Structured vocabulary analysis for representative customer documents.',
                    'strict' => true,
                    'schema' => $this->analysisSchema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Purpose: Build the developer instructions for vocabulary extraction.
     * Inputs: None.
     * Returns: A short instruction string for the model.
     * Side effects: None.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'You analyze representative customer documents to propose customer-specific vocabulary.',
            'You are not writing SQL.',
            'You must use existing approved vocabulary values when they fit the documents.',
            'You must not invent authoritative terms.',
            'If a concept is already covered by an approved canonical term or synonym, prefer that existing term.',
            'If a concept is genuinely new, propose it as a pending suggestion.',
            'If a suggestion clearly extends an approved term, set related_existing_term to that approved canonical name when it exists in the approved vocabulary.',
            'Do not over-tag.',
            'Keep canonical names compact and meaningful.',
            'Keep synonyms concrete, relevant, and non-duplicative.',
            'Return only JSON that matches the schema.',
            'Write all string values in Norwegian.',
            'Preserve important acronyms when they appear in the documents and are meaningful to the customer.',
        ]);
    }

    /**
     * Purpose: Build the user-facing payload for vocabulary extraction.
     * Inputs: The analysis batch, the selected documents, and the approved vocabulary catalog.
     * Returns: A JSON string the model can inspect deterministically.
     * Side effects: None.
     */
    private function userPrompt(
        KnowledgeVocabularyAnalysisBatch $batch,
        Collection $documents,
        array $approvedVocabularyCatalog,
    ): string {
        $payload = [
            'analysis_batch' => [
                'id' => $batch->id,
                'customer_id' => $batch->customer_id,
                'source_document_ids' => $batch->source_document_ids ?? [],
                'status' => $batch->status,
            ],
            'allowed_metadata_types' => KnowledgeMetadataTerm::TYPES,
            'approved_vocabulary' => data_get($approvedVocabularyCatalog, 'groups', []),
            'documents' => $this->documentPayload($documents),
            'instructions' => [
                'Les dokumentene samlet og finn ord, tjenester, prosesser, systemer og begreper som kunden bruker konsekvent.',
                'Bruk eksisterende godkjent vokabular der det passer.',
                'Foreslå nye canonical_name bare når dokumentene tydelig bruker et begrep som ikke allerede finnes i godkjent vokabular.',
                'Samle nært beslektede synonymer under samme canonical_name.',
                'Returner confidence_score for hvert forslag mellom 0 og 1.',
                'batch_summary skal være en kort oppsummering av hva dokumentene samlet beskriver.',
            ],
        ];

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the vocabulary analysis prompt payload.', 0, $exception);
        }
    }

    /**
     * Purpose: Convert selected documents into a compact AI context payload.
     * Inputs: The selected documents.
     * Returns: An array with representative document context.
     * Side effects: None.
     */
    private function documentPayload(Collection $documents): array
    {
        return $documents
            ->map(function (KnowledgeItem $document): array {
                $content = trim((string) ($document->extracted_text ?: $document->content));
                $content = Str::squish($content);

                $sectionTitles = $document->relationLoaded('chunks')
                    ? $document->chunks
                        ->map(static fn (KnowledgeItemChunk $chunk): ?string => trim((string) ($chunk->section_title ?: $chunk->title)))
                        ->filter(static fn (?string $value): bool => is_string($value) && trim($value) !== '')
                        ->unique()
                        ->take(20)
                        ->values()
                        ->all()
                    : [];

                return [
                    'document_id' => $document->id,
                    'file_name' => $document->original_filename,
                    'document_title' => $document->title,
                    'document_type' => $document->document_type,
                    'summary' => $document->summary,
                    'detected_language' => null,
                    'section_titles' => $sectionTitles,
                    'extracted_text' => Str::limit($content, 6000, '...'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Define the strict JSON schema for the vocabulary analysis response.
     * Inputs: None.
     * Returns: The JSON schema array used for structured output.
     * Side effects: None.
     */
    private function analysisSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'batch_summary' => [
                    'type' => 'string',
                ],
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => KnowledgeMetadataTerm::TYPES,
                            ],
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
                            'related_existing_term' => [
                                'type' => ['string', 'null'],
                            ],
                            'reason' => [
                                'type' => ['string', 'null'],
                            ],
                            'confidence_score' => [
                                'type' => 'number',
                            ],
                        ],
                        'required' => [
                            'type',
                            'canonical_name',
                            'synonyms',
                            'description',
                            'related_existing_term',
                            'reason',
                            'confidence_score',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'batch_summary',
                'suggestions',
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
            throw new RuntimeException('OpenAI vocabulary analysis response did not include any text output.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI vocabulary analysis response was not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI vocabulary analysis response did not decode to a JSON object.');
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
                    throw new RuntimeException('OpenAI refused to return vocabulary analysis.');
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
     * Purpose: Resolve the configured OpenAI model for vocabulary extraction requests.
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
            throw new RuntimeException('OpenAI vocabulary analysis model is not configured.');
        }

        return $model;
    }

    /**
     * Purpose: Convert elapsed time to milliseconds.
     * Inputs: The start time in seconds.
     * Returns: The elapsed milliseconds as an integer.
     * Side effects: None.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
