<?php

namespace App\Services;

use Illuminate\Support\Collection;

class KnowledgeChunkCoverageService
{
    private const GREEN_SCORE_THRESHOLD = 0.60;

    private const AMBER_SCORE_THRESHOLD = 0.45;

    private const STRONG_SCORE_THRESHOLD = 0.50;

    private const STRONG_SCORE_MATCH_COUNT = 2;

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

    /**
     * Purpose: Normalize a keyword input into a stable persisted list.
     * Inputs: A comma-separated string, an array of strings, or null.
     * Returns: A de-duplicated keyword list or null when no usable keywords are supplied.
     * Side effects: None.
     */
    public function normalizeKeywords(mixed $keywords): ?array
    {
        $items = [];

        if (is_string($keywords)) {
            $items = preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $keywords), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($keywords)) {
            $items = $keywords;
        } elseif ($keywords === null) {
            return null;
        } else {
            $items = [(string) $keywords];
        }

        $normalizedKeywords = [];
        $seen = [];

        foreach ($items as $item) {
            $keyword = trim(preg_replace('/\s+/u', ' ', (string) $item) ?? '');

            if ($keyword === '') {
                continue;
            }

            $normalizedKey = mb_strtolower($keyword, 'UTF-8');

            if (isset($seen[$normalizedKey])) {
                continue;
            }

            $seen[$normalizedKey] = true;
            $normalizedKeywords[] = $keyword;
        }

