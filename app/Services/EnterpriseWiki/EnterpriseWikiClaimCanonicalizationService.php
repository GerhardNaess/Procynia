<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Splits "the same underlying fact" from "an occurrence of it in one Wiki page/block" — the
 * cross-page overgeneration fix. A canonical fact is verified, decided on (for best_practice),
 * and made stale exactly once; every EnterpriseWikiClaim occurrence that expresses it links via
 * canonical_fact_id but keeps its own page/version/block provenance untouched.
 *
 * Identity is a COMBINATION of signals, never one alone (Del 3):
 *   Tier 1 (hard key, exact match required): customer + content_origin + the exact document/
 *   source identity (source_type/source_id/source_hash) + the exact set of cited source element
 *   keys. This alone only produces CANDIDATES — many genuinely different facts share the same
 *   source elements (e.g. a block citing two paragraphs can yield several distinct claims).
 *   Tier 2 (equivalence, deterministic, no AI): normalized token overlap + matching numeric/
 *   entity tokens + matching normative-language ("skal"/"bør"/"må") class. A claim only reuses
 *   an existing fact when it clears BOTH tiers.
 *
 * Backend owns the final identity decision — this class never trusts a model-proposed fact id
 * or embedding score as authoritative; it is not called at all yet (no embeddings/AI equivalence
 * call exists here), only deterministic text analysis.
 */
class EnterpriseWikiClaimCanonicalizationService
{
    /**
     * Minimum Jaccard token-overlap ratio (on normalized, stopword-filtered tokens) for two
     * claim texts to be considered candidates for the same fact.
     */
    private const TOKEN_OVERLAP_THRESHOLD = 0.4;

    private const STOPWORDS = [
        'er', 'og', 'i', 'på', 'til', 'fra', 'av', 'som', 'en', 'et', 'den', 'det', 'de', 'for',
        'med', 'skal', 'bør', 'må', 'kan', 'har', 'blir', 'ble', 'å', 'om', 'at', 'ikke', 'alle',
        'innen', 'kl', 'denne', 'dette', 'disse', 'sin', 'sine', 'sitt',
    ];

