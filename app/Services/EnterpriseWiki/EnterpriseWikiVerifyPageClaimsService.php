<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
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

    public function __construct(
        private readonly WikiClaimVerificationAiClient $aiClient,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
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
                // Authoritative checkpoint: this claim already completed verification —
                // supported or not — so skip without an AI call.
                if ($claim->verified_at !== null) {
                    $skipped++;

                    continue;
                }

                $token = (string) Str::uuid();
                $reservation = $this->reserve($claim, $token);

                if ($reservation === 'completed') {
                    $skipped++;

                    continue;
                }

                if ($reservation === 'busy') {
                    $busy++;

                    continue;
                }

                $claims++;

                $anchorFailure = $this->claimAnchorFailureReason($claim, $version);

                if ($anchorFailure !== null) {
                    $this->markInternalGenerationError($claim, $anchorFailure);
                    $noSupport++;

                    continue;
                }

                // Best-practice classification fix: a claim already classified best_practice
                // (inherited from its generation block) must never be run through "prove this is
                // in the customer's source document" — that is exactly what best_practice content
                // deliberately is not, and doing so is precisely how a legitimate suggestion used
                // to get silently downgraded to unsupported_generated_content (or, worse, upgraded
                // to source_based on a coincidental partial text match). Only re-validate that it
                // is still genuinely normative and still anchored — never prove source support.
                if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    && $this->canonicalizationService->isGenuineBestPracticeText($claim->claim_text)
                ) {
                    $outcome = $this->persistBestPracticeVerification($claim->id, $token, $run->customer_id, $version);

                    if ($outcome === null) {
                        $busy++;

                        continue;
                    }

                    $noSupport++;

                    continue;
                }

                // Cross-page overgeneration fix: before calling AI, check whether this claim
                // expresses a fact already verified for another occurrence (same customer,
                // content_origin, document/source version, and cited source elements — Del 3/6).
                // Only claims carrying a real structured source reference are eligible; a claim
                // with none (e.g. an unstructured/manual reference) has nothing safe to key on
                // and is always verified independently.
                $reusableFact = $this->canonicalizationService->findReusableFact($claim, $run->customer_id);

                if ($reusableFact !== null) {
                    Log::info('[WIKI_CLAIM_VERIFICATION] Reusing an existing canonical fact verification result.', [
                        'claim_id' => $claim->id,
                        'canonical_fact_id' => $reusableFact->id,
                        'verification_status' => $reusableFact->verification_status,
                    ]);

                    $outcome = $this->persistReusedFact($claim->id, $token, $reusableFact);

                    if ($outcome === null) {
                        $busy++;

                        continue;
                    }

                    $reused++;

                    if ($outcome === 'unsupported') {
                        $noSupport++;
                    } else {
                        $references++;
                    }

                    continue;
                }

                $block = $this->findBlockByKey($version, (string) ($claim->content_block_key ?? ''));
                $candidateElements = $this->candidateElementsForAi($block);

                // Run-38 fix: a verbatim/near-verbatim claim never needs an AI call at all — see
                // EnterpriseWikiClaimCanonicalizationService::detectDeterministicSupport().
                if ($this->canonicalizationService->detectDeterministicSupport(
                    $claim->claim_text,
                    array_column($candidateElements, 'excerpt'),
                )) {
                    $outcome = $this->persistDeterministicSupport($claim->id, $token, $document, $version, $block);

                    if ($outcome === null) {
                        $busy++;

                        continue;
                    }

                    if ($outcome === 'unsupported') {
                        $noSupport++;
                    } else {
                        $references++;
                    }

                    continue;
                }

                try {
                    $result = $this->aiClient->verifyClaim(
                        claimText: $claim->claim_text,
                        sourceElements: $candidateElements,
                        fallbackSourceText: $sourceText,
                        languageCode: $languageCode,
                        blockMarkdown: $block['markdown'] ?? null,
                        documentLabel: $document->original_filename,
                    );
                } catch (Throwable $e) {
                    $this->release($claim->id, $token);

                    throw $e;
                }

                $outcome = $this->persist($claim->id, $token, $document, $result, $version, $block, $sourceText);

                if ($outcome === null) {
                    // Another worker reclaimed this lease as stale while the AI call was in
                    // flight; that worker's own attempt is the one that will persist a result.
                    $busy++;

                    continue;
                }

                if ($outcome === 'unsupported') {
                    $noSupport++;
                } else {
                    $references++;
                }
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

        $finalVerdict = $deterministicMatch
            ? WikiClaimVerificationAiClient::VERDICT_SUPPORTED
            : $this->applyDeterministicSafetyNet($claim->claim_text, $result, $elementsByKey, $block, $fallbackSourceText);

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

        $newContentOrigin = DB::transaction(function () use ($claim, $result, $elementsByKey, $document, $finalVerdict, $run, $fallbackSourceText, $deterministicMatch): ?string {
            $locked = EnterpriseWikiClaim::query()->whereKey($claim->id)->lockForUpdate()->first();

            if ($locked === null || $locked->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT) {
                return null;
            }

            $originalContentOrigin = (string) $locked->content_origin;

            $this->applyVerdictOutcome($locked, $finalVerdict, $result, $elementsByKey, $document, $originalContentOrigin, $fallbackSourceText, [
                'classification_basis' => $deterministicMatch ? 'deterministic_verbatim_match' : 'scoped_run_reevaluation',
                'reevaluated_at' => now()->toIso8601String(),
                'reevaluated_from_content_origin' => $originalContentOrigin,
                'reevaluated_run_id' => $run->id,
            ]);

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
     * @return string|null 'supported', 'unsupported', or null if the reservation was lost
     */
    private function persist(int $claimId, string $token, EnterpriseWikiDocument $document, array $result, EnterpriseWikiPageVersion $version, ?array $block, string $fallbackSourceText): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $document, $result, $version, $block, $fallbackSourceText): ?string {
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
                $claim->update([
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                    'verified_at' => now(),
                    'verification_claimed_at' => null,
                    'verification_claim_token' => null,
                ]);

                return 'unsupported';
            }

            // A local anchor problem is always a per-occurrence defect (Del 8) — captured before
            // recordOutcome() below, which must key on the claim's pre-verification origin
            // (source_based/best_practice), never on internal_error/unsupported.
            $originalContentOrigin = (string) $claim->content_origin;

            $elementsByKey = $this->elementsByKey($block);
            $verdict = $this->applyDeterministicSafetyNet($claim->claim_text, $result, $elementsByKey, $block, $fallbackSourceText);

            return $this->applyVerdictOutcome($claim, $verdict, $result, $elementsByKey, $document, $originalContentOrigin, $fallbackSourceText);
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
     * @return string|null 'supported', 'unsupported' (anchor failure), or null if the reservation was lost
     */
    private function persistDeterministicSupport(int $claimId, string $token, EnterpriseWikiDocument $document, EnterpriseWikiPageVersion $version, ?array $block): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $document, $version, $block): ?string {
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
                $claim->update([
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                    'verified_at' => now(),
                    'verification_claimed_at' => null,
                    'verification_claim_token' => null,
                ]);

                return 'unsupported';
            }

            $originalContentOrigin = (string) $claim->content_origin;
            $elementsByKey = $this->elementsByKey($block);

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
    ): string {
        if ($verdict === WikiClaimVerificationAiClient::VERDICT_CONTRADICTED
            || $verdict === WikiClaimVerificationAiClient::VERDICT_PARTIALLY_SUPPORTED
        ) {
            $claim->update([
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
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            $this->canonicalizationService->recordOutcome(
                $claim->fresh(['sourceReferences']),
                $document->customer_id,
                $originalContentOrigin,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                $result['reason'],
            );

            return 'unsupported';
        }

        if ($verdict === WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED) {
            $bestPractice = $this->isPositiveBestPracticeSuggestion($claim);

            // Run-38 fix: a plain not_supported verdict used to store review_reason/review_metadata
            // as null — leaving no trace of why AI rejected the claim, unlike the contradicted/
            // partially_supported branch above. Always keep the AI's own reason (or, for the rarer
            // case where the safety net downgraded an AI "supported"/"partially_supported" verdict
            // to not_supported, whatever conflict reason came through in $extraReviewMetadata).
            $notSupportedReason = trim((string) ($result['reason'] ?? '')) !== ''
                ? $result['reason']
                : 'Ingen kildeutdrag støtter påstanden.';

            $claim->update([
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
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            $this->canonicalizationService->recordOutcome(
                $claim->fresh(['sourceReferences']),
                $document->customer_id,
                $originalContentOrigin,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                $bestPractice ? null : $notSupportedReason,
            );

            return 'unsupported';
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

            $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, true);
        }

        $claim->update([
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'review_reason' => null,
            'generation_issue' => null,
            'review_metadata' => $extraReviewMetadata !== [] ? $extraReviewMetadata : null,
            'verified_at' => now(),
            'verification_claimed_at' => null,
            'verification_claim_token' => null,
        ]);

        Log::info('[WIKI_CLAIM_VERIFICATION] Claim verified as supported via semantic (cross-language/paraphrase) match.', [
            'claim_id' => $claim->id,
            'ai_verdict' => $result['verdict'],
            'claim_language' => $result['claim_language'] ?? null,
            'source_language' => $result['source_language'] ?? null,
            'same_meaning_across_languages' => $result['same_meaning_across_languages'] ?? null,
            'supporting_source_element_keys' => $result['supporting_source_element_keys'] ?? [],
        ]);

        $this->canonicalizationService->recordOutcome(
            $claim->fresh(['sourceReferences']),
            $document->customer_id,
            $originalContentOrigin,
            EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
            $supportingExcerpt,
        );

        return 'supported';
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
     */
    private function applyDeterministicSafetyNet(string $claimText, array $result, array $elementsByKey, ?array $block, string $fallbackSourceText): string
    {
        $verdict = $result['verdict'];

        if (! in_array($verdict, [
            WikiClaimVerificationAiClient::VERDICT_SUPPORTED,
            WikiClaimVerificationAiClient::VERDICT_PARTIALLY_SUPPORTED,
        ], true)) {
            return $verdict;
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

            return WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED;
        }

        $hadStructuredCandidates = $elementsByKey !== [];
        $supportingKeys = (array) ($result['supporting_source_element_keys'] ?? []);

        if ($hadStructuredCandidates) {
            $supportingTexts = [];

            foreach ($supportingKeys as $key) {
                $excerpt = trim((string) ($elementsByKey[$key]['source_excerpt'] ?? ''));

                if ($excerpt !== '') {
                    // Run-38 fix: narrow each cited excerpt to its claim-relevant sentence(s)
                    // before combining — see EnterpriseWikiClaimCanonicalizationService::
                    // filterToRelevantSentences() for why this is needed now that a claim may
                    // legitimately cite several full paragraphs at once.
                    $supportingTexts[] = $this->canonicalizationService->filterToRelevantSentences($claimText, $excerpt);
                }
            }

            if ($supportingTexts === []) {
                Log::warning('[WIKI_CLAIM_VERIFICATION] AI verdict cited no valid candidate source element — downgrading.', [
                    'ai_verdict' => $verdict,
                ]);

                return WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED;
            }

            $supportingText = implode("\n", $supportingTexts);

            // Backstop for when the AI's own subject_entity self-report (checked above) misses a
            // real misattribution — see detectSubjectMismatch()'s docblock for the concrete
            // production case that motivated this.
            if ($this->canonicalizationService->detectSubjectMismatch($claimText, $supportingTexts)) {
                Log::warning('[WIKI_CLAIM_VERIFICATION] Deterministic subject-entity mismatch overrode an AI verdict of support.', [
                    'ai_verdict' => $verdict,
                ]);

                return WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED;
            }
        } else {
            // Legacy claim with no structured block source elements — the AI verified against
            // the whole-document fallback text, so that is what the deterministic check compares
            // against too.
            $supportingText = $block['markdown'] ?? $fallbackSourceText;
        }

        $conflict = $this->canonicalizationService->detectDeterministicConflict($claimText, $supportingText);

        if ($conflict !== null) {
            Log::warning('[WIKI_CLAIM_VERIFICATION] Deterministic conflict overrode an AI verdict of support.', [
                'ai_verdict' => $verdict,
                'conflict' => $conflict,
            ]);

            return WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED;
        }

        return $verdict;
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
     * this is a real internal error exactly like any other claim. Reuses recordOutcome() so a
     * later occurrence of the same suggestion on another page can be reused via
     * findReusableFact() instead of repeating this check.
     *
     * @return string|null 'unsupported' (verified as a legitimate suggestion, or a real anchor
     *                     error), or null if the reservation was lost
     */
    private function persistBestPracticeVerification(int $claimId, string $token, int $customerId, EnterpriseWikiPageVersion $version): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $customerId, $version): ?string {
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
                $claim->update([
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => null,
                    'generation_issue' => $anchorFailure,
                    'verified_at' => now(),
                    'verification_claimed_at' => null,
                    'verification_claim_token' => null,
                ]);

                return 'unsupported';
            }

            $reviewReason = trim((string) $claim->review_reason) !== ''
                ? $claim->review_reason
                : 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.';

            $claim->update([
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $reviewReason,
                'generation_issue' => null,
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            $this->canonicalizationService->recordOutcome(
                $claim->fresh(['sourceReferences']),
                $customerId,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_UNSUPPORTED,
                null,
            );

            return 'unsupported';
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
    private function persistReusedFact(int $claimId, string $token, EnterpriseWikiCanonicalFact $fact): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $fact): ?string {
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

            $claim->update([
                'content_origin' => $finalOrigin,
                'canonical_fact_id' => $fact->id,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => $bestPractice
                    ? 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.'
                    : null,
                'generation_issue' => $unsupported ? 'unsupported_generated_content' : null,
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            if ($finalOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                $hasExistingReferences = EnterpriseWikiSourceReference::query()
                    ->where('enterprise_wiki_claim_id', $claim->id)
                    ->exists();

                if ($hasExistingReferences) {
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

    private function markInternalGenerationError(EnterpriseWikiClaim $claim, string $issue): void
    {
        EnterpriseWikiClaim::query()
            ->where('id', $claim->id)
            ->update([
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => null,
                'generation_issue' => $issue,
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);
    }

    /**
     * Only reached for a claim that did NOT take the best-practice fast path above — either it
     * was never best_practice, or it was but its wording had already drifted into an unverified
     * factual assertion (Del 4 test: "bør" → "har" requires re-classification). In both cases the
     * text must still genuinely read as a recommendation now, under the AI verdict this method
     * gates — a stale content_origin/review_metadata tag is never trusted on its own.
     */
    private function isPositiveBestPracticeSuggestion(EnterpriseWikiClaim $claim): bool
    {
        if (! $this->canonicalizationService->isGenuineBestPracticeText($claim->claim_text)) {
            return false;
        }

        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return true;
        }

        $metadata = (array) ($claim->review_metadata ?? []);

        return in_array(($metadata['classification_basis'] ?? null), [
            'ai_block_content_origin',
            'approved_best_practice',
        ], true);
    }
}
