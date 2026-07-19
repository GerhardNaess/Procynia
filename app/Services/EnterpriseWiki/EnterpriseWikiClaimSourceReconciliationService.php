<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimSourceReconciliationAttempt;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks a newly-processed Enterprise Wiki document against existing claims of the same
 * customer that currently have no source reference (including manually-approved claims — see
 * EnterpriseWikiClaim::hasSourceReference()) and, where the document actually supports a claim,
 * attaches a real EnterpriseWikiSourceReference to it.
 *
 * Deliberately reuses the existing claim-verification AI client
 * (WikiClaimVerificationAiClient) rather than a parallel engine — the only difference from
 * page-authoring-time verification is the checkpoint: EnterpriseWikiClaim.verified_at cannot
 * tell "checked against document A" from "checked against document B", so a persistent
 * EnterpriseWikiClaimSourceReconciliationAttempt row per (claim, document) pair is the actual
 * checkpoint, guarded by the same reserve-then-lease protocol used elsewhere for AI calls in
 * this subsystem. Never regenerates pages, page versions, or claims.
 */
class EnterpriseWikiClaimSourceReconciliationService
{
    /**
     * Must exceed the owning job's timeout (see ReconcileEnterpriseWikiClaimSourcesForDocument
     * ::TIMEOUT_SECONDS) by a safety margin, or a still-running worker could have its
     * reservation reclaimed by another worker before it finishes.
     */
    private const LEASE_SECONDS = ReconcileEnterpriseWikiClaimSourcesForDocument::TIMEOUT_SECONDS + 300;

    public function __construct(
        private readonly WikiClaimVerificationAiClient $aiClient,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
    ) {}

    /**
     * @return array{claims_checked: int, sources_found: int, skipped: int, errors: int}
     */
    public function reconcileForDocument(EnterpriseWikiDocument $document): array
    {
        $counts = ['claims_checked' => 0, 'sources_found' => 0, 'skipped' => 0, 'errors' => 0];

        if ($document->document_status !== EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
            || trim((string) $document->extracted_text) === ''
            || ! WikiClaimVerificationAiClient::isAvailable()
        ) {
            return $counts;
        }

        $customerId = $document->customer_id;
        $document->loadMissing('customer.language');
        $languageCode = $document->customer?->language?->code ?? 'no';

        $claims = EnterpriseWikiClaim::query()
            ->whereHas('page', fn ($query) => $query->where('customer_id', $customerId))
            ->doesntHave('sourceReferences')
            ->get();

        foreach ($claims as $claim) {
            $counts['claims_checked']++;

            $attempt = $this->reserve($claim, $document, $customerId);

            if ($attempt === null) {
                $counts['skipped']++;

                continue;
            }

            try {
                $result = $this->aiClient->verifyClaim(
                    claimText: $claim->claim_text,
                    sourceElements: [],
                    fallbackSourceText: (string) $document->extracted_text,
                    languageCode: $languageCode,
                    documentLabel: $document->original_filename,
                );
            } catch (Throwable $e) {
                $this->markError($attempt, $e->getMessage());
                $counts['errors']++;

                continue;
            }

            $verdict = $result['verdict'];

            if ($verdict === WikiClaimVerificationAiClient::VERDICT_SUPPORTED) {
                $conflict = $this->canonicalizationService->detectDeterministicConflict(
                    $claim->claim_text,
                    (string) $document->extracted_text,
                );

                if ($conflict !== null) {
                    $verdict = WikiClaimVerificationAiClient::VERDICT_NOT_SUPPORTED;
                }
            }

            if ($verdict !== WikiClaimVerificationAiClient::VERDICT_SUPPORTED) {
                $this->markUnsupported($attempt);

                continue;
            }

            if ($this->persistSupported($claim, $document, $attempt, mb_substr((string) $document->extracted_text, 0, 500))) {
                $counts['sources_found']++;
            }
        }

        return $counts;
    }

