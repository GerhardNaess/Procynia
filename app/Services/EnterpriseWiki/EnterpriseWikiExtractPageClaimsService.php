<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly EnterpriseWikiTableBlockBuilder $tableBlockBuilder,
    ) {}

    /**
     * @return array{pages: int, claims: int, skipped: int, busy: int, capped_pages: int}
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

        $maxNewClaims = (int) config('services.enterprise_wiki.max_new_claims_per_run', 60);
        $persistedClaimCount = $this->existingClaimCountForPages($pivotRows->pluck('page.id')->filter()->values());

        $pages = 0;
        $claims = 0;
        $skipped = 0;
        $busy = 0;
        $cappedPages = 0;

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

            // Del 4 (v0.10, docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): a
            // sensible, run-wide ceiling on new claims — never a failure. Existing generation
            // order (article/summary first, then concept/entity — see
            // EnterpriseWikiGenerateAppliedPagesService) already prioritizes the two mandatory
            // pages ahead of the pages most likely to restate the same facts, so reaching the cap
            // here naturally omits the least material candidates first. The page's own extraction
            // checkpoint is still recorded so this never re-appears as an incomplete step
            // (EnterpriseWikiPostIngestQaService::findIncompleteSteps()) and the run still
            // completes normally.
            if (($persistedClaimCount + $claims) >= $maxNewClaims) {
                $row->update(['claims_extracted_at' => now()]);
                $cappedPages++;
                $skipped++;

                Log::info('[WIKI_CLAIM_EXTRACTION] Run-level claim cap reached — omitting further claim candidates for this page.', [
                    'run_id' => $run->id,
                    'page_id' => $page->id,
                    'cap' => $maxNewClaims,
                ]);

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

        if ($cappedPages > 0) {
            Log::info('[WIKI_CLAIM_EXTRACTION] Run-level claim cap reached for this run.', [
                'run_id' => $run->id,
                'pages_capped' => $cappedPages,
                'cap' => $maxNewClaims,
            ]);
        }

        return [
            'pages' => $pages,
            'claims' => $claims,
            'skipped' => $skipped,
            'busy' => $busy,
            'capped_pages' => $cappedPages,
        ];
    }

    /**
     * Total claims already persisted for this run's pages' CURRENT versions — the baseline the
     * run-level cap (Del 4, v0.10) is measured against, so a busy/retry re-entry into extract()
     * never allows more than max_new_claims_per_run claims cumulatively across multiple calls.
     */
    private function existingClaimCountForPages(Collection $pageIds): int
    {
        if ($pageIds->isEmpty()) {
            return 0;
        }

        $currentVersionIds = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->pluck('id');

        if ($currentVersionIds->isEmpty()) {
            return 0;
        }

        return EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersionIds)
            ->count();
    }

    /**
     * Extract and persist claims for one explicitly selected mixed-provenance block. This is the
     * future manual-edit entrypoint: no page-level extraction, no ingest-run checkpoints, and no
     * claims from other blocks are touched.
     *
     * @return array{claims: int, claim_ids: list<int>}
     */
    public function extractClaimsForManualMixedBlock(EnterpriseWikiIngestRun $run, EnterpriseWikiPageVersion $version, array $block): array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have manually edited block claims extracted."
            );
        }

        $page = $version->page()->first();

        if ($page === null) {
            throw new \RuntimeException("Page version [{$version->id}] is not attached to a Wiki page.");
        }

        $blockKey = trim((string) ($block['block_key'] ?? ''));

        if ($blockKey === '') {
            throw new \InvalidArgumentException('Manual mixed block claim extraction requires a content_block_key.');
        }

        $storedBlock = $this->findBlockByKey($version, $blockKey);

        if ($storedBlock === null) {
            throw new \RuntimeException("Content block [{$blockKey}] was not found in page version [{$version->id}].");
        }

        $sourceElementsByKey = $this->sourceElementsByKeyForBlock($storedBlock);

        $result = $this->aiClient->extractClaimsForManualMixedBlock(
            pageTitle: $page->title,
            pageType: $page->page_type,
            blockMarkdown: (string) ($storedBlock['markdown'] ?? ''),
            contentBlockKey: $blockKey,
            sourceElements: $this->sourceElementsForManualMixedBlockAi($sourceElementsByKey),
            languageCode: $this->resolveLanguageCode($run->customer_id),
        );

        return $this->persistManualMixedBlockClaims($page, $version, $blockKey, $sourceElementsByKey, $result);
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
                    && ! $this->canonicalizationService->isEligibleForBestPractice((string) $claim['text']);

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
                            // Provisional only (verified_at stays null on create) — this is
                            // extraction's own inheritance from the generation block, not yet an
                            // authoritative decision. EnterpriseWikiVerifyPageClaimsService is the
                            // next, authoritative writer for this claim.
                            'decision_source' => EnterpriseWikiClaimClassificationService::SOURCE_EXTRACTION,
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

            $created += $this->createTableClaims($page, $version, $created);

            $row->update([
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

            return $created;
        });
    }

    /**
     * Deterministic, non-AI claims for any "table" content block on this version — one claim per
     * (row, non-label column) cell, built directly from the table's own structured data (see
     * EnterpriseWikiTableBlockBuilder::tableClaimPayloads()). Never calls the AI claim-extraction
     * client: a table's cell values are already exact, verbatim source data, so there is nothing
     * for an LLM to usefully paraphrase, and generating these deterministically gives every table
     * claim exact row+cell provenance by construction instead of by the AI faithfully restating a
     * source_element_key.
     *
     * Runs inside the same reservation-guarded transaction as the AI-derived claims above, so it
     * shares the same (run, page) idempotency checkpoint — a re-ingest that reuses an existing
     * completed extraction never re-enters this method at all.
     *
     * @return int number of claims created
     */
    private function createTableClaims(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, int $startingPosition): int
    {
        $created = 0;
        $position = $startingPosition;

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (! is_array($block) || ($block['block_type'] ?? null) !== 'table') {
                continue;
            }

            $sourceId = (int) ($block['source_id'] ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $document = EnterpriseWikiDocument::query()->find($sourceId);

            if ($document === null) {
                continue;
            }

            foreach ($this->tableBlockBuilder->tableClaimPayloads($document, $block) as $payload) {
                $createdClaim = EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $version->id,
                    'claim_text' => $payload['claim_text'],
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'page_excerpt' => $payload['excerpt'],
                    'content_block_key' => $block['block_key'] ?? null,
                    'position_order' => $position++,
                    'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                    'conflict_flag' => false,
                    'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                EnterpriseWikiSourceReference::query()->create([
                    'enterprise_wiki_claim_id' => $createdClaim->id,
                    'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $document->id,
                    'source_element_key' => $payload['source_row_key'],
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
                    'source_row_key' => $payload['source_row_key'],
                    'source_cell_key' => $payload['source_cell_key'],
                    'source_column_key' => $payload['source_column_key'],
                    'source_label' => $document->original_filename,
                    'excerpt' => $payload['excerpt'],
                    'source_hash' => $document->file_hash_sha256 ?? '',
                    'page_reference' => $payload['page_reference'],
                ]);

                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sourceElementsByKey
     * @return array{claims: int, claim_ids: list<int>}
     */
    private function persistManualMixedBlockClaims(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $blockKey, array $sourceElementsByKey, array $result): array
    {
        return DB::transaction(function () use ($page, $version, $blockKey, $sourceElementsByKey, $result): array {
            $lockedVersion = EnterpriseWikiPageVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->first();

            if ($lockedVersion === null) {
                throw new \RuntimeException("Page version [{$version->id}] no longer exists.");
            }

            $block = $this->findBlockByKey($lockedVersion, $blockKey);

            if ($block === null) {
                throw new \RuntimeException("Content block [{$blockKey}] was not found in page version [{$version->id}].");
            }

            $claims = [];

            foreach ($this->dedupeClaims($result['claims'] ?? []) as $claim) {
                $claims[] = $this->validatedManualMixedBlockClaim($claim, $block, $sourceElementsByKey);
            }

            $maxPosition = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $lockedVersion->id)
                ->max('position_order');
            $nextPosition = $maxPosition === null ? 0 : ((int) $maxPosition) + 1;
            $createdClaimIds = [];

            foreach ($claims as $claim) {
                $contentOrigin = $claim['content_origin'];
                $bestPracticeDrifted = $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    && ! $this->canonicalizationService->isEligibleForBestPractice($claim['text']);

                if ($bestPracticeDrifted) {
                    $contentOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT;
                }

                $createdClaim = EnterpriseWikiClaim::query()->create([
                    'enterprise_wiki_page_id' => $page->id,
                    'enterprise_wiki_page_version_id' => $lockedVersion->id,
                    'claim_text' => $claim['text'],
                    'content_origin' => $contentOrigin,
                    'page_excerpt' => $claim['excerpt'],
                    'content_block_key' => $blockKey,
                    'review_reason' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                        ? $claim['best_practice_reason']
                        : null,
                    'review_metadata' => $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                        ? [
                            'statement_kind' => 'recommendation',
                            'classification_basis' => 'ai_manual_mixed_block_claim_origin',
                            'decision_source' => EnterpriseWikiClaimClassificationService::SOURCE_EXTRACTION,
                            'suggested_placement' => $blockKey,
                            'visible_wiki_link_recommendation' => 'auto_evaluate',
                        ]
                        : null,
                    'generation_issue' => match (true) {
                        $bestPracticeDrifted => 'best_practice_claim_asserts_current_state',
                        $contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT => 'unsupported_generated_content',
                        default => null,
                    },
                    'position_order' => $nextPosition++,
                    'confidence' => $claim['confidence'],
                    'conflict_flag' => $claim['conflict_note'] !== null,
                    'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                ]);

                if ($contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                    foreach ($this->sourceReferencePayloadsForKeys($sourceElementsByKey, $claim['source_element_keys'], $claim['excerpt']) as $sourceReferencePayload) {
                        EnterpriseWikiSourceReference::query()->create(array_merge([
                            'enterprise_wiki_claim_id' => $createdClaim->id,
                        ], $sourceReferencePayload));
                    }
                }

                $createdClaimIds[] = $createdClaim->id;
            }

            return ['claims' => count($createdClaimIds), 'claim_ids' => $createdClaimIds];
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

    /**
     * @param  array<string, array<string, mixed>>  $sourceElementsByKey
     * @return list<array{key: string, type: string|null, text: string}>
     */
    private function sourceElementsForManualMixedBlockAi(array $sourceElementsByKey): array
    {
        return array_values(array_map(static fn (array $sourceElement): array => [
            'key' => (string) $sourceElement['source_element_key'],
            'type' => is_string($sourceElement['source_element_type'] ?? null) ? $sourceElement['source_element_type'] : null,
            'text' => (string) $sourceElement['source_excerpt'],
        ], $sourceElementsByKey));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceElementsByKeyForBlock(array $block): array
    {
        $sourceElements = (array) ($block['source_elements'] ?? []);

        if ($sourceElements === [] && ($block['source_id'] ?? null) !== null) {
            $sourceElements = [$block];
        }

        $byKey = [];

        foreach ($sourceElements as $sourceElement) {
            if (! is_array($sourceElement)) {
                continue;
            }

            $key = trim((string) ($sourceElement['source_element_key'] ?? ''));
            $sourceId = (int) ($sourceElement['source_id'] ?? 0);
            $sourceExcerpt = trim((string) ($sourceElement['source_excerpt'] ?? ''));

            if ($key === '' || $sourceId <= 0 || $sourceExcerpt === '') {
                continue;
            }

            if (! array_key_exists($key, $byKey)) {
                $byKey[$key] = $sourceElement;
            }
        }

        return $byKey;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sourceElementsByKey
     * @param  list<string>  $sourceElementKeys
     * @return list<array<string, mixed>>
     */
    private function sourceReferencePayloadsForKeys(array $sourceElementsByKey, array $sourceElementKeys, string $pageExcerpt): array
    {
        $payloads = [];

        foreach ($sourceElementKeys as $key) {
            $sourceElement = $sourceElementsByKey[$key] ?? null;

            if ($sourceElement === null) {
                throw new \RuntimeException("Source element key [{$key}] is not available for this content block.");
            }

            $payloads[] = [
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => (int) $sourceElement['source_id'],
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

    /**
     * @param  array<string, array<string, mixed>>  $sourceElementsByKey
     * @return array{text: string, confidence: string, excerpt: string, content_origin: string, source_element_keys: list<string>, best_practice_reason: string|null, conflict_note: string|null}
     */
    private function validatedManualMixedBlockClaim(mixed $claim, array $block, array $sourceElementsByKey): array
    {
        if (! is_array($claim)) {
            throw new \RuntimeException('Manual mixed block claim extraction returned an invalid claim.');
        }

        $text = trim((string) ($claim['text'] ?? ''));
        $confidence = $claim['confidence'] ?? null;
        $excerpt = trim((string) ($claim['excerpt'] ?? ''));
        $contentOrigin = $claim['content_origin'] ?? null;
        $sourceElementKeys = $claim['source_element_keys'] ?? null;
        $bestPracticeReason = is_string($claim['best_practice_reason'] ?? null) ? trim($claim['best_practice_reason']) : ($claim['best_practice_reason'] ?? null);
        $conflictNote = is_string($claim['conflict_note'] ?? null) ? trim($claim['conflict_note']) : ($claim['conflict_note'] ?? null);

        if ($text === ''
            || ! is_string($confidence)
            || ! in_array($confidence, [
                EnterpriseWikiClaim::CONFIDENCE_HIGH,
                EnterpriseWikiClaim::CONFIDENCE_MEDIUM,
                EnterpriseWikiClaim::CONFIDENCE_LOW,
                EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            ], true)
            || $excerpt === ''
            || ! is_string($contentOrigin)
            || ! in_array($contentOrigin, [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            ], true)
            || ! is_array($sourceElementKeys)
            || ! (is_string($bestPracticeReason) || $bestPracticeReason === null)
            || ! (is_string($conflictNote) || $conflictNote === null)
        ) {
            throw new \RuntimeException('Manual mixed block claim extraction returned an invalid claim.');
        }

        if (! $this->textNormalizer->contains((string) ($block['markdown'] ?? ''), $excerpt)) {
            throw new \RuntimeException('Manual mixed block claim extraction returned an excerpt that is not anchored in the block.');
        }

        $sourceElementKeys = $this->validatedManualMixedBlockSourceKeys($sourceElementKeys, $sourceElementsByKey);

        if ($contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
            if ($sourceElementKeys === []) {
                throw new \RuntimeException('Manual mixed block source_based claim requires source_element_keys.');
            }

            if ($bestPracticeReason !== null && $bestPracticeReason !== '') {
                throw new \RuntimeException('Manual mixed block source_based claim cannot include best_practice_reason.');
            }
        } elseif ($contentOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            if ($sourceElementKeys !== []) {
                throw new \RuntimeException('Manual mixed block best_practice claim cannot include source_element_keys.');
            }

            if ($bestPracticeReason === null || $bestPracticeReason === '') {
                throw new \RuntimeException('Manual mixed block best_practice claim requires best_practice_reason.');
            }
        } elseif ($sourceElementKeys !== []) {
            throw new \RuntimeException('Manual mixed block unsupported_generated_content claim cannot include source_element_keys.');
        } elseif ($bestPracticeReason !== null && $bestPracticeReason !== '') {
            throw new \RuntimeException('Manual mixed block unsupported_generated_content claim cannot include best_practice_reason.');
        }

        return [
            'text' => $text,
            'confidence' => $confidence,
            'excerpt' => $excerpt,
            'content_origin' => $contentOrigin,
            'source_element_keys' => $sourceElementKeys,
            'best_practice_reason' => $bestPracticeReason === '' ? null : $bestPracticeReason,
            'conflict_note' => $conflictNote === '' ? null : $conflictNote,
        ];
    }

    /**
     * @param  list<mixed>  $sourceElementKeys
     * @param  array<string, array<string, mixed>>  $sourceElementsByKey
     * @return list<string>
     */
    private function validatedManualMixedBlockSourceKeys(array $sourceElementKeys, array $sourceElementsByKey): array
    {
        $validated = [];

        foreach ($sourceElementKeys as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new \RuntimeException('Manual mixed block claim returned an invalid source_element_key.');
            }

            $key = trim($key);

            if (! array_key_exists($key, $sourceElementsByKey)) {
                throw new \RuntimeException("Manual mixed block claim referenced unknown source_element_key [{$key}].");
            }

            if (! in_array($key, $validated, true)) {
                $validated[] = $key;
            }
        }

        return $validated;
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
