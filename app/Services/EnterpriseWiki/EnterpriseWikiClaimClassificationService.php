<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative owner of the transition to an EnterpriseWikiClaim's final
 * content_origin. Before this service existed, five independent code paths
 * (EnterpriseWikiExtractPageClaimsService, EnterpriseWikiVerifyPageClaimsService,
 * EnterpriseWikiClaimCanonicalizationService's reuse path, EnterpriseWikiClaimIntegrityRepairService,
 * EnterpriseWikiRunBestPracticeReevaluationService) each wrote content_origin directly, with no
 * shared rule for whether one was allowed to override what another had already decided — so a
 * claim's final classification depended on whichever of the five happened to write last, not on
 * any single, traceable decision.
 *
 * Authority model — reuses EnterpriseWikiClaim.verified_at, no new column:
 *
 *   - verified_at === null means the claim has only ever received a PROVISIONAL classification
 *     (extraction's inheritance from its generation block). Any source may set it.
 *   - verified_at !== null means the claim has an AUTHORITATIVE decision — one actually reached by
 *     verifying the claim (a real AI verdict, a deterministic verbatim match, a best-practice
 *     block reconfirmation, or a reused canonical fact's own prior verification). Only
 *     SOURCE_VERIFICATION and SOURCE_MANUAL_REVERIFICATION may change content_origin at that
 *     point — those are, respectively, the live pipeline's one-time verification pass and the
 *     explicit, single-run `wiki:reevaluate-run-claim-verification` re-verification command. Every
 *     other source (integrity repair, best-practice reevaluation) may confirm a matching proposal
 *     or correct a purely structural field (content_block_key re-anchoring), but can never change
 *     what the claim was authoritatively decided to be — see apply().
 *
 * This is deliberately a THIN transition guard, not a reimplementation of any service's own
 * decision logic: EnterpriseWikiVerifyPageClaimsService still decides what a claim's verdict-based
 * content_origin/review_reason/review_metadata should be; EnterpriseWikiClaimIntegrityRepairService
 * still decides what its structural-integrity rule would classify a claim as. Both simply hand
 * that decision to apply()/classifyClaim() instead of writing it directly, so the SAME rule about
 * whether the write is actually allowed applies everywhere, in one place.
 *
 * Finding #5646: a proposal of content_origin=best_practice is additionally checked here — the
 * ONE place every such proposal passes through, regardless of which of the five sources made it —
 * against EnterpriseWikiNavigationReferenceDetector. A claim whose anchor text is nothing but a
 * bare pointer to another Wiki page ("For detaljert flyt og rollebeskrivelser, se [[...]].") is
 * never a professional recommendation, never a documented fact, and never an unsupported
 * assertion — it does not semantically fit any of the three real content_origin categories, so it
 * is resolved to the existing, currently-unused CONTENT_ORIGIN_UNCLASSIFIED instead of forcing it
 * into one. That value is already excluded from every finding/QA-signal query (see
 * EnterpriseWikiRunFindingsService, EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals())
 * and from EnterpriseWikiClaim::needsSourceWarning() — a navigation-only claim simply produces no
 * finding at all, rather than a correctly-labeled but still-visible one. See resolveContentOrigin().
 */
class EnterpriseWikiClaimClassificationService
{
    /** The live verify() pipeline's own AI-verdict-based decision (including its deterministic
     *  fast paths: verbatim match, best-practice block reconfirmation, reused canonical fact). */
    public const SOURCE_VERIFICATION = 'verification';

    /** The explicit, single-run `wiki:reevaluate-run-claim-verification` re-verification command —
     *  the one sanctioned way to change an already-authoritative decision after the fact. */
    public const SOURCE_MANUAL_REVERIFICATION = 'manual_reverification';

    /** Claim extraction's provisional classification, inherited from the generation block. */
    public const SOURCE_EXTRACTION = 'extraction';

    /** `wiki:repair-claim-integrity` — structural-integrity recovery for claims never verified. */
    public const SOURCE_INTEGRITY_REPAIR = 'integrity_repair';

    /** `wiki:reevaluate-run-best-practice-claims` — best-practice recovery for claims never verified. */
    public const SOURCE_BEST_PRACTICE_REEVALUATION = 'best_practice_reevaluation';

    private const SOURCES_THAT_MAY_OVERRIDE_AUTHORITATIVE = [
        self::SOURCE_VERIFICATION,
        self::SOURCE_MANUAL_REVERIFICATION,
    ];

    /** The proposed content_origin was written; the claim changed. */
    public const RESULT_APPLIED = 'applied';

    /** Nothing was written — the claim's stored state already matched the proposal. */
    public const RESULT_ALREADY_CORRECT = 'already_correct';

    /** Nothing was written — the claim already has an authoritative decision this source is not
     *  allowed to change, and the proposal disagreed with it. */
    public const RESULT_REJECTED_AUTHORITATIVE = 'rejected_authoritative';

    /** Nothing was written — the caller's expectation of the claim's prior state (expected_verified_at)
     *  no longer matches; a concurrent write already moved the claim on. */
    public const RESULT_STALE = 'stale';

    /** No claim exists with the given id. */
    public const RESULT_NOT_FOUND = 'not_found';

    public function __construct(
        private readonly EnterpriseWikiNavigationReferenceDetector $navigationReferenceDetector,
    ) {}

    /**
     * Read-only preview of the authority gate in apply(), for a dry-run report (--apply not
     * passed) that still needs to tell an operator accurately which claims WOULD be protected
     * rather than reclassified. Never writes anything.
     */
    public function wouldBeRejectedAsAuthoritative(EnterpriseWikiClaim $claim, string $source, string $proposedContentOrigin): bool
    {
        $proposedContentOrigin = $this->resolveContentOrigin($claim, $proposedContentOrigin);
        $hasAuthoritativeDecision = $claim->verified_at !== null;
        $mayOverrideAuthoritative = in_array($source, self::SOURCES_THAT_MAY_OVERRIDE_AUTHORITATIVE, true);
        $proposesClassificationChange = $proposedContentOrigin !== $claim->content_origin;
        $isStructuralErrorTransition = $proposedContentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR
            || $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR;

        return $hasAuthoritativeDecision && $proposesClassificationChange && ! $mayOverrideAuthoritative && ! $isStructuralErrorTransition;
    }

    /**
     * Convenience wrapper for callers with no transaction/lock of their own (repair,
     * reevaluation): opens a short transaction, loads the claim under lockForUpdate(), and
     * delegates to apply(). The AI call (if any) must already be complete before this is invoked —
     * never hold this lock across an AI call.
     *
     * @param  array<string, mixed>  $proposed
     * @return array{result: string, claim: ?EnterpriseWikiClaim}
     */
    public function classifyClaim(int $claimId, string $source, array $proposed): array
    {
        return DB::transaction(function () use ($claimId, $source, $proposed): array {
            $claim = EnterpriseWikiClaim::query()->whereKey($claimId)->lockForUpdate()->first();

            if ($claim === null) {
                return $this->result(self::RESULT_NOT_FOUND, null);
            }

            return $this->apply($claim, $source, $proposed);
        });
    }

    /**
     * Core decision point. $claim MUST already be loaded under lockForUpdate() inside an open
     * transaction — callers that manage their own transaction/lock (extraction's and
     * verification's existing reservation-token protocol) pass their own already-locked instance
     * directly, so this never double-locks or nests a redundant transaction; classifyClaim() above
     * is the wrapper for callers with no lock of their own.
     *
     * @param  array{
     *     content_origin?: string,
     *     content_block_key?: string,
     *     confidence?: string,
     *     review_reason?: string|null,
     *     review_metadata?: array<string, mixed>|null,
     *     generation_issue?: string|null,
     *     canonical_fact_id?: int|null,
     *     authoritative?: bool,
     *     expected_verified_at?: Carbon|null,
     * }  $proposed  authoritative defaults to true — whether this write, if allowed, should mark
     *              the claim as having an authoritative decision (sets verified_at). Extraction's
     *              provisional write passes authoritative: false so verified_at stays null.
     * @return array{result: string, claim: ?EnterpriseWikiClaim}
     */
    public function apply(EnterpriseWikiClaim $claim, string $source, array $proposed): array
    {
        if (array_key_exists('expected_verified_at', $proposed)
            && ! $this->verifiedAtMatches($claim, $proposed['expected_verified_at'])
        ) {
            return $this->result(self::RESULT_STALE, $claim);
        }

        if (array_key_exists('content_origin', $proposed)) {
            $proposed = $this->applyNavigationOnlyDowngrade($claim, $proposed);
        }

        $hasAuthoritativeDecision = $claim->verified_at !== null;
        $mayOverrideAuthoritative = in_array($source, self::SOURCES_THAT_MAY_OVERRIDE_AUTHORITATIVE, true);
        $proposedOrigin = $proposed['content_origin'] ?? $claim->content_origin;
        $proposesClassificationChange = array_key_exists('content_origin', $proposed)
            && $proposedOrigin !== $claim->content_origin;

        // internal_error is a structural/technical flag ("this claim's anchor is currently
        // broken"), never a deliberate content decision — transitioning a claim INTO it (the
        // claim's grounding just broke) or OUT of it (repair found the grounding valid again, and
        // must classify it for the first time, same as any never-verified claim) is never gated by
        // source. Only a change between two genuine content classifications (source_based,
        // best_practice, unsupported_generated_content) is protected.
        $isStructuralErrorTransition = $proposedOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR
            || $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR;

        if ($hasAuthoritativeDecision
            && array_key_exists('content_origin', $proposed)
            && ! $mayOverrideAuthoritative
            && ! $isStructuralErrorTransition
        ) {
            // This source may not touch what verification already, authoritatively, decided —
            // whether it's proposing an actual change (rejected) or merely happens to agree with
            // the existing value (already correct, but still never allowed to re-tag
            // decision_source/review_metadata as if IT were the one that decided this). A purely
            // structural correction (content_block_key re-anchoring) is a different concern — the
            // claim's grounding location, not its classification — and is still allowed through
            // even here, in either case.
            if ($this->applyStructuralOnlyChange($claim, $proposed)) {
                return $this->result(self::RESULT_APPLIED, $claim->fresh());
            }

            return $this->result(
                $proposesClassificationChange ? self::RESULT_REJECTED_AUTHORITATIVE : self::RESULT_ALREADY_CORRECT,
                $claim,
            );
        }

        $fields = $proposed;
        unset($fields['expected_verified_at']);
        $authoritative = $fields['authoritative'] ?? true;
        unset($fields['authoritative']);

        if (array_key_exists('review_metadata', $fields) && is_array($fields['review_metadata'])) {
            $fields['review_metadata']['decision_source'] = $source;
        } elseif (array_key_exists('review_metadata', $fields) && $fields['review_metadata'] === null && $authoritative) {
            $fields['review_metadata'] = ['decision_source' => $source];
        }

        if (! $this->substantiveFieldsDiffer($claim, $fields)) {
            // Nothing meaningful would change. Still apply a genuinely different structural field
            // (content_block_key) if one was proposed, but never bump verified_at/tokens, and
            // never overwrite an existing decision_source tag, for a call that changes nothing.
            $this->applyStructuralOnlyChange($claim, $proposed);

            return $this->result(self::RESULT_ALREADY_CORRECT, $claim->fresh());
        }

        if ($authoritative) {
            $fields['verified_at'] = now();
            $fields['verification_claimed_at'] = null;
            $fields['verification_claim_token'] = null;
        }

        $claim->update($fields);

        return $this->result(self::RESULT_APPLIED, $claim->fresh());
    }

    /**
     * Finding #5646: rewrites a best_practice proposal to CONTENT_ORIGIN_UNCLASSIFIED when the
     * claim's own anchor text is nothing but a bare Wiki-navigation pointer — see the class
     * docblock. Only ever changes something when the incoming proposal is best_practice; every
     * other proposed content_origin passes through this method completely untouched.
     *
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function applyNavigationOnlyDowngrade(EnterpriseWikiClaim $claim, array $proposed): array
    {
        $proposedOrigin = (string) $proposed['content_origin'];
        $resolvedOrigin = $this->resolveContentOrigin($claim, $proposedOrigin);

        if ($resolvedOrigin === $proposedOrigin) {
            return $proposed;
        }

        $proposed['content_origin'] = $resolvedOrigin;
        $proposed['review_reason'] = null;
        $proposed['generation_issue'] = null;

        $metadata = is_array($proposed['review_metadata'] ?? null) ? $proposed['review_metadata'] : [];
        $metadata['classification_basis'] = 'navigation_reference_only';
        $proposed['review_metadata'] = $metadata;

        return $proposed;
    }

    /**
     * The single place a best_practice proposal is checked against the navigation-reference
     * detector — used both by the real write path (applyNavigationOnlyDowngrade()) and by
     * wouldBeRejectedAsAuthoritative()'s read-only dry-run preview, so a dry-run report can never
     * say "would become best_practice" for a claim that would actually resolve to unclassified.
     * Only ever downgrades FROM best_practice; every other proposed origin is returned unchanged.
     */
    private function resolveContentOrigin(EnterpriseWikiClaim $claim, string $proposedContentOrigin): string
    {
        if ($proposedContentOrigin !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return $proposedContentOrigin;
        }

        $anchorText = $this->navigationAnchorText($claim);

        if ($anchorText === '' || ! $this->navigationReferenceDetector->isPureNavigationReference($anchorText)) {
            return $proposedContentOrigin;
        }

        return EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED;
    }

    /**
     * Prefers the claim's own page_excerpt — the verbatim source text a normally-extracted claim
     * always carries, still retaining [[wikilink]] markup. Falls back to the claim's own anchored
     * block's markdown (identified by content_block_key, already the same block link_intents
     * lives on) only when page_excerpt is unavailable, so block-level structural metadata is
     * still consulted when it is the only signal on hand — never a required input, since most
     * claims never need the fallback at all.
     */
    private function navigationAnchorText(EnterpriseWikiClaim $claim): string
    {
        $excerpt = trim((string) ($claim->page_excerpt ?? ''));

        if ($excerpt !== '') {
            return $excerpt;
        }

        $blockKey = trim((string) ($claim->content_block_key ?? ''));

        if ($blockKey === '') {
            return '';
        }

        $version = $claim->version;

        if (! $version instanceof EnterpriseWikiPageVersion) {
            return '';
        }

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (is_array($block) && (string) ($block['block_key'] ?? '') === $blockKey) {
                return trim((string) ($block['markdown'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Applies content_block_key alone, if the proposal includes one and it genuinely differs from
     * what is stored — never content_origin/review_reason/review_metadata/generation_issue.
     */
    private function applyStructuralOnlyChange(EnterpriseWikiClaim $claim, array $proposed): bool
    {
        if (! array_key_exists('content_block_key', $proposed)) {
            return false;
        }

        if ($claim->content_block_key === $proposed['content_block_key']) {
            return false;
        }

        $claim->update(['content_block_key' => $proposed['content_block_key']]);

        return true;
    }

    /**
     * Compares every proposed field except the write's own bookkeeping (authoritative marker,
     * concurrency guard) against the claim's current values.
     */
    private function substantiveFieldsDiffer(EnterpriseWikiClaim $claim, array $fields): bool
    {
        foreach ($fields as $key => $value) {
            $current = $claim->{$key};

            if (is_array($current) || is_array($value)) {
                if ($current != $value) {
                    return true;
                }

                continue;
            }

            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    private function verifiedAtMatches(EnterpriseWikiClaim $claim, mixed $expected): bool
    {
        if ($expected === null) {
            return $claim->verified_at === null;
        }

        return $claim->verified_at !== null && $expected->equalTo($claim->verified_at);
    }

    /**
     * @return array{result: string, claim: ?EnterpriseWikiClaim}
     */
    private function result(string $outcome, ?EnterpriseWikiClaim $claim): array
    {
        return ['result' => $outcome, 'claim' => $claim];
    }
}
