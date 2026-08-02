<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifies existing EnterpriseWikiClaim rows against the originating source document
 * and writes EnterpriseWikiSourceReference rows with verbatim supporting excerpts.
 *
 * Idempotency checkpoint: EnterpriseWikiClaim.verified_at, set once per claim regardless of
 * the verdict — a claim that AI found unsupported never gets a source reference, so "a
 * reference exists" cannot distinguish "not yet verified" from "verified and found
 * unsupported". Without this, every continuation pass would re-call AI for every
 * unsupported claim indefinitely.
 *
 * Concurrency: a claim is reserved via a single atomic conditional UPDATE
 * (verification_claimed_at/verification_claim_token) BEFORE the AI call — a plain SQL
 * compare-and-swap, not a held transaction/row lock, so nothing is locked while the AI call is
 * in flight. Persisting the result (reference, when supported, and the verified_at checkpoint
 * either way) happens in a short transaction that re-validates the reservation token is still
 * owned by this worker before writing anything, so a worker whose lease was reclaimed by
 * another worker can never overwrite that worker's result.
 *
 * Does not touch claim text, page versions, lint findings, or ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiVerifyPageClaimsService
{
    /**
     * Same safety margin as EnterpriseWikiExtractPageClaimsService::TIMEOUT_SAFETY_MARGIN_SECONDS.
     */
    private const TIMEOUT_SAFETY_MARGIN_SECONDS = 300;

    /**
     * Lease duration for a claim-verification reservation — same invariant and rationale as
     * EnterpriseWikiExtractPageClaimsService::LEASE_SECONDS: must exceed
     * ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS, since the reservation is
     * taken inside that job's single execution and a live worker may legitimately still be
     * mid-AI-call at any point up to the job's own timeout.
     */
    private const LEASE_SECONDS = ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS + self::TIMEOUT_SAFETY_MARGIN_SECONDS;

    private const EVIDENCE_SCOPE_BLOCK = 'block';

    private const EVIDENCE_SCOPE_CLAIM_REFERENCES = 'claim_references';

    public function __construct(
        private readonly WikiClaimVerificationAiClient $aiClient,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
        private readonly EnterpriseWikiClaimClassificationService $classificationService,
    ) {}

    /**
     * @return array{pages: int, claims: int, references: int, skipped: int, no_support: int, busy: int, reused: int}
     *
     * @throws \InvalidArgumentException if the run is not applied or the source document is missing
     * @throws \RuntimeException if AI is unavailable or verification fails
     */
    public function verify(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have claims verified."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Source document [{$run->source_id}] not found for run [{$run->id}]."
            );
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $sourceText = (string) ($document->extracted_text ?? '');
        $policy = $this->ordinaryVerificationPolicy($sourceText);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $pages = 0;
        $claims = 0;
        $references = 0;
        $skipped = 0;
        $noSupport = 0;
        $busy = 0;
        $reused = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                continue;
            }

            $pageClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->get();

            if ($pageClaims->isEmpty()) {
                continue;
            }

            $pages++;

            foreach ($pageClaims as $claim) {
                $outcome = $this->verifyClaimWithPolicy($claim, $run, $document, $version, $languageCode, $policy);

                $claims += $outcome['claims'];
                $references += $outcome['references'];
                $skipped += $outcome['skipped'];
                $noSupport += $outcome['no_support'];
                $busy += $outcome['busy'];
                $reused += $outcome['reused'];
            }
        }

        return [
            'pages' => $pages,
            'claims' => $claims,
            'references' => $references,
            'skipped' => $skipped,
            'no_support' => $noSupport,
            'busy' => $busy,
            'reused' => $reused,
        ];
    }

    /**
     * Claim-scoped verification for claims extracted from one manually edited mixed block on an
     * explicit staged page version.
     *
     * This is deliberately not reachable from the ordinary page/run verify() pipeline. Callers must
     * pass the exact page, expected current version, staged version, block key, and newly-created
     * claim ids/models for the edited block; this method never discovers sibling claims from the
     * page version and never reads block-level source elements as evidence. Source-based claims are
     * verified only against their own sourceReferences.
     *
     * @param  list<int|EnterpriseWikiClaim>  $claims
     * @return array{
     *     pages: int,
     *     claims: int,
     *     references: int,
     *     skipped: int,
     *     no_support: int,
     *     busy: int,
     *     reused: int,
     *     canonical_recording_candidates: list<array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }>
     * }
     *
     * @throws \InvalidArgumentException if the staged verification scope is invalid
     * @throws \RuntimeException if AI is unavailable or verification fails
     */
    public function verifyClaimsForManualMixedBlock(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        EnterpriseWikiPageVersion $stagedVersion,
        string $contentBlockKey,
        array $claims,
    ): array {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have claims verified."
            );
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            throw new \InvalidArgumentException(
                "Source document [{$run->source_id}] not found for run [{$run->id}]."
            );
        }

        $stagedVersion = $this->validateManualMixedBlockStagedScope(
            $run,
            $page,
            $expectedCurrentVersion,
            $stagedVersion,
            $contentBlockKey,
        );

        $claimIds = [];

        foreach ($claims as $claim) {
            $claimIds[] = $claim instanceof EnterpriseWikiClaim
                ? (int) $claim->id
                : (int) $claim;
        }

        $claimIds = array_values(array_unique(array_filter($claimIds, static fn (int $id): bool => $id > 0)));

        if ($claimIds === []) {
            return [
                'pages' => 0,
                'claims' => 0,
                'references' => 0,
                'skipped' => 0,
                'no_support' => 0,
                'busy' => 0,
                'reused' => 0,
                'canonical_recording_candidates' => [],
            ];
        }

        $contentBlockKey = trim($contentBlockKey);
        $scopedClaims = EnterpriseWikiClaim::query()
            ->whereIn('id', $claimIds)
            ->with('sourceReferences')
            ->get()
            ->keyBy('id');

        if ($scopedClaims->count() !== count($claimIds)) {
            $missingIds = array_values(array_diff($claimIds, $scopedClaims->keys()->map(fn ($id): int => (int) $id)->all()));

            throw new \InvalidArgumentException('Claim id(s) not found for manual mixed-block verification: '.implode(', ', $missingIds));
        }

        foreach ($scopedClaims as $claim) {
            if ((int) $claim->enterprise_wiki_page_id !== (int) $page->id) {
                throw new \InvalidArgumentException("Claim [{$claim->id}] does not belong to page [{$page->id}].");
            }

            if ((int) $claim->enterprise_wiki_page_version_id !== (int) $stagedVersion->id) {
                throw new \InvalidArgumentException("Claim [{$claim->id}] does not belong to staged page version [{$stagedVersion->id}].");
            }

            if ((string) ($claim->content_block_key ?? '') !== $contentBlockKey) {
                throw new \InvalidArgumentException("Claim [{$claim->id}] does not belong to content block [{$contentBlockKey}].");
            }
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $policy = $this->manualMixedBlockVerificationPolicy();
        $result = [
            'pages' => 1,
            'claims' => 0,
            'references' => 0,
            'skipped' => 0,
            'no_support' => 0,
            'busy' => 0,
            'reused' => 0,
            'canonical_recording_candidates' => [],
        ];

        foreach ($claimIds as $claimId) {
            /** @var EnterpriseWikiClaim $claim */
            $claim = $scopedClaims->get($claimId);
            $outcome = $this->verifyClaimWithPolicy($claim, $run, $document, $stagedVersion, $languageCode, $policy);

            $result['claims'] += $outcome['claims'];
            $result['references'] += $outcome['references'];
            $result['skipped'] += $outcome['skipped'];
            $result['no_support'] += $outcome['no_support'];
            $result['busy'] += $outcome['busy'];
            $result['reused'] += $outcome['reused'];
            array_push($result['canonical_recording_candidates'], ...$outcome['canonical_recording_candidates']);
        }

        return $result;
    }

    private function validateManualMixedBlockStagedScope(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        EnterpriseWikiPageVersion $stagedVersion,
        string $contentBlockKey,
    ): EnterpriseWikiPageVersion {
        $contentBlockKey = trim($contentBlockKey);

        if ($contentBlockKey === '') {
            throw new \InvalidArgumentException('A content_block_key is required for manual mixed-block verification.');
        }

        if ((int) $page->customer_id !== (int) $run->customer_id) {
            throw new \InvalidArgumentException("Page [{$page->id}] does not belong to run customer [{$run->customer_id}].");
        }

        $runTargetsPage = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->exists();

        if (! $runTargetsPage) {
            throw new \InvalidArgumentException("Page [{$page->id}] is not part of run [{$run->id}].");
        }

        $current = EnterpriseWikiPageVersion::query()
            ->whereKey($expectedCurrentVersion->id)
            ->first();

        if ($current === null) {
            throw new \InvalidArgumentException("Expected current page version [{$expectedCurrentVersion->id}] not found.");
        }

        if ((int) $current->enterprise_wiki_page_id !== (int) $page->id) {
            throw new \InvalidArgumentException("Expected current page version [{$current->id}] does not belong to page [{$page->id}].");
        }

        if (! $current->is_current || $current->is_staged) {
            throw new \InvalidArgumentException("Expected current page version [{$current->id}] is not the published current version.");
        }

        $runPage = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();

        if ((int) ($runPage?->generated_page_version_id ?? 0) !== (int) $current->id) {
            throw new \InvalidArgumentException("Run [{$run->id}] page [{$page->id}] does not point to expected current page version [{$current->id}].");
        }

        $staged = EnterpriseWikiPageVersion::query()
            ->whereKey($stagedVersion->id)
            ->first();

        if ($staged === null) {
            throw new \InvalidArgumentException("Staged page version [{$stagedVersion->id}] not found.");
        }

        if ((int) $staged->enterprise_wiki_page_id !== (int) $page->id) {
            throw new \InvalidArgumentException("Staged page version [{$staged->id}] does not belong to page [{$page->id}].");
        }

        if ($staged->is_current || ! $staged->is_staged) {
            throw new \InvalidArgumentException("Page version [{$staged->id}] is not a staged non-current version.");
        }

        if ((int) $staged->id === (int) $current->id) {
            throw new \InvalidArgumentException('The staged page version must be different from the current page version.');
        }

        if ($staged->generated_by_model !== null) {
            throw new \InvalidArgumentException("Staged page version [{$staged->id}] must not have generated_by_model set.");
        }

        if ((int) ($staged->created_by_user_id ?? 0) <= 0) {
            throw new \InvalidArgumentException("Staged page version [{$staged->id}] must have created_by_user_id set.");
        }

        if ((int) $staged->version_number !== (int) $current->version_number + 1) {
            throw new \InvalidArgumentException("Staged page version [{$staged->id}] must be exactly one version after expected current version [{$current->id}].");
        }

        $stagedRunPagePointerExists = EnterpriseWikiIngestRunPage::query()
            ->where('generated_page_version_id', $staged->id)
            ->exists();

        if ($stagedRunPagePointerExists) {
            throw new \InvalidArgumentException("No run/page row may point to staged page version [{$staged->id}].");
        }

        if ($this->findBlockByKey($staged, $contentBlockKey) === null) {
            throw new \InvalidArgumentException("Content block [{$contentBlockKey}] was not found on staged page version [{$staged->id}].");
        }

        return $staged;
    }

    /**
     * Del 7: re-evaluate ONE already-verified claim with the CURRENT (semantic/cross-language)
     * verification logic — used only by the narrow, single-run `wiki:reevaluate-run-claim-
     * verification` command, never by the concurrent verify() pipeline (which only ever verifies
     * a claim once, via verified_at as the checkpoint, and therefore skips already-verified
     * claims entirely). Deliberately bypasses the reserve/lease token protocol: this is an
     * explicit, single-worker, manual operation against one already-completed run, not a step in
     * the concurrent continuation-job pipeline.
     *
     * Only reachable for a claim that is currently unsupported_generated_content, still anchored
     * to a source_based block in the page's CURRENT version — best_practice, internal_error, and
     * already-source_based claims are a different (and, per Del 1, largely unrelated) problem and
     * are never touched here. Reuses applyVerdictOutcome()/applyDeterministicSafetyNet() — the
     * exact same mapping and safety net the live pipeline uses — so a re-evaluated claim can never
     * end up classified differently depending on which code path verified it.
     *
     * @return array{
     *     eligible: bool, skipped_reason: ?string, ai_verdict: ?string, final_verdict: ?string,
     *     deterministic_override: bool, reason: ?string, applied: bool, new_content_origin: ?string
     * }
     */
    public function reevaluateClaimForRun(EnterpriseWikiClaim $claim, EnterpriseWikiIngestRun $run, bool $apply): array
    {
        $ineligible = [
            'eligible' => false, 'skipped_reason' => null, 'ai_verdict' => null, 'final_verdict' => null,
            'deterministic_override' => false, 'reason' => null, 'applied' => false, 'new_content_origin' => null,
        ];

        if ($claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT) {
            return array_merge($ineligible, ['skipped_reason' => 'not_unsupported_generated_content']);
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if ($document === null) {
            return array_merge($ineligible, ['skipped_reason' => 'document_not_found']);
        }

        $version = EnterpriseWikiPageVersion::query()
            ->where('id', $claim->enterprise_wiki_page_version_id)
            ->where('is_current', true)
            ->first();

        if ($version === null) {
            return array_merge($ineligible, ['skipped_reason' => 'not_current_page_version']);
        }

        $block = $this->findBlockByKey($version, (string) ($claim->content_block_key ?? ''));

        if ($block === null || ($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
            return array_merge($ineligible, ['skipped_reason' => 'not_a_source_based_block']);
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);
        $fallbackSourceText = mb_substr((string) ($document->extracted_text ?? ''), 0, 8000);

        $candidateElements = $this->candidateElementsForAi($block);
        $elementsByKey = $this->elementsByKey($block);

        // Run-38 fix: same deterministic verbatim/near-verbatim fast path as verify() — never
        // spend an AI call re-confirming what a plain substring check already proves.
        $deterministicMatch = $this->canonicalizationService->detectDeterministicSupport(
            $claim->claim_text,
            array_column($candidateElements, 'excerpt'),
        );

        if ($deterministicMatch) {
            $result = [
                'verdict' => WikiClaimVerificationAiClient::VERDICT_SUPPORTED,
                'same_meaning_across_languages' => true,
                'claim_language' => '',
                'source_language' => '',
                'supporting_source_element_keys' => array_keys($elementsByKey),
                'reason' => 'Ordrett eller nær-ordrett samsvar med kildeteksten, bekreftet deterministisk uten AI-kall.',
                'unsupported_parts' => '',
                'checks' => [],
            ];
        } else {
            $result = $this->aiClient->verifyClaim(
                claimText: $claim->claim_text,
                sourceElements: $candidateElements,
                fallbackSourceText: $fallbackSourceText,
                languageCode: $languageCode,
                blockMarkdown: $block['markdown'] ?? null,
                documentLabel: $document->original_filename,
            );
        }

        $safetyNet = $deterministicMatch
            ? ['verdict' => WikiClaimVerificationAiClient::VERDICT_SUPPORTED, 'deterministic_reason' => null]
            : $this->applyDeterministicSafetyNet($claim->claim_text, $result, $elementsByKey, $block, $fallbackSourceText);
        $finalVerdict = $safetyNet['verdict'];

        $report = [
            'eligible' => true,
            'skipped_reason' => null,
            'ai_verdict' => $deterministicMatch ? 'deterministic_verbatim_match' : $result['verdict'],
            'final_verdict' => $finalVerdict,
            'deterministic_override' => ! $deterministicMatch && $finalVerdict !== $result['verdict'],
            'reason' => $result['reason'],
            'applied' => false,
            'new_content_origin' => null,
        ];

        if (! $apply) {
            return $report;
        }

        $newContentOrigin = DB::transaction(function () use ($claim, $result, $elementsByKey, $document, $finalVerdict, $run, $fallbackSourceText, $deterministicMatch, $safetyNet): ?string {
            $locked = EnterpriseWikiClaim::query()->whereKey($claim->id)->lockForUpdate()->first();

            if ($locked === null || $locked->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT) {
                return null;
            }

            $originalContentOrigin = (string) $locked->content_origin;

            $this->applyVerdictOutcome($locked, $finalVerdict, $result, $elementsByKey, $document, $originalContentOrigin, $fallbackSourceText, array_filter([
                'classification_basis' => $deterministicMatch ? 'deterministic_verbatim_match' : 'scoped_run_reevaluation',
                'reevaluated_at' => now()->toIso8601String(),
                'reevaluated_from_content_origin' => $originalContentOrigin,
                'reevaluated_run_id' => $run->id,
                'deterministic_reason' => $safetyNet['deterministic_reason'],
            ], static fn ($value): bool => $value !== null), source: EnterpriseWikiClaimClassificationService::SOURCE_MANUAL_REVERIFICATION);

            return $locked->fresh()->content_origin;
        });

        Log::info('[WIKI_CLAIM_VERIFICATION] Scoped run re-evaluation applied.', [
            'claim_id' => $claim->id,
            'run_id' => $run->id,
            'ai_verdict' => $result['verdict'],
            'final_verdict' => $finalVerdict,
            'new_content_origin' => $newContentOrigin,
        ]);

        $report['applied'] = $newContentOrigin !== null;
        $report['new_content_origin'] = $newContentOrigin;

        return $report;
    }

    /**
     * @return array{
     *     evidence_scope: string,
     *     fallback_source_text: string,
     *     allow_block_markdown_context: bool,
     *     allow_block_markdown_safety_fallback: bool,
     *     allow_claim_decision_reset: bool,
     *     allow_best_practice_promotion: bool,
     *     allow_canonical_reuse: bool,
     *     allow_canonical_recording: bool,
     *     verify_unsupported_without_ai: bool
     * }
     */
    private function ordinaryVerificationPolicy(string $fallbackSourceText): array
    {
        return [
            'evidence_scope' => self::EVIDENCE_SCOPE_BLOCK,
            'fallback_source_text' => $fallbackSourceText,
            'allow_block_markdown_context' => true,
            'allow_block_markdown_safety_fallback' => true,
            'allow_claim_decision_reset' => true,
            'allow_best_practice_promotion' => true,
            'allow_canonical_reuse' => true,
            'allow_canonical_recording' => true,
            'verify_unsupported_without_ai' => false,
        ];
    }

    /**
     * @return array{
     *     evidence_scope: string,
     *     fallback_source_text: string,
     *     allow_block_markdown_context: bool,
     *     allow_block_markdown_safety_fallback: bool,
     *     allow_claim_decision_reset: bool,
     *     allow_best_practice_promotion: bool,
     *     allow_canonical_reuse: bool,
     *     allow_canonical_recording: bool,
     *     verify_unsupported_without_ai: bool
     * }
     */
    private function manualMixedBlockVerificationPolicy(): array
    {
        return [
            'evidence_scope' => self::EVIDENCE_SCOPE_CLAIM_REFERENCES,
            'fallback_source_text' => '',
            'allow_block_markdown_context' => false,
            'allow_block_markdown_safety_fallback' => false,
            'allow_claim_decision_reset' => false,
            'allow_best_practice_promotion' => false,
            'allow_canonical_reuse' => true,
            'allow_canonical_recording' => false,
            'verify_unsupported_without_ai' => true,
        ];
    }

    /**
     * @param  array{
     *     evidence_scope: string,
     *     fallback_source_text: string,
     *     allow_block_markdown_context: bool,
     *     allow_block_markdown_safety_fallback: bool,
     *     allow_claim_decision_reset: bool,
     *     allow_best_practice_promotion: bool,
     *     allow_canonical_reuse: bool,
     *     allow_canonical_recording: bool,
     *     verify_unsupported_without_ai: bool
     * }  $policy
     * @return array{
     *     claims: int,
     *     references: int,
     *     skipped: int,
     *     no_support: int,
     *     busy: int,
     *     reused: int,
     *     canonical_recording_candidates: list<array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }>
     * }
     */
    private function verifyClaimWithPolicy(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiDocument $document,
        EnterpriseWikiPageVersion $version,
        string $languageCode,
        array $policy,
    ): array {
        $counts = $this->emptyClaimVerificationCounts();

        // Authoritative checkpoint: this claim already completed verification —
        // supported or not — so skip without an AI call.
        if ($claim->verified_at !== null) {
            $counts['skipped']++;

            return $counts;
        }

        $token = (string) Str::uuid();
        $reservation = $this->reserve($claim, $token);

        if ($reservation === 'completed') {
            $counts['skipped']++;

            return $counts;
        }

        if ($reservation === 'busy') {
            $counts['busy']++;

            return $counts;
        }

        $counts['claims']++;

        $anchorFailure = $this->claimAnchorFailureReason($claim, $version);

        if ($anchorFailure !== null) {
            $updated = $this->markInternalGenerationError(
                $claim,
                $anchorFailure,
                $policy['evidence_scope'] === self::EVIDENCE_SCOPE_CLAIM_REFERENCES ? $token : null,
            );
            $counts[$updated ? 'no_support' : 'busy']++;

            return $counts;
        }

        // Best-practice classification fix: a claim already classified best_practice
        // (inherited from its generation block) must never be run through "prove this is
        // in the customer's source document" — that is exactly what best_practice content
        // deliberately is not, and doing so is precisely how a legitimate suggestion used
        // to get silently downgraded to unsupported_generated_content (or, worse, upgraded
        // to source_based on a coincidental partial text match). Only re-validate that it
        // is still genuinely normative and still anchored — never prove source support.
        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
            && $this->canonicalizationService->isEligibleForBestPractice($claim->claim_text)
        ) {
            $outcome = $this->persistBestPracticeVerification(
                $claim->id,
                $token,
                $run->customer_id,
                $version,
                $policy['allow_canonical_recording'],
            );

            if ($outcome === null) {
                $counts['busy']++;

                return $counts;
            }

            $counts['no_support']++;
            $this->appendCanonicalRecordingCandidate($counts, $outcome['canonical_recording_candidate']);

            return $counts;
        }

        if ($policy['verify_unsupported_without_ai']
            && $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT
        ) {
            $outcome = $this->persist(
                $claim->id,
                $token,
                $document,
                $this->unsupportedGeneratedContentVerificationResult(),
                $version,
                null,
                '',
                [],
                false,
                false,
                $policy['allow_canonical_recording'],
            );

            if ($outcome === null) {
                $counts['busy']++;

                return $counts;
            }

            $counts['no_support']++;
            $this->appendCanonicalRecordingCandidate($counts, $outcome['canonical_recording_candidate']);

            return $counts;
        }

        $evidence = $this->verificationEvidenceForPolicy($claim, $version, $policy);
        $candidateElements = $evidence['candidate_elements'];

        if ($policy['evidence_scope'] === self::EVIDENCE_SCOPE_CLAIM_REFERENCES
            && $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED
            && $candidateElements === []
        ) {
            $updated = $this->markInternalGenerationError($claim, 'source_based_claim_missing_source_reference', $token);
            $counts[$updated ? 'no_support' : 'busy']++;

            return $counts;
        }

        // Cross-page overgeneration fix: before calling AI, check whether this claim
        // expresses a fact already verified SUPPORTED for another occurrence (same
        // customer, content_origin, document/source version, and cited source elements —
        // Del 3/6). Only claims carrying a real structured source reference are eligible;
        // a claim with none (e.g. an unstructured/manual reference) has nothing safe to
        // key on and is always verified independently.
        //
        // A verified_unsupported fact is deliberately NEVER reused as a final result (run-
        // 39 fix): a negative outcome can be based on different wording, a different Wiki
        // block, different source excerpts, an earlier verification bug, or since-improved
        // verification logic — copying it forward would block a claim without this
        // specific occurrence ever having been checked against its OWN current text, block,
        // and source references. An unsupported fact only marks the claim as eligible for
        // deterministic-support/AI verification below, exactly like a claim with no
        // reusable fact at all — canonical_fact_id may end up pointing at the same or a new
        // fact once recordOutcome() runs, but the fact never decides the outcome itself.
        $reusableFact = $policy['allow_canonical_reuse']
            ? $this->canonicalizationService->findReusableFact($claim, $run->customer_id)
            : null;

        if ($reusableFact !== null && $reusableFact->verification_status === EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED) {
            Log::info('[WIKI_CLAIM_VERIFICATION] Reusing an existing canonical fact verification result.', [
                'claim_id' => $claim->id,
                'canonical_fact_id' => $reusableFact->id,
                'verification_status' => $reusableFact->verification_status,
            ]);

            $outcome = $this->persistReusedFact($claim->id, $token, $reusableFact, $policy['allow_claim_decision_reset']);

            if ($outcome === null) {
                $counts['busy']++;

                return $counts;
            }

            $counts['reused']++;
            $counts['references']++;

            return $counts;
        }

        if ($reusableFact !== null) {
            Log::info('[WIKI_CLAIM_VERIFICATION] Found a verified_unsupported canonical fact but re-verifying this occurrence independently instead of reusing it.', [
                'claim_id' => $claim->id,
                'canonical_fact_id' => $reusableFact->id,
            ]);
        }

        // Run-38 fix: a verbatim/near-verbatim claim never needs an AI call at all — see
        // EnterpriseWikiClaimCanonicalizationService::detectDeterministicSupport().
        if ($this->canonicalizationService->detectDeterministicSupport(
            $claim->claim_text,
            array_column($candidateElements, 'excerpt'),
        )) {
            $outcome = $this->persistDeterministicSupport(
                $claim->id,
                $token,
                $document,
                $version,
                $evidence['block_for_safety_net'],
                $evidence['elements_by_key'],
                $policy['allow_claim_decision_reset'],
                $policy['allow_best_practice_promotion'],
                $policy['allow_canonical_recording'],
            );

            if ($outcome === null) {
                $counts['busy']++;

                return $counts;
            }

            if ($outcome['outcome'] === 'unsupported') {
                $counts['no_support']++;
            } else {
                $counts['references']++;
            }

            $this->appendCanonicalRecordingCandidate($counts, $outcome['canonical_recording_candidate']);

            return $counts;
        }

        try {
            $result = $this->aiClient->verifyClaim(
                claimText: $claim->claim_text,
                sourceElements: $candidateElements,
                fallbackSourceText: $evidence['fallback_source_text'],
                languageCode: $languageCode,
                blockMarkdown: $evidence['block_markdown_for_ai'],
                documentLabel: $document->original_filename,
            );
        } catch (Throwable $e) {
            $this->release($claim->id, $token);

            throw $e;
        }

        $outcome = $this->persist(
            $claim->id,
            $token,
            $document,
            $result,
            $version,
            $evidence['block_for_safety_net'],
            $evidence['fallback_source_text'],
            $evidence['elements_by_key'],
            $policy['allow_claim_decision_reset'],
            $policy['allow_best_practice_promotion'],
            $policy['allow_canonical_recording'],
        );

        if ($outcome === null) {
            // Another worker reclaimed this lease as stale while the AI call was in
            // flight; that worker's own attempt is the one that will persist a result.
            $counts['busy']++;

            return $counts;
        }

        if ($outcome['outcome'] === 'unsupported') {
            $counts['no_support']++;
        } else {
            $counts['references']++;
        }

        $this->appendCanonicalRecordingCandidate($counts, $outcome['canonical_recording_candidate']);

        return $counts;
    }

    /**
     * @return array{
     *     claims: int,
     *     references: int,
     *     skipped: int,
     *     no_support: int,
     *     busy: int,
     *     reused: int,
     *     canonical_recording_candidates: list<array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }>
     * }
     */
    private function emptyClaimVerificationCounts(): array
    {
        return [
            'claims' => 0,
            'references' => 0,
            'skipped' => 0,
            'no_support' => 0,
            'busy' => 0,
            'reused' => 0,
            'canonical_recording_candidates' => [],
        ];
    }

    /**
     * @param  array{
     *     canonical_recording_candidates: list<array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }>
     * }  $counts
     * @param  array{
     *     claim_id: int,
     *     original_content_origin: string,
     *     verification_status: string,
     *     reason: ?string,
     *     supporting_excerpt: ?string
     * }|null  $candidate
     */
    private function appendCanonicalRecordingCandidate(array &$counts, ?array $candidate): void
    {
        if ($candidate !== null) {
            $counts['canonical_recording_candidates'][] = $candidate;
        }
    }

    /**
     * @param  array{
     *     evidence_scope: string,
     *     fallback_source_text: string,
     *     allow_block_markdown_context: bool,
     *     allow_block_markdown_safety_fallback: bool,
     *     allow_claim_decision_reset: bool,
     *     allow_best_practice_promotion: bool,
     *     verify_unsupported_without_ai: bool
     * }  $policy
     * @return array{
     *     candidate_elements: list<array{key: string, type: ?string, excerpt: string, page_reference: ?string}>,
     *     elements_by_key: array<string, array<string, mixed>>,
     *     block_for_safety_net: ?array<string, mixed>,
     *     block_markdown_for_ai: ?string,
     *     fallback_source_text: string
     * }
     */
    private function verificationEvidenceForPolicy(EnterpriseWikiClaim $claim, EnterpriseWikiPageVersion $version, array $policy): array
    {
        $block = $this->findBlockByKey($version, (string) ($claim->content_block_key ?? ''));

        if ($policy['evidence_scope'] === self::EVIDENCE_SCOPE_CLAIM_REFERENCES) {
            $elementsByKey = $this->elementsByKeyForClaimSourceReferences($claim);

            return [
                'candidate_elements' => $this->candidateElementsForClaimSourceReferences($claim),
                'elements_by_key' => $elementsByKey,
                'block_for_safety_net' => $policy['allow_block_markdown_safety_fallback'] ? $block : null,
                'block_markdown_for_ai' => null,
                'fallback_source_text' => '',
            ];
        }

        return [
            'candidate_elements' => $this->candidateElementsForAi($block),
            'elements_by_key' => $this->elementsByKey($block),
            'block_for_safety_net' => $policy['allow_block_markdown_safety_fallback'] ? $block : null,
            'block_markdown_for_ai' => $policy['allow_block_markdown_context'] ? ($block['markdown'] ?? null) : null,
            'fallback_source_text' => $policy['fallback_source_text'],
        ];
    }

    /**
     * @return array{key: string, type: ?string, excerpt: string, page_reference: ?string}[]
     */
    private function candidateElementsForClaimSourceReferences(EnterpriseWikiClaim $claim): array
    {
        $elements = [];

        foreach ($this->sourceReferencesForClaim($claim) as $reference) {
            $key = (string) ($reference->source_element_key ?? '');
            $excerpt = trim((string) ($reference->excerpt ?? ''));

            if ($key === '' || $excerpt === '') {
                continue;
            }

            $elements[] = [
                'key' => $key,
                'type' => $reference->source_element_type,
                'excerpt' => $excerpt,
                'page_reference' => $reference->page_reference,
            ];
        }

        return $elements;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by source_element_key
     */
    private function elementsByKeyForClaimSourceReferences(EnterpriseWikiClaim $claim): array
    {
        $byKey = [];

        foreach ($this->sourceReferencesForClaim($claim) as $reference) {
            $key = (string) ($reference->source_element_key ?? '');
            $excerpt = trim((string) ($reference->excerpt ?? ''));

            if ($key === '' || $excerpt === '') {
                continue;
            }

            $byKey[$key] = [
                'source_element_key' => $key,
                'source_element_type' => $reference->source_element_type,
                'source_row_key' => $reference->source_row_key,
                'source_excerpt' => $excerpt,
                'page_reference' => $reference->page_reference,
            ];
        }

        return $byKey;
    }

    /**
     * @return iterable<EnterpriseWikiSourceReference>
     */
    private function sourceReferencesForClaim(EnterpriseWikiClaim $claim): iterable
    {
        return $claim->relationLoaded('sourceReferences')
            ? $claim->sourceReferences
            : $claim->sourceReferences()->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedGeneratedContentVerificationResult(): array
    {
        return [
            'verdict' => WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED,
            'same_meaning_across_languages' => true,
            'claim_language' => '',
            'source_language' => '',
            'supporting_source_element_keys' => [],
            'reason' => 'Påstanden er klassifisert som generert innhold uten kildegrunnlag for denne blokken.',
            'unsupported_parts' => '',
            'checks' => [],
        ];
    }

    /**
     * Atomically reserve a claim for verification — see
     * EnterpriseWikiExtractPageClaimsService::reserve() for the same compare-and-swap pattern.
     *
     * @return string one of 'reserved', 'completed', 'busy'
     */
    private function reserve(EnterpriseWikiClaim $claim, string $token): string
    {
        $staleThreshold = now()->subSeconds(self::LEASE_SECONDS);

        $claimed = EnterpriseWikiClaim::query()
            ->where('id', $claim->id)
            ->whereNull('verified_at')
            ->where(function ($q) use ($staleThreshold): void {
                $q->whereNull('verification_claimed_at')->orWhere('verification_claimed_at', '<', $staleThreshold);
            })
            ->update([
                'verification_claimed_at' => now(),
                'verification_claim_token' => $token,
            ]);

        if ($claimed > 0) {
            return 'reserved';
        }

        $fresh = EnterpriseWikiClaim::query()->find($claim->id);

        return $fresh?->verified_at !== null ? 'completed' : 'busy';
    }

    /**
     * Release a reservation this worker still owns — a no-op if the token no longer matches
     * (already reclaimed by another worker as stale).
     */
    private function release(int $claimId, string $token): void
    {
        EnterpriseWikiClaim::query()
            ->where('id', $claimId)
            ->where('verification_claim_token', $token)
            ->update([
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);
    }

    /**
     * @return array{key: string, type: ?string, excerpt: string, page_reference: ?string}[]
     */
    private function candidateElementsForAi(?array $block): array
    {
        if ($block === null) {
            return [];
        }

        $elements = [];

        foreach ((array) ($block['source_elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $key = (string) ($element['source_element_key'] ?? '');
            $excerpt = trim((string) ($element['source_excerpt'] ?? ''));

            if ($key === '' || $excerpt === '') {
                continue;
            }

            $elements[] = [
                'key' => $key,
                'type' => $element['source_element_type'] ?? null,
                'excerpt' => $excerpt,
                'page_reference' => $element['page_reference'] ?? null,
            ];
        }

        return $elements;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by source_element_key
     */
    private function elementsByKey(?array $block): array
    {
        if ($block === null) {
            return [];
        }

        $byKey = [];

        foreach ((array) ($block['source_elements'] ?? []) as $element) {
            $key = is_array($element) ? (string) ($element['source_element_key'] ?? '') : '';

            if ($key !== '') {
                $byKey[$key] = $element;
            }
        }

        return $byKey;
    }

    /**
     * Persist the verification result and the completion checkpoint atomically, but only if
     * this worker's token is still the current owner of the reservation.
     *
     * Del 3/Del 5: the AI verdict is never trusted blindly for "supported"/"partially_supported" —
     * a deterministic conflict check runs against the specific excerpt(s) it cited as support
     * (never the whole candidate pool, which would let unrelated excerpts manufacture false
     * support), and a citation outside the candidates actually offered is treated the same as no
     * citation at all. Either failure downgrades the verdict to not_supported before anything is
     * persisted.
     *
     * @return array{
     *     outcome: string,
     *     canonical_recording_candidate: ?array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }
     * }|null supported/unsupported outcome, or null if the reservation was lost
     */
    private function persist(
        int $claimId,
        string $token,
        EnterpriseWikiDocument $document,
        array $result,
        EnterpriseWikiPageVersion $version,
        ?array $block,
        string $fallbackSourceText,
        ?array $elementsByKeyOverride = null,
        bool $allowClaimDecisionReset = true,
        bool $allowBestPracticePromotion = true,
        bool $allowCanonicalRecording = true,
    ): ?array {
        return DB::transaction(function () use ($claimId, $token, $document, $result, $version, $block, $fallbackSourceText, $elementsByKeyOverride, $allowClaimDecisionReset, $allowBestPracticePromotion, $allowCanonicalRecording): ?array {
            $claim = EnterpriseWikiClaim::query()
                ->where('id', $claimId)
                ->where('verification_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return null;
            }

            $anchorFailure = $this->claimAnchorFailureReason($claim, $version);

            if ($anchorFailure !== null) {
                $this->classificationService->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                ]);

                return $this->verificationPersistenceResult('unsupported');
            }

            // A local anchor problem is always a per-occurrence defect (Del 8) — captured before
            // recordOutcome() below, which must key on the claim's pre-verification origin
            // (source_based/best_practice), never on internal_error/unsupported.
            $originalContentOrigin = (string) $claim->content_origin;

            $elementsByKey = $elementsByKeyOverride ?? $this->elementsByKey($block);
            $safetyNet = $this->applyDeterministicSafetyNet($claim->claim_text, $result, $elementsByKey, $block, $fallbackSourceText);

            return $this->applyVerdictOutcome(
                $claim,
                $safetyNet['verdict'],
                $result,
                $elementsByKey,
                $document,
                $originalContentOrigin,
                $fallbackSourceText,
                array_filter([
                    'deterministic_reason' => $safetyNet['deterministic_reason'],
                ]),
                $allowClaimDecisionReset,
                $allowBestPracticePromotion,
                $allowCanonicalRecording,
            );
        });
    }

    /**
     * Persist a claim matched by
     * EnterpriseWikiClaimCanonicalizationService::detectDeterministicSupport() — reuses the exact
     * same verdict-outcome mapping as an AI-confirmed "supported" result (applyVerdictOutcome())
     * so a deterministically-matched claim ends up in an identical final state to one AI
     * confirmed, just tagged with classification_basis = 'deterministic_verbatim_match' in
     * review_metadata for traceability. No AI call is made for this claim at all.
     *
     * @return array{
     *     outcome: string,
     *     canonical_recording_candidate: ?array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }
     * }|null supported/unsupported outcome, or null if the reservation was lost
     */
    private function persistDeterministicSupport(
        int $claimId,
        string $token,
        EnterpriseWikiDocument $document,
        EnterpriseWikiPageVersion $version,
        ?array $block,
        ?array $elementsByKeyOverride = null,
        bool $allowClaimDecisionReset = true,
        bool $allowBestPracticePromotion = true,
        bool $allowCanonicalRecording = true,
    ): ?array {
        return DB::transaction(function () use ($claimId, $token, $document, $version, $block, $elementsByKeyOverride, $allowClaimDecisionReset, $allowBestPracticePromotion, $allowCanonicalRecording): ?array {
            $claim = EnterpriseWikiClaim::query()
                ->where('id', $claimId)
                ->where('verification_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return null;
            }

            $anchorFailure = $this->claimAnchorFailureReason($claim, $version);

            if ($anchorFailure !== null) {
                $this->classificationService->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                ]);

                return $this->verificationPersistenceResult('unsupported');
            }

            $originalContentOrigin = (string) $claim->content_origin;
            $elementsByKey = $elementsByKeyOverride ?? $this->elementsByKey($block);

            $result = [
                'verdict' => WikiClaimVerificationAiClient::VERDICT_SUPPORTED,
                'same_meaning_across_languages' => true,
                'claim_language' => '',
                'source_language' => '',
                'supporting_source_element_keys' => array_keys($elementsByKey),
                'reason' => 'Ordrett eller nær-ordrett samsvar med kildeteksten, bekreftet deterministisk uten AI-kall.',
                'unsupported_parts' => '',
                'checks' => [],
            ];

            return $this->applyVerdictOutcome(
                $claim,
                WikiClaimVerificationAiClient::VERDICT_SUPPORTED,
                $result,
                $elementsByKey,
                $document,
                $originalContentOrigin,
                '',
                ['classification_basis' => 'deterministic_verbatim_match'],
                $allowClaimDecisionReset,
                $allowBestPracticePromotion,
                $allowCanonicalRecording,
            );
        });
    }

    /**
     * Shared verdict → claim-state mapping used both by the live verify() pipeline (persist())
     * and the narrow, single-run Del 7 re-evaluation command — one mechanism, never a parallel
     * one. Must be called from inside a transaction with $claim already locked.
     *
     * @param  array<string, array<string, mixed>>  $elementsByKey
     * @param  array<string, mixed>  $extraReviewMetadata  merged into review_metadata — used by
     *                                                     the Del 7 re-evaluation command to leave a provenance trail (reevaluated_at,
     *                                                     reevaluated_run_id, reevaluated_from_content_origin) without a new schema/column.
     */
    private function applyVerdictOutcome(
        EnterpriseWikiClaim $claim,
        string $verdict,
        array $result,
        array $elementsByKey,
        EnterpriseWikiDocument $document,
        string $originalContentOrigin,
        string $fallbackSourceText,
        array $extraReviewMetadata = [],
        bool $allowClaimDecisionReset = true,
        bool $allowBestPracticePromotion = true,
        bool $allowCanonicalRecording = true,
        string $source = EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION,
    ): array {
        if ($verdict === WikiClaimVerificationAiClient::VERDICT_CONTRADICTED
            || $verdict === WikiClaimVerificationAiClient::VERDICT_PARTIALLY_SUPPORTED
        ) {
            $recordingReason = $this->nullableString($result['reason'] ?? null);

            $this->classificationService->apply($claim, $source, [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => trim((string) ($result['unsupported_parts'] ?? '')) !== ''
                    ? $result['unsupported_parts']
                    : $result['reason'],
                'review_metadata' => array_merge([
                    'classification_basis' => 'semantic_verification',
                    'verdict' => $verdict,
                    'reason' => $result['reason'],
                    'unsupported_parts' => $result['unsupported_parts'] ?? '',
                ], $extraReviewMetadata),
                'generation_issue' => $verdict === WikiClaimVerificationAiClient::VERDICT_CONTRADICTED
                    ? 'claim_contradicted_by_source'
                    : 'claim_partially_supported',
            ]);

            if ($allowCanonicalRecording) {
                $this->canonicalizationService->recordOutcome(
                    $claim->fresh(['sourceReferences']),
                    $document->customer_id,
                    $originalContentOrigin,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    $recordingReason,
                );

                return $this->verificationPersistenceResult('unsupported');
            }

            return $this->verificationPersistenceResult(
                'unsupported',
                $this->canonicalRecordingCandidate(
                    $claim->id,
                    $originalContentOrigin,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    $recordingReason,
                    null,
                ),
            );
        }

        if ($verdict === WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED) {
            // A deterministic conflict (number/date/negation/modality/actor/scope/currency/subject
            // mismatch — applyDeterministicSafetyNet()) is concrete, checkable evidence that this
            // claim describes a specific fact, never a general professional statement — it must
            // never be waved through to best_practice just because its own wording is party-
            // agnostic.
            $hasDeterministicConflict = ($extraReviewMetadata['deterministic_reason'] ?? null) !== null;
            $bestPractice = $allowBestPracticePromotion
                && ! $hasDeterministicConflict
                && $this->isPositiveBestPracticeSuggestion($claim);

            // Run-38 fix: a plain not_supported verdict used to store review_reason/review_metadata
            // as null — leaving no trace of why AI rejected the claim, unlike the contradicted/
            // partially_supported branch above. Always keep the AI's own reason (or, for the rarer
            // case where the safety net downgraded an AI "supported"/"partially_supported" verdict
            // to not_supported, whatever conflict reason came through in $extraReviewMetadata).
            $notSupportedReason = trim((string) ($result['reason'] ?? '')) !== ''
                ? $result['reason']
                : 'Ingen kildeutdrag støtter påstanden.';

            $this->classificationService->apply($claim, $source, [
                'content_origin' => $bestPractice
                    ? EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    : EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $bestPractice
                    ? 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.'
                    : $notSupportedReason,
                'review_metadata' => $bestPractice
                    ? array_merge([
                        'statement_kind' => 'recommendation',
                        'classification_basis' => 'normative_language',
                        // Explicit, self-consistent record of the legitimate rescue (task rule 7):
                        // this claim's verification verdict against the customer's own source was
                        // not_supported, and it is DELIBERATELY still kept as best_practice — never
                        // left implicit or inferable only from classification_basis.
                        'verification_verdict' => $verdict,
                        'suggested_placement' => $claim->content_block_key,
                        'visible_wiki_link_recommendation' => 'auto_evaluate',
                    ], $extraReviewMetadata)
                    : array_merge([
                        'classification_basis' => 'semantic_verification',
                        'verdict' => $verdict,
                        'reason' => $result['reason'] ?? '',
                        'checks' => $result['checks'] ?? [],
                    ], $extraReviewMetadata),
                'generation_issue' => $bestPractice ? null : 'unsupported_generated_content',
            ]);

            $recordingReason = $bestPractice ? null : $notSupportedReason;

            if ($allowCanonicalRecording) {
                $this->canonicalizationService->recordOutcome(
                    $claim->fresh(['sourceReferences']),
                    $document->customer_id,
                    $originalContentOrigin,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    $recordingReason,
                );

                return $this->verificationPersistenceResult('unsupported');
            }

            return $this->verificationPersistenceResult(
                'unsupported',
                $this->canonicalRecordingCandidate(
                    $claim->id,
                    $originalContentOrigin,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    $recordingReason,
                    null,
                ),
            );
        }

        $hasExistingReferences = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->exists();

        $supportingExcerpt = '';

        if (! $hasExistingReferences) {
            $sourceElement = $this->resolveSupportingElement($result, $elementsByKey);
            $supportingExcerpt = (string) ($sourceElement['source_excerpt'] ?? mb_substr($fallbackSourceText, 0, 500));

            EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_element_key' => $sourceElement['source_element_key'] ?? null,
                'source_element_type' => $sourceElement['source_element_type'] ?? null,
                'source_row_key' => $sourceElement['source_row_key'] ?? null,
                'source_label' => $document->original_filename,
                'excerpt' => $supportingExcerpt,
                'source_hash' => $document->file_hash_sha256 ?? '',
                'page_reference' => $sourceElement['page_reference'] ?? null,
            ]);

            if ($allowClaimDecisionReset) {
                $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, true);
            }
        }

        $this->classificationService->apply($claim, $source, [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'generation_issue' => null,
            'review_metadata' => $extraReviewMetadata !== [] ? $extraReviewMetadata : null,
        ]);

        Log::info('[WIKI_CLAIM_VERIFICATION] Claim verified as supported via semantic (cross-language/paraphrase) match.', [
            'claim_id' => $claim->id,
            'ai_verdict' => $result['verdict'],
            'claim_language' => $result['claim_language'] ?? null,
            'source_language' => $result['source_language'] ?? null,
            'same_meaning_across_languages' => $result['same_meaning_across_languages'] ?? null,
            'supporting_source_element_keys' => $result['supporting_source_element_keys'] ?? [],
        ]);

        if ($allowCanonicalRecording) {
            $this->canonicalizationService->recordOutcome(
                $claim->fresh(['sourceReferences']),
                $document->customer_id,
                $originalContentOrigin,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
                $supportingExcerpt,
            );

            return $this->verificationPersistenceResult('supported');
        }

        return $this->verificationPersistenceResult(
            'supported',
            $this->canonicalRecordingCandidate(
                $claim->id,
                $originalContentOrigin,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
                null,
                $supportingExcerpt !== ''
                    ? $supportingExcerpt
                    : $this->supportingExcerptForCanonicalCandidate($result, $elementsByKey, $fallbackSourceText),
            ),
        );
    }

    /**
     * @param  array{
     *     claim_id: int,
     *     original_content_origin: string,
     *     verification_status: string,
     *     reason: ?string,
     *     supporting_excerpt: ?string
     * }|null  $candidate
     * @return array{
     *     outcome: string,
     *     canonical_recording_candidate: ?array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }
     * }
     */
    private function verificationPersistenceResult(string $outcome, ?array $candidate = null): array
    {
        return [
            'outcome' => $outcome,
            'canonical_recording_candidate' => $candidate,
        ];
    }

    /**
     * @return array{
     *     claim_id: int,
     *     original_content_origin: string,
     *     verification_status: string,
     *     reason: ?string,
     *     supporting_excerpt: ?string
     * }
     */
    private function canonicalRecordingCandidate(
        int $claimId,
        string $originalContentOrigin,
        string $verificationStatus,
        ?string $reason,
        ?string $supportingExcerpt,
    ): array {
        return [
            'claim_id' => $claimId,
            'original_content_origin' => $originalContentOrigin,
            'verification_status' => $verificationStatus,
            'reason' => $this->nullableString($reason),
            'supporting_excerpt' => $this->nullableString($supportingExcerpt),
        ];
    }

    private function supportingExcerptForCanonicalCandidate(array $result, array $elementsByKey, string $fallbackSourceText): ?string
    {
        $sourceElement = $this->resolveSupportingElement($result, $elementsByKey);
        $sourceExcerpt = $this->nullableString($sourceElement['source_excerpt'] ?? null);

        if ($sourceExcerpt !== null) {
            return $sourceExcerpt;
        }

        return $this->nullableString(mb_substr($fallbackSourceText, 0, 500));
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Del 3's hard backstop: an AI verdict of "supported"/"partially_supported" is never trusted
     * on its own. This checks, in order: (1) the cited supporting_source_element_keys actually
     * resolve to real candidates — an AI citation of a key that isn't one of the ones it was
     * given (or, for "supported", no citation at all) is treated as ungrounded and downgraded;
     * (2) a deterministic conflict (number, date/time, negation, modality, actor, scope,
     * currency) between the claim and specifically the excerpt(s) actually cited as support — not
     * the whole candidate pool (Del 5) — forces a downgrade to not_supported regardless of what
     * the AI concluded.
     *
     * @param  array<string, array<string, mixed>>  $elementsByKey
     * @return array{verdict: string, deterministic_reason: ?string}
     */
    private function applyDeterministicSafetyNet(string $claimText, array $result, array $elementsByKey, ?array $block, string $fallbackSourceText): array
    {
        $verdict = $result['verdict'];

        if (! in_array($verdict, [
            WikiClaimVerificationAiClient::VERDICT_SUPPORTED,
            WikiClaimVerificationAiClient::VERDICT_PARTIALLY_SUPPORTED,
        ], true)) {
            return ['verdict' => $verdict, 'deterministic_reason' => null];
        }

        // Run-38 fix: misattribution (the claim's named subject borrowing an action/property the
        // excerpts actually describe for a DIFFERENT named subject) is not something numbers/
        // negation/modality/actor/scope text-matching can detect — those check FACTS, not WHICH
        // named entity a fact belongs to. The AI is asked to reason about this explicitly as its
        // own "subject_entity" check (see WikiClaimVerificationAiClient's prompt); a
        // self-reported mismatch there is never allowed to still resolve as supported, exactly
        // like the raw-text conflict check below.
        if (($result['checks']['subject_entity'] ?? null) === 'mismatch') {
            Log::warning('[WIKI_CLAIM_VERIFICATION] AI-reported subject-entity mismatch overrode a supported/partially_supported verdict.', [
                'ai_verdict' => $verdict,
            ]);

            return ['verdict' => WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED, 'deterministic_reason' => 'subject_mismatch'];
        }

        $hadStructuredCandidates = $elementsByKey !== [];
        $supportingKeys = (array) ($result['supporting_source_element_keys'] ?? []);

        if ($hadStructuredCandidates) {
            $rawExcerpts = [];
            $supportingTexts = [];

            foreach ($supportingKeys as $key) {
                $excerpt = trim((string) ($elementsByKey[$key]['source_excerpt'] ?? ''));

                if ($excerpt !== '') {
                    $rawExcerpts[] = $excerpt;

                    // Run-38 fix: narrow each cited excerpt to its claim-relevant sentence(s)
                    // before combining — see EnterpriseWikiClaimCanonicalizationService::
                    // filterToRelevantSentences() for why this is needed now that a claim may
                    // legitimately cite several full paragraphs at once.
                    $supportingTexts[] = $this->canonicalizationService->filterToRelevantSentences($claimText, $excerpt);
                }
            }

            if ($rawExcerpts === []) {
                Log::warning('[WIKI_CLAIM_VERIFICATION] AI verdict cited no valid candidate source element — downgrading.', [
                    'ai_verdict' => $verdict,
                ]);

                return ['verdict' => WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED, 'deterministic_reason' => null];
            }

            $supportingText = implode("\n", $supportingTexts);

            // Run-38 fix (second pass): filterToRelevantSentences() now discards a clause entirely
            // rather than falling back to the raw excerpt (see its docblock) — correct when at
            // least one OTHER cited excerpt still contributes real content, but if EVERY cited
            // excerpt filtered down to nothing, that empty text would silently skip every
            // remaining deterministic check below (number/negation/modality/actor/scope/currency),
            // including genuine conflicts a lexically-unrelated excerpt can still carry (e.g. "15
            // minutter" vs. "30 minutes" sharing no words at all). Falling back to the raw cited
            // excerpts only in this all-empty case keeps the original claim-3780 fix (drop a
            // genuinely irrelevant clause from an otherwise-useful combination) while still
            // checking something instead of nothing when filtering discarded everything.
            if (trim($supportingText) === '') {
                $supportingText = implode("\n", $rawExcerpts);
            }

            // Backstop for when the AI's own subject_entity self-report (checked above) misses a
            // real misattribution — see detectSubjectMismatch()'s docblock for the concrete
            // production case that motivated this.
            if ($this->canonicalizationService->detectSubjectMismatch($claimText, $supportingTexts)) {
                Log::warning('[WIKI_CLAIM_VERIFICATION] Deterministic subject-entity mismatch overrode an AI verdict of support.', [
                    'ai_verdict' => $verdict,
                ]);

                return ['verdict' => WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED, 'deterministic_reason' => 'subject_mismatch'];
            }
        } elseif ($block !== null) {
            // Legacy claim with a real content block but no structured source elements on it —
            // the block's own markdown (this page's actual current content) is the closest thing
            // to a cited excerpt available, and is at least scoped to the page, not the document.
            $supportingText = (string) ($block['markdown'] ?? '');
        } else {
            // No structured source elements AND no block at all (e.g. a page version whose
            // content_blocks_json is empty) — there is no cited excerpt of any kind to compare
            // against. detectDeterministicConflict() is documented to run "only against the
            // excerpt(s) actually cited... not the whole candidate pool"; silently falling back to
            // $fallbackSourceText (up to 8000 chars of the whole source document) violates that
            // and produces a near-universal false negation/modality/scope mismatch, since a
            // document of any real length almost always contains a negation marker SOMEWHERE
            // unrelated to this specific claim (run-39: 64 claims wrongly flagged
            // negation_mismatch this way). Trust the AI verdict as-is instead of guessing.
            return ['verdict' => $verdict, 'deterministic_reason' => null];
        }

        $conflict = $this->canonicalizationService->detectDeterministicConflict($claimText, $supportingText);

        if ($conflict !== null) {
            Log::warning('[WIKI_CLAIM_VERIFICATION] Deterministic conflict overrode an AI verdict of support.', [
                'ai_verdict' => $verdict,
                'conflict' => $conflict,
            ]);

            return ['verdict' => WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED, 'deterministic_reason' => $conflict];
        }

        return ['verdict' => $verdict, 'deterministic_reason' => null];
    }

    /**
     * @param  array<string, array<string, mixed>>  $elementsByKey
     * @return array<string, mixed>
     */
    private function resolveSupportingElement(array $result, array $elementsByKey): array
    {
        foreach ((array) ($result['supporting_source_element_keys'] ?? []) as $key) {
            if (isset($elementsByKey[$key])) {
                return $elementsByKey[$key];
            }
        }

        return [];
    }

    /**
     * Del 4: confirms a claim already classified best_practice still qualifies — anchored to its
     * block/version, and its text is still genuinely normative (re-checked under lock, not just
     * trusted from the earlier unlocked read, in case a concurrent edit changed it) — without
     * ever calling the "prove this is in the source" verification AI. If the anchor is broken,
     * this is a real internal error exactly like any other claim. When policy allows canonical
     * recording, reuses recordOutcome() so a later occurrence of the same suggestion on another
     * page can be reused via findReusableFact() instead of repeating this check.
     *
     * @return array{
     *     outcome: string,
     *     canonical_recording_candidate: ?array{
     *         claim_id: int,
     *         original_content_origin: string,
     *         verification_status: string,
     *         reason: ?string,
     *         supporting_excerpt: ?string
     *     }
     * }|null unsupported outcome, or null if the reservation was lost
     */
    private function persistBestPracticeVerification(
        int $claimId,
        string $token,
        int $customerId,
        EnterpriseWikiPageVersion $version,
        bool $allowCanonicalRecording = true,
    ): ?array {
        return DB::transaction(function () use ($claimId, $token, $customerId, $version, $allowCanonicalRecording): ?array {
            $claim = EnterpriseWikiClaim::query()
                ->where('id', $claimId)
                ->where('verification_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return null;
            }

            $anchorFailure = $this->claimAnchorFailureReason($claim, $version);

            if ($anchorFailure !== null) {
                $this->classificationService->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                ]);

                return $this->verificationPersistenceResult('unsupported');
            }

            $originalContentOrigin = (string) $claim->content_origin;
            $reviewReason = trim((string) $claim->review_reason) !== ''
                ? $claim->review_reason
                : 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.';

            $this->classificationService->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $reviewReason,
                'generation_issue' => null,
            ]);

            if ($allowCanonicalRecording) {
                $this->canonicalizationService->recordOutcome(
                    $claim->fresh(['sourceReferences']),
                    $customerId,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    null,
                );

                return $this->verificationPersistenceResult('unsupported');
            }

            return $this->verificationPersistenceResult(
                'unsupported',
                $this->canonicalRecordingCandidate(
                    $claim->id,
                    $originalContentOrigin,
                    EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                    null,
                    null,
                ),
            );
        });
    }

    /**
     * Apply an already-verified canonical fact's outcome to a NEW occurrence without calling AI
     * — the actual cost/duplication saving of cross-page canonicalization (Del 6). Only reached
     * after EnterpriseWikiClaimCanonicalizationService::findReusableFact() has already confirmed
     * both the Tier-1 hard key and the Tier-2 deterministic equivalence check.
     *
     * @return string|null 'supported', 'unsupported', or null if the reservation was lost
     */
    private function persistReusedFact(int $claimId, string $token, EnterpriseWikiCanonicalFact $fact, bool $allowClaimDecisionReset = true): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $fact, $allowClaimDecisionReset): ?string {
            $claim = EnterpriseWikiClaim::query()
                ->where('id', $claimId)
                ->where('verification_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return null;
            }

            $finalOrigin = $this->canonicalizationService->resolveContentOriginForReuse($fact);
            $bestPractice = $finalOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;
            $unsupported = $finalOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT;

            // Explicitly tagged as a REUSE of an already-verified canonical fact, never left to be
            // confused with a fresh verification of this claim's own excerpt (task rule 9) — the
            // fact's own id and verification_status are recorded alongside so this claim's
            // authoritative decision stays traceable to exactly which prior decision it reused.
            $this->classificationService->apply($claim, EnterpriseWikiClaimClassificationService::SOURCE_VERIFICATION, [
                'content_origin' => $finalOrigin,
                'canonical_fact_id' => $fact->id,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $bestPractice
                    ? 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.'
                    : null,
                'review_metadata' => [
                    'classification_basis' => 'canonical_fact_reuse',
                    'reused_canonical_fact_id' => $fact->id,
                    'reused_canonical_fact_verification_status' => $fact->verification_status,
                ],
                'generation_issue' => $unsupported ? 'unsupported_generated_content' : null,
            ]);

            if ($finalOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                $hasExistingReferences = EnterpriseWikiSourceReference::query()
                    ->where('enterprise_wiki_claim_id', $claim->id)
                    ->exists();

                if ($hasExistingReferences && $allowClaimDecisionReset) {
                    $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, true);
                }

                return 'supported';
            }

            return 'unsupported';
        });
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }

    /**
     * Whether a claim's anchor is still valid against $version, and — when it is not — the
     * concrete diagnostic reason (App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService
     * and System Owner diagnostics rely on these exact codes, not a single generic one):
     *
     *   - 'wrong_version': the claim is tied to a different page version than the one being
     *     checked (e.g. it belongs to a superseded version).
     *   - 'missing_block': the claim has a content_block_key, but no block with that key exists
     *     in $version's current content_blocks_json — the block was removed or never persisted.
     *   - 'genuine_content_mismatch': the claim's anchor text was not found in its resolved
     *     block's visible text (or, for a legacy claim with no content_block_key, in the whole
     *     page's visible text) even after normalizing both sides — a real anchoring problem, not
     *     a false positive from wikilink/Markdown markup differing from the plain-text anchor.
     *
     * Priority order (Wiki run-34 fix): a claim with a stable content_block_key is checked
     * against ITS OWN resolved block only — never against the whole page — so an anchor that's
     * genuinely present in one block can't be mistaken as valid because a different block on the
     * same page happens to contain similar text. Whole-page markdown is used only as a fallback
     * for claims that predate block-level provenance (no content_block_key at all).
     *
     * @return string|null null when the anchor is valid
     */
    private function claimAnchorFailureReason(EnterpriseWikiClaim $claim, EnterpriseWikiPageVersion $version): ?string
    {
        if ((int) $claim->enterprise_wiki_page_version_id !== (int) $version->id) {
            return 'wrong_version';
        }

        $anchor = trim((string) ($claim->page_excerpt ?: $claim->claim_text));

        if ($anchor === '') {
            return 'genuine_content_mismatch';
        }

        $blockKey = trim((string) ($claim->content_block_key ?? ''));

        if ($blockKey !== '') {
            $block = $this->findBlockByKey($version, $blockKey);

            if ($block === null) {
                return 'missing_block';
            }

            return $this->textNormalizer->contains((string) ($block['markdown'] ?? ''), $anchor)
                ? null
                : 'genuine_content_mismatch';
        }

        // Legacy claim with no stable block anchor — whole-page markdown is the fallback.
        return $this->textNormalizer->contains((string) ($version->content_markdown ?? ''), $anchor)
            ? null
            : 'genuine_content_mismatch';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBlockByKey(EnterpriseWikiPageVersion $version, string $blockKey): ?array
    {
        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (is_array($block) && (string) ($block['block_key'] ?? '') === $blockKey) {
                return $block;
            }
        }

        return null;
    }

    private function markInternalGenerationError(EnterpriseWikiClaim $claim, string $issue, ?string $token = null): bool
    {
        $query = EnterpriseWikiClaim::query()
            ->where('id', $claim->id);

        if ($token !== null) {
            $query->where('verification_claim_token', $token);
        }

        return $query
            ->update([
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => null,
                'generation_issue' => $issue,
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]) > 0;
    }

    /**
     * Only reached for a claim that did NOT take the best-practice fast path above — either it
     * was never best_practice, or its block never declared best_practice at all, or a prior
     * reconfirm found it had drifted into a party/agreement-specific current-state assertion.
     *
     * Run-482 fix: this used to ALSO require the claim's own content_origin/review_metadata to
     * already say best_practice (i.e. only ever "reconfirming" a tag the AI got right at
     * generation time) — so a genuinely general-practice sentence the model mis-tagged
     * source_based (a real, observed generation-time inconsistency, not a hypothetical) had no
     * path to rescue: it failed source verification and was recorded as
     * unsupported_generated_content — a blocking claim-integrity defect — purely because of the
     * model's own labeling mistake, not because the text was actually presented as a customer
     * fact.
     *
     * Run-486-follow-up fix: this used to also require the claim's own extracted sentence to
     * carry an explicit recommendation marker ("bør"/"anbefales"/...) — but Procynia's best-
     * practice text is written in the same formal, declarative register as any other Wiki text
     * (CLAUDE.md: the distinction is content_origin plus UI labeling, never wording), so a marker
     * requirement rejected genuine, plainly-stated professional content just because it wasn't
     * phrased as advice. isEligibleForBestPractice() (party-/agreement-specific drift check only)
     * is now the deterministic content criterion, replacing the marker requirement in either
     * direction.
     *
     * A second, structural requirement guards against the failure mode a text-only check cannot
     * see: a decontextualized, never-anchored claim (no content_block_key, or one that no longer
     * resolves to a real block on the page's CURRENT version) has no page content it is actually
     * part of — "blokktilhørighet" (item 3/5 of the Del 3 spec) — so it is never eligible for
     * rescue regardless of how general its wording reads. This is what still correctly blocks an
     * arbitrary undocumented factual claim with generic phrasing and no block anchor at all, while
     * still rescuing a genuinely general-practice sentence anchored to a real (if mis-tagged)
     * content block.
     */
    private function isPositiveBestPracticeSuggestion(EnterpriseWikiClaim $claim): bool
    {
        if (! $this->canonicalizationService->isEligibleForBestPractice($claim->claim_text)) {
            return false;
        }

        $blockKey = trim((string) ($claim->content_block_key ?? ''));
        $version = $claim->version;

        if ($blockKey === '' || ! $version instanceof EnterpriseWikiPageVersion) {
            return false;
        }

        return $this->findBlockByKey($version, $blockKey) !== null;
    }
}
