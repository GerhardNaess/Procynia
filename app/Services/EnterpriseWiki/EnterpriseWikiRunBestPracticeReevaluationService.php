<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;

/**
 * A narrowly-scoped, single-run re-evaluation for the best-practice classification fix (Del 7) —
 * deliberately separate from EnterpriseWikiClaimIntegrityRepairService's broader, customer-wide
 * repair (`wiki:repair-claim-integrity`), which also reclassifies based on whether a claim
 * happens to still carry a source reference — a different concern this task does not touch.
 *
 * Only ever reclassifies a claim FROM unsupported_generated_content TO best_practice, and only
 * when ALL of the following hold, using nothing but data already stored on the page version and
 * the claim itself — never a keyword list as the sole mechanism, never touching internal_error
 * claims (those are anchor/matching failures, a different, unrelated problem):
 *
 *   1. The claim still anchors to a real block in the page's CURRENT version via its own stored
 *      content_block_key.
 *   2. That block's own content_origin is genuinely best_practice (i.e. the page-generation step
 *      already made this call — this service never invents best-practice status for a block that
 *      was never tagged as such; doing so would be constructing false history).
 *   3. That block carries a real, non-empty best_practice_reason.
 *   4. The claim's OWN current text has not drifted into a party-/agreement-specific
 *      current-state assertion (EnterpriseWikiClaimCanonicalizationService::
 *      isEligibleForBestPractice() — the same deterministic check used by extraction and
 *      verification, so a claim can never end up classified differently depending on which
 *      code path last touched it). No recommendation marker ("bør"/"anbefales"/...) is
 *      required anywhere in this check — Procynia writes best-practice text in the same
 *      formal, declarative register as any other Wiki text.
 *
 * Read-only by default; only writes when explicitly told to apply.
 *
 * Only ever reachable for a claim with NO prior authoritative decision (verified_at null) —
 * EnterpriseWikiClaimClassificationService rejects any attempt by this service to reclassify a
 * claim verification already authoritatively decided, so a claim that was genuinely verified and
 * kept unsupported_generated_content (e.g. a deterministic conflict veto) can never be silently
 * promoted here. The claims this service can still act on are the ones that never received a
 * verification decision at all (e.g. demoted straight at extraction, before verification ever
 * ran) — for those, this IS their first real decision, so it is recorded as authoritative
 * (verified_at set, decision_source: best_practice_reevaluation) exactly like a claim verification
 * decided directly.
 */
class EnterpriseWikiRunBestPracticeReevaluationService
{
    public function __construct(
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
        private readonly EnterpriseWikiClaimClassificationService $classificationService,
    ) {}

    /**
     * @return array{
     *     run_id: int, applied: bool, checked: int, eligible: int, reclassified: int,
     *     skipped_no_matching_best_practice_block: int, skipped_missing_best_practice_reason: int,
     *     skipped_not_genuine_recommendation: int, skipped_authoritative: int,
     *     candidates: list<array{claim_id: int, page_id: int, block_key: ?string, claim_text: string}>
     * }
     */
    public function reevaluate(EnterpriseWikiIngestRun $run, bool $apply): array
    {
        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $currentVersionsByPageId = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->get()
            ->keyBy('enterprise_wiki_page_id');

        $claims = $currentVersionsByPageId->isEmpty()
            ? collect()
            : EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $currentVersionsByPageId->pluck('id'))
                ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT)
                ->orderBy('id')
                ->get();

        $checked = 0;
        $eligible = 0;
        $reclassified = 0;
        $skippedNoBlock = 0;
        $skippedNoReason = 0;
        $skippedNotGenuine = 0;
        $skippedAuthoritative = 0;
        $candidates = [];

        foreach ($claims as $claim) {
            $checked++;

            $version = $currentVersionsByPageId->get($claim->enterprise_wiki_page_id);
            $block = $version instanceof EnterpriseWikiPageVersion
                ? $this->findBlockByKey($version, (string) ($claim->content_block_key ?? ''))
                : null;

            if ($block === null || ($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
                $skippedNoBlock++;

                continue;
            }

            $reason = trim((string) ($block['best_practice_reason'] ?? ''));

            if ($reason === '') {
                $skippedNoReason++;

                continue;
            }

            if (! $this->canonicalizationService->isEligibleForBestPractice((string) $claim->claim_text)) {
                $skippedNotGenuine++;

                continue;
            }

            // Task rule 8: a claim already carrying an authoritative verification decision (it
            // went through real verification and was deliberately kept unsupported_generated_content
            // — e.g. a deterministic conflict veto) must never be silently promoted by this
            // narrower, block-driven rule. Only a claim that never received an authoritative
            // decision at all (verified_at null — e.g. demoted straight at extraction, before
            // verification ever ran) is still eligible here.
            if ($this->classificationService->wouldBeRejectedAsAuthoritative(
                $claim,
                EnterpriseWikiClaimClassificationService::SOURCE_BEST_PRACTICE_REEVALUATION,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            )) {
                $skippedAuthoritative++;

                continue;
            }

            $eligible++;
            $candidates[] = [
                'claim_id' => $claim->id,
                'page_id' => $claim->enterprise_wiki_page_id,
                'block_key' => $claim->content_block_key,
                'claim_text' => $claim->claim_text,
            ];

            if (! $apply) {
                continue;
            }

            $outcome = $this->classificationService->classifyClaim($claim->id, EnterpriseWikiClaimClassificationService::SOURCE_BEST_PRACTICE_REEVALUATION, [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $reason,
                'review_metadata' => array_merge((array) ($claim->review_metadata ?? []), [
                    'statement_kind' => 'recommendation',
                    'classification_basis' => 'scoped_run_reevaluation',
                    'suggested_placement' => $claim->content_block_key,
                    'visible_wiki_link_recommendation' => 'auto_evaluate',
                    'reevaluated_at' => now()->toIso8601String(),
                    'reevaluated_from_content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'reevaluated_run_id' => $run->id,
                ]),
                'generation_issue' => null,
                // Only ever reached for a claim with no prior authoritative decision at all
                // (verified_at was null — verification, for whatever reason, never ran on it).
                // This IS therefore that claim's first real decision — mark it authoritative
                // (verified_at set, decision_source: best_practice_reevaluation) so it is itself
                // now protected, exactly like a claim verification decided directly.
                'authoritative' => true,
            ]);

            if ($outcome['result'] === EnterpriseWikiClaimClassificationService::RESULT_REJECTED_AUTHORITATIVE) {
                $skippedAuthoritative++;

                continue;
            }

            if ($outcome['result'] === EnterpriseWikiClaimClassificationService::RESULT_APPLIED) {
                $reclassified++;
            }
        }

        return [
            'run_id' => $run->id,
            'applied' => $apply,
            'checked' => $checked,
            'eligible' => $eligible,
            'reclassified' => $reclassified,
            'skipped_no_matching_best_practice_block' => $skippedNoBlock,
            'skipped_missing_best_practice_reason' => $skippedNoReason,
            'skipped_not_genuine_recommendation' => $skippedNotGenuine,
            'skipped_authoritative' => $skippedAuthoritative,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBlockByKey(EnterpriseWikiPageVersion $version, string $blockKey): ?array
    {
        if ($blockKey === '') {
            return null;
        }

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (is_array($block) && (string) ($block['block_key'] ?? '') === $blockKey) {
                return $block;
            }
        }

        return null;
    }
}
