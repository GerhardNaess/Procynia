<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;

/**
 * The single place that turns a claim's raw verification data (content_origin, generation_issue,
 * review_metadata, review_reason) into a concrete, human-readable finding — a short title, a
 * specific explanation of what deviates and why, a recommended action, and the system's
 * SUGGESTED blocking state (never the final word — an authorized user can always override it,
 * see EnterpriseWikiClaim::blocking_override and WikiClaimController::updateBlockingOverride()).
 *
 * Every caller that needs to describe or gate on a claim-integrity defect (the Kjøringer "Funn"
 * panel via EnterpriseWikiRunFindingsService, and the QA gate via
 * EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()) must go through this class —
 * never re-derive "is this blocking" or "what does this mean" independently, or the two will
 * drift apart exactly the way the old hardcoded severity=critical/blocks_run=true did.
 *
 * Two top-level distinctions drive everything below (see CLAUDE.md's product rules this
 * implements):
 *
 * - internal_error is ALWAYS "technical uncertainty" — a missing or ambiguous link between the
 *   claim, its Wiki text block, and a source paragraph. This is a system/pipeline limitation, not
 *   evidence the claim itself is wrong, so it is never presented as a content error and does not
 *   suggest blocking by default.
 * - unsupported_generated_content is classified by the ACTUAL cause, not by the label alone (see
 *   CLAUDE.md: "Klassifisering skal følge den faktiske årsaken, ikke bare content_origin"):
 *     - If the claim never actually reached a semantic verdict — no content_block_key, or a block
 *       but no linked EnterpriseWikiSourceReference, or some review_metadata without a 'verdict'
 *       — the system never confirmed the CONTENT is wrong, only that it could not confidently
 *       link/check it. That is "technical uncertainty" too, and does not suggest blocking.
 *       EXCEPT: a claim persisted via EnterpriseWikiVerifyPageClaimsService::persistReusedFact()
 *       (an identical/equivalent claim elsewhere already has a verified canonical fact, so this
 *       occurrence reuses that outcome instead of calling AI again) never gets its own
 *       review_metadata by design, but it is NOT unverified — it inherited a real, already-
 *       confirmed verdict. Such a claim's canonical_fact_id is resolved and, when the fact is
 *       verified_unsupported, treated as a confirmed content error using the fact's own
 *       verification_reason (or an honest "reused, no further detail" message when the fact
 *       itself carries none) — never misclassified as a technical link failure just because this
 *       particular occurrence has no review_metadata of its own.
 *     - Otherwise a semantic verdict was actually reached, so it is at least "undocumented or
 *       incorrect factual claim" and suggests blocking by default. When a genuine deterministic
 *       conflict was found (an actor/modality/negation/scope/number/currency/subject mismatch —
 *       see EnterpriseWikiClaimCanonicalizationService) or the claim was reconfirmed by the
 *       stricter combined-evidence run re-evaluation (review_metadata.classification_basis ===
 *       'scoped_run_reevaluation' — see EnterpriseWikiVerifyPageClaimsService::
 *       reevaluateClaimForRun()), the finding is a confirmed content error. A first-pass verdict
 *       with no specific dimension flagged (every self-reported check "match", no deterministic
 *       reason) is hedged as "possible content deviation" instead — the checks found nothing
 *       concrete, so it should not be presented as a confirmed error. Absent even that, an AI
 *       self-reported check mismatch is surfaced as merely "possible" (that self-report is known
 *       to be unreliable — see project_wiki_run38_combined_evidence_verification_fix memory), and
 *       absent even that, the AI's own free-text verification reason is shown verbatim rather
 *       than one generic sentence.
 */
class EnterpriseWikiClaimFindingExplainer
{
    public const CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM = 'undocumented_or_incorrect_claim';

    public const CATEGORY_POSSIBLE_CONTENT_DEVIATION = 'possible_content_deviation';

    public const CATEGORY_TECHNICAL_UNCERTAINTY = 'technical_uncertainty';

    /**
     * No decision has been recorded — EnterpriseWikiClaim::blocking_override is null. This is
     * NOT the same as "the system's suggestion applies as the user's decision" (see
     * blockingState()'s docblock) — it means a decision is still outstanding.
     */
    public const USER_DECISION_PENDING = 'pending';

    /**
     * An authorized user explicitly recorded blocking_override = true.
     */
    public const USER_DECISION_BLOCKING = 'blocking';

    /**
     * An authorized user explicitly recorded blocking_override = false — approved the deviation
     * / chose not to block.
     */
    public const USER_DECISION_NOT_BLOCKING = 'not_blocking';

