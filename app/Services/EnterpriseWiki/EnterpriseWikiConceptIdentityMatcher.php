<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic, conservative check for whether two page/concept titles are plausibly the same
 * identity — e.g. "ITIL Incident Management", "Incident Management", and "Incident management
 * (ITIL)" should be treated as one concept, not three. Built for
 * EnterpriseWikiMaintainerDecisionConsistencyValidator's cross-referencing of concept candidates
 * against the wiki index and the decision's own planned pages; NOT used by
 * EnterpriseWikiMaintainerDecisionApplyService::resolvePage(), which stays exact-key-only by
 * design.
 *
 * Deliberately narrow: two titles match only when one title's significant token set is a subset
 * of the other's (never a partial-overlap ratio) — this is what lets "Incident Management" match
 * "ITIL Incident Management" while still refusing to match "Problem Management" (disjoint core
 * token). Single-token titles are exempted from the subset rule and require exact equality,
 * because a subset match against a lone generic word (e.g. "Management") would otherwise match
 * almost anything.
 */
class EnterpriseWikiConceptIdentityMatcher
{
    private const STOPWORDS = ['the', 'a', 'an', 'of', 'for', 'and', 'og', 'en', 'et', 'til', 'for'];

    public static function sameIdentity(string $a, string $b): bool
    {
        $tokensA = array_values(array_unique(self::tokens($a)));
        $tokensB = array_values(array_unique(self::tokens($b)));

        if ($tokensA === [] || $tokensB === []) {
            return false;
        }

        if (count($tokensA) === 1 || count($tokensB) === 1) {
            sort($tokensA);
            sort($tokensB);

            return $tokensA === $tokensB;
        }

        return array_diff($tokensA, $tokensB) === [] || array_diff($tokensB, $tokensA) === [];
    }

    /** @return string[] */
    private static function tokens(string $title): array
    {
        $normalized = mb_strtolower($title);
        $normalized = str_replace(['(', ')'], ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? '';

        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $t): bool => mb_strlen($t) > 2 && ! in_array($t, self::STOPWORDS, true),
        ));
    }
}
