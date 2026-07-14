<?php

namespace App\Services\Ai\Wiki;

/**
 * Shared deterministic text-normalization used across the Fase 9 Wiki-research flow — the Wiki
 * catalog (term extraction), the page ranker (search/scoring), and claim-relevance signals all
 * need the exact same token definition so "the same word" is recognized consistently everywhere.
 *
 * Deliberately simple and hand-picked, not a general-purpose NLP component: this exists only to
 * stop two concrete, observed classes of under-matching (see RequirementWikiAnswerServiceTest's
 * inflection/terminology regression tests, added after a real requirement/Wiki example showed
 * genuinely relevant material being missed):
 * - Norwegian plural/definite inflection ("prosessen" vs "prosesser"/"prosessene").
 * - Norwegian/English ITIL terminology divergence ("incident" vs "hendelse").
 *
 * No embeddings, no external service, no vector search — pure string processing.
 */
class RequirementWikiTermNormalizer
{
    /** Tokens shorter than this are treated as noise and ignored. */
    private const MIN_TOKEN_LENGTH = 4;

    /**
     * Common Norwegian inflectional suffixes stripped from the END of a token before matching —
     * longest first, so "hendelsene" strips to "hendels" via "ene" rather than stopping at "en".
     */
    private const INFLECTIONAL_SUFFIXES = ['ene', 'ens', 'en', 'er', 'et'];

    /**
     * Small, explicit Norwegian/English terminology groups for common Wiki/ITIL vocabulary — each
     * group's members are treated as interchangeable for relevance scoring only.
     *
     * @var list<list<string>>
     */
    private const TERMINOLOGY_GROUPS = [
        ['incident', 'hendelse'],
        ['change', 'endring'],
        ['service', 'tjeneste'],
        ['requirement', 'krav'],
        ['supplier', 'leverandør'],
        ['vendor', 'leverandør'],
        ['security', 'sikkerhet'],
        ['availability', 'tilgjengelighet'],
        ['deployment', 'utrulling'],
        ['release', 'utgivelse'],
    ];

    /**
     * Purpose: Tokenize text into a normalized, deduplicated set of meaningful words for relevance
     * scoring — lowercased, split on non-letter/non-digit boundaries (already normalizes hyphens/
     * punctuation), short (noise) tokens dropped, common Norwegian inflectional suffixes stripped,
     * and known Norwegian/English terminology synonyms expanded in place.
     * Inputs: Raw text.
     * Returns: A deduplicated list of normalized tokens.
     * Side effects: None.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];

        $tokens = array_filter(
            $parts,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= self::MIN_TOKEN_LENGTH,
        );

        $stemmed = array_map(static fn (string $token): string => self::stemToken($token), $tokens);
        $expanded = self::expandTerminologySynonyms($stemmed);

        return array_values(array_unique($expanded));
    }

    /**
     * Purpose: Number of distinct requirement tokens found in a target token set, and the share
     * (0..1) of the requirement's own vocabulary that represents.
     * Inputs: The requirement's tokens and a target text's tokens (both already tokenize()d).
     * Returns: [overlapCount, overlapRatio].
     * Side effects: None.
     *
     * @param  list<string>  $requirementTokens
     * @param  list<string>  $targetTokens
     * @return array{0: int, 1: float}
     */
    public static function overlap(array $requirementTokens, array $targetTokens): array
    {
        if ($requirementTokens === []) {
            return [0, 0.0];
        }

        $count = count(array_intersect($requirementTokens, $targetTokens));

        return [$count, $count / count($requirementTokens)];
    }

    /**
     * Purpose: Strip one trailing Norwegian inflectional suffix from a token, longest match first.
     * Inputs: A lowercased token.
     * Returns: The stemmed token, or the original token when no suffix applies or stripping it
     *          would leave an unreasonably short (likely wrong) stem.
     * Side effects: None.
     */
    private static function stemToken(string $token): string
    {
        foreach (self::INFLECTIONAL_SUFFIXES as $suffix) {
            $suffixLength = mb_strlen($suffix, 'UTF-8');

            if (mb_strlen($token, 'UTF-8') <= $suffixLength + 3) {
                continue;
            }

            if (mb_substr($token, -$suffixLength, null, 'UTF-8') === $suffix) {
                return mb_substr($token, 0, -$suffixLength, 'UTF-8');
            }
        }

        return $token;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function expandTerminologySynonyms(array $tokens): array
    {
        $expanded = $tokens;

        foreach ($tokens as $token) {
            foreach (self::TERMINOLOGY_GROUPS as $group) {
                if (in_array($token, $group, true)) {
                    $expanded = [...$expanded, ...$group];
                }
            }
        }

        return $expanded;
    }
}
