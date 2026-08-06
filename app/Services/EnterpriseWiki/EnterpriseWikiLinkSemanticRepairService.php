<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\Ai\Wiki\WikiLinkSemanticQaAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Semantic QA and repair of a run's pages' inline wikilinks (8I-6).
 *
 * Deterministic lint (EnterpriseWikiAppliedRunLintService) catches structural defects — broken
 * slugs, self-links, materialization drift. This service catches what only a semantic judgement
 * can: a central concept mentioned in prose but never linked, an existing link with a misleading
 * anchor or wrong target, or excessive linking. It never invents a slug — the reviewer
 * (WikiLinkSemanticQaAiClient) is restricted to the same page-scoped catalog used elsewhere in
 * Phase 8I, and any recommended add/remove is deterministically re-validated before persistence.
 *
 * One repair attempt per (run, page), tracked in enterprise_wiki_page_link_qa_attempts — a
 * repeat call for the same run is a no-op, matching the "one automatic repair" convention
 * already used by EnterpriseWikiSemanticRepairService (8G-5) for content repair.
 */
class EnterpriseWikiLinkSemanticRepairService
{
    /**
     * Ingest run ids collected during the current repairForRun() call whose claims need
     * re-syncing (EnterpriseWikiPageVersionClaimSyncService::syncRuns()) because one of their
     * pages got a new current version. Reset at the start of every repairForRun() call.
     *
     * @var list<int>
     */
    private array $pendingClaimResyncRunIds = [];

    public function __construct(
        private readonly WikiLinkSemanticQaAiClient $qaClient,
        private readonly WikiLinkRevisionAiClient $revisionClient,
        private readonly EnterpriseWikiLinkCatalogService $linkCatalogService,
        private readonly EnterpriseWikiLinkParser $linkParser,
        private readonly EnterpriseWikiLinkResolver $linkResolver,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
        private readonly EnterpriseWikiPageVersionBlockProvenanceRepairService $blockProvenanceRepairService,
    ) {}

    /**
     * @return array{pages_reviewed: int, applied: int, skipped: int, failed: int}
     *
     * @throws InvalidArgumentException if the run is not applied
     */
    public function repairForRun(EnterpriseWikiIngestRun $run): array
    {
        if (($run->fresh() ?? $run)->isTerminal()) {
            return ['pages_reviewed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];
        }

        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can have link semantic repair run."
            );
        }

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        $languageCode = $this->resolveLanguageCode($run->customer_id);

        $counts = [
            'pages_reviewed' => 0,
            'applied' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->pendingClaimResyncRunIds = [];

        foreach ($pivotRows as $row) {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                break;
            }

            $page = $row->page;

            if ($page === null) {
                continue;
            }

            $counts['pages_reviewed']++;

            $status = $this->attemptPageRepair($run, $page, $languageCode);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        // Re-run deterministic lint so findings reflect the state after any repair — closes
        // findings for links that were fixed, opens findings for anything still wrong. Only
        // needed when a repair actually changed a page; skip the extra pass otherwise.
        if ($counts['applied'] > 0 && ! (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal() ?? true)) {
            // A revised page is a brand-new EnterpriseWikiPageVersion — its claims must be
            // re-extracted/verified against this version, not left pointing at the superseded
            // one (see EnterpriseWikiPageVersionClaimSyncService). pendingClaimResyncRunIds
            // always includes $run itself, plus any other run a repaired page also belongs to.
            $affectedRunIds = array_unique(array_merge($this->pendingClaimResyncRunIds, [$run->id]));
            $this->claimSyncService->syncRuns($affectedRunIds);

            foreach ($affectedRunIds as $runId) {
                $affectedRun = $runId === $run->id ? $run : EnterpriseWikiIngestRun::query()->find($runId);

                if ($affectedRun !== null) {
                    $this->lintService->lint($affectedRun->fresh() ?? $affectedRun);
                }
            }
        }

        Log::info('[WIKI_LINK_SEMANTIC_REPAIR] Link semantic QA/repair completed.', [
            'run_id' => $run->id,
            'pages_reviewed' => $counts['pages_reviewed'],
            'applied' => $counts['applied'],
            'skipped' => $counts['skipped'],
            'failed' => $counts['failed'],
        ]);

        return $counts;
    }

    /**
     * @return string one of EnterpriseWikiPageLinkQaAttempt::STATUSES
     */
    private function attemptPageRepair(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $languageCode): string
    {
        $claimed = DB::transaction(function () use ($run, $page): bool {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return false;
            }

            $exists = EnterpriseWikiPageLinkQaAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            EnterpriseWikiPageLinkQaAttempt::query()->create([
                'customer_id' => $run->customer_id,
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'status' => EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED,
                'reason' => 'reserved',
                'attempted_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return $this->existingAttemptStatus($run, $page);
        }

        if (! WikiLinkSemanticQaAiClient::isAvailable()) {
            return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_AI_UNAVAILABLE);
        }

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        $markdown = trim((string) ($currentVersion?->content_markdown ?? ''));

        if ($markdown === '') {
            return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, 'empty_content');
        }

        $catalogResult = $this->linkCatalogService->buildForPage($run, $page);

        try {
            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                return EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED;
            }

            $diagnosis = $this->qaClient->review($markdown, $page->page_type, $catalogResult['catalog'], $languageCode);

            if ($diagnosis['assessment'] !== 'repair_recommended'
                || (empty($diagnosis['missing_link_slugs']) && empty($diagnosis['remove_link_slugs']))
            ) {
                return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_NO_CHANGE_RECOMMENDED);
            }

