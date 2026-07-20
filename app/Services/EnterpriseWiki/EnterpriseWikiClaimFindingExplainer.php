<?php

namespace App\Services\EnterpriseWiki;

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
 * - unsupported_generated_content is always at least "undocumented or incorrect factual claim" —
 *   content was generated that the source material does not confirm, which is itself already a
 *   real, actionable fact regardless of how much diagnostic detail is available for the specific
 *   reason. It suggests blocking by default (an authorized user must actively decide otherwise).
 *   When a genuine deterministic conflict was found (an actor/modality/negation/scope/number/
 *   currency/subject mismatch — see EnterpriseWikiClaimCanonicalizationService), the explanation
 *   names it concretely. Absent that, an AI self-reported check mismatch is surfaced as merely
 *   "possible" (that self-report is known to be unreliable — see
 *   project_wiki_run38_combined_evidence_verification_fix memory), and absent even that, the
 *   AI's own free-text verification reason is shown verbatim rather than one generic sentence.
 */
class EnterpriseWikiClaimFindingExplainer
{
    public const CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM = 'undocumented_or_incorrect_claim';

    public const CATEGORY_POSSIBLE_CONTENT_DEVIATION = 'possible_content_deviation';

    public const CATEGORY_TECHNICAL_UNCERTAINTY = 'technical_uncertainty';

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
     */
    public function suggestedBlocking(EnterpriseWikiClaim $claim): bool
    {
        return $claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    private function explainInternalError(EnterpriseWikiClaim $claim): array
    {
        $issue = (string) $claim->generation_issue;

        [$title, $explanation] = match ($issue) {
            'claim_not_tied_to_current_page_version', 'wrong_version' => [
                __('procynia.wiki.claim_finding.stale_version.title'),
                __('procynia.wiki.claim_finding.stale_version.explanation'),
            ],
            'missing_block' => [
                __('procynia.wiki.claim_finding.missing_block.title'),
                __('procynia.wiki.claim_finding.missing_block.explanation'),
            ],
            'claim_missing_unique_content_block_anchor' => [
                __('procynia.wiki.claim_finding.ambiguous_block_link.title'),
                __('procynia.wiki.claim_finding.ambiguous_block_link.explanation'),
            ],
            'genuine_content_mismatch' => [
                __('procynia.wiki.claim_finding.no_confident_source_link.title'),
                __('procynia.wiki.claim_finding.no_confident_source_link.explanation'),
            ],
            default => [
                __('procynia.wiki.claim_finding.technical_link_issue.title'),
                __('procynia.wiki.claim_finding.technical_link_issue.explanation'),
            ],
        };

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

    /**
     * @return array<string, mixed>
     */
    private function explainUnsupportedContent(EnterpriseWikiClaim $claim): array
    {
        $meta = (array) ($claim->review_metadata ?? []);

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

        $reasonText = $this->preferredReasonText($claim, $meta);

        if ($reasonText !== null) {
            return $this->buildContentFinding(
                self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM,
                __('procynia.wiki.claim_finding.no_source_support.title'),
                $reasonText,
            );
        }

        return $this->buildContentFinding(
            self::CATEGORY_UNDOCUMENTED_OR_INCORRECT_CLAIM,
            __('procynia.wiki.claim_finding.no_source_support.title'),
            __('procynia.wiki.claim_finding.no_source_support.explanation_no_detail'),
        );
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
