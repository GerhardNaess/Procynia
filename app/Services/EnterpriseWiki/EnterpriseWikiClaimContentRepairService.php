<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controlled, bounded repair of Wiki content blocks whose claims were found to be
 * unsupported_generated_content or internal_error by EnterpriseWikiVerifyPageClaimsService.
 *
 * Called only for runs whose qa_status is repair_required (see
 * EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects() /
 * EnterpriseWikiDocumentFlowService::escalateRunForClaimIntegrityRepair()), and only from
 * EnterpriseWikiMaintenanceCycleService — never inline in the ordinary continuation flow, so a
 * repair attempt's AI calls never share a queue job's timeout budget with page
 * generation/extraction/verification.
 *
 * For each page with a repairable defect (a bad claim whose content_block_key still resolves to
 * a block in the page's current version), this:
 *   1. Asks WikiSemanticReviserAiClient to revise just that block's markdown against the source
 *      document and the concrete unsupported claim text(s).
 *   2. Creates a new, immutable EnterpriseWikiPageVersion with the block replaced — the previous
 *      version (and every claim tied to it) is preserved untouched as historical record; nothing
 *      is deleted or overwritten.
 *   3. Re-points the run's pivot row at the new version and clears its claim-extraction
 *      checkpoint so EnterpriseWikiExtractPageClaimsService/EnterpriseWikiVerifyPageClaimsService
 *      naturally (re-)process the new version on the next call.
 *
 * A defect with no content_block_key, or whose block no longer exists in the current version,
 * cannot be targeted — the page is left unrepaired and the run stays escalated for manual/
 * technical follow-up (Del 5: "Dersom revisjonen fortsatt feiler: stopp siden eller runen, behold
 * diagnostikk, ikke send siden til Dokumenteier").
 *
 * Bounded via EnterpriseWikiIngestRun::MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS /
 * claim_content_repair_attempt_count — never an unbounded AI loop.
 *
 * Does not finalize the run (does not call EnterpriseWikiDocumentFlowService::finalizeFromExistingQaResult()) —
 * same precedent as EnterpriseWikiDeepRepairService: re-evaluating QA here only updates
 * qa_status; moving the run out of `escalated` (to completed/awaiting_document_owner_approval)
 * is a separate, explicit step (wiki:recover-document-flow), exactly as for deep-repaired runs.
 */
class EnterpriseWikiClaimContentRepairService
{
    public function __construct(
        private readonly WikiSemanticReviserAiClient $aiClient,
        private readonly EnterpriseWikiExtractPageClaimsService $extractPageClaimsService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyPageClaimsService,
        private readonly EnterpriseWikiPostIngestQaService $qaService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
    ) {}

    /**
     * Attempt a bounded, targeted content repair for a run whose qa_status is repair_required.
     *
     * Returns a result array with keys:
     * - attempted (bool)
     * - reason (string|null)              — why not attempted, or null when it was
     * - repaired_page_ids (list<int>)     — pages whose current version was replaced
     * - unrepairable_page_ids (list<int>) — pages with a bad claim that could not be targeted
     * - qa_status (string|null)           — final qa_status after re-evaluation
     *
     * Never throws — AI/repair failures are captured in the returned/stored result and the run
     * is left escalated for manual follow-up.
     */
    public function attempt(EnterpriseWikiIngestRun $run): array
    {
        if ($run->claim_content_repair_attempt_count >= EnterpriseWikiIngestRun::MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS) {
            return $this->store($run, $this->result(attempted: false, reason: 'max_attempts_reached'));
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return $this->store($run, $this->result(attempted: false, reason: 'source_type_not_supported'));
        }

        $document = EnterpriseWikiDocument::find($run->source_id);

        if (! $document) {
            return $this->store($run, $this->result(attempted: false, reason: 'source_document_not_found'));
        }

        $sourceText = trim((string) $document->extracted_text);

        if ($sourceText === '') {
            return $this->store($run, $this->result(attempted: false, reason: 'source_text_empty'));
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->get()
            ->keyBy('enterprise_wiki_page_id');

        $currentVersions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pivotRows->keys())
            ->where('is_current', true)
            ->get()
            ->keyBy('enterprise_wiki_page_id');

        $badClaimsByPage = EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_version_id', $currentVersions->pluck('id'))
            ->whereIn('content_origin', [
                EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            ])
            ->get()
            ->groupBy('enterprise_wiki_page_id');

        if ($badClaimsByPage->isEmpty()) {
            return $this->store($run, $this->result(attempted: false, reason: 'no_repairables'));
        }

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $run->update([
            'claim_content_repair_attempted_at' => now(),
            'claim_content_repair_attempt_count' => $run->claim_content_repair_attempt_count + 1,
        ]);

        $repairedPageIds = [];
        $unrepairablePageIds = [];

        foreach ($badClaimsByPage as $pageId => $claimsForPage) {
            $pivotRow = $pivotRows->get($pageId);
            $version = $currentVersions->get($pageId);

            if ($pivotRow === null || $version === null) {
                $unrepairablePageIds[] = (int) $pageId;

                continue;
            }

            $success = $this->repairPage($run, $sourceText, $languageCode, $pivotRow, $version, $claimsForPage);

            if ($success) {
                $repairedPageIds[] = (int) $pageId;
            } else {
                $unrepairablePageIds[] = (int) $pageId;
            }
        }

        if ($repairedPageIds === []) {
            return $this->store($run, $this->result(
                attempted: true,
                reason: 'unrepairable_blocks_present',
                repairedPageIds: $repairedPageIds,
                unrepairablePageIds: $unrepairablePageIds,
            ));
        }

        try {
            $this->extractPageClaimsService->extract($run->fresh() ?? $run);
            $this->verifyPageClaimsService->verify($run->fresh() ?? $run);
        } catch (\Throwable $e) {
            Log::error('[WIKI_CLAIM_CONTENT_REPAIR] Re-extraction/re-verification failed after repair', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return $this->store($run, $this->result(
                attempted: true,
                reason: 'reverification_failed',
                repairedPageIds: $repairedPageIds,
                unrepairablePageIds: $unrepairablePageIds,
            ));
        }

        try {
            $this->qaService->runForRun($run->fresh() ?? $run, retry: true);
        } catch (\Throwable $e) {
            Log::error('[WIKI_CLAIM_CONTENT_REPAIR] QA re-evaluation after repair failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $fresh = $run->fresh() ?? $run;

        return $this->store($fresh, $this->result(
            attempted: true,
            reason: $unrepairablePageIds !== [] ? 'partially_repaired' : null,
            repairedPageIds: $repairedPageIds,
            unrepairablePageIds: $unrepairablePageIds,
            qaStatus: $fresh->qa_status,
        ));
    }

    /**
     * Repair every targetable block on one page. Returns false (page left unrepaired) if any
     * bad claim on the page cannot be targeted (missing/unresolvable block anchor) or the AI
     * revision/version-creation step fails for any of its blocks — a page is only considered
     * repaired when every one of its flagged blocks was successfully revised.
     */
    private function repairPage(
        EnterpriseWikiIngestRun $run,
        string $sourceText,
        string $languageCode,
        EnterpriseWikiIngestRunPage $pivotRow,
        EnterpriseWikiPageVersion $version,
        Collection|EloquentCollection $claimsForPage,
    ): bool {
        $blocks = (array) ($version->content_blocks_json ?? []);

        if ($blocks === []) {
            return false;
        }

        $blocksByKey = collect($blocks)->filter(fn ($block) => is_array($block))->keyBy('block_key');

        $hasUnanchoredClaim = $claimsForPage->contains(
            fn (EnterpriseWikiClaim $claim): bool => trim((string) $claim->content_block_key) === ''
        );

        if ($hasUnanchoredClaim) {
            // At least one bad claim has no content_block_key at all — no anchor to revise.
            return false;
        }

        $blockKeys = $claimsForPage->pluck('content_block_key')->unique()->values();

        $revisedBlocks = $blocksByKey;

        foreach ($blockKeys as $blockKey) {
            $block = $blocksByKey->get($blockKey);

            if ($block === null) {
                return false;
            }

            $claimTexts = $claimsForPage
                ->where('content_block_key', $blockKey)
                ->pluck('claim_text')
                ->filter()
                ->values()
                ->all();

            $diagnosis = [
                'recommended_repair_action' => 'targeted_revision',
                'critique' => 'The following statement(s) derived from this text could not be confirmed against the source document.',
                'unsupported_claims' => $claimTexts,
            ];

            try {
                $revisedMarkdown = trim($this->aiClient->revise(
                    $sourceText,
                    (string) ($block['markdown'] ?? ''),
                    'block',
                    $diagnosis,
                    $languageCode,
                ));
            } catch (\Throwable $e) {
                Log::warning('[WIKI_CLAIM_CONTENT_REPAIR] Block revision failed', [
                    'run_id' => $run->id,
                    'page_id' => $pivotRow->enterprise_wiki_page_id,
                    'block_key' => $blockKey,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            if ($revisedMarkdown === '') {
                return false;
            }

            $block['markdown'] = $revisedMarkdown;
            $revisedBlocks->put($blockKey, $block);
        }

        $newVersion = $this->createRevisedVersion($version, $revisedBlocks->values()->all());

        $pivotRow->update([
            'generated_page_version_id' => $newVersion->id,
            'claims_extracted_at' => null,
            'claims_claimed_at' => null,
            'claims_claim_token' => null,
        ]);

        // The revised blocks can change or drop an inline [[wikilink]] — re-sync the graph from
        // the new current version so link_type=wikilink rows never drift from actual content
        // (the CODE_STALE_WIKILINK_GRAPH_EDGE lint check this omission previously caused).
        if ($pivotRow->page !== null) {
            $this->buildPageLinksService->materializeWikilinksForPage($pivotRow->page->fresh(), $run->id);
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function createRevisedVersion(EnterpriseWikiPageVersion $previousVersion, array $blocks): EnterpriseWikiPageVersion
    {
        return DB::transaction(function () use ($previousVersion, $blocks): EnterpriseWikiPageVersion {
            $pageId = (int) $previousVersion->enterprise_wiki_page_id;

            DB::table('enterprise_wiki_page_versions')
                ->where('enterprise_wiki_page_id', $pageId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            $markdown = implode("\n\n", array_map(
                static fn (array $block): string => (string) ($block['markdown'] ?? ''),
                $blocks,
            ));

            $newVersion = EnterpriseWikiPageVersion::create([
                'enterprise_wiki_page_id' => $pageId,
                'version_number' => (int) $previousVersion->version_number + 1,
                'is_current' => true,
                'content_markdown' => $markdown,
                'content_blocks_json' => $blocks,
                'generated_by_model' => WikiSemanticReviserAiClient::MODEL.'/claim-content-repair',
            ]);

            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($pageId);

            return $newVersion;
        });
    }

    /**
     * @return array{attempted: bool, reason: ?string, repaired_page_ids: list<int>, unrepairable_page_ids: list<int>, qa_status: ?string}
     */
    private function result(
        bool $attempted,
        ?string $reason = null,
        array $repairedPageIds = [],
        array $unrepairablePageIds = [],
        ?string $qaStatus = null,
    ): array {
        return [
            'attempted' => $attempted,
            'reason' => $reason,
            'repaired_page_ids' => $repairedPageIds,
            'unrepairable_page_ids' => $unrepairablePageIds,
            'qa_status' => $qaStatus,
        ];
    }

    private function store(EnterpriseWikiIngestRun $run, array $result): array
    {
        $run->update(['claim_content_repair_result' => $result]);

        Log::info('[WIKI_CLAIM_CONTENT_REPAIR] Attempt complete', [
            'run_id' => $run->id,
            'result' => $result,
        ]);

        return $result;
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
