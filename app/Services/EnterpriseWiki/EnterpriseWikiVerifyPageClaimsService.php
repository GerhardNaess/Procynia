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
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
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

                // Cross-page overgeneration fix: before calling AI, check whether this claim
                // expresses a fact already verified for another occurrence (same customer,
                // content_origin, document/source version, and cited source elements — Del 3/6).
                // Only claims carrying a real structured source reference are eligible; a claim
                // with none (e.g. an unstructured/manual reference) has nothing safe to key on
                // and is always verified independently.
                $reusableFact = $this->canonicalizationService->findReusableFact($claim, $run->customer_id);

                if ($reusableFact !== null) {
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

                try {
                    $result = $this->aiClient->verifyClaim(
                        claimText: $claim->claim_text,
                        sourceText: $sourceText,
                        languageCode: $languageCode,
                    );
                } catch (Throwable $e) {
                    $this->release($claim->id, $token);

                    throw $e;
                }

                $outcome = $this->persist($claim->id, $token, $document, $result, $version);

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
     * Persist the verification result and the completion checkpoint atomically, but only if
     * this worker's token is still the current owner of the reservation.
     *
     * @return string|null 'supported', 'unsupported', or null if the reservation was lost
     */
    private function persist(int $claimId, string $token, EnterpriseWikiDocument $document, array $result, EnterpriseWikiPageVersion $version): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $document, $result, $version): ?string {
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

            if (! $result['supported']) {
                $bestPractice = $this->isPositiveBestPracticeSuggestion($claim);

                $claim->update([
                    'content_origin' => $bestPractice
                        ? EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                        : EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'review_reason' => $bestPractice
                        ? 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.'
                        : null,
                    'review_metadata' => $bestPractice
                        ? [
                            'statement_kind' => 'recommendation',
                            'classification_basis' => 'normative_language',
                            'suggested_placement' => $claim->content_block_key,
                            'visible_wiki_link_recommendation' => 'auto_evaluate',
                        ]
                        : null,
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
                    null,
                );

                return 'unsupported';
            }

            $hasExistingReferences = EnterpriseWikiSourceReference::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->exists();

            if (! $hasExistingReferences) {
                $sourceElement = $this->matchSourceElement($document, (string) ($result['excerpt'] ?? ''));

                EnterpriseWikiSourceReference::query()->create([
                    'enterprise_wiki_claim_id' => $claim->id,
                    'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $document->id,
                    'source_element_key' => $sourceElement['source_element_key'] ?? null,
                    'source_element_type' => $sourceElement['source_element_type'] ?? null,
                    'source_row_key' => $sourceElement['source_row_key'] ?? null,
                    'source_label' => $document->original_filename,
                    'excerpt' => $result['excerpt'],
                    'source_hash' => $document->file_hash_sha256 ?? '',
                    'page_reference' => $sourceElement['page_reference'] ?? null,
                ]);

                $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, true);
            }

            $claim->update([
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'review_reason' => null,
                'generation_issue' => null,
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            $this->canonicalizationService->recordOutcome(
                $claim->fresh(['sourceReferences']),
                $document->customer_id,
                $originalContentOrigin,
                EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED,
                (string) ($result['excerpt'] ?? ''),
            );

            return 'supported';
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
     * @return array<string, mixed>|null
     */
    private function matchSourceElement(EnterpriseWikiDocument $document, string $excerpt): ?array
    {
        $excerpt = trim($excerpt);

        if ($excerpt === '') {
            return null;
        }

        foreach ($this->sourceElementService->inspect($document)['elements'] as $element) {
            $referenceText = (string) ($element['reference_text'] ?? '');

            if ($this->containsNormalized($referenceText, $excerpt) || $this->containsNormalized($excerpt, $referenceText)) {
                return $element;
            }
        }

        return null;
    }

    private function isPositiveBestPracticeSuggestion(EnterpriseWikiClaim $claim): bool
    {
        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return true;
        }

        $metadata = (array) ($claim->review_metadata ?? []);

        return in_array(($metadata['classification_basis'] ?? null), [
            'ai_block_content_origin',
            'approved_best_practice',
        ], true);
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        $needle = $normalize($needle);

        return $needle !== '' && str_contains(
            mb_strtolower($normalize($haystack)),
            mb_strtolower($needle),
        );
    }
}
