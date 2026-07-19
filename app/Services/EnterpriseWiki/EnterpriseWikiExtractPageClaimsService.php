<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
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
     * Safety margin added on top of the continuation job's own timeout (queue scheduling
     * jitter, clock skew between workers, Laravel's own grace period before force-killing a
     * timed-out job) — not itself the source of truth for how long a legitimate AI call may
     * run.
     */
    private const TIMEOUT_SAFETY_MARGIN_SECONDS = 300;

    /**
     * Lease duration for a claim-extraction reservation.
     *
     * Invariant: LEASE_SECONDS > ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS.
     * The reservation is taken inside that job's single execution, so a live worker legitimately
     * mid-AI-call can still be running at any point up to the job's own timeout. A lease shorter
     * than that timeout (600s was tried and rejected — see the class docs below) lets another
     * worker reclaim it and start a SECOND AI call for the same page while the first is still
     * within its allowed execution window — exactly the duplicate-AI-call race this reservation
     * exists to prevent. Deliberately derived from the job's timeout constant rather than a
     * separately hand-picked number, so the two can never silently drift apart; enforced by
     * EnterpriseWikiClaimStepLeaseTest::test_lease_duration_exceeds_continuation_job_timeout_by_a_safety_margin().
     */
    private const LEASE_SECONDS = ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS + self::TIMEOUT_SAFETY_MARGIN_SECONDS;

    public function __construct(
        private readonly WikiPageClaimExtractionAiClient $aiClient,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
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

            foreach ($this->dedupeClaims($result['claims']) as $i => $claim) {
                $pageExcerpt = trim((string) ($claim['excerpt'] ?? ''));
                $block = $this->contentBlockService->findUniqueBlockForExcerpt($version, $pageExcerpt);
                $hasPageAnchor = $block !== null;
                $blockOrigin = $hasPageAnchor ? (string) ($block['content_origin'] ?? '') : '';
                $contentOrigin = match ($blockOrigin) {
                    EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                    default => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                };

                // Del 3: a block being best_practice does not automatically make every claim
                // extracted from it best_practice — extraction must preserve the recommendation's
                // own wording faithfully. If the extracted claim text itself has turned the
                // suggestion into an assertion about the customer's/supplier's current state (or
                // otherwise lost its normative framing), it is no longer a suggestion this claim
                // can safely inherit — treat it as an unsupported factual claim instead, so it
                // still gets real (human) scrutiny rather than silently riding through as a
                // "harmless suggestion" it no longer actually is.
                $bestPracticeDrifted = $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    && ! $this->canonicalizationService->isGenuineBestPracticeText((string) $claim['text']);

                if ($bestPracticeDrifted) {
                    $contentOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT;
                }

                $createdClaim = EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => $claim['text'],
                    'content_origin' => $contentOrigin,
                    'page_excerpt' => $pageExcerpt !== '' ? $pageExcerpt : null,
                    'content_block_key' => $block['block_key'] ?? null,
                    'review_reason' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                        ? (string) ($block['best_practice_reason'] ?? 'Vurder om anbefalingen skal beholdes som beste praksis.')
                        : null,
                    'review_metadata' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                        ? [
                            'statement_kind' => 'recommendation',
                            'classification_basis' => 'ai_block_content_origin',
                            'suggested_placement' => $block['block_key'] ?? null,
                            'visible_wiki_link_recommendation' => ($block['link_intents'] ?? []) !== [] ? 'recommended' : 'not_needed',
                            'link_intents' => $block['link_intents'] ?? [],
                        ]
                        : null,
                    'generation_issue' => match (true) {
                        $bestPracticeDrifted => 'best_practice_claim_asserts_current_state',
                        $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR => 'claim_excerpt_not_found_in_page_version',
                        default => null,
                    },
                    'position_order' => $i,
                    'confidence' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR
                        ? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN
                        : ($claim['confidence'] ?? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN),
                    'conflict_flag' => ($claim['conflict_note'] ?? null) !== null,
                    'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                if ($block !== null && $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                    foreach ($this->sourceReferencePayloadsForBlock($block, $pageExcerpt) as $sourceReferencePayload) {
                        EnterpriseWikiSourceReference::query()->create(array_merge([
                            'enterprise_wiki_claim_id' => $createdClaim->id,
                        ], $sourceReferencePayload));
                    }
                }

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

    /**
     * Drops an exact (post-normalization) duplicate claim within a single extraction response —
     * the same AI call occasionally restates one fact twice (e.g. once from a heading/summary
     * line and once from the body sentence it summarizes). A single page version must not carry
     * two active claims for the literal same statement (Wiki run-34 overgeneration finding).
     *
     * Deliberately narrow in scope: this only removes claims identical to one another WITHIN
     * this one page's extraction result. It does not attempt cross-page/cross-run fact
     * deduplication, which would need a shared fact registry — out of scope here.
     *
     * @param  list<array<string, mixed>>  $claims
     * @return list<array<string, mixed>>
     */
    private function dedupeClaims(array $claims): array
    {
        $seen = [];
        $deduped = [];

        foreach ($claims as $claim) {
            $text = is_array($claim) ? (string) ($claim['text'] ?? '') : '';
            $key = $this->textNormalizer->normalize($text);

            if ($key !== '' && in_array($key, $seen, true)) {
                continue;
            }

            if ($key !== '') {
                $seen[] = $key;
            }

            $deduped[] = $claim;
        }

        return array_values($deduped);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceReferencePayloadsForBlock(array $block, string $pageExcerpt): array
    {
        $sourceElements = (array) ($block['source_elements'] ?? []);

        if ($sourceElements === [] && ($block['source_id'] ?? null) !== null) {
            $sourceElements = [$block];
        }

        $payloads = [];

        foreach ($sourceElements as $sourceElement) {
            if (! is_array($sourceElement)) {
                continue;
            }

            $sourceId = (int) ($sourceElement['source_id'] ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $payloads[] = [
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $sourceId,
                'source_element_key' => $sourceElement['source_element_key'] ?? null,
                'source_element_type' => $sourceElement['source_element_type'] ?? null,
                'source_row_key' => $sourceElement['source_row_key'] ?? null,
                'source_label' => (string) ($sourceElement['source_label'] ?? 'Kildedokument'),
                'excerpt' => (string) ($sourceElement['source_excerpt'] ?? $pageExcerpt),
                'source_hash' => (string) ($sourceElement['source_hash'] ?? ''),
                'page_reference' => $sourceElement['page_reference'] ?? null,
            ];
        }

        return $payloads;
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return str_contains(
            mb_strtolower($normalize($haystack)),
            mb_strtolower($normalize($needle)),
        );
    }
}