    /**
     * Words whose presence signals normative/deontic language — a strong-obligation claim
     * ("skal", "må") must never be silently merged with a plain descriptive claim about the
     * same source elements (Del 3's "skal besvare innen" vs "er åpen" example).
     */
    private const NORMATIVE_MARKERS = ['skal', 'bør', 'må', 'plikter', 'kreves', 'pålagt'];

    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
    ) {}

    /**
     * Look up an existing, reusable canonical fact for $claim — null when none exists (the
     * caller must then verify $claim independently and call recordOutcome() afterwards).
     *
     * Only claims whose content_origin is source_based or best_practice (Del 8: unsupported and
     * internal_error content is never shared — those are always local defects) and that cite at
     * least one structured source element are eligible at all.
     */
    public function findReusableFact(EnterpriseWikiClaim $claim, int $customerId): ?EnterpriseWikiCanonicalFact
    {
        $key = $this->identityKey($claim, $customerId, (string) $claim->content_origin);

        if ($key === null) {
            return null;
        }

        $candidates = EnterpriseWikiCanonicalFact::query()
            ->where('customer_id', $key['customer_id'])
            ->where('content_origin', $key['content_origin'])
            ->where('source_type', $key['source_type'])
            ->where('source_id', $key['source_id'])
            ->where('source_hash', $key['source_hash'])
            ->where('source_element_keys_hash', $key['source_element_keys_hash'])
            ->where('is_stale', false)
            ->where('verification_status', '!=', EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_PENDING)
            ->get();

        foreach ($candidates as $fact) {
            if ($this->areEquivalentTexts($claim->claim_text, $fact->canonical_text)) {
                return $fact;
            }
        }

        return null;
    }

    /**
     * Record a just-completed AI verification outcome as this claim's canonical fact — either
     * attaching to an existing fact row created by a concurrent worker for the exact same
     * normalized text (race-safe via the DB unique constraint), or creating a new one so future
     * equivalent occurrences on other pages can reuse this result instead of calling AI again.
     *
     * $originalContentOrigin must be the claim's content_origin BEFORE this verification pass
     * (source_based or best_practice) — the caller captures it before updating the claim.
     *
     * Returns null when the claim has no structured source elements to canonicalize on (nothing
     * is recorded in that case — this is not an error, just nothing to share).
     */
    public function recordOutcome(
        EnterpriseWikiClaim $claim,
        int $customerId,
        string $originalContentOrigin,
        string $verificationStatus,
        ?string $reason,
    ): ?EnterpriseWikiCanonicalFact {
        $key = $this->identityKey($claim, $customerId, $originalContentOrigin);

        if ($key === null) {
            return null;
        }

        $fingerprint = $this->fingerprint($claim->claim_text);

        return DB::transaction(function () use ($key, $fingerprint, $claim, $verificationStatus, $reason): EnterpriseWikiCanonicalFact {
            $attributes = [
                'customer_id' => $key['customer_id'],
                'content_origin' => $key['content_origin'],
                'source_type' => $key['source_type'],
                'source_id' => $key['source_id'],
                'source_hash' => $key['source_hash'],
                'source_element_keys_hash' => $key['source_element_keys_hash'],
                'normalized_fingerprint' => $fingerprint,
            ];

            $fact = EnterpriseWikiCanonicalFact::query()->where($attributes)->lockForUpdate()->first();

            if ($fact === null) {
                try {
                    $fact = EnterpriseWikiCanonicalFact::query()->create(array_merge($attributes, [
                        'source_element_keys' => $key['source_element_keys'],
                        'canonical_text' => $claim->claim_text,
                        'verification_status' => $verificationStatus,
                        'verification_reason' => $reason,
                        'verified_at' => now(),
                    ]));
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) !== '23505') {
                        throw $e;
                    }

                    // Another worker recorded the exact same fact concurrently — reuse it rather
                    // than overwriting its result.
                    $fact = EnterpriseWikiCanonicalFact::query()->where($attributes)->firstOrFail();
                }
            }

            if ((int) $claim->canonical_fact_id !== (int) $fact->id) {
                $claim->update(['canonical_fact_id' => $fact->id]);
            }

            return $fact;
        });
    }

    /**
     * Maps a reusable fact's stored verdict onto the final content_origin a reusing claim should
     * take — the same branching EnterpriseWikiVerifyPageClaimsService::persist() already applies
     * for a fresh AI verdict, just read from the fact instead of a live API response.
     */
    public function resolveContentOriginForReuse(EnterpriseWikiCanonicalFact $fact): string
    {
        if ($fact->verification_status === EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED) {
            return EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED;
        }

        return $fact->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
            ? EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
            : EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT;
    }

    /**
     * If $newText no longer expresses the same fact as $fact, mark the fact stale so it stops
     * being offered for reuse to other occurrences until a human/technical process re-confirms
     * it — used when a specific occurrence (claim) is edited in a way that changes its meaning
     * (Del 9/Del 11: an edit must never silently change every other page's occurrence).
     */
    public function markStaleIfDiverged(EnterpriseWikiCanonicalFact $fact, string $newText): bool
    {
        if ($this->areEquivalentTexts($newText, $fact->canonical_text)) {
            return false;
        }

        $fact->update([
            'is_stale' => true,
            'stale_reason' => EnterpriseWikiCanonicalFact::STALE_REASON_DIVERGENT_OCCURRENCE,
        ]);

        Log::info('[WIKI_CANONICAL_FACT] Marked stale — an occurrence diverged from the canonical text.', [
            'canonical_fact_id' => $fact->id,
        ]);

        return true;
    }

    /**
     * Deterministic equivalence check — never the sole identity signal on its own (callers
     * always combine it with the Tier-1 hard key), but the actual paraphrase-vs-different-fact
     * decision within one Tier-1 candidate group.
     */
    public function areEquivalentTexts(string $a, string $b): bool
    {
        $normalizedA = $this->textNormalizer->normalize($a);
        $normalizedB = $this->textNormalizer->normalize($b);

        if ($normalizedA === $normalizedB) {
            return true;
        }

        if ($this->hasNormativeMarker($normalizedA) !== $this->hasNormativeMarker($normalizedB)) {
            return false;
        }

        $tokensA = $this->significantTokens($normalizedA);
        $tokensB = $this->significantTokens($normalizedB);

        if ($tokensA === [] || $tokensB === []) {
            return false;
        }

        $numbersA = $this->numberTokens($normalizedA);
        $numbersB = $this->numberTokens($normalizedB);

        // Neither side may assert a number (time, count, deadline, ...) the other doesn't
        // mention at all — this is what keeps "open 09-15" from merging with a claim that adds
        // an unrelated number of its own, even when the surrounding words overlap heavily.
        if ($numbersA !== [] && $numbersB !== [] && array_diff($numbersA, $numbersB) !== [] && array_diff($numbersB, $numbersA) !== []) {
            return false;
        }

        $frequencyA = $this->frequencyTokens($normalizedA);
        $frequencyB = $this->frequencyTokens($normalizedB);

        // A frequency/period word ("måned" vs "kvartal") is a distinguishing entity exactly like
        // a number — two claims sharing every other word but disagreeing on the period describe
        // different facts, not a wording variant.
        if ($frequencyA !== [] && $frequencyB !== [] && $frequencyA !== $frequencyB) {
            return false;
        }

        if (! $this->hasProportionateOverlap($tokensA, $tokensB)) {
            return false;
        }

        return $this->jaccard($tokensA, $tokensB) >= self::TOKEN_OVERLAP_THRESHOLD;
    }

    /**
     * @return array{customer_id: int, content_origin: string, source_type: ?string, source_id: ?int, source_hash: ?string, source_element_keys: list<string>, source_element_keys_hash: string}|null
     */
    private function identityKey(EnterpriseWikiClaim $claim, int $customerId, string $contentOrigin): ?array
    {
        if (! in_array($contentOrigin, [
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        ], true)) {
            return null;
        }

        $references = $claim->relationLoaded('sourceReferences')
            ? $claim->sourceReferences
            : $claim->sourceReferences()->get();

        $references = $references->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT);

        if ($references->isEmpty()) {
            return null;
        }

        $elementKeys = $references->pluck('source_element_key')->filter()->unique()->sort()->values()->all();

        if ($elementKeys === []) {
            return null;
        }

        $first = $references->first();

        return [
            'customer_id' => $customerId,
            'content_origin' => $contentOrigin,
            'source_type' => $first->source_type,
            'source_id' => $first->source_id !== null ? (int) $first->source_id : null,
            'source_hash' => $first->source_hash,
            'source_element_keys' => $elementKeys,
            'source_element_keys_hash' => hash('sha256', json_encode($elementKeys, JSON_THROW_ON_ERROR)),
        ];
    }

    private function fingerprint(string $text): string
    {
        return hash('sha256', $this->textNormalizer->normalize($text));
    }

    private function hasNormativeMarker(string $normalizedText): bool
    {
        foreach (self::NORMATIVE_MARKERS as $marker) {
            if (preg_match('/(?<!\p{L})'.preg_quote($marker, '/').'(?!\p{L})/u', $normalizedText) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recurrence/period words present in the text, as a sorted set — a closed vocabulary
     * treated as a distinguishing entity class exactly like numbers (Del 3): "hver måned" and
     * "hvert kvartal" share almost every other word but describe different facts.
     *
     * @return list<string>
     */
    private function frequencyTokens(string $normalizedText): array
    {
        $vocabulary = ['dag', 'dager', 'uke', 'uker', 'måned', 'måneder', 'kvartal', 'kvartaler', 'år', 'halvår', 'time', 'timer'];
        $found = [];

        foreach ($vocabulary as $word) {
            if (preg_match('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u', $normalizedText) === 1) {
                $found[] = $word;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Rejects a pair where one side carries a large amount of content the other side doesn't
     * mention at all — e.g. one sentence combining two independent facts will share every word
     * of a claim about just one of them, but roughly half its own tokens are still pure
     * additions the shorter claim never mentions. A true wording variant of the same single fact
     * does not exhibit this — both sides restate essentially the same content in different words.
     *
     * @param  list<string>  $tokensA
     * @param  list<string>  $tokensB
     */
    private function hasProportionateOverlap(array $tokensA, array $tokensB): bool
    {
        $setA = array_unique($tokensA);
        $setB = array_unique($tokensB);

        $extraInA = count(array_diff($setA, $setB)) / count($setA);
        $extraInB = count(array_diff($setB, $setA)) / count($setB);

        if (max($extraInA, $extraInB) > 0.6) {
            return false;
        }

        // A genuine wording variant reworks something on both sides — one side being an almost
        // pure subset of the other (near-zero extra tokens of its own) while the other side is
        // markedly longer looks instead like one claim is a single clause split out of a
        // compound sentence that also asserts the other side's content (Del 4).
        $longerCount = max(count($setA), count($setB));
        $shorterCount = max(1, min(count($setA), count($setB)));

        if (min($extraInA, $extraInB) <= 0.05 && ($longerCount / $shorterCount) >= 1.4) {
            return false;
        }

        return true;
    }

    /**
     * Words shorter than 3 characters are dropped as noise — EXCEPT purely numeric tokens
     * ("09", "15"), which are exactly the key entities (times, counts, deadlines) that must
     * survive into the overlap comparison regardless of their (often short) length.
     *
     * @return list<string>
     */
    private function significantTokens(string $normalizedText): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => (ctype_digit($token) || mb_strlen($token) > 2) && ! in_array($token, self::STOPWORDS, true),
        ));
    }

    /**
     * Normalized numeric tokens, deduplicated. A time written as "09.00"/"09:00" (exactly zero
     * minutes) is reduced to its bare hour ("9") so it compares equal to a range expression like
     * "kl. 09–15" that never writes the ":00" at all — both name the same hour. Any other
     * fractional/minute value ("15.30") is kept in full, so a genuine difference in minutes
     * still counts as a different number.
     *
     * @return list<string>
     */
    private function numberTokens(string $normalizedText): array
    {
        if (preg_match_all('/\d+(?:[.,:]\d+)?/u', $normalizedText, $matches) === false) {
            return [];
        }

        $numbers = array_map(function (string $number): string {
            $number = str_replace([',', ':'], '.', $number);

            if (preg_match('/^(\d+)\.0+$/', $number, $wholeHour) === 1) {
                $number = $wholeHour[1];
            }

            return ltrim($number, '0') ?: '0';
        }, $matches[0]);

        return array_values(array_unique($numbers));
    }

    /**
     * @param  list<string>  $tokensA
     * @param  list<string>  $tokensB
     */
    private function jaccard(array $tokensA, array $tokensB): float
    {
        $setA = array_unique($tokensA);
        $setB = array_unique($tokensB);

        $intersection = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