    /**
     * Reserve the (claim, document) attempt row for this worker. Returns null when the pair is
     * already completed, or currently owned by another still-live worker — in both cases the
     * caller must skip without making an AI call.
     */
    private function reserve(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        int $customerId,
    ): ?EnterpriseWikiClaimSourceReconciliationAttempt {
        $token = (string) Str::uuid();

        try {
            return EnterpriseWikiClaimSourceReconciliationAttempt::query()->create([
                'customer_id' => $customerId,
                'enterprise_wiki_claim_id' => $claim->id,
                'enterprise_wiki_document_id' => $document->id,
                'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_RUNNING,
                'claimed_at' => now(),
                'claim_token' => $token,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }
        }

        return $this->reclaimIfStale($claim, $document, $token);
    }

    /**
     * The row already existed (a prior attempt for this exact pair). Only reclaim it if it is
     * still pending, or running with an expired lease — a conditional UPDATE guarantees only one
     * concurrent caller wins the reclaim.
     */
    private function reclaimIfStale(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        string $token,
    ): ?EnterpriseWikiClaimSourceReconciliationAttempt {
        $staleBefore = now()->subSeconds(self::LEASE_SECONDS);

        $updated = EnterpriseWikiClaimSourceReconciliationAttempt::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('enterprise_wiki_document_id', $document->id)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_PENDING)
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_RUNNING)
                            ->where('claimed_at', '<', $staleBefore);
                    });
            })
            ->update([
                'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_RUNNING,
                'claimed_at' => now(),
                'claim_token' => $token,
            ]);

        if ($updated !== 1) {
            return null;
        }

        return EnterpriseWikiClaimSourceReconciliationAttempt::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('enterprise_wiki_document_id', $document->id)
            ->where('claim_token', $token)
            ->first();
    }

    /**
     * Persist the new source reference under a token-guarded row lock — if this worker's lease
     * was somehow reclaimed by another worker mid-AI-call, the token check fails and we make no
     * changes. Returns whether a source reference was actually created.
     */
    private function persistSupported(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        EnterpriseWikiClaimSourceReconciliationAttempt $attempt,
        string $excerpt,
    ): bool {
        $created = DB::transaction(function () use ($claim, $document, $attempt, $excerpt) {
            $locked = EnterpriseWikiClaimSourceReconciliationAttempt::query()
                ->whereKey($attempt->id)
                ->where('claim_token', $attempt->claim_token)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            $freshClaim = EnterpriseWikiClaim::query()->lockForUpdate()->find($claim->id);

            if ($freshClaim === null || $freshClaim->hasSourceReference()) {
                $locked->update([
                    'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_COMPLETED,
                    'result' => EnterpriseWikiClaimSourceReconciliationAttempt::RESULT_SUPPORTED,
                    'attempted_at' => now(),
                ]);

                return false;
            }

            $sourceReference = EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'excerpt' => $excerpt,
            ]);

            $this->lintService->resetClaimDecisionAfterFirstSourceReference($freshClaim, true);

            $locked->update([
                'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_COMPLETED,
                'result' => EnterpriseWikiClaimSourceReconciliationAttempt::RESULT_SUPPORTED,
                'enterprise_wiki_source_reference_id' => $sourceReference->id,
                'attempted_at' => now(),
            ]);

            return true;
        });

        if ($created) {
            $this->lintService->resolveClaimMissingSourceFinding($claim);
        }

        return $created;
    }

    private function markUnsupported(EnterpriseWikiClaimSourceReconciliationAttempt $attempt): void
    {
        $attempt->update([
            'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_COMPLETED,
            'result' => EnterpriseWikiClaimSourceReconciliationAttempt::RESULT_UNSUPPORTED,
            'attempted_at' => now(),
        ]);
    }

    private function markError(EnterpriseWikiClaimSourceReconciliationAttempt $attempt, string $message): void
    {
        $attempt->update([
            'status' => EnterpriseWikiClaimSourceReconciliationAttempt::STATUS_COMPLETED,
            'result' => EnterpriseWikiClaimSourceReconciliationAttempt::RESULT_ERROR,
            'error_message' => $message,
            'attempted_at' => now(),
        ]);
    }
}
