<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document-scoped deletion for one EnterpriseWikiDocument — never a customer-wide purge, never a
 * database reset. Deletes only the data that provably belongs to the given document, and only
 * ever removes a Wiki page wholesale when the page has no provenance from any other document.
 *
 * Provenance model (Del 1): most Enterprise Wiki tables do NOT carry a direct document_id column.
 * A page's document dependency is derived from enterprise_wiki_ingest_run_pages — the pivot
 * between ingest runs (each tied to exactly one source document via source_type/source_id) and
 * the pages those runs touched. A page that only ever appears in run_pages rows for THIS
 * document's runs is "sole-source" and is deleted wholesale (its versions, claims, and source
 * references all cascade via existing foreign keys). A page that also appears in a run_pages row
 * for a different run is "shared" and is never deleted — only this document's own source
 * references and lint findings are removed from it, exactly the same reconciliation already
 * applied by EnterpriseWikiClaimSourceReconciliationService when a document is first linked.
 *
 * Several tables reference a document only through a customer_id + source_type + source_id triple
 * with no real foreign key (enterprise_wiki_ingest_runs, enterprise_wiki_source_references,
 * enterprise_wiki_canonical_facts) — those rows are deleted explicitly here since the database
 * cannot cascade them. Tables with a real (cascadeOnDelete) foreign key to the page, page version,
 * ingest run, or document rows this service does delete are left to the database: page_versions,
 * claims (via page), further source_references (via claim), page_links (via either page),
 * qa_snapshots/qa_regressions/page_relink_attempts/page_link_qa_attempts (via run),
 * claim_source_reconciliation_attempts and page_version_document_owner_approvals (via document/
 * page/version/run) all disappear on their own once their parent row is gone.
 *
 * lint_findings, by contrast, only has NULLABLE (nullOnDelete) foreign keys to page/claim/run/
 * document — deleting a page would otherwise leave orphaned finding rows with every FK nulled
 * out, so they are deleted explicitly, before their parents, in the same transaction.
 *
 * Provenance model (Del 8 — human-waiting runs): a run stuck in
 * EnterpriseWikiIngestRun::isAwaitingHumanAction() (currently only
 * STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL) has already finished all automatic processing and has
 * nothing left running to interrupt — it does NOT block deletion (see hasActiveRun()). Deletion
 * ends any such run via EnterpriseWikiDocumentFlowService::cancelRun(), the same underlying action
 * WikiSourceController::cancelBlockingRunsForDeletion() uses, inside the SAME transaction as the
 * rest of the deletion so the whole operation is atomic — never a parallel cancellation path. A
 * run genuinely still under automatic processing (expectsAutomaticProgress()) still blocks
 * deletion exactly as before and requires that separate, explicit cancel-first action.
 */
class EnterpriseWikiDocumentDeletionService
{
    public function __construct(
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $stalenessService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
        private readonly EnterpriseWikiDocumentWithdrawalService $withdrawalService,
    ) {}

    /**
     * @return Collection<int, EnterpriseWikiIngestRun>
     */
    public function documentRuns(EnterpriseWikiDocument $document): Collection
    {
        return EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->where('customer_id', $document->customer_id)
            ->get(['id', 'status']);
    }

    /**
     * Whether any run for this document is still genuinely under automatic processing — the only
     * thing that blocks deletion. A run merely awaiting a human decision
     * (EnterpriseWikiIngestRun::isAwaitingHumanAction(), e.g. STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL)
     * has nothing left running to interrupt and does NOT block deletion; delete() ends it
     * automatically instead. Deliberately expectsAutomaticProgress(), not !isTerminal() — see that
     * method's docblock.
     *
     * @param  Collection<int, EnterpriseWikiIngestRun>  $runs
     */
    public function hasActiveRun(Collection $runs): bool
    {
        return $runs->contains(fn (EnterpriseWikiIngestRun $run): bool => $run->expectsAutomaticProgress());
    }

    /**
     * @param  Collection<int, EnterpriseWikiIngestRun>  $runs
     * @return Collection<int, EnterpriseWikiIngestRun>
     */
    private function awaitingHumanActionRuns(Collection $runs): Collection
    {
        return $runs->filter(fn (EnterpriseWikiIngestRun $run): bool => $run->isAwaitingHumanAction())->values();
    }