    /**
     * Deterministic conflict types EnterpriseWikiClaimCanonicalizationService::detectDeterministicConflict()
     * / detectSubjectMismatch() can return, threaded into review_metadata.deterministic_reason by
     * EnterpriseWikiVerifyPageClaimsService — the most concrete, highest-confidence explanation
     * available, since these are plain deterministic text comparisons, not an AI judgment call.
     */
    private const DETERMINISTIC_REASON_KEYS = [
        'actor_mismatch', 'modality_mismatch', 'negation_mismatch', 'scope_mismatch',
        'number_mismatch', 'currency_mismatch', 'subject_mismatch',
    ];

    /**
     * AI self-reported check dimensions (WikiClaimVerificationAiClient's schema) worth surfacing
     * as a *possible* deviation when no deterministic reason was found. Deliberately excludes
     * subject_entity — verified unreliable for self-detection (see class docblock); a subject
     * mismatch is only ever surfaced via the deterministic detectSubjectMismatch() backstop above.
     */
    private const SELF_REPORTED_CHECK_DIMENSIONS = [
        'actor', 'action', 'object', 'modality', 'negation', 'numbers_and_units',
        'time_and_date', 'scope', 'conditions_and_exceptions',
    ];

    /**
     * @return array{
     *     category: string,
     *     category_label: string,
     *     title: string,
     *     explanation: string,
     *     recommended_action: string,
     *     suggested_blocking: bool,
     *     has_confident_source: bool,
     * }
     */
    public function explain(EnterpriseWikiClaim $claim): array
    {
        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR) {
            return $this->explainInternalError($claim);
        }

