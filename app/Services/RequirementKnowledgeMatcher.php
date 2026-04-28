<?php

namespace App\Services;

use App\Support\CosineSimilarity;
use Illuminate\Support\Collection;

class RequirementKnowledgeMatcher
{
    private const MAX_RESULTS = 5;

    private const EMBEDDING_WEIGHT = 0.5;

    private const HEURISTIC_BOOST = 2;

    private const METADATA_MAX_BOOST = 2.5;

    private const METADATA_FIELD_WEIGHTS = [
        'keywords' => 1.2,
        'topic' => 1.0,
        'sub_topic' => 0.85,
        'service_product_tag' => 0.9,
        'theme_tag' => 0.8,
        'section_title' => 0.75,
        'section_path' => 0.6,
        'knowledge_item_title' => 0.55,
        'knowledge_item_summary' => 0.3,
    ];

    private const STOPWORDS = [
        'and',
        'are',
        'av',
        'be',
        'den',
        'det',
        'en',
        'er',
        'et',
        'for',
        'from',
        'had',
        'has',
        'i',
        'in',
        'is',
        'it',
        'med',
        'og',
        'of',
        'on',
        'over',
        'på',
        'som',
        'the',
        'til',
        'to',
        'we',
        'you',
    ];

    public function __construct(
        private readonly CosineSimilarity $cosineSimilarity,
    ) {
    }

    /**
     * Purpose: Match one requirement text against a scoped set of knowledge chunks.
     * Inputs: The requirement text and a collection of chunk payloads.
     * Returns: The top ranked knowledge matches, limited to a small deterministic set.
     * Side effects: None.
     */
    public function match(string $requirementText, Collection $knowledgeChunks, ?array $requirementEmbedding = null): Collection
    {
        $normalizedRequirementText = $this->normalizeText($requirementText);
        $requirementTokens = $this->tokenize($normalizedRequirementText);

        if ($requirementTokens === []) {
            return collect();
        }

        $heuristicBoosts = $this->heuristicBoosts($normalizedRequirementText);

        $rankedCandidates = $knowledgeChunks
            ->map(function ($chunk) use ($requirementTokens, $heuristicBoosts): ?array {
                $chunkContent = (string) data_get($chunk, 'content', '');
                $normalizedChunkContent = $this->normalizeText($chunkContent);

                if ($normalizedChunkContent === '') {
                    return null;
                }

                $chunkTokens = $this->tokenize($normalizedChunkContent);

                if ($chunkTokens === []) {
                    return null;
                }

                $score = count(array_intersect($requirementTokens, $chunkTokens));
                $metadataScore = data_get($chunk, 'metadata_score');
                $chunkMetadataScore = is_numeric($metadataScore)
                    ? (float) $metadataScore
                    : $this->metadataBoostScore($requirementTokens, $chunk);

                $score += $chunkMetadataScore;
                $contentType = (string) data_get($chunk, 'content_type', '');

                if ($contentType !== '' && isset($heuristicBoosts[$contentType])) {
                    $score += self::HEURISTIC_BOOST;
                }

                if ($score <= 0) {
                    return null;
                }

                return [
                    'knowledge_item_id' => (int) data_get($chunk, 'knowledge_item_id'),
                    'knowledge_item_title' => (string) data_get($chunk, 'knowledge_item_title', ''),
                    'content_type' => $contentType,
                    'chunk_id' => (int) data_get($chunk, 'chunk_id'),
                    'chunk_index' => (int) data_get($chunk, 'chunk_index', 0),
                    'chunk_content' => $chunkContent,
                    'score' => $score,
                    'base_score' => $score,
                    'metadata_score' => $chunkMetadataScore,
                    'metadata_matches' => data_get($chunk, 'metadata_matches', []),
                    'embedding_vector' => data_get($chunk, 'embedding_vector'),
                    'embedding_similarity' => null,
                    'final_score' => (float) $score,
                    'knowledge_item_updated_at' => (string) data_get($chunk, 'knowledge_item_updated_at', ''),
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                if ($left['score'] !== $right['score']) {
                    return $right['score'] <=> $left['score'];
                }

                if ($left['knowledge_item_updated_at'] !== $right['knowledge_item_updated_at']) {
                    return strcmp($right['knowledge_item_updated_at'], $left['knowledge_item_updated_at']);
                }

                if ($left['knowledge_item_id'] !== $right['knowledge_item_id']) {
                    return $right['knowledge_item_id'] <=> $left['knowledge_item_id'];
                }

                if ($left['chunk_index'] !== $right['chunk_index']) {
                    return $left['chunk_index'] <=> $right['chunk_index'];
                }

                return $left['chunk_id'] <=> $right['chunk_id'];
            })
            ->take(self::MAX_RESULTS)
            ->values();

        if (! is_array($requirementEmbedding) || $requirementEmbedding === []) {
            return $rankedCandidates->map(function (array $candidate): array {
                $candidate['embedding_similarity'] = null;
                $candidate['final_score'] = (float) $candidate['base_score'];

                return $candidate;
            });
        }

        return $rankedCandidates
            ->map(function (array $candidate) use ($requirementEmbedding): array {
                $chunkEmbedding = data_get($candidate, 'embedding_vector');

                if (! is_array($chunkEmbedding) || $chunkEmbedding === []) {
                    $candidate['embedding_similarity'] = null;
                    $candidate['final_score'] = (float) $candidate['base_score'];

                    return $candidate;
                }

                $similarity = $this->cosineSimilarity->calculate($requirementEmbedding, $chunkEmbedding);

                if ($similarity === null) {
                    $candidate['embedding_similarity'] = null;
                    $candidate['final_score'] = (float) $candidate['base_score'];

                    return $candidate;
                }

                $candidate['embedding_similarity'] = $similarity;
                $candidate['final_score'] = (float) $candidate['base_score'] + ((($similarity + 1.0) / 2.0) * self::EMBEDDING_WEIGHT);

                return $candidate;
            })
            ->sort(function (array $left, array $right): int {
                if (abs($left['final_score'] - $right['final_score']) > 0.000001) {
                    return $right['final_score'] <=> $left['final_score'];
                }

                if ($left['base_score'] !== $right['base_score']) {
                    return $right['base_score'] <=> $left['base_score'];
                }

                $leftSimilarity = $left['embedding_similarity'] ?? -INF;
                $rightSimilarity = $right['embedding_similarity'] ?? -INF;

                if (abs($leftSimilarity - $rightSimilarity) > 0.000001) {
                    return $rightSimilarity <=> $leftSimilarity;
                }

                if ($left['knowledge_item_updated_at'] !== $right['knowledge_item_updated_at']) {
                    return strcmp($right['knowledge_item_updated_at'], $left['knowledge_item_updated_at']);
                }

                if ($left['knowledge_item_id'] !== $right['knowledge_item_id']) {
                    return $right['knowledge_item_id'] <=> $left['knowledge_item_id'];
                }

                if ($left['chunk_index'] !== $right['chunk_index']) {
                    return $left['chunk_index'] <=> $right['chunk_index'];
                }

                return $left['chunk_id'] <=> $right['chunk_id'];
            })
            ->values();
    }

    /**
     * Purpose: Normalize text for deterministic chunk-to-requirement matching.
     * Inputs: Raw text from a requirement or knowledge chunk.
     * Returns: Lowercased text with punctuation collapsed to whitespace.
     * Side effects: None.
     */
    private function normalizeText(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Purpose: Build a compact token list for deterministic scoring.
     * Inputs: Normalized text.
     * Returns: A de-duplicated token array with simple stop-word filtering.
     * Side effects: None.
     */
    private function tokenize(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens)) {
            return [];
        }

        $tokens = array_map(static fn (string $token): string => trim($token), $tokens);
        $tokens = array_filter($tokens, static function (string $token): bool {
            if ($token === 'cv') {
                return true;
            }

            if (mb_strlen($token, 'UTF-8') < 3) {
                return false;
            }

            return ! in_array($token, self::STOPWORDS, true);
        });
        $tokens = array_values(array_unique($tokens));

        return $tokens;
    }