        return $normalizedKeywords !== [] ? $normalizedKeywords : null;
    }

    /**
     * Purpose: Calculate a deterministic knowledge grounding label from retrieval results and requirement text.
     * Inputs: Retrieved knowledge rows and the source requirement text.
     * Returns: A compact traffic-light grounding summary.
     * Side effects: None.
     */
    public function evaluateKnowledgeGrounding(Collection|array|null $retrievedChunks, ?string $requirementText = null): array
    {
        $chunks = $this->normalizeChunks($retrievedChunks);

        if ($chunks->isEmpty()) {
            return $this->groundingPayload('red', 0.0, 0);
        }

        $scores = $chunks
            ->map(static fn (array $chunk): float => (float) data_get($chunk, 'score', data_get($chunk, 'final_score', data_get($chunk, 'base_score', 0))))
            ->values();

        $sourcesCount = $scores->count();
        $maxScore = $sourcesCount > 0 ? (float) $scores->max() : 0.0;
        $strongScoreCount = $scores->filter(static fn (float $score): bool => $score >= self::STRONG_SCORE_THRESHOLD)->count();

        $requirementTerms = $this->extractTerms((string) $requirementText);
        $chunkContextTerms = $this->chunkContextTerms($chunks);
        $sectionContextTerms = $this->sectionContextTerms($chunks);
        $documentContextTerms = $this->documentContextTerms($chunks);

        $hasChunkContext = $chunkContextTerms !== [];
        $hasSectionContext = $sectionContextTerms !== [];
        $hasDocumentContext = $documentContextTerms !== [];

        if (! $hasChunkContext && ! $hasSectionContext && ! $hasDocumentContext) {
            return $this->scoreOnlyGrounding($maxScore, $strongScoreCount, $sourcesCount);
        }

        $chunkContextMatches = $this->matchedRequirementTerms($requirementTerms, $chunkContextTerms);
        $sectionContextMatches = $this->matchedRequirementTerms($requirementTerms, $sectionContextTerms);
        $documentContextMatches = $this->matchedRequirementTerms($requirementTerms, $documentContextTerms);

        $hasStrongContextMatch = $chunkContextMatches !== [] || $sectionContextMatches !== [];
        $hasDocumentContextMatch = $documentContextMatches !== [];

        if (! $hasStrongContextMatch && ! $hasDocumentContextMatch) {
            return $this->groundingPayload('red', $maxScore, $sourcesCount);
        }

        if (! $hasStrongContextMatch && $hasDocumentContextMatch) {
            if ($maxScore >= self::AMBER_SCORE_THRESHOLD) {
                return $this->groundingPayload('amber', $maxScore, $sourcesCount);
            }

            return $this->groundingPayload('red', $maxScore, $sourcesCount);
        }

        if (
            $maxScore >= self::GREEN_SCORE_THRESHOLD
            && $strongScoreCount >= self::STRONG_SCORE_MATCH_COUNT
            && $hasStrongContextMatch
        ) {
            return $this->groundingPayload('green', $maxScore, $sourcesCount);
        }

        if ($maxScore >= self::AMBER_SCORE_THRESHOLD) {
            return $this->groundingPayload('amber', $maxScore, $sourcesCount);
        }

        return $this->groundingPayload('red', $maxScore, $sourcesCount);
    }

    /**
     * Purpose: Normalize a chunk collection or payload into array rows only.
     * Inputs: Retrieved knowledge chunks as a collection, array, or null.
     * Returns: A collection of array rows.
     * Side effects: None.
     */
    private function normalizeChunks(Collection|array|null $retrievedChunks): Collection
    {
        if ($retrievedChunks instanceof Collection) {
            return $retrievedChunks
                ->filter(static fn ($row): bool => is_array($row))
                ->values();
        }

        if (! is_array($retrievedChunks)) {
            return collect();
        }

        return collect($retrievedChunks)
            ->filter(static fn ($row): bool => is_array($row))
            ->values();
    }

    /**
     * Purpose: Build the chunk-level semantic term set from the retrieved chunks.
     * Inputs: Retrieved chunk rows.
     * Returns: Unique normalized tokens used for chunk-text and editable metadata matching.
     * Side effects: None.
     */
    private function chunkContextTerms(Collection $chunks): array
    {
        $terms = [];

        foreach ($chunks as $chunk) {
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'content', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'title', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'topic', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'sub_topic', '')));

            $keywords = $this->normalizeKeywords(data_get($chunk, 'keywords'));

            foreach ($keywords ?? [] as $keyword) {
                $terms = array_merge($terms, $this->extractTerms($keyword));
            }
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * Purpose: Build the section-level semantic term set from the retrieved chunks.
     * Inputs: Retrieved chunk rows.
     * Returns: Unique normalized tokens used for section matching.
     * Side effects: None.
     */
    private function sectionContextTerms(Collection $chunks): array
    {
        $terms = [];

        foreach ($chunks as $chunk) {
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'section_title', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'section_path', '')));
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * Purpose: Build the broad document-level term set from the retrieved chunks.
     * Inputs: Retrieved chunk rows.
     * Returns: Unique normalized tokens used for broad document context matching.
     * Side effects: None.
     */
    private function documentContextTerms(Collection $chunks): array
    {
        $terms = [];

        foreach ($chunks as $chunk) {
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'knowledge_item_title', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'document_title', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'knowledge_item_summary', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'content_type', '')));
            $terms = array_merge($terms, $this->extractTerms((string) data_get($chunk, 'document_type', '')));
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * Purpose: Build a small set of content terms from requirement text or metadata text.
     * Inputs: Raw text.
     * Returns: Normalized tokens with simple stop-word filtering.
     * Side effects: None.
     */
    private function extractTerms(string $value): array
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

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

        return array_values(array_unique($tokens));
    }

    /**
     * Purpose: Match requirement terms against metadata terms with a conservative exact/substring check.
     * Inputs: Requirement tokens and metadata tokens.
     * Returns: Unique requirement terms that are supported by metadata.
     * Side effects: None.
     */
    private function matchedRequirementTerms(array $requirementTerms, array $metadataTerms): array
    {
        $matches = [];

        foreach ($requirementTerms as $requirementTerm) {
            foreach ($metadataTerms as $metadataTerm) {
                if ($this->termMatches($requirementTerm, $metadataTerm)) {
                    $matches[$requirementTerm] = true;
                    break;
                }
            }
        }

        return array_keys($matches);
    }

    /**
     * Purpose: Normalize a token list into unique non-empty terms.
     * Inputs: An array of candidate terms.
     * Returns: A deduplicated list of terms.
     * Side effects: None.
     */
    private function uniqueTerms(array $terms): array
    {
        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    /**
     * Purpose: Determine whether two normalized terms are a meaningful match.
     * Inputs: Two normalized tokens.
     * Returns: True when the tokens are a conservative semantic match.
     * Side effects: None.
     */
    private function termMatches(string $requirementTerm, string $metadataTerm): bool
    {
        if ($requirementTerm === '' || $metadataTerm === '') {
            return false;
        }

        if ($requirementTerm === $metadataTerm) {
            return true;
        }

        if (mb_strlen($requirementTerm, 'UTF-8') < 4 && mb_strlen($metadataTerm, 'UTF-8') < 4) {
            return false;
        }

        return str_contains($requirementTerm, $metadataTerm) || str_contains($metadataTerm, $requirementTerm);
    }

    /**
     * Purpose: Return the score-only fallback grounding payload.
     * Inputs: The top score, the count of strong scores, and the source count.
     * Returns: A traffic-light grounding payload.
     * Side effects: None.
     */
    private function scoreOnlyGrounding(float $maxScore, int $strongScoreCount, int $sourcesCount): array
    {
        if ($maxScore >= self::GREEN_SCORE_THRESHOLD && $strongScoreCount >= self::STRONG_SCORE_MATCH_COUNT) {
            return $this->groundingPayload('green', $maxScore, $sourcesCount);
        }

        if ($maxScore >= self::AMBER_SCORE_THRESHOLD) {
            return $this->groundingPayload('amber', $maxScore, $sourcesCount);
        }

        return $this->groundingPayload('red', $maxScore, $sourcesCount);
    }

    /**
     * Purpose: Format the canonical grounding payload.
     * Inputs: The chosen level, max score, and source count.
     * Returns: A compact normalized payload.
     * Side effects: None.
     */
    private function groundingPayload(string $level, float $maxScore, int $sourcesCount): array
    {
        return [
            'level' => in_array($level, ['green', 'amber', 'red'], true) ? $level : 'red',
            'max_score' => $maxScore,
            'sources_count' => $sourcesCount,
        ];
    }
}
