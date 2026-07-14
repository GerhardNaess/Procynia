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

    /** At most this many of the highest-scoring candidate claims are ever sent to the AI. */
    private const MAX_CANDIDATE_CLAIMS = 12;

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

        return array_values(array_unique($tokens));
    }

    /**
     * @param  Collection<int|string, EnterpriseWikiClaim>  $claims
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, claim_id: int, claim_text: string}>
     */
    private function sourcesPayload(Collection $claims): array
    {
        return $claims
            ->map(fn (EnterpriseWikiClaim $claim): array => [
                'enterprise_wiki_page_id' => $claim->page->id,
                'page_title' => $claim->page->title,
                'page_slug' => $claim->page->slug,
                'page_type' => $claim->page->page_type,
                'claim_id' => $claim->id,
                'claim_text' => (string) $claim->claim_text,
            ])
            ->values()
            ->all();
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