        return $this->explainUnsupportedContent($claim);
    }

    /**
     * The system's suggested blocking state for this claim, independent of any human override —
     * exactly the value a fresh claim would be shown with. Callers that need the EFFECTIVE
     * blocking state must combine this with EnterpriseWikiClaim::blocking_override themselves
     * (null → this suggestion applies; true/false → the recorded human decision wins), since only
     * the caller knows whether an override should be consulted at all.
     *
     * Delegates to explain() rather than re-deriving the rule independently — the QA gate,
     * Document Owner approval, the Funn panel, and the claim detail view must never be able to
     * disagree about whether a given claim is blocking.
     */
    public function suggestedBlocking(EnterpriseWikiClaim $claim): bool
    {
        return $this->explain($claim)['suggested_blocking'];
    }

    /**
     * The full blocking picture for a claim, keeping the system's recommendation and the user's
     * decision as two genuinely separate facts — never collapsed into one ambiguous boolean the
     * UI could show as an already-decided "Blokkerer kjøringen" before anyone actually decided
     * anything (see CLAUDE.md's product rules: "Systemforslag er ikke brukerbeslutning").
     *
     * blocking_override = null means NO decision has been recorded — it must never be silently
     * treated as if the system's suggestion IS the user's decision. `requires_decision` is the
     * honest "unhandled decision need" state: a content-deviation category the system recommends
     * blocking, with nobody having decided yet.
     *
     * `blocks_gate` is the one internal, gate-only computation (QA repair_required / Document
     * Owner approval suppression) — never expose this raw boolean to the UI as "is blocking"; it
     * deliberately conflates "awaiting decision" with "user decided to block" because both must
     * still hold up final approval (CLAUDE.md: "Før endelig godkjenning kan systemet fortsatt
     * kreve at brukeren tar stilling til åpne innholdsavvik"). UI-facing code must read
     * `system_recommends_blocking` and `user_decision` instead.
     *
     * @return array{
     *     system_recommends_blocking: bool,
     *     user_decision: string,
     *     requires_decision: bool,
     *     blocks_gate: bool,
     * }
     */
    public function blockingState(EnterpriseWikiClaim $claim): array
    {
        $systemRecommendsBlocking = $this->suggestedBlocking($claim);

        $userDecision = match ($claim->blocking_override) {
            true => self::USER_DECISION_BLOCKING,
            false => self::USER_DECISION_NOT_BLOCKING,
            default => self::USER_DECISION_PENDING,
        };

        $requiresDecision = $userDecision === self::USER_DECISION_PENDING && $systemRecommendsBlocking;

        return [
            'system_recommends_blocking' => $systemRecommendsBlocking,
            'user_decision' => $userDecision,
            'requires_decision' => $requiresDecision,
            'blocks_gate' => $userDecision === self::USER_DECISION_BLOCKING || $requiresDecision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function explainInternalError(EnterpriseWikiClaim $claim): array
    {
        $issue = (string) $claim->generation_issue;

        return match ($issue) {
            'claim_not_tied_to_current_page_version', 'wrong_version' => $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.stale_version.title'),
                __('procynia.wiki.claim_finding.stale_version.explanation'),
            ),
            'missing_block' => $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.missing_block.title'),
                __('procynia.wiki.claim_finding.missing_block.explanation'),
            ),
            'claim_missing_unique_content_block_anchor' => $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.ambiguous_block_link.title'),
                __('procynia.wiki.claim_finding.ambiguous_block_link.explanation'),
            ),
            'genuine_content_mismatch' => $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.no_confident_source_link.title'),
                __('procynia.wiki.claim_finding.no_confident_source_link.explanation'),
            ),
            default => $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.technical_link_issue.title'),
                __('procynia.wiki.claim_finding.technical_link_issue.explanation'),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function explainUnsupportedContent(EnterpriseWikiClaim $claim): array
    {
        $meta = (array) ($claim->review_metadata ?? []);

        // The claim was never actually checked against a confidently identified source excerpt —
        // no block anchor, or a block with no linked source reference, or review_metadata with no
        // recorded verdict at all. That is a technical linking failure, not evidence of an
        // incorrect claim, regardless of the unsupported_generated_content label (see class
        // docblock and CLAUDE.md's "Klassifisering skal følge den faktiske årsaken" rule).
        if (! $this->hasVerifiedVerdict($meta)) {
            $reused = $this->explainReusedCanonicalFact($claim);

            return $reused ?? $this->explainUnverifiedLink($claim);
        }

        if ($claim->generation_issue === 'claim_contradicted_by_source') {
            return $this->buildContentFinding(
                self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM,
                __('procynia.wiki.claim_finding.contradicted.title'),
                $this->preferredReasonText($claim, $meta) ?? __('procynia.wiki.claim_finding.contradicted.explanation'),
            );
        }

        if ($claim->generation_issue === 'claim_partially_supported') {
            return $this->buildContentFinding(
                self::CATEGORY_POSSIBLE_CONTENT_DEVIATION,
                __('procynia.wiki.claim_finding.partially_supported.title'),
                $this->preferredReasonText($claim, $meta) ?? __('procynia.wiki.claim_finding.partially_supported.explanation'),
            );
        }

        $deterministicReason = (string) ($meta['deterministic_reason'] ?? '');

        if (in_array($deterministicReason, self::DETERMINISTIC_REASON_KEYS, true)) {
            return $this->buildContentFinding(
                self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM,
                __('procynia.wiki.claim_finding.'.$deterministicReason.'.title'),
                __('procynia.wiki.claim_finding.'.$deterministicReason.'.explanation'),
            );
        }

        $selfReportedDimension = $this->firstSelfReportedMismatch($meta);

        if ($selfReportedDimension !== null) {
            return $this->buildContentFinding(
                self::CATEGORY_POSSIBLE_CONTENT_DEVIATION,
                __('procynia.wiki.claim_finding.possible_'.$selfReportedDimension.'.title'),
                __('procynia.wiki.claim_finding.possible_'.$selfReportedDimension.'.explanation'),
            );
        }

        // Reached a not_supported verdict with no specific dimension flagged as wrong (every
        // self-reported check "match", no deterministic reason). A stricter combined-evidence run
        // re-evaluation (scoped_run_reevaluation) reaching the same verdict is a confirmed error —
        // the more rigorous re-check still found nothing to support it. A first-pass verdict with
        // nothing specific flagged is only a possible deviation: the checks found no concrete
        // problem, so the claim should not be presented as confirmed wrong.
        $confirmed = ($meta['classification_basis'] ?? null) === 'scoped_run_reevaluation';
        $category = $confirmed ? self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM : self::CATEGORY_POSSIBLE_CONTENT_DEVIATION;
        $titleKey = $confirmed ? 'no_source_support' : 'possible_no_source_support';

        $reasonText = $this->preferredReasonText($claim, $meta);

        if ($reasonText !== null) {
            return $this->buildContentFinding(
                $category,
                __('procynia.wiki.claim_finding.'.$titleKey.'.title'),
                $reasonText,
            );
        }

        return $this->buildContentFinding(
            $category,
            __('procynia.wiki.claim_finding.'.$titleKey.'.title'),
            __('procynia.wiki.claim_finding.'.$titleKey.'.explanation_no_detail'),
        );
    }

    /**
     * A claim with no own review_metadata may still have a real, already-confirmed basis: it
     * reused a canonical fact (EnterpriseWikiClaim::canonicalFact, set by
     * EnterpriseWikiVerifyPageClaimsService::persistReusedFact()/recordOutcome()) instead of
     * being individually re-verified. Returns null when there is no such fact, or the fact is not
     * (yet) a confirmed unsupported verdict — the caller then falls back to
     * explainUnverifiedLink() for the genuinely never-checked case.
     */
    private function explainReusedCanonicalFact(EnterpriseWikiClaim $claim): ?array
    {
        $fact = $claim->canonicalFact;

        if (! $fact instanceof EnterpriseWikiCanonicalFact
            || $fact->verification_status !== EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED
        ) {
            return null;
        }

        $reason = trim((string) $fact->verification_reason);

        return $this->buildContentFinding(
            self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM,
            __('procynia.wiki.claim_finding.reused_unsupported_fact.title'),
            $reason !== '' ? $reason : __('procynia.wiki.claim_finding.reused_unsupported_fact.explanation_no_detail'),
        );
    }

    /**
     * A generation_issue of unsupported_generated_content with no verified verdict at all — the
     * verification pipeline never reached (or never persisted) a semantic comparison for this
     * claim. Distinguishes WHICH link is missing so the explanation stays concrete rather than one
     * generic "technical problem" sentence.
     */
    private function explainUnverifiedLink(EnterpriseWikiClaim $claim): array
    {
        if ($claim->content_block_key === null) {
            return $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.no_block_link.title'),
                __('procynia.wiki.claim_finding.no_block_link.explanation'),
            );
        }

        if ($this->hasSourceReference($claim)) {
            return $this->buildTechnicalFinding(
                __('procynia.wiki.claim_finding.ambiguous_source_candidate.title'),
                __('procynia.wiki.claim_finding.ambiguous_source_candidate.explanation'),
            );
        }

        return $this->buildTechnicalFinding(
            __('procynia.wiki.claim_finding.no_confident_source_candidate.title'),
            __('procynia.wiki.claim_finding.no_confident_source_candidate.explanation'),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasVerifiedVerdict(array $meta): bool
    {
        return ! empty($meta['verdict']);
    }

    private function hasSourceReference(EnterpriseWikiClaim $claim): bool
    {
        if ($claim->relationLoaded('sourceReferences')) {
            return $claim->sourceReferences->isNotEmpty();
        }

        if (array_key_exists('source_references_count', $claim->getAttributes())) {
            return (int) $claim->getAttribute('source_references_count') > 0;
        }

        return $claim->sourceReferences()->exists();
    }

    private function buildTechnicalFinding(string $title, string $explanation): array
    {
        return [
            'category' => self::CATEGORY_TECHNICAL_UNCERTAINTY,
            'category_label' => __('procynia.wiki.claim_finding_category_technical_uncertainty'),
            'title' => $title,
            'explanation' => $explanation,
            'recommended_action' => __('procynia.wiki.claim_finding_action_technical_uncertainty'),
            'suggested_blocking' => false,
            'has_confident_source' => false,
        ];
    }

    private function buildContentFinding(string $category, string $title, string $explanation): array
    {
        return [
            'category' => $category,
            'category_label' => __('procynia.wiki.claim_finding_category_'.$category),
            'title' => $title,
            'explanation' => $explanation,
            'recommended_action' => __('procynia.wiki.claim_finding_action_'.$category),
            'suggested_blocking' => true,
            'has_confident_source' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function firstSelfReportedMismatch(array $meta): ?string
    {
        $checks = (array) ($meta['checks'] ?? []);

        foreach (self::SELF_REPORTED_CHECK_DIMENSIONS as $dimension) {
            if (($checks[$dimension] ?? null) === 'mismatch') {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * The AI's own free-text verification reason, preferring the more specific
     * "unsupported_parts" (contradicted/partially_supported verdicts) over the general "reason".
     * Never fabricated — null when nothing was actually recorded for this claim, so the caller can
     * fall back to an honest "no detail available" message instead of a blank explanation.
     *
     * @param  array<string, mixed>  $meta
     */
    private function preferredReasonText(EnterpriseWikiClaim $claim, array $meta): ?string
    {
        $unsupportedParts = trim((string) ($meta['unsupported_parts'] ?? ''));

        if ($unsupportedParts !== '') {
            return $unsupportedParts;
        }

        $reason = trim((string) ($meta['reason'] ?? $claim->review_reason ?? ''));

        return $reason !== '' ? $reason : null;
    }
}
