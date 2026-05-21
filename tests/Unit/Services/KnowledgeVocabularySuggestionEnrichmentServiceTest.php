<?php

namespace Tests\Unit\Services;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\Ai\Knowledge\KnowledgeVocabularySuggestionEnrichmentService;
use Mockery;
use Tests\TestCase;

class KnowledgeVocabularySuggestionEnrichmentServiceTest extends TestCase
{
    public function test_enrich_suggestion_builds_expected_payload_and_parses_json_response(): void
    {
        [$document, $chunk] = $this->makeDocumentAndChunk();
        $responsePayload = [
            'canonical_name' => 'Beredskap',
            'synonyms' => ['Kriseberedskap', 'Nødberedskap'],
            'description' => 'Beskrivelse av beredskap.',
            'reason' => 'Begrunnelse for forslaget.',
        ];

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($document, $chunk): bool {
                $expectedModel = config('services.openai.requirement_answer_model', config('services.openai.model', 'gpt-4.1-mini'));

                $this->assertSame($expectedModel, data_get($payload, 'model'));
                $this->assertSame(0, data_get($payload, 'temperature'));
                $this->assertSame(1200, data_get($payload, 'max_output_tokens'));
                $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
                $this->assertSame('knowledge_vocabulary_suggestion_enrichment', data_get($payload, 'text.format.name'));
                $this->assertTrue(data_get($payload, 'text.format.strict'));
                $this->assertSame('developer', data_get($payload, 'input.0.role'));
                $this->assertSame('user', data_get($payload, 'input.1.role'));

                $prompt = json_decode((string) data_get($payload, 'input.1.content.0.text'), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame([
                    'id' => $document->id,
                    'title' => $document->title,
                    'original_filename' => $document->original_filename,
                    'summary' => $document->summary,
                ], data_get($prompt, 'document'));

                $this->assertSame([
                    'id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                    'heading_path' => $chunk->heading_path,
                    'section_title' => $chunk->section_title,
                    'section_path' => $chunk->section_path,
                    'topic' => $chunk->topic,
                    'sub_topic' => $chunk->sub_topic,
                    'keywords' => $chunk->keywords,
                    'summary_for_retrieval' => $chunk->summary_for_retrieval,
                    'content_excerpt' => 'Kort innhold som oppsummerer seksjonen.',
                ], data_get($prompt, 'chunk'));

                $this->assertSame([
                    'field' => 'topic',
                    'term' => 'Beredskap',
                ], data_get($prompt, 'candidate'));

                return true;
            }))
            ->andReturn([
                'output_text' => json_encode($responsePayload, JSON_THROW_ON_ERROR),
            ]);

        $this->app->instance(AiTextGenerationClient::class, $client);

        $service = $this->app->make(KnowledgeVocabularySuggestionEnrichmentService::class);
        $result = $service->enrichSuggestion($document, $chunk, 'topic', 'Beredskap');

        $this->assertSame($responsePayload, $result);
    }

    public function test_enrich_suggestion_strips_code_fences_from_json_response(): void
    {
        [$document, $chunk] = $this->makeDocumentAndChunk();
        $responsePayload = [
            'canonical_name' => 'Beredskap',
            'synonyms' => ['Kriseberedskap'],
            'description' => 'Beskrivelse av beredskap.',
            'reason' => 'Begrunnelse for forslaget.',
        ];

        $client = Mockery::mock(AiTextGenerationClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'output_text' => "```json\n".json_encode($responsePayload, JSON_THROW_ON_ERROR)."\n```",
            ]);

        $this->app->instance(AiTextGenerationClient::class, $client);

        $service = $this->app->make(KnowledgeVocabularySuggestionEnrichmentService::class);
        $result = $service->enrichSuggestion($document, $chunk, 'topic', 'Beredskap');

        $this->assertSame($responsePayload, $result);
    }

    /**
     * Purpose: Build a representative document and chunk pair for service tests.
     * Inputs: None.
     * Returns: A tuple with an unsaved document and chunk model.
     * Side effects: None.
     *
     * @return array{0: KnowledgeItem, 1: KnowledgeItemChunk}
     */
    private function makeDocumentAndChunk(): array
    {
        $document = new KnowledgeItem();
        $document->forceFill([
            'id' => 101,
            'title' => 'Beredskapsplan',
            'original_filename' => 'beredskap-plan.pdf',
            'summary' => 'Dokument om beredskap og krisehåndtering.',
        ]);

        $chunk = new KnowledgeItemChunk();
        $chunk->forceFill([
            'id' => 202,
            'chunk_index' => 3,
            'heading_path' => '1 > 1.2 Beredskap',
            'section_title' => '1.2 Beredskap',
            'section_path' => '1 > 1.2 Beredskap',
            'topic' => 'Beredskap',
            'sub_topic' => 'Kriseberedskap',
            'keywords' => ['beredskap', 'krise'],
            'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
            'content' => 'Kort innhold som oppsummerer seksjonen.',
        ]);

        return [$document, $chunk];
    }
}