    /**
     * Read-only preview — never writes anything. Del 2.
     *
     * @return array{
     *     blocked: bool, reason: ?string, document_name?: string, document_owner_name?: ?string,
     *     run_count?: int, sole_source_page_count?: int, shared_page_count?: int,
     *     page_version_count?: int, claim_count?: int, source_reference_count?: int,
     *     finding_count?: int, stale_wiki_answer_count?: int, storage_file_exists?: bool,
     *     pending_approval_run_count?: int
     * }
     */
    public function preview(EnterpriseWikiDocument $document): array
    {
        $runs = $this->documentRuns($document);

        if ($this->hasActiveRun($runs)) {
            return ['blocked' => true, 'reason' => 'in_progress_run'];
        }

        $runIds = $runs->pluck('id');
        [$soleSourcePageIds, $sharedPageIds] = $this->classifyPages($runIds);
        $staleImpact = $this->stalenessService->previewDeletionImpact($document, $runIds, $soleSourcePageIds);

        return [
            'blocked' => false,
            'reason' => null,
            'document_name' => $document->original_filename,
            'document_owner_name' => $document->owner?->name,
            'run_count' => $runs->count(),
            'sole_source_page_count' => $soleSourcePageIds->count(),
            'shared_page_count' => $sharedPageIds->count(),
            'page_version_count' => $this->pageVersionCount($soleSourcePageIds),
            'claim_count' => $staleImpact['impacted_claim_count'],
            'source_reference_count' => $staleImpact['impacted_source_reference_count'],
            'finding_count' => $this->findingCount($document, $runIds, $soleSourcePageIds),
            'stale_wiki_answer_count' => $staleImpact['stale_wiki_answer_count'],
            'storage_file_exists' => $this->storageFileExists($document),
            'pending_approval_run_count' => $this->awaitingHumanActionRuns($runs)->count(),
        ];
    }

