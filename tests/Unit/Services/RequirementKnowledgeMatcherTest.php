<?php

namespace Tests\Unit\Services;

use App\Services\RequirementKnowledgeMatcher;
use Tests\TestCase;

class RequirementKnowledgeMatcherTest extends TestCase
{
    public function test_it_boosts_relevant_metadata_above_generic_content_only_matches(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'kontinuerlig leveranse og oppfølging',
            collect([
                $this->chunkPayload(
                    1,
                    'Generell rutine',
                    'leveranse',
                    '2026-04-06 10:00:00',
                    null,
                    [
                        'topic' => 'Generelt',
                        'sub_topic' => 'Informasjon',
                        'keywords' => ['annet'],
                        'section_title' => 'Bakgrunn',
                        'section_path' => 'Bakgrunn > Generelt',
                        'knowledge_item_summary' => 'Uspesifikk beskrivelse.',
                    ],
                ),
                $this->chunkPayload(
                    2,
                    'Leveranseoppfølging',
                    'leveranse',
                    '2026-04-06 10:01:00',
                    null,
                    [
                        'topic' => 'Oppfølging',
                        'sub_topic' => 'Leveranse',
                        'keywords' => ['oppfølging', 'leveranse'],
                        'section_title' => 'Leveranse og oppfølging',
                        'section_path' => 'Prosess > Oppfølging',
                        'knowledge_item_summary' => 'Dokumentet beskriver oppfølging av leveranse.',
                    ],
                ),
            ]),
        );

