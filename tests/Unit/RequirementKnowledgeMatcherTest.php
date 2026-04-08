<?php

namespace Tests\Unit;

use App\Services\RequirementKnowledgeMatcher;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RequirementKnowledgeMatcherTest extends TestCase
{
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
        $this->assertSame([2, 2, 2], $matches->pluck('score')->all());
        $this->assertSame(2, $matches->first()['base_score']);
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
        $this->assertSame(2, $matches->first()['score']);
    }

    private function chunkPayload(
        int $chunkId,
        string $title,
        string $content,
        string $updatedAt,
        ?array $embeddingVector,
    ): array {
        return [
            'chunk_id' => $chunkId,
            'knowledge_item_id' => $chunkId,
            'knowledge_item_title' => $title,
            'content_type' => 'other',
            'chunk_index' => 0,
            'content' => $content,
            'embedding_vector' => $embeddingVector,
            'knowledge_item_updated_at' => $updatedAt,
        ];
    }
}
