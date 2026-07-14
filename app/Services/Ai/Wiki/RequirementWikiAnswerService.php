<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates a Wiki-based answer for an already-extracted requirement (Fase 9 — "Generer
 * Wiki-svar"), using only Enterprise Wiki content that is approved and available for the
 * requirement's own customer environment.
 *
 * This is deliberately a fully separate flow from the existing answer-draft engine
 * (RequirementAnswerDraftService): no new requirement extraction, no parallel requirement table,
 * no dependency on KnowledgeItem/KnowledgeItemChunk, no reuse of the existing Knowledge Base/RAG
 * retrieval, and no writes to answer_draft_* — every Wiki answer is persisted on its own
 * SavedNoticeAiRequirementWikiAnswer row (see that model's fillable columns).
 *
 * "Approved and available for the customer environment" is defined exactly the way
 * WikiController::visibleStatuses() defines it for an anonymous/base-level reader (no elevated
 * draft/pending-review visibility): EnterpriseWikiPage::STATUS_APPROVED, scoped to the
 * requirement's own customer_id. Claims are additionally required to belong to their page's
 * CURRENT version and to carry no conflict_flag — a claim flagged as contradicting other Wiki
 * content is not "available" material to answer from, even if its page is approved.
 */
class RequirementWikiAnswerService
{
    /** Minimum number of shared meaningful tokens between requirement text and a claim to consider it relevant. */
    private const MIN_TOKEN_OVERLAP = 2;

    /** Tokens shorter than this are treated as noise and ignored for relevance scoring. */
    private const MIN_TOKEN_LENGTH = 4;

    /**
     * At most this many of the highest-scoring candidate claims are ever sent to the AI. Raised
     * from an earlier 12 after a real-world example showed 29-49 claims clearing the relevance
     * bar (depending on tokenization) for one requirement — 12 was cutting off genuinely relevant
     * material via an arbitrary tie-break, not because 12 is inherently the right ceiling. 20 stays
     * a small, bounded slice of the customer's Wiki (typically well under 5% of eligible claims),
     * not "most of the Wiki" being sent — see RequirementWikiAnswerServiceTest for the token-count
     * assertion that keeps this honest.
     */
    private const MAX_CANDIDATE_CLAIMS = 20;

    /**
     * Common Norwegian inflectional suffixes stripped from the END of a token before matching —
     * longest first, so "hendelsene" strips to "hendels" via "ene" rather than stopping at "en".
     * Deliberately simple (not a full stemmer): this only exists to stop a plural/definite form in
     * a claim ("prosesser"/"prosessene") from missing a singular/definite form in the requirement
     * ("prosessen"), which is a real, observed cause of under-matching — not a general-purpose NLP
     * component.
     */
    private const INFLECTIONAL_SUFFIXES = ['ene', 'ens', 'en', 'er', 'et'];

    /**
     * Small, explicit Norwegian/English terminology groups for common Wiki/ITIL vocabulary — each
     * group's members are treated as interchangeable for relevance scoring only (a requirement
     * written in English ITIL terms must still match a claim phrased in Norwegian, and vice versa).
     * Deliberately a short, hand-picked list, not a general synonym dictionary or embedding lookup.
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

    public function __construct(
        private readonly RequirementWikiAnswerAiClient $aiClient,
    ) {}

    /**
     * Purpose: Generate (or regenerate) the Wiki-based answer for one requirement.
     * Inputs: The requirement, the customer id it belongs to (never trusted from the requirement
     *         itself — always the owning SavedNotice's own customer_id, resolved by the caller),
     *         the language to answer in, and the user triggering generation.
     * Returns: The persisted (created or updated in place) Wiki-answer row.
     * Side effects: Writes exactly one row to saved_notice_ai_requirement_wiki_answers, upserted
     *               by saved_notice_ai_requirement_id. Never touches saved_notice_ai_requirements
     *               itself or any answer_draft_* column.
     *
     * @throws RuntimeException when candidate claims exist but Enterprise Wiki AI generation is disabled
     */
    public function generate(
        SavedNoticeAiRequirement $requirement,
        int $customerId,
        string $languageCode,
        ?int $userId = null,
    ): SavedNoticeAiRequirementWikiAnswer {
        $candidates = $this->relevantCandidateClaims($requirement, $customerId);

        if ($candidates->isEmpty()) {
            return $this->persist($requirement, [
                'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE,
                'answer_text' => null,
                'missing_summary' => 'Ingen godkjent Wiki-informasjon er tilgjengelig for dette kravet i kundemiljøet.',
                'sources' => [],
                'model' => null,
            ], $userId);
        }

        if (! RequirementWikiAnswerAiClient::isAvailable()) {
            throw new RuntimeException('RequirementWikiAnswerService: Enterprise Wiki AI generation is not enabled for this environment.');
        }

        $candidateClaims = $candidates->map(fn (EnterpriseWikiClaim $claim): array => [
            'claim_key' => 'claim-'.$claim->id,
            'claim_text' => (string) $claim->claim_text,
        ])->values()->all();

        $result = $this->aiClient->generateAnswer(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $candidateClaims,
            $languageCode,
        );

        $usedClaimKeys = $result['used_claim_keys'];
        $claimsByKey = $candidates->keyBy(fn (EnterpriseWikiClaim $claim): string => 'claim-'.$claim->id);

        // A non-'none' answer with no explicit used_claim_keys still needs transparent sourcing —
        // fall back to every candidate actually sent, rather than silently reporting zero sources.
        // Deliberately NOT Collection::only() here — Eloquent\Collection overrides only() to mean
        // "models with these primary keys", not "these array keys" (which is what keyBy() above
        // actually produced); filter() with a key-aware callback keeps the intended array-key semantics.
        $sourceClaims = $result['coverage_status'] === SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE
            ? new Collection
            : ($usedClaimKeys !== []
                ? $claimsByKey->filter(fn (EnterpriseWikiClaim $claim, string $key): bool => in_array($key, $usedClaimKeys, true))
                : $claimsByKey);

        return $this->persist($requirement, [
            'coverage_status' => $result['coverage_status'],
            'answer_text' => $result['answer_text'],
            'missing_summary' => $result['missing_summary'],
            'sources' => $this->sourcesPayload($sourceClaims),
            'model' => 'gpt-4.1-mini',
        ], $userId);
    }

    /**
     * Purpose: Retrieve the approved, customer-scoped Wiki claims relevant to a requirement's text.
     * Inputs: The requirement and its customer id.
     * Returns: The highest-scoring candidate claims (at most MAX_CANDIDATE_CLAIMS), or an empty
     *          collection when nothing scores above the minimum relevance threshold.
     * Side effects: None.
     *
     * @return Collection<int, EnterpriseWikiClaim>
     */
    private function relevantCandidateClaims(SavedNoticeAiRequirement $requirement, int $customerId): Collection
    {
        $eligibleClaims = EnterpriseWikiClaim::query()
            ->whereHas('page', function ($query) use ($customerId): void {
                $query->where('customer_id', $customerId)
                    ->where('status', EnterpriseWikiPage::STATUS_APPROVED);
            })
            ->whereHas('version', function ($query): void {
                $query->where('is_current', true);
            })
            ->where('conflict_flag', false)
            ->with('page')
            ->get();

        if ($eligibleClaims->isEmpty()) {
            return $eligibleClaims;
        }

        $requirementTokens = $this->tokenize(trim(
            ($requirement->requirement_identifier ?? '').' '.$requirement->requirement_text,
        ));

        if ($requirementTokens === []) {
            return new Collection;
        }

        $scored = $eligibleClaims
            ->map(function (EnterpriseWikiClaim $claim) use ($requirementTokens): array {
                $claimTokens = $this->tokenize((string) $claim->claim_text);
                $overlap = count(array_intersect($requirementTokens, $claimTokens));

                return ['claim' => $claim, 'overlap' => $overlap];
            })
            ->filter(fn (array $scored): bool => $scored['overlap'] >= self::MIN_TOKEN_OVERLAP)
            ->sortByDesc('overlap')
            ->take(self::MAX_CANDIDATE_CLAIMS)
            ->map(fn (array $scored): EnterpriseWikiClaim => $scored['claim'])
            ->values();

        return new Collection($scored->all());
    }

    /**
     * Purpose: Tokenize text into a normalized, deduplicated set of meaningful words for relevance
     * scoring — lowercased, split on non-letter/non-digit boundaries (which already normalizes
     * hyphens/punctuation), short (noise) tokens dropped, common Norwegian inflectional suffixes
     * stripped (see INFLECTIONAL_SUFFIXES), and known Norwegian/English ITIL-terminology synonyms
     * expanded in place (see TERMINOLOGY_GROUPS) so a requirement written in one language/term
     * still matches a claim using the other.
     * Inputs: Raw text.
     * Returns: A deduplicated list of normalized tokens.
     * Side effects: None.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];

        $tokens = array_filter(
            $parts,
            fn (string $token): bool => mb_strlen($token, 'UTF-8') >= self::MIN_TOKEN_LENGTH,
        );

        $stemmed = array_map(fn (string $token): string => $this->stemToken($token), $tokens);
        $expanded = $this->expandTerminologySynonyms($stemmed);

        return array_values(array_unique($expanded));
    }

    /**
     * Purpose: Strip one trailing Norwegian inflectional suffix from a token, longest match first.
     * Inputs: A lowercased token.
     * Returns: The stemmed token, or the original token when no suffix applies or stripping it
     *          would leave an unreasonably short (likely wrong) stem.
     * Side effects: None.
     */
    private function stemToken(string $token): string
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
     * Purpose: Expand a token list with known Norwegian/English terminology synonyms.
     * Inputs: A list of (already stemmed) tokens.
     * Returns: The same tokens plus, for each token that is a member of a TERMINOLOGY_GROUPS
     *          group, every other member of that group.
     * Side effects: None.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function expandTerminologySynonyms(array $tokens): array
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

    /**
     * Purpose: Convert candidate claims into the requirement's Wiki-answer sources, one entry per
     * distinct Wiki page (never per claim) — the stable identity for deduplication is
     * enterprise_wiki_page_id, never the display title, since two different approved pages could
     * legitimately share a title.
     * Inputs: The claims actually used for (or sent as candidates for) the answer.
     * Returns: One source entry per distinct page, each listing every claim id from that page that
     *          contributed, in encounter order.
     * Side effects: None.
     *
     * @param  Collection<int|string, EnterpriseWikiClaim>  $claims
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, claim_ids: list<int>}>
     */
    private function sourcesPayload(Collection $claims): array
    {
        $byPageId = [];

        foreach ($claims as $claim) {
            $pageId = $claim->page->id;

            if (! isset($byPageId[$pageId])) {
                $byPageId[$pageId] = [
                    'enterprise_wiki_page_id' => $pageId,
                    'page_title' => $claim->page->title,
                    'page_slug' => $claim->page->slug,
                    'page_type' => $claim->page->page_type,
                    'claim_ids' => [],
                ];
            }

            $byPageId[$pageId]['claim_ids'][] = $claim->id;
        }

        return array_values($byPageId);
    }

    private function persist(SavedNoticeAiRequirement $requirement, array $attributes, ?int $userId): SavedNoticeAiRequirementWikiAnswer
    {
        return DB::transaction(function () use ($requirement, $attributes, $userId): SavedNoticeAiRequirementWikiAnswer {
            return SavedNoticeAiRequirementWikiAnswer::query()->updateOrCreate(
                ['saved_notice_ai_requirement_id' => $requirement->id],
                array_merge($attributes, [
                    'generated_by_user_id' => $userId,
                    'generated_at' => now(),
                ]),
            );
        });
    }
}
