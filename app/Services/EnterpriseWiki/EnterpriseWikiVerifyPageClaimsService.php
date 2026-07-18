<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
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
    ) {}

    /**
     * @return array{pages: int, claims: int, references: int, skipped: int, no_support: int, busy: int}
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

                // Defensive fallback for a claim that already has a reference but predates
                // the checkpoint column being set: record the checkpoint instead of
                // calling AI again.
                $hasRef = EnterpriseWikiSourceReference::query()
                    ->where('enterprise_wiki_claim_id', $claim->id)
                    ->exists();

                if ($hasRef) {
                    $claim->update(['verified_at' => now()]);
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

                $outcome = $this->persist($claim->id, $token, $document, $result);

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
    private function persist(int $claimId, string $token, EnterpriseWikiDocument $document, array $result): ?string
    {
        return DB::transaction(function () use ($claimId, $token, $document, $result): ?string {
            $claim = EnterpriseWikiClaim::query()
                ->where('id', $claimId)
                ->where('verification_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                return null;
            }

            if (! $result['supported']) {
                $claim->update([
                    'verified_at' => now(),
                    'verification_claimed_at' => null,
                    'verification_claim_token' => null,
                ]);

                return 'unsupported';
            }

            EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'excerpt' => $result['excerpt'],
                'source_hash' => $document->file_hash_sha256 ?? '',
            ]);

            $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, true);

            $claim->update([
                'verified_at' => now(),
                'verification_claimed_at' => null,
                'verification_claim_token' => null,
            ]);

            return 'supported';
        });
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