    /**
     * Performs the deletion. Returns `['blocked' => true, 'reason' => ...]` without changing
     * anything if a run is still genuinely under automatic processing — the caller (controller) is
     * expected to check this exactly like preview() rather than relying on an exception, since an
     * active run showing up between preview and confirm is an ordinary race, not an exceptional
     * error. $actor is the deleting user, used to attribute any human-waiting run this call ends
     * on the document's behalf (see class docblock, "Del 8").
     *
     * Run rows are locked (lockForUpdate()) inside the transaction before anything is cancelled or
     * deleted, re-checking for a genuinely active run under the lock — this closes the race where
     * a run transitions to active processing between the unlocked read above and the transaction
     * actually starting. If that happens, the transaction commits having changed nothing and this
     * still returns the ordinary blocked response.
     *
     * @return array{
     *     blocked: bool, reason?: string,
     *     runs_deleted?: int, sole_source_pages_deleted?: int, shared_pages_kept?: int,
     *     page_versions_deleted?: int, claims_affected?: int, findings_deleted?: int,
     *     stale_wiki_answers_marked?: int, pending_approval_runs_cancelled?: int,
     *     storage_deleted?: bool, storage_error?: ?string
     * }
     */
    public function delete(EnterpriseWikiDocument $document, User $actor): array
    {
        $runs = $this->documentRuns($document);

        if ($this->hasActiveRun($runs)) {
            return ['blocked' => true, 'reason' => 'in_progress_run'];
        }

        $runIds = $runs->pluck('id');
        [$soleSourcePageIds, $sharedPageIds] = $this->classifyPages($runIds);
        $staleImpact = $this->stalenessService->previewDeletionImpact($document, $runIds, $soleSourcePageIds);
        $impactedClaimIds = EnterpriseWikiSourceReference::query()
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->whereNotNull('enterprise_wiki_claim_id')
            ->distinct()
            ->pluck('enterprise_wiki_claim_id');

        $pageVersionsDeleted = $this->pageVersionCount($soleSourcePageIds);
        $findingsDeleted = $this->findingCount($document, $runIds, $soleSourcePageIds);
        $filePath = (string) $document->file_path;
        $customerId = (int) $document->customer_id;

        $blockedByRace = false;
        $pendingApprovalRunsCancelled = 0;
        $withdrawal = ['pages_rewritten' => 0, 'blocks_removed' => 0, 'links_dematerialized' => 0, 'pages_deleted_without_substance' => 0];
        $keptSharedPages = $sharedPageIds;
        $pagesDeleted = $soleSourcePageIds->count();

        DB::transaction(function () use (
            $document, $runIds, $soleSourcePageIds, $sharedPageIds, $impactedClaimIds, $actor,
            &$blockedByRace, &$pendingApprovalRunsCancelled, &$withdrawal, &$keptSharedPages, &$pagesDeleted,
        ): void {
            $lockedRuns = $runIds->isEmpty()
                ? collect()
                : EnterpriseWikiIngestRun::query()->whereIn('id', $runIds)->lockForUpdate()->get(['id', 'status']);

            if ($lockedRuns->contains(fn (EnterpriseWikiIngestRun $run): bool => $run->expectsAutomaticProgress())) {
                $blockedByRace = true;

                return;
            }

            $awaitingRuns = $lockedRuns->filter(fn (EnterpriseWikiIngestRun $run): bool => $run->isAwaitingHumanAction());
            $pendingApprovalRunsCancelled = $awaitingRuns->count();

            foreach ($awaitingRuns as $run) {
                $this->documentFlowService->cancelRun($run, $actor);
            }

            $this->stalenessService->markAnswersStaleForDeletedDocument($document, $runIds, $soleSourcePageIds);

            // WITHDRAWAL, before anything is torn down — both steps need the state that is about to
            // disappear: the shared pages' blocks still carry this document's source_id, and the
            // links still have both their graph edges and their target pages. Deterministic, no AI,
            // and fail-closed: anything either step cannot represent safely throws, and the whole
            // deletion rolls back rather than leaving the Wiki half-withdrawn.
            $blockWithdrawal = $this->withdrawalService->withdrawBlocks($document, $sharedPageIds);

            // A shared page left with no substance of its own goes with the document. It joins the
            // sole-source pages and is deleted through the same path — same link cleanup, same
            // cascades, no separate deletion mechanism. Current state decides: a page that once had
            // another document's substance but no longer does is, today, held up by this document
            // alone, and history is audit rather than a reason to keep an empty page.
            $doomedPageIds = $soleSourcePageIds->merge($blockWithdrawal['doomed_page_ids'])->unique()->values();
            $keptSharedPages = $sharedPageIds->diff($blockWithdrawal['doomed_page_ids'])->values();
            $pagesDeleted = $doomedPageIds->count();
            $deletedPageSlugs = EnterpriseWikiPage::query()->whereIn('id', $doomedPageIds)->pluck('slug')->all();

            $linkWithdrawal = $this->withdrawalService->dematerializeIncomingLinks($doomedPageIds);

            $withdrawal = [
                'pages_rewritten' => $blockWithdrawal['pages_rewritten'] + $linkWithdrawal['pages_rewritten'],
                'blocks_removed' => $blockWithdrawal['blocks_removed'],
                'links_dematerialized' => $linkWithdrawal['links_dematerialized'],
                'pages_deleted_without_substance' => $blockWithdrawal['doomed_page_ids']->count(),
            ];

            if ($doomedPageIds->isNotEmpty()) {
                EnterpriseWikiLintFinding::query()
                    ->whereIn('enterprise_wiki_page_id', $doomedPageIds)
                    ->delete();
            }

            if ($runIds->isNotEmpty()) {
                EnterpriseWikiLintFinding::query()
                    ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
                    ->delete();
            }

            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_document_id', $document->id)
                ->delete();

            // Canonical facts identified by (source_type, source_id) = this document have no real
            // foreign key — claims.canonical_fact_id is nullOnDelete, so any surviving claim on a
            // shared page simply loses its cross-page reuse cache link, never its own content.
            EnterpriseWikiCanonicalFact::query()
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->where('customer_id', $document->customer_id)
                ->delete();

            // Source references citing this document — on a sole-source page's claim this is
            // redundant with the cascade the page delete below performs anyway; on a SHARED
            // page's claim this is the only cleanup that claim needs (Del 4: the claim, its other
            // sources, and the page itself are all preserved).
            EnterpriseWikiSourceReference::query()
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->delete();

            $this->resetClaimsThatLostTheirOnlySource($impactedClaimIds);

            // Cascades: page_versions, claims, (any remaining) source_references, page_links,
            // ingest_run_pages, page_version_document_owner_approvals, page_relink_attempts,
            // page_link_qa_attempts.
            if ($doomedPageIds->isNotEmpty()) {
                EnterpriseWikiPage::query()
                    ->whereIn('id', $doomedPageIds)
                    ->delete();
            }

            // Cascades: ingest_sections (also deleted explicitly first for clarity), qa_snapshots,
            // qa_regressions, page_links.enterprise_wiki_ingest_run_id (nulled), page_relink_attempts,
            // page_link_qa_attempts, page_version_document_owner_approvals.enterprise_wiki_ingest_run_id (nulled).
            if ($runIds->isNotEmpty()) {
                EnterpriseWikiIngestSection::query()
                    ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
                    ->delete();

                EnterpriseWikiIngestRun::query()
                    ->whereIn('id', $runIds)
                    ->delete();
            }

            // Cascades: claim_source_reconciliation_attempts.
            $document->delete();

            // The state the active Wiki must be in for this deletion to be allowed to commit. Runs
            // last, inside the transaction, so a violation rolls everything back.
            $this->withdrawalService->assertActiveWikiIsClean(
                (int) $document->id,
                (int) $document->customer_id,
                $doomedPageIds,
                $deletedPageSlugs,
            );
        });

        if ($blockedByRace) {
            return ['blocked' => true, 'reason' => 'in_progress_run'];
        }

        // Del 4: a kept shared page's document-owner-approval requirements were computed from its
        // claims' source references, which just changed (this document's references are gone) —
        // re-derive them through the existing sync mechanism rather than leaving a stale
        // requirement pointing at a document that no longer exists.
        $this->resyncSharedPages($keptSharedPages);

        [$storageDeleted, $storageError] = $this->deleteStorageFileIfExclusive($document->id, $customerId, $filePath);

        return [
            'blocked' => false,
            'runs_deleted' => $runIds->count(),
            'pending_approval_runs_cancelled' => $pendingApprovalRunsCancelled,
            'sole_source_pages_deleted' => $pagesDeleted,
            'shared_pages_kept' => $keptSharedPages->count(),
            'pages_rewritten_by_withdrawal' => $withdrawal['pages_rewritten'],
            'blocks_withdrawn' => $withdrawal['blocks_removed'],
            'links_dematerialized' => $withdrawal['links_dematerialized'],
            'pages_deleted_without_substance' => $withdrawal['pages_deleted_without_substance'],
            'page_versions_deleted' => $pageVersionsDeleted,
            'claims_affected' => $impactedClaimIds->count(),
            'findings_deleted' => $findingsDeleted,
            'stale_wiki_answers_marked' => $staleImpact['stale_wiki_answer_count'],
            'storage_deleted' => $storageDeleted,
            'storage_error' => $storageError,
        ];
    }

