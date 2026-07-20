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

    /**
     * Words that signal a genuine best-practice RECOMMENDATION rather than a requirement or a
     * plain factual statement — deliberately excludes "skal"/"må" (NORMATIVE_MARKERS above):
     * those read as obligations/requirements ("Leverandøren skal levere...") which are exactly
     * the kind of contractual/factual-sounding phrasing that must NOT be waved through as
     * best_practice just because it contains a modal verb. A genuine improvement suggestion is
     * phrased as advice the customer may or may not already follow, not as a requirement anyone
     * must meet.
     */
    private const BEST_PRACTICE_MARKERS = [
        'bør', 'anbefales', 'anbefaling', 'anbefalt', 'kan vurdere', 'bør vurdere', 'vurdere å',
        'hensiktsmessig', 'kan bidra', 'kan redusere', 'kan forbedre', 'kan bedre', 'forbedring',
        'mulig å', 'god praksis', 'beste praksis', 'recommended', 'recommendation', 'best practice',
        'should consider', 'could', 'may want', 'may consider', 'suggested',
    ];

    /**
     * A party noun immediately followed by a present-tense factual verb — "Kunden har",
     * "Leverandøren følger" — asserts that a named party ALREADY has/does/is something, which is
     * exactly what best_practice text must never claim (Del 2/4's "ikke hevder at kunden allerede
     * gjør dette"). Deliberately a plain substring/regex check, not a full grammatical parse — it
     * is a secondary safety-net signal alongside BEST_PRACTICE_MARKERS, never the sole mechanism
     * (the AI's own declared content_origin, and human review of the resulting suggestion, remain
     * the primary decision).
     */
    private const CURRENT_STATE_SUBJECTS = [
        'kunden', 'leverandøren', 'systemet', 'tjenesten', 'virksomheten', 'selskapet',
        'servicedesken', 'servicedesk', 'organisasjonen', 'avdelingen',
        'the customer', 'the contractor', 'the supplier', 'the vendor', 'the service',
    ];

    private const CURRENT_STATE_VERBS = [
        'har', 'er', 'bruker', 'følger', 'tilbyr', 'benytter', 'opererer', 'driver', 'besvarer',
        'håndterer', 'gjennomfører', 'anvender', 'praktiserer',
        'has', 'is', 'uses', 'follows', 'offers', 'operates', 'provides', 'runs', 'handles',
    ];

    /**
     * Bilingual recurrence/period vocabulary → canonical category, used by frequencyTokens() so
     * "quarterly" and "hvert kvartal" (Del 6/Del 9) compare equal across languages instead of
     * being treated as two different, unrelated words.
     */
    private const FREQUENCY_VOCABULARY = [
        'dag' => 'day', 'dager' => 'day', 'daglig' => 'day', 'day' => 'day', 'days' => 'day', 'daily' => 'day',
        'uke' => 'week', 'uker' => 'week', 'ukentlig' => 'week', 'week' => 'week', 'weeks' => 'week', 'weekly' => 'week',
        'måned' => 'month', 'måneder' => 'month', 'månedlig' => 'month', 'month' => 'month', 'months' => 'month', 'monthly' => 'month',
        'kvartal' => 'quarter', 'kvartaler' => 'quarter', 'kvartalsvis' => 'quarter', 'quarter' => 'quarter', 'quarters' => 'quarter', 'quarterly' => 'quarter',
        'år' => 'year', 'årlig' => 'year', 'year' => 'year', 'years' => 'year', 'yearly' => 'year', 'annual' => 'year', 'annually' => 'year',
        'halvår' => 'half_year', 'halvårlig' => 'half_year', 'semiannual' => 'half_year', 'semi-annual' => 'half_year',
        // Deliberately excludes "time"/"timer"/"hour(s)": in SLA/tender text these words are used
        // constantly to mean an opening-hours window or a response-time duration, not a
        // recurrence period ("every 2 hours") — treating them as a frequency entity caused real
        // false-positive conflicts (e.g. "opening hours" vs "per month" reads as a frequency
        // mismatch even though neither claim nor source actually disagrees on any recurrence).
    ];

    /**
     * Bilingual negation markers (Del 3/Del 9 #14) — a claim must never silently drop, add, or
     * reverse a source's negation ("not available outside business hours" vs. "available outside
     * business hours").
     */
    private const NEGATION_MARKERS = [
        'ikke', 'aldri', 'uten', 'ingen', 'not', 'never', 'without', 'no longer',
    ];

    /**
     * Modality tiers — deliberately only two. A claim may never assert a STRONGER commitment
     * than its source excerpt (Del 3): "may/kan" upgraded to "shall/skal", or a recommendation
     * upgraded to a statement that something already exists, must both be rejected.
     *
     * "Obligatory" (skal/må/shall/must) and "unmarked/factual" (no modal marker at all, e.g.
     * "svartiden er 30 sekunder") are DELIBERATELY the SAME tier, not two different ones: a
     * contractual "the Contractor SHALL respond within 30 seconds" and a Wiki page factually
     * restating "responstiden er 30 sekunder" describe the identical binding commitment — the
     * Wiki text dropping the modal verb when summarizing an already-agreed requirement is normal
     * paraphrase, not an escalation. Only WEAK->STRONG actually changes meaning (Del 9 #11/#12):
     * turning a mere permission/suggestion ("may"/"kan"/"anbefales"/"recommended") into something
     * asserted as required or already true.
     */
    private const MODALITY_TIER_WEAK = 0;

    private const MODALITY_TIER_STRONG = 1;

    // Deliberately excludes English "should" and Norwegian "mulig å": both are ambiguous in
    // formal contract text — "the response time should be measured from..." and "det skal være
    // mulig å kontakte..." are prescriptive/binding in this document, not mere suggestions, so
    // including them caused real false-positive conflicts against genuinely matching claims.
    private const MODALITY_MARKERS_WEAK = [
        'anbefales', 'anbefaling', 'anbefalt', 'bør', 'recommended', 'recommendation', 'suggested',
        'kan', 'may', 'could', 'optional',
    ];

    /** Del 3/Del 9 #13 — a claim must not silently swap which party performs an action. */
    private const ACTOR_TERMS_SUPPLIER = [
        'leverandøren', 'leverandør', 'contractor', 'supplier', 'vendor',
    ];

    private const ACTOR_TERMS_CUSTOMER = [
        'kunden', 'kunde', 'customer', 'oppdragsgiver', 'client',
    ];

    /** Del 3/Del 9 #15 — a claim must not widen a source's case scope ("critical" → "all"). */
    private const SCOPE_TERMS_NARROW_CASE = [
        'kritisk', 'kritiske', 'critical', 'alvorlig', 'alvorlige',
    ];

    private const SCOPE_TERMS_BROAD_CASE = [
        'alle saker', 'alle henvendelser', 'samtlige saker', 'all cases', 'all requests', 'every case',
    ];

    /** Del 3/Del 9 #10/#16 — a claim must not widen a source's day/hour scope. */
    private const SCOPE_TERMS_NARROW_DAY = [
        'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'virkedager', 'hverdager', 'business days', 'weekdays', 'åpningstid', 'opening hours',
    ];

    private const SCOPE_TERMS_BROAD_DAY = [
        'alle dager', 'hele døgnet', 'døgnet rundt', 'daglig', '24/7', '24/7/365',
        'every day', 'around the clock', 'all day',
    ];

    /** Del 3 — currency amounts, checked as a distinguishing entity class like numbers. */
    private const CURRENCY_MARKERS = ['kr', 'nok', 'eur', 'usd', 'gbp', '$', '€', '£'];

    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
    ) {}

    /**
     * Del 2/4's deterministic backend guard on top of the AI's own content_origin/best_practice
     * choice: text only counts as a genuine best-practice suggestion when it carries a real
     * recommendation marker AND does not itself assert that a named party already has/does the
     * thing being suggested. Never the sole classification mechanism — the model's own declared
     * content_origin (block-level, inherited by the claim) remains the primary signal; this is
     * the check that stops a claim whose wording has drifted from advice ("bør") into an
     * unverified factual assertion ("har") from silently keeping the best_practice label.
     */
    public function isGenuineBestPracticeText(string $text): bool
    {
        $normalized = $this->textNormalizer->normalize($text);

        if ($normalized === '') {
            return false;
        }

        return $this->hasBestPracticeMarker($normalized) && ! $this->assertsCurrentPartyState($normalized);
    }

    public function hasBestPracticeMarker(string $normalizedText): bool
    {
        foreach (self::BEST_PRACTICE_MARKERS as $marker) {
            if ($this->containsWord($normalizedText, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function assertsCurrentPartyState(string $normalizedText): bool
    {
        foreach (self::CURRENT_STATE_SUBJECTS as $subject) {
            $pattern = '/(?<!\p{L})'.preg_quote($subject, '/').'(?!\p{L})\s+\p{L}*\s{0,1}('.
                implode('|', array_map(fn (string $verb): string => preg_quote($verb, '/'), self::CURRENT_STATE_VERBS)).
                ')(?!\p{L})/ui';

            if (preg_match($pattern, $normalizedText) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsWord(string $normalizedText, string $phrase): bool
    {
        if (str_contains($phrase, ' ')) {
            return str_contains($normalizedText, $phrase);
        }

        return preg_match('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/ui', $normalizedText) === 1;
    }

    /**
     * Run-38 fix: a claim is deterministically SUPPORTED — without any AI call at all — when its
     * own text is already, verbatim or near-verbatim (after the same markdown/whitespace/case
     * normalization EnterpriseWikiClaimAnchorTextNormalizer uses for anchor checking), a
     * CONTIGUOUS substring of its candidate source excerpts combined in source-document order.
     *
     * Deliberately a strict substring check, not a bag-of-words token-overlap measure: a
     * token-overlap score across a combined multi-paragraph corpus produces false positives for
     * misattribution — a claim naming the WRONG named entity/process can still score high word
     * overlap purely because the right words appear somewhere else in the combined text (verified
     * against real run-38 data: claim "Hendelseshåndtering bidrar til forutsigbar registrering,
     * prioritering og oppfølging..." scores 90% token coverage against its combined excerpts
     * despite that function actually being described for "Brukerstøtte", not "Hendelseshåndtering",
     * in the source — a substring check correctly finds no match and correctly leaves this claim
     * to full semantic AI judgment instead of silently approving a misattribution).
     *
     * Genuine paraphrase/synthesis across excerpts that is NOT a verbatim substring is
     * deliberately left to WikiClaimVerificationAiClient, whose prompt explicitly allows combining
     * excerpts about the same named entity while still rejecting misattribution and reinforcement
     * — a substring check has no way to make that judgment safely, so it does not try to.
     *
     * @param  list<string>  $orderedCandidateExcerpts  in source-document order
     */
    public function detectDeterministicSupport(string $claimText, array $orderedCandidateExcerpts): bool
    {
        $combined = trim(implode(' ', array_filter(
            $orderedCandidateExcerpts,
            static fn (string $excerpt): bool => trim($excerpt) !== '',
        )));

        if ($combined === '' || trim($claimText) === '') {
            return false;
        }

        return $this->textNormalizer->contains($combined, $claimText);
    }

    /**
     * Run-38 fix: restricts a candidate excerpt to only the sentence(s) sharing at least one
     * significant token with the claim, before it is combined with other cited excerpts for
     * detectDeterministicConflict(). Necessary now that a claim may legitimately combine evidence
     * from several full paragraphs (Del: combined-evidence synthesis): a long cited excerpt
     * routinely contains an entirely unrelated sentence elsewhere — different clause, different
     * topic — that happens to carry its own incidental negation/modality/scope marker. Comparing
     * the claim against the WHOLE excerpt treats that irrelevant sentence's marker as if it were
     * part of the evidence actually supporting the claim (verified against real run-38 data:
     * paragraph-11's closing sentence "... endringer gjennomføres uten å sette stabilitet ... i
     * fare" shares no token with a claim about ITIL governance, yet its "uten" alone triggered a
     * false negation_mismatch once paragraph-11 was legitimately cited alongside others).
     *
     * This never changes what counts as a conflict, only which text is considered "the specific
     * excerpt(s) cited as support" — detectDeterministicConflict() itself, and every actor/
     * negation/modality/number/scope rule inside it, is untouched. Falls back to the full excerpt
     * when no sentence shares a token with the claim (a stricter, safer default than silently
     * discarding an excerpt this check cannot confidently narrow down).
     */
    public function filterToRelevantSentences(string $claimText, string $excerptText): string
    {
        $claimTokens = array_unique($this->significantTokens($this->textNormalizer->normalize($claimText)));

        if ($claimTokens === [] || trim($excerptText) === '') {
            return $excerptText;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($excerptText), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $relevant = array_values(array_filter($sentences, function (string $sentence) use ($claimTokens): bool {
            $sentenceTokens = $this->significantTokens($this->textNormalizer->normalize($sentence));

            return array_intersect($claimTokens, $sentenceTokens) !== [];
        }));

        return $relevant !== [] ? implode(' ', $relevant) : $excerptText;
    }

    /**
     * Run-38 fix: a deterministic backstop against misattribution, for when the AI's own
     * self-reported "subject_entity" check (WikiClaimVerificationAiClient) is not reliable enough
     * on its own — verified against real production data (run 38, claim 4048): the model
     * repeatedly self-reported "subject_entity: match" for a claim that borrows "Brukerstøtte"'s
     * specifically-described function ("registrering, prioritering og oppfølging") and attaches
     * it to "Hendelseshåndtering", which is named only in a DIFFERENT excerpt's generic list of
     * related ITIL practices — even after two rounds of explicit prompt refinement including a
     * worked example of exactly this pattern.
     *
     * Only relevant once 2+ excerpts are combined (Del: combined-evidence synthesis) — a single-
     * excerpt claim was never affected by this failure mode before that fix. Flags a likely
     * misattribution when a cited excerpt does NOT mention the claim's own leading subject term at
     * all, yet still covers a large share of the claim's OTHER distinguishing tokens — i.e. an
     * excerpt that describes almost the whole claim in detail while never naming the claim's
     * subject is a strong signal that description belongs to a different, unnamed-here entity.
     *
     * @param  list<string>  $citedExcerptTexts  the (already claim-relevant-sentence-filtered) text
     *                                           of each excerpt the AI cited as support
     */
    public function detectSubjectMismatch(string $claimText, array $citedExcerptTexts): bool
    {
        if (count($citedExcerptTexts) < 2) {
            return false;
        }

        $claimNorm = $this->textNormalizer->normalize($claimText);
        $claimTokens = $this->significantTokens($claimNorm);

        if (count($claimTokens) < 2) {
            return false;
        }

        $subjectTerm = $claimTokens[0];
        $otherTokens = array_values(array_unique(array_slice($claimTokens, 1)));

        if ($otherTokens === []) {
            return false;
        }

        foreach ($citedExcerptTexts as $excerpt) {
            $excerptNorm = $this->textNormalizer->normalize($excerpt);

            if ($excerptNorm === '' || $this->containsWord($excerptNorm, $subjectTerm)) {
                continue;
            }

            $covered = 0;

            foreach ($otherTokens as $token) {
                if ($this->containsWord($excerptNorm, $token)) {
                    $covered++;
                }
            }

            if (($covered / count($otherTokens)) >= 0.4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Del 3's conservative safety net for cross-language/paraphrase verification: a deterministic,
     * non-AI check for a genuine MEANING change between a claim and the specific source excerpt(s)
     * the AI verifier cited as support. This never runs against the whole candidate pool — only
     * against the excerpt(s) actually cited — so it can't be defeated by an unrelated candidate
     * elsewhere in the same block "diluting" a real conflict (Del 5).
     *
     * Deliberately conservative: an AI verdict of "supported"/"partially_supported" must never
     * override a conflict found here (Del 3) — the caller downgrades to not_supported instead.
     * Returns the first conflict found, or null when no conflict is detected (which is not proof
     * of support — it only means this specific safety net found nothing wrong).
     */
    public function detectDeterministicConflict(string $claimText, string $supportingText): ?string
    {
        $claimNorm = $this->textNormalizer->normalize($claimText);
        $sourceNorm = $this->textNormalizer->normalize($supportingText);

        if ($claimNorm === '' || $sourceNorm === '') {
            return null;
        }

        $claimNumbers = $this->numberTokens($claimNorm);
        $sourceNumbers = $this->numberTokens($sourceNorm);

        if ($claimNumbers !== [] && $sourceNumbers !== [] && array_diff($claimNumbers, $sourceNumbers) !== []) {
            return 'number_mismatch';
        }

        $claimFrequency = $this->frequencyTokens($claimNorm);
        $sourceFrequency = $this->frequencyTokens($sourceNorm);

        if ($claimFrequency !== [] && $sourceFrequency !== [] && $claimFrequency !== $sourceFrequency) {
            return 'frequency_mismatch';
        }

        if ($this->hasNegationMarker($claimNorm) !== $this->hasNegationMarker($sourceNorm)) {
            return 'negation_mismatch';
        }

        if ($this->modalityTier($claimNorm) > $this->modalityTier($sourceNorm)) {
            return 'modality_mismatch';
        }

        if ($this->actorMismatch($claimNorm, $sourceNorm)) {
            return 'actor_mismatch';
        }

        if ($this->scopeMismatch($claimNorm, $sourceNorm)) {
            return 'scope_mismatch';
        }

        if ($this->currencyMismatch($claimNorm, $sourceNorm)) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * "ikke-kritisk"/"ikke-kritiske" ("non-critical") is an ordinary hyphenated adjective, not a
     * negation of anything — excluded explicitly, since a bare word-boundary match on "ikke"
     * would otherwise treat every mention of a non-critical case as if the claim negated
     * something, causing false conflicts against claims that are actually a faithful match.
     *
     * Run-38 fix: a rhetorical "ikke X, men Y" / "not X, but Y" CONTRAST (e.g. "Leverandøren
     * bruker ITIL ikke som en teoretisk modell, men som et styringsverktøy...") asserts Y — it is
     * not a negation of any fact a claim would need to also state, unlike a plain negation
     * ("ikke tilgjengelig i helgene"). This became a real false-positive source once claims were
     * allowed to combine evidence from several candidate excerpts (Del: combined-evidence
     * synthesis) — a long, multi-sentence excerpt cited only for its unrelated Y-clause could
     * still contain an incidental "ikke ... men" elsewhere, wrongly blocking a claim that never
     * negates anything itself. Only the contrastive span itself is stripped; a genuine standalone
     * negation anywhere else in the same text still counts.
     */
    private function hasNegationMarker(string $normalizedText): bool
    {
        $withoutNegatedCompounds = preg_replace('/\bikke-/u', '', $normalizedText) ?? $normalizedText;

        $withoutContrast = preg_replace('/\bikke\b[^.!?]*?\bmen\b/ui', '', $withoutNegatedCompounds)
            ?? $withoutNegatedCompounds;
        $withoutContrast = preg_replace('/\bnot\b[^.!?]*?\bbut\b/ui', '', $withoutContrast) ?? $withoutContrast;

        foreach (self::NEGATION_MARKERS as $marker) {
            if ($this->containsWord($withoutContrast, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * MODALITY_TIER_STRONG (binding/factual) unless a WEAK (permissive/recommendation) marker is
     * present — text with an obligatory marker ("skal"/"shall"/"must") and unmarked declarative
     * text ("Kunden har...", "The service is...") are the SAME tier: both describe a binding
     * commitment, never a mere suggestion or permission.
     */
    private function modalityTier(string $normalizedText): int
    {
        foreach (self::MODALITY_MARKERS_WEAK as $marker) {
            if ($this->containsWord($normalizedText, $marker)) {
                return self::MODALITY_TIER_WEAK;
            }
        }

        return self::MODALITY_TIER_STRONG;
    }

    /**
     * True only when the claim names EXCLUSIVELY one party (supplier or customer) and the cited
     * source excerpt names EXCLUSIVELY the other — deliberately conservative, since real source
     * text routinely names both parties in the same sentence (e.g. "the Contractor shall report
     * to the Customer"), which must never be flagged as a mismatch.
     */
    private function actorMismatch(string $claimNormalized, string $sourceNormalized): bool
    {
        $claimHasSupplier = $this->containsAny($claimNormalized, self::ACTOR_TERMS_SUPPLIER);
        $claimHasCustomer = $this->containsAny($claimNormalized, self::ACTOR_TERMS_CUSTOMER);
        $sourceHasSupplier = $this->containsAny($sourceNormalized, self::ACTOR_TERMS_SUPPLIER);
        $sourceHasCustomer = $this->containsAny($sourceNormalized, self::ACTOR_TERMS_CUSTOMER);

        $claimOnlySupplier = $claimHasSupplier && ! $claimHasCustomer;
        $claimOnlyCustomer = $claimHasCustomer && ! $claimHasSupplier;
        $sourceOnlySupplier = $sourceHasSupplier && ! $sourceHasCustomer;
        $sourceOnlyCustomer = $sourceHasCustomer && ! $sourceHasSupplier;

        return ($claimOnlySupplier && $sourceOnlyCustomer) || ($claimOnlyCustomer && $sourceOnlySupplier);
    }

    /**
     * True when the claim widens a scope the source excerpt keeps narrow — "critical cases"
     * generalized to "all cases", or specific weekdays/business hours generalized to "every day"
     * (Del 3/Del 9 #10, #15, #16). Conservative in the same way as actorMismatch(): only flags
     * when the source contains the narrow term but never the broad one.
     */
    private function scopeMismatch(string $claimNormalized, string $sourceNormalized): bool
    {
        $caseWidened = $this->containsAny($claimNormalized, self::SCOPE_TERMS_BROAD_CASE)
            && $this->containsAny($sourceNormalized, self::SCOPE_TERMS_NARROW_CASE)
            && ! $this->containsAny($sourceNormalized, self::SCOPE_TERMS_BROAD_CASE);

        if ($caseWidened) {
            return true;
        }

        return $this->containsAny($claimNormalized, self::SCOPE_TERMS_BROAD_DAY)
            && $this->containsAny($sourceNormalized, self::SCOPE_TERMS_NARROW_DAY)
            && ! $this->containsAny($sourceNormalized, self::SCOPE_TERMS_BROAD_DAY);
    }

    /**
     * True only when claim and source each name a DIFFERENT, non-empty set of currency markers —
     * e.g. a claim stating an amount in NOK when the source excerpt states it in EUR.
     */
    private function currencyMismatch(string $claimNormalized, string $sourceNormalized): bool
    {
        $claimCurrencies = $this->foundTerms($claimNormalized, self::CURRENCY_MARKERS);
        $sourceCurrencies = $this->foundTerms($sourceNormalized, self::CURRENCY_MARKERS);

        return $claimCurrencies !== [] && $sourceCurrencies !== [] && $claimCurrencies !== $sourceCurrencies;
    }

    /**
     * @param  list<string>  $terms
     */
    private function containsAny(string $normalizedText, array $terms): bool
    {
        return $this->foundTerms($normalizedText, $terms) !== [];
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function foundTerms(string $normalizedText, array $terms): array
    {
        $found = [];

        foreach ($terms as $term) {
            if ($this->containsWord($normalizedText, $term)) {
                $found[] = $term;
            }
        }

        sort($found);

        return $found;
    }

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
     * Recurrence/period words present in the text, normalized to a bilingual canonical category
     * ("quarterly" and "hvert kvartal" both become "quarter") and returned as a sorted set — a
     * closed vocabulary treated as a distinguishing entity class exactly like numbers (Del 3):
     * "hver måned" and "hvert kvartal" share almost every other word but describe different
     * facts. Canonicalizing across languages (rather than comparing the raw words) is what lets
     * a Norwegian claim reuse a canonical fact recorded from an English source clause, and vice
     * versa (Del 6), without treating a same-meaning translation as a differing frequency.
     *
     * @return list<string>
     */
    private function frequencyTokens(string $normalizedText): array
    {
        $found = [];

        foreach (self::FREQUENCY_VOCABULARY as $word => $category) {
            if (preg_match('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/ui', $normalizedText) === 1) {
                $found[] = $category;
            }
        }

        $found = array_values(array_unique($found));
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
