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
            'content_type' => 'other',
            'chunk_index' => 0,
            'content' => $content,
            'embedding_vector' => $embeddingVector,
            'knowledge_item_updated_at' => $updatedAt,
            'topic' => '',
            'sub_topic' => '',
            'keywords' => [],
            'section_title' => '',
            'section_path' => '',
            'knowledge_item_summary' => '',
        ], $metadata);
    }
}
