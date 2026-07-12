<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extracts claims from generated EnterpriseWikiPageVersion.content_markdown records
 * linked to an applied maintainer decision run, and writes EnterpriseWikiClaim rows.
 *
 * Idempotency checkpoint: EnterpriseWikiIngestRunPage.claims_extracted_at, set once per
 * (run, page) — not merely "claims exist" — because a page can legitimately extract zero
 * claims, and an existence check alone cannot distinguish "not started" from "started and
 * genuinely produced nothing".
 *
 * Concurrency: a (run, page) is reserved via a single atomic conditional UPDATE
 * (claims_claimed_at/claims_claim_token) BEFORE the AI call — this is a plain SQL
 * compare-and-swap, not a held transaction/row lock, so nothing is locked while the AI call
 * is in flight. The AI call and its persistence therefore never race two workers into calling
 * AI for the same page: only the worker whose UPDATE actually matched a row proceeds. Writing
 * the claim rows and the checkpoint happens in a short transaction that re-validates the
 * reservation token is still owned by this worker before writing anything — a worker whose
 * lease was reclaimed by another worker (because it went stale) is refused at that point and
 * writes nothing, so the reclaiming worker's own result is the only one persisted.
 *
 * Does not create source references, lint findings, or touch ProcessEnterpriseWikiIngest.
 */
class EnterpriseWikiExtractPageClaimsService
{
    /**
     * Lease duration for a claim-extraction reservation. Long enough that a normal AI call
     * (single request, typically a few seconds) never expires mid-flight; short enough that a
     * genuinely dead worker (killed process, no chance to release) does not block the page
     * indefinitely — well under the 1800s job timeout of ContinueEnterpriseWikiDocumentFlowAfterPages.
     */
    private const LEASE_SECONDS = 600;

    public function __construct(
        private readonly WikiPageClaimExtractionAiClient $aiClient,
    ) {}

    /**
     * @return array{pages: int, claims: int, skipped: int, busy: int}
     *
     * @throws \InvalidArgumentException if the run is not in a state that permits extraction
     * @throws \RuntimeException if AI is unavailable or extraction fails
     */
    public function extract(EnterpriseWikiIngestRun $run): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have claims extracted."
            );
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $pages = 0;
        $claims = 0;
        $skipped = 0;
        $busy = 0;

        foreach ($pivotRows as $row) {
            $page = $row->page;

            if ($page === null) {
                continue;
            }

            // Authoritative checkpoint: this (run, page) already completed extraction —
            // regardless of whether it produced any claims — so skip without an AI call.
            if ($row->claims_extracted_at !== null) {
                $skipped++;

                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                $skipped++;

                continue;
            }

            // Defensive fallback for rows that already have claims but predate the
            // checkpoint column being set (e.g. an interrupted write from before this
            // checkpoint existed): record the checkpoint instead of calling AI again.
            $hasExistingClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists();

            if ($hasExistingClaims) {
                $row->update(['claims_extracted_at' => now()]);
                $skipped++;

                continue;
            }

            $token = (string) Str::uuid();
            $reservation = $this->reserve($row, $token);

            if ($reservation === 'completed') {
                $skipped++;

                continue;
            }

            if ($reservation === 'busy') {
                $busy++;

                continue;
            }

            try {
                $result = $this->aiClient->extractClaims(
                    pageTitle: $page->title,
                    pageType: $page->page_type,
                    contentMarkdown: (string) ($version->content_markdown ?? ''),
                    languageCode: $languageCode,
                );
            } catch (Throwable $e) {
                $this->release($row->id, $token);

                throw $e;
            }

            $pageClaimsCreated = $this->persist($row->id, $page, $version, $token, $result);

            if ($pageClaimsCreated === null) {
                // Another worker reclaimed this lease as stale while the AI call was in
                // flight; that worker's own attempt is the one that will persist a result.
                $busy++;

                continue;
            }

            $claims += $pageClaimsCreated;
            $pages++;
        }

        return compact('pages', 'claims', 'skipped', 'busy');
    }

    /**
     * Atomically reserve a (run, page) for extraction: a plain conditional UPDATE, matched
     * only when extraction isn't already complete and no active (non-stale) lease exists.
     * Under concurrent UPDATEs on the same row, Postgres serializes them via the row lock and
     * re-evaluates the WHERE clause after the first commits, so only one call can ever match.
     *
     * @return string one of 'reserved', 'completed', 'busy'
     */
    private function reserve(EnterpriseWikiIngestRunPage $row, string $token): string
    {
        $staleThreshold = now()->subSeconds(self::LEASE_SECONDS);

        $claimed = EnterpriseWikiIngestRunPage::query()
            ->where('id', $row->id)
            ->whereNull('claims_extracted_at')
            ->where(function ($q) use ($staleThreshold): void {
                $q->whereNull('claims_claimed_at')->orWhere('claims_claimed_at', '<', $staleThreshold);
            })
            ->update([
                'claims_claimed_at' => now(),
                'claims_claim_token' => $token,
            ]);

        if ($claimed > 0) {
            return 'reserved';
        }

        $fresh = EnterpriseWikiIngestRunPage::query()->find($row->id);

        return $fresh?->claims_extracted_at !== null ? 'completed' : 'busy';
    }

    /**
     * Release a reservation this worker still owns — a no-op if the token no longer matches
     * (already reclaimed by another worker as stale).
     */
    private function release(int $rowId, string $token): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('id', $rowId)
            ->where('claims_claim_token', $token)
            ->update([
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);
    }

    /**
     * Persist the extracted claims and the completion checkpoint atomically, but only if this
     * worker's token is still the current owner of the reservation — re-validated under a row
     * lock inside the same transaction as the writes, so a worker whose lease was reclaimed by
     * another worker while its AI call was in flight can never overwrite that worker's result.
     *
     * @return int|null the number of claims created, or null if the reservation was lost
     */
    private function persist(int $rowId, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $token, array $result): ?int
    {
        return DB::transaction(function () use ($rowId, $page, $version, $token, $result): ?int {
            $row = EnterpriseWikiIngestRunPage::query()
                ->where('id', $rowId)
                ->where('claims_claim_token', $token)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $created = 0;

            foreach ($result['claims'] as $i => $claim) {
                EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => $claim['text'],
                    'position_order' => $i,
                    'confidence' => $claim['confidence'] ?? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                    'conflict_flag' => ($claim['conflict_note'] ?? null) !== null,
                    'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                $created++;
            }

            $row->update([
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

            return $created;
        });
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