        $this->assertSame([2, 1], $matches->pluck('chunk_id')->all());
        $this->assertGreaterThan($matches->get(1)['score'], $matches->first()['score']);
    }

    public function test_it_uses_table_summary_and_table_text_when_matching_table_evidence(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'Beskriv sikkerhetsparametrene for SOC tjenesten i en tabell.',
            collect([
                $this->chunkPayload(
                    1,
                    'Tabell over sikkerhetsparametere',
                    'Oversikt',
                    '2026-04-06 10:00:00',
                    null,
                    [
                        'chunk_type' => 'table',
                        'summary_for_retrieval' => 'Tabellen viser sikkerhetsparametere for SOC tjenesten.',
                        'table_text' => 'Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.',
                        'section_title' => 'SOC-tjenesten',
                        'section_path' => 'Sikkerhet > SOC-tjenesten',
                    ],
                ),
                $this->chunkPayload(
                    2,
                    'Urelevant tekst',
                    'Oversikt',
                    '2026-04-06 10:01:00',
                    null,
                ),
            ]),
        );

        $this->assertSame([1], $matches->pluck('chunk_id')->all());
        $this->assertGreaterThan(0, $matches->first()['score']);
    }

    public function test_it_should_rank_table_and_image_chunks_when_the_relevant_text_is_only_present_in_structured_table_and_image_fields(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'Beskriv sikkerhetsparametere og vis Business Cybersecurity Services i dokumentasjonen.',
            collect([
                $this->chunkPayload(
                    1,
                    'Generisk prosjekttekst',
                    'sikkerhetsparametere',
                    '2026-04-06 10:00:00',
                    null,
                    [
                        'section_title' => 'Prosjekt',
                        'section_path' => 'Prosjekt > Generelt',
                    ],
                ),
                $this->chunkPayload(
                    2,
                    'Sikkerhetsparametere',
                    '',
                    '2026-04-06 10:01:00',
                    null,
                    [
                        'chunk_type' => 'table',
                        'content' => '',
                        'table_text' => '',
                        'table_html' => '<table><tr><th>Sikkerhetsparameter</th><th>Kontroll</th></tr><tr><td>Loggovervåking</td><td>Kontinuerlig</td></tr></table>',
                        'table_json' => [
                            'title' => 'Sikkerhetsparametere',
                            'table_text' => 'Sikkerhetsparameter | Kontroll',
                            'rows' => [
                                [
                                    [
                                        'text' => 'Sikkerhetsparameter',
                                    ],
                                    [
                                        'text' => 'Kontroll',
                                    ],
                                ],
                                [
                                    [
                                        'text' => 'Loggovervåking',
                                    ],
                                    [
                                        'text' => 'Kontinuerlig',
                                    ],
                                ],
                            ],
                        ],
                        'section_title' => 'SOC-tjenesten',
                        'section_path' => 'Sikkerhet > SOC-tjenesten',
                    ],
                ),
                $this->chunkPayload(
                    3,
                    'Tjenestebilde',
                    '',
                    '2026-04-06 10:02:00',
                    null,
                    [
                        'chunk_type' => 'image',
                        'content' => '',
                        'image_caption' => 'Business Cybersecurity Services',
                        'image_description' => 'Illustrasjon av Business Cybersecurity Services',
                        'ocr_text' => 'Business Cybersecurity Services',
                        'section_title' => 'Tjenestebilde',
                        'section_path' => 'Prosjekt > Illustrasjoner',
                    ],
                ),
            ]),
        );

        $this->assertContains(2, $matches->pluck('chunk_id')->all());
        $this->assertContains(3, $matches->pluck('chunk_id')->all());
        $this->assertSame([3, 2, 1], $matches->pluck('chunk_id')->all());
    }

    public function test_it_does_not_give_a_strong_boost_from_generic_metadata_words_alone(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'dokumentasjon',
            collect([
                $this->chunkPayload(
                    1,
                    'Ren tekst',
                    'dokumentasjon',
                    '2026-04-06 10:00:00',
                    null,
                ),
                $this->chunkPayload(
                    2,
                    'Ren tekst med summary',
                    'dokumentasjon',
                    '2026-04-06 10:01:00',
                    null,
                    [
                        'knowledge_item_summary' => 'Dokumentasjon',
                    ],
                ),
            ]),
        );

        $this->assertSame([2, 1], $matches->pluck('chunk_id')->all());
        $this->assertLessThan(0.5, $matches->first()['score'] - $matches->get(1)['score']);
    }

    public function test_it_reranks_base_candidates_using_embedding_similarity_and_keeps_missing_embeddings_deterministic(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);
        $requirementEmbedding = [1.0, 0.0];

        $matches = $matcher->match(
            'erfaring metode',
            collect([
                $this->chunkPayload(1, 'Hybrid A', 'erfaring metode', '2026-04-06 10:00:00', [1.0, 0.0]),
                $this->chunkPayload(2, 'Hybrid B', 'erfaring metode', '2026-04-06 10:01:00', [0.0, 1.0]),
                $this->chunkPayload(3, 'Hybrid C', 'erfaring metode', '2026-04-06 10:02:00', null),
            ]),
            $requirementEmbedding,
        );

        $this->assertSame([1, 2, 3], $matches->pluck('chunk_id')->all());
        $this->assertSame([2.0, 2.0, 2.0], $matches->pluck('score')->all());
        $this->assertSame(2.0, $matches->first()['base_score']);
        $this->assertSame(1.0, $matches->first()['embedding_similarity']);
        $this->assertNotNull($matches->get(1)['embedding_similarity']);
        $this->assertNull($matches->get(2)['embedding_similarity']);
        $this->assertGreaterThan($matches->get(1)['final_score'], $matches->first()['final_score']);
        $this->assertGreaterThan($matches->last()['final_score'], $matches->get(1)['final_score']);
    }

    public function test_it_prefers_pgvector_embedding_over_json_fallback_when_embedding_vector_pgvector_is_set(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'erfaring metode',
            collect([
                $this->chunkPayload(1, 'Pgvector first', 'erfaring metode', '2026-04-06 10:00:00', [0.0, 1.0], [
                    'embedding_vector_pgvector' => [1.0, 0.0],
                ]),
                $this->chunkPayload(2, 'Json first', 'erfaring metode', '2026-04-06 10:01:00', [1.0, 0.0], [
                    'embedding_vector_pgvector' => [0.0, 1.0],
                ]),
            ]),
            [1.0, 0.0],
        );

        $this->assertSame([1, 2], $matches->pluck('chunk_id')->all());
        $this->assertSame(1.0, $matches->first()['embedding_similarity']);
        $this->assertSame([1.0, 0.0], $matches->first()['embedding_vector_pgvector']);
    }

    public function test_it_uses_precomputed_embedding_similarity_from_retrieval_and_skips_php_cosine(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'erfaring metode',
            collect([
                $this->chunkPayload(1, 'Low precomputed', 'erfaring metode', '2026-04-06 10:00:00', null, [
                    'embedding_similarity' => 0.1,
                ]),
                $this->chunkPayload(2, 'High precomputed', 'erfaring metode', '2026-04-06 10:01:00', null, [
                    'embedding_similarity' => 0.9,
                ]),
            ]),
            [1.0, 0.0],
        );

        $this->assertSame([2, 1], $matches->pluck('chunk_id')->all());
        $this->assertSame(0.9, $matches->first()['embedding_similarity']);
        $this->assertSame(0.1, $matches->get(1)['embedding_similarity']);
        $this->assertGreaterThan($matches->get(1)['final_score'], $matches->first()['final_score']);
    }

    public function test_it_uses_a_precomputed_metadata_score_when_present(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'metadata søk',
            collect([
                $this->chunkPayload(1, 'Weak metadata', 'gamma delta', '2026-04-06 10:00:00', null, [
                    'metadata_score' => 0.25,
                ]),
                $this->chunkPayload(2, 'Strong metadata', 'gamma delta', '2026-04-06 10:01:00', null, [
                    'metadata_score' => 1.5,
                ]),
            ]),
        );

        $this->assertSame([2, 1], $matches->pluck('chunk_id')->all());
        $this->assertSame(1.5, $matches->first()['metadata_score']);
        $this->assertSame(0.25, $matches->get(1)['metadata_score']);
        $this->assertSame(1.5, $matches->first()['base_score']);
    }

    public function test_it_falls_back_to_base_ranking_when_requirement_embedding_is_missing(): void
    {
        $matcher = app(RequirementKnowledgeMatcher::class);

        $matches = $matcher->match(
            'erfaring metode',
            collect([
                $this->chunkPayload(1, 'Fallback Old', 'erfaring metode', '2026-04-06 10:00:00', [0.0, 1.0]),
                $this->chunkPayload(2, 'Fallback New', 'erfaring metode', '2026-04-06 10:01:00', [1.0, 0.0]),
            ]),
            null,
        );

        $this->assertSame([2, 1], $matches->pluck('chunk_id')->all());
        $this->assertNull($matches->first()['embedding_similarity']);
        $this->assertSame(2.0, $matches->first()['final_score']);
        $this->assertSame(2.0, $matches->first()['score']);
    }

    private function chunkPayload(
        int $chunkId,
        string $title,
        string $content,
        string $updatedAt,
        ?array $embeddingVector,
        array $metadata = [],
    ): array {
        return array_merge([
            'chunk_id' => $chunkId,
            'knowledge_item_id' => $chunkId,
            'knowledge_item_title' => $title,
            'document_type' => 'other',
            'chunk_index' => 0,
            'content' => $content,
            'embedding_vector' => $embeddingVector,
            'embedding_vector_pgvector' => null,
            'embedding_similarity' => null,
            'knowledge_item_updated_at' => $updatedAt,
            'metadata_score' => null,
            'metadata_matches' => [],
            'chunk_type' => 'semantic',
            'topic' => '',
            'sub_topic' => '',
            'service_product_tag' => '',
            'theme_tag' => '',
            'keywords' => [],
            'section_title' => '',
            'section_path' => '',
            'knowledge_item_summary' => '',
            'summary_for_retrieval' => '',
            'table_text' => '',
        ], $metadata);
    }
}