    /**
     * Purpose: Detect the canonical heuristic boosts for a requirement statement.
     * Inputs: Normalized requirement text.
     * Returns: A content-type keyed boost map.
     * Side effects: None.
     */
    private function heuristicBoosts(string $requirementText): array
    {
        $boosts = [];

        if (preg_match('/\berfaring\b/u', $requirementText) === 1) {
            $boosts['reference'] = true;
        }

        if (preg_match('/\bmetode\b/u', $requirementText) === 1) {
            $boosts['method'] = true;
        }

        if (preg_match('/\bcv\b/u', $requirementText) === 1) {
            $boosts['cv'] = true;
        }

        return $boosts;
    }

    /**
     * Purpose: Calculate a conservative metadata boost for a chunk.
     * Inputs: Requirement tokens and one retrieved chunk row.
     * Returns: A small additive score based on overlapping metadata terms.
     * Side effects: None.
     */
    private function metadataBoostScore(array $requirementTokens, array $chunk): float
    {
        if ($requirementTokens === []) {
            return 0.0;
        }

        $termWeights = [];

        foreach (self::METADATA_FIELD_WEIGHTS as $field => $fieldWeight) {
            $metadataTerms = $this->metadataTermsFromValue(data_get($chunk, $field));

            if ($metadataTerms === []) {
                continue;
            }

            foreach (array_intersect($requirementTokens, $metadataTerms) as $term) {
                $termWeights[$term] = max($termWeights[$term] ?? 0.0, (float) $fieldWeight);
            }
        }

        if ($termWeights === []) {
            return 0.0;
        }

        return min(array_sum($termWeights), self::METADATA_MAX_BOOST);
    }

    /**
     * Purpose: Normalize metadata values into comparable terms.
     * Inputs: A scalar metadata value or an array of metadata values.
     * Returns: A de-duplicated token list ready for overlap checks.
     * Side effects: None.
     */
    private function metadataTermsFromValue(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $value = implode(' ', array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ));
        }

        return $this->tokenize($this->normalizeText((string) $value));
    }
}