    /**
     * Classify pages linked to the given run IDs as sole-source or shared.
     *
     * Sole-source: page only appears in run_pages rows for these runs.
     * Shared: page also appears in run_pages rows from other runs (other documents).
     *
     * @param  Collection<int, int>  $runIds
     * @return array{0: Collection<int, int>, 1: Collection<int, int>} [soleSourcePageIds, sharedPageIds]
     */
    public function classifyPages(Collection $runIds): array
    {
        if ($runIds->isEmpty()) {
            return [collect(), collect()];
        }

        $allPageIds = DB::table('enterprise_wiki_ingest_run_pages')
            ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
            ->pluck('enterprise_wiki_page_id')
            ->unique()
            ->values();

        if ($allPageIds->isEmpty()) {
            return [collect(), collect()];
        }

        $sharedPageIds = DB::table('enterprise_wiki_ingest_run_pages')
            ->whereIn('enterprise_wiki_page_id', $allPageIds)
            ->whereNotIn('enterprise_wiki_ingest_run_id', $runIds)
            ->pluck('enterprise_wiki_page_id')
            ->unique()
            ->values();

        $soleSourcePageIds = $allPageIds->diff($sharedPageIds)->values();

        return [$soleSourcePageIds, $sharedPageIds];
    }

    /**
     * @param  Collection<int, int>  $soleSourcePageIds
     */
    private function pageVersionCount(Collection $soleSourcePageIds): int
    {
        if ($soleSourcePageIds->isEmpty()) {
            return 0;
        }

        return EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $soleSourcePageIds)
            ->count();
    }

    /**
     * @param  Collection<int, int>  $runIds
     * @param  Collection<int, int>  $soleSourcePageIds
     */
    private function findingCount(EnterpriseWikiDocument $document, Collection $runIds, Collection $soleSourcePageIds): int
    {
        return EnterpriseWikiLintFinding::query()
            ->where(function ($query) use ($document, $runIds, $soleSourcePageIds): void {
                $query->where('enterprise_wiki_document_id', $document->id);

                if ($runIds->isNotEmpty()) {
                    $query->orWhereIn('enterprise_wiki_ingest_run_id', $runIds);
                }

                if ($soleSourcePageIds->isNotEmpty()) {
                    $query->orWhereIn('enterprise_wiki_page_id', $soleSourcePageIds);
                }
            })
            ->count();
    }

    private function storageFileExists(EnterpriseWikiDocument $document): bool
    {
        $filePath = (string) $document->file_path;

        return $filePath !== '' && Storage::disk('local')->exists($filePath);
    }

    /**
     * @param  Collection<int, int>  $sharedPageIds
     */
    private function resyncSharedPages(Collection $sharedPageIds): void
    {
        if ($sharedPageIds->isEmpty()) {
            return;
        }

        $versions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $sharedPageIds)
            ->where('is_current', true)
            ->get();

        foreach ($versions as $version) {
            $this->documentOwnerApprovalService->syncForPageVersion($version);
        }
    }

    /**
     * Del 7: only ever deletes the file when it (a) genuinely belongs to this document (no other
     * document row still references the same path — defensive, since each upload is stored under
     * a unique ULID filename and duplicate uploads are already rejected at store time), and (b)
     * sits inside the expected Enterprise Wiki storage area for this customer. A failure (or a
     * skip) is reported back rather than silently swallowed, and never logs document content.
     *
     * @return array{0: bool, 1: ?string} [deleted, error]
     */
    private function deleteStorageFileIfExclusive(int $documentId, int $customerId, string $filePath): array
    {
        if ($filePath === '') {
            return [true, null];
        }

        $expectedPrefix = sprintf('customers/%d/wiki-documents/', $customerId);

        if (! Str::startsWith($filePath, $expectedPrefix)) {
            Log::warning('[PROCYNIA][WIKI_SOURCE_DELETE] Storage file path outside expected Enterprise Wiki area — not deleted.', [
                'document_id' => $documentId,
                'file_path' => $filePath,
            ]);

            return [false, 'unexpected_path'];
        }

        $stillReferenced = EnterpriseWikiDocument::query()
            ->where('file_path', $filePath)
            ->where('id', '!=', $documentId)
            ->exists();

        if ($stillReferenced) {
            Log::warning('[PROCYNIA][WIKI_SOURCE_DELETE] Storage file still referenced by another document — not deleted.', [
                'document_id' => $documentId,
                'file_path' => $filePath,
            ]);

            return [false, 'shared_with_another_document'];
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($filePath)) {
            return [true, null];
        }

        try {
            $deleted = $disk->delete($filePath);
        } catch (\Throwable $e) {
            Log::error('[PROCYNIA][WIKI_SOURCE_DELETE] Failed to delete storage file.', [
                'document_id' => $documentId,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [false, 'exception'];
        }

        if (! $deleted) {
            Log::error('[PROCYNIA][WIKI_SOURCE_DELETE] Storage disk delete() reported failure.', [
                'document_id' => $documentId,
                'file_path' => $filePath,
            ]);

            return [false, 'delete_failed'];
        }

        return [true, null];
    }

    /**
     * @param  Collection<int, int|string>  $claimIds
     */
    private function resetClaimsThatLostTheirOnlySource(Collection $claimIds): void
    {
        $resolvedClaimIds = $claimIds
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($resolvedClaimIds->isEmpty()) {
            return;
        }

        $claims = EnterpriseWikiClaim::query()
            ->whereIn('id', $resolvedClaimIds->all())
            ->withCount('sourceReferences')
            ->get();

        foreach ($claims as $claim) {
            if ($claim->source_references_count > 0) {
                continue;
            }

            if (! in_array($claim->approval_status, [
                EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
                EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
            ], true)) {
                continue;
            }

            $claim->update([
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'approval_comment' => null,
            ]);

            $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);
        }
    }
}