            $instructions = $this->buildInstructions($diagnosis, $catalogResult['catalog']);

            if (EnterpriseWikiIngestRun::query()->find($run->id)?->isTerminal()) {
                return EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED;
            }

            $revision = $this->revisionClient->reviseLinks(
                existingContent: $markdown,
                pageType: $page->page_type,
                linkCatalog: $catalogResult['catalog'],
                instructions: $instructions,
                languageCode: $languageCode,
            );

            if (! $revision['changed'] || trim($revision['markdown']) === $markdown) {
                return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_NO_CHANGE_RECOMMENDED);
            }

            $this->validateRevision($run, $page, $markdown, $revision['markdown'], $diagnosis);

            $newVersion = DB::transaction(function () use ($run, $page, $revision): ?EnterpriseWikiPageVersion {
                $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

                if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                    return null;
                }

                $version = $this->writeNewCurrentVersion($run->id, $page->id, $revision['markdown']);
                $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($page->id);
                $this->buildPageLinksService->materializeWikilinksForPage($page, $run->id);
                $this->pendingClaimResyncRunIds = array_merge(
                    $this->pendingClaimResyncRunIds,
                    $this->claimSyncService->markPageForResync($page),
                );

                return $version;
            });

            if (! $newVersion instanceof EnterpriseWikiPageVersion) {
                return EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED;
            }

            return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_APPLIED, null, $newVersion->id);
        } catch (EnterpriseWikiInvalidWikilinksException $e) {
            Log::warning('[WIKI_LINK_SEMANTIC_REPAIR] Revision rejected.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'reason' => $e->getMessage(),
            ]);

            return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED, EnterpriseWikiPageLinkQaAttempt::REASON_INVALID_REVISION);
        } catch (Throwable $e) {
            Log::error('[WIKI_LINK_SEMANTIC_REPAIR] Repair attempt failed.', [
                'run_id' => $run->id,
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);

            return $this->finalize($run, $page, EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED, 'ai_error');
        }
    }

    /**
     * Reject a revision that introduces any broken/self/cross-customer wikilink, a
     * malformed-but-attempted wikilink, or an added/removed target the diagnosis did not
     * explicitly recommend. Never repairs — an invalid revision is rejected outright.
     *
     * @throws EnterpriseWikiInvalidWikilinksException
     */
    private function validateRevision(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $originalMarkdown,
        string $revisedMarkdown,
        array $diagnosis,
    ): void {
        $parsed = $this->linkParser->parse($revisedMarkdown);
        $rawOccurrences = $this->linkParser->countRawOccurrences($revisedMarkdown);

        if ($rawOccurrences > count($parsed)) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] page [%d]: revised content contains %d malformed wikilink attempt(s).',
                $run->id,
                $page->id,
                $rawOccurrences - count($parsed),
            ));
        }

        $occurrences = $this->linkResolver->resolveOccurrences($run->customer_id, $page, $parsed);
        $invalidSlugs = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['status'] !== EnterpriseWikiLinkResolver::STATUS_VALID) {
                $invalidSlugs[] = $occurrence['link']['target_slug'];
            }
        }

        if ($invalidSlugs !== []) {
            throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                'Run [%d] page [%d]: %d invalid wikilink slug(s) in revision: %s.',
                $run->id,
                $page->id,
                count($invalidSlugs),
                implode(', ', array_values(array_unique($invalidSlugs))),
            ));
        }

        $originalResolution = $this->linkResolver->resolve($run->customer_id, $page, $this->linkParser->parse($originalMarkdown));
        $originalValidTargetIds = array_map(fn (array $t) => $t['to_page']->id, $originalResolution['resolved']);

        $newResolution = $this->linkResolver->resolve($run->customer_id, $page, $parsed);
        $newValidTargetIds = array_map(fn (array $t) => $t['to_page']->id, $newResolution['resolved']);

        $addedIds = array_diff($newValidTargetIds, $originalValidTargetIds);
        $removedIds = array_diff($originalValidTargetIds, $newValidTargetIds);

        if ($addedIds !== []) {
            $addedSlugs = EnterpriseWikiPage::query()->whereIn('id', $addedIds)->pluck('slug')->all();
            $unrequested = array_diff($addedSlugs, $diagnosis['missing_link_slugs']);

            if ($unrequested !== []) {
                throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                    'Run [%d] page [%d]: revision added unrequested wikilink(s): %s.',
                    $run->id,
                    $page->id,
                    implode(', ', $unrequested),
                ));
            }
        }

        if ($removedIds !== []) {
            $removedSlugs = EnterpriseWikiPage::query()->whereIn('id', $removedIds)->pluck('slug')->all();
            $unrequested = array_diff($removedSlugs, $diagnosis['remove_link_slugs']);

            if ($unrequested !== []) {
                throw new EnterpriseWikiInvalidWikilinksException(sprintf(
                    'Run [%d] page [%d]: revision removed unrequested wikilink(s): %s.',
                    $run->id,
                    $page->id,
                    implode(', ', $unrequested),
                ));
            }
        }
    }

    private function buildInstructions(array $diagnosis, array $catalog): string
    {
        $bySlug = collect($catalog)->keyBy('slug');
        $lines = [];

        if (! empty($diagnosis['missing_link_slugs'])) {
            $lines[] = 'Add a natural inline wikilink for each of the following pages, at their first or most natural mention:';

            foreach ($diagnosis['missing_link_slugs'] as $slug) {
                $title = $bySlug->get($slug)['title'] ?? $slug;
                $lines[] = "  - [[{$slug}]] ({$title})";
            }
        }

        if (! empty($diagnosis['remove_link_slugs'])) {
            $lines[] = 'Remove the existing wikilink(s) to the following page(s) — convert back to plain text, keep the surrounding wording:';

            foreach ($diagnosis['remove_link_slugs'] as $slug) {
                $lines[] = "  - {$slug}";
            }
        }

        if (! empty($diagnosis['critique'])) {
            $lines[] = '';
            $lines[] = "Reviewer note: {$diagnosis['critique']}";
        }

        $lines[] = '';
        $lines[] = 'Make only these specific changes. Do not add or remove any other wikilink, and do not otherwise alter the content.';

        return implode("\n", $lines);
    }

    private function writeNewCurrentVersion(int $runId, int $pageId, string $markdown): ?EnterpriseWikiPageVersion
    {
        return DB::transaction(function () use ($runId, $pageId, $markdown): ?EnterpriseWikiPageVersion {
            $run = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($runId);

            if (! $run instanceof EnterpriseWikiIngestRun || $run->isTerminal()) {
                return null;
            }

            $next = ((int) EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $pageId)
                ->max('version_number')) + 1;

            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $pageId)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $version = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $pageId,
                'version_number' => $next,
                'is_current' => true,
                'content_markdown' => $markdown,
                'generated_by_model' => WikiLinkRevisionAiClient::MODEL.'/link-semantic-repair',
            ]);

            $this->restoreBlockProvenance($pageId, $version);

            return $version;
        });
    }

    /**
     * The revised markdown just persisted above never carries content_blocks_json — reuses
     * EnterpriseWikiPageVersionBlockProvenanceRepairService (the same reconstruction/matching
     * rules as wiki:repair-page-version-block-provenance) to restore it immediately from the
     * superseded version's blocks, and re-link any already-existing unanchored claims, instead of
     * leaving this version anchor-less until a later manual sweep. Never guesses: an ambiguous or
     * impossible reconstruction is logged and left for the manual command — it must never block
     * or fail this otherwise-successful link repair.
     */
    private function restoreBlockProvenance(int $pageId, EnterpriseWikiPageVersion $version): void
    {
        $result = $this->blockProvenanceRepairService->repairPageVersion($pageId, $version);

        if ($result['status'] === 'repaired') {
            Log::info('[WIKI_LINK_SEMANTIC_REPAIR] Block provenance restored for new version.', [
                'page_id' => $pageId,
                'page_version_id' => $version->id,
                'claims_linked' => $result['claims_linked'],
            ]);

            return;
        }

        if ($result['status'] === 'skipped_already_has_blocks') {
            return;
        }

        Log::warning('[WIKI_LINK_SEMANTIC_REPAIR] Block provenance could not be restored for new version — claims on it will remain unanchored until a targeted repair runs.', [
            'page_id' => $pageId,
            'page_version_id' => $version->id,
            'status' => $result['status'],
        ]);
    }

    private function finalize(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        string $status,
        ?string $reason,
        ?int $createdPageVersionId = null,
    ): string {
        $updated = DB::transaction(function () use ($run, $page, $status, $reason, $createdPageVersionId): bool {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return false;
            }

            return EnterpriseWikiPageLinkQaAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->update([
                    'status' => $status,
                    'reason' => $reason,
                    'created_page_version_id' => $createdPageVersionId,
                    'attempted_at' => now(),
                ]) > 0;
        });

        return $updated ? $status : EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED;
    }

    private function existingAttemptStatus(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): string
    {
        $attempt = EnterpriseWikiPageLinkQaAttempt::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();

        return $attempt?->status ?? EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED;
    }

    private function resolveLanguageCode(int $customerId): string
    {
        $customer = Customer::query()->with('language')->find($customerId);

        return $customer?->language?->code ?? 'no';
    }
}
