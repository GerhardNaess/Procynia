<?php

namespace App\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
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
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
    ) {}

    /**
     * Apply a manual edit to one or more existing mixed-provenance content blocks.
     *
     * The single-block calling form is:
     *   applyManualMixedBlockEdit($run, $page, $version, $claim, 'block-0003', 'new markdown', $actor)
     *
     * The multi-block calling form is:
     *   applyManualMixedBlockEdit($run, $page, $version, $claim, ['block-0003' => '...', ...], $actor)
     *
     * @param  string|array<string, string>|list<array<string, mixed>>  $contentBlockKey
     * @return array{
     *     page_version_id: int,
     *     previous_page_version_id: int,
     *     changed_content_block_keys: list<string>,
     *     copied_claim_ids: list<int>,
     *     new_claim_ids: list<int>,
     *     extracted_claims: int,
     *     verified_claims: int,
     *     canonical_fact_ids: list<int>
     * }
     */
    public function applyManualMixedBlockEdit(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        EnterpriseWikiClaim $reviewClaim,
        string|array $contentBlockKey,
        string|User|null $markdownOrActor,
        ?User $actor = null,
    ): array {
        [$submittedMarkdownByBlockKey, $actor] = $this->normalizeManualMixedBlockEdits($contentBlockKey, $markdownOrActor, $actor);

        $this->assertManualMixedBlockEditScope(
            $run,
            $page,
            $expectedCurrentVersion,
            $reviewClaim,
            array_keys($submittedMarkdownByBlockKey),
            $actor,
        );

        $stagedVersion = null;

        try {
            $staged = $this->createStagedManualMixedBlockVersion(
                $run,
                $page,
                $expectedCurrentVersion,
                $submittedMarkdownByBlockKey,
                $actor,
            );
            $stagedVersion = $staged['version'];
            $reviewClaimBlockKey = trim((string) ($reviewClaim->content_block_key ?? ''));

            if (! array_key_exists($reviewClaimBlockKey, $staged['changed_blocks'])) {
                throw new \InvalidArgumentException("Review claim [{$reviewClaim->id}] must belong to a changed content block.");
            }

            $newClaimIds = [];
            $extractedClaims = 0;
            $verifiedClaims = 0;
            $canonicalRecordingCandidates = [];

            foreach ($staged['changed_blocks'] as $blockKey => $block) {
                $extraction = $this->extractPageClaimsService->extractClaimsForManualMixedBlock(
                    $run->fresh() ?? $run,
                    $stagedVersion->fresh() ?? $stagedVersion,
                    $block,
                );

                $blockClaimIds = $extraction['claim_ids'];
                $extractedClaims += $extraction['claims'];
                array_push($newClaimIds, ...$blockClaimIds);

                $verification = $this->verifyPageClaimsService->verifyClaimsForManualMixedBlock(
                    $run->fresh() ?? $run,
                    $page->fresh() ?? $page,
                    $expectedCurrentVersion->fresh() ?? $expectedCurrentVersion,
                    $stagedVersion->fresh() ?? $stagedVersion,
                    $blockKey,
                    $blockClaimIds,
                );

                if ($verification['busy'] > 0) {
                    throw new \RuntimeException("Manual Wiki block edit could not verify all claims for content block [{$blockKey}].");
                }

                $verifiedClaims += $verification['claims'];
                array_push($canonicalRecordingCandidates, ...$verification['canonical_recording_candidates']);
            }

            $this->assertManualMixedBlockClaimsVerified(
                $stagedVersion->fresh() ?? $stagedVersion,
                array_keys($staged['changed_blocks']),
                $newClaimIds,
            );

            $canonicalFactIds = $this->promoteStagedManualMixedBlockVersion(
                $run,
                $page,
                $expectedCurrentVersion,
                $stagedVersion,
                array_keys($staged['changed_blocks']),
                $newClaimIds,
                $canonicalRecordingCandidates,
            );

            return [
                'page_version_id' => $stagedVersion->id,
                'previous_page_version_id' => $expectedCurrentVersion->id,
                'changed_content_block_keys' => array_keys($staged['changed_blocks']),
                'copied_claim_ids' => $staged['copied_claim_ids'],
                'new_claim_ids' => array_values($newClaimIds),
                'extracted_claims' => $extractedClaims,
                'verified_claims' => $verifiedClaims,
                'canonical_fact_ids' => $canonicalFactIds,
            ];
        } catch (\Throwable $e) {
            $this->cleanupStagedVersion($stagedVersion);

            throw $e;
        }
    }

    /**
     * @param  string|array<string, string>|list<array<string, mixed>>  $contentBlockKey
     * @return array{0: array<string, string>, 1: User}
     */
    private function normalizeManualMixedBlockEdits(
        string|array $contentBlockKey,
        string|User|null $markdownOrActor,
        ?User $actor,
    ): array {
        if (is_array($contentBlockKey)) {
            if ($markdownOrActor instanceof User && $actor === null) {
                $actor = $markdownOrActor;
            } elseif ($markdownOrActor !== null && ! $markdownOrActor instanceof User) {
                throw new \InvalidArgumentException('Manual Wiki block edit received invalid markdown arguments.');
            }

            if (! $actor instanceof User) {
                throw new \InvalidArgumentException('Manual Wiki block edit requires an actor.');
            }

            $edits = [];

            if (array_is_list($contentBlockKey)) {
                foreach ($contentBlockKey as $block) {
                    if (! is_array($block)) {
                        throw new \InvalidArgumentException('Manual Wiki block edit block payload must be an array.');
                    }

                    $key = trim((string) ($block['block_key'] ?? $block['content_block_key'] ?? ''));
                    $markdown = $block['markdown'] ?? null;

                    if (! is_string($markdown)) {
                        throw new \InvalidArgumentException("Manual Wiki block edit for block [{$key}] requires markdown.");
                    }

                    $this->putManualMixedBlockEdit($edits, $key, $markdown);
                }
            } else {
                foreach ($contentBlockKey as $key => $markdown) {
                    if (! is_string($markdown)) {
                        throw new \InvalidArgumentException("Manual Wiki block edit for block [{$key}] requires markdown.");
                    }

                    $this->putManualMixedBlockEdit($edits, (string) $key, $markdown);
                }
            }
        } else {
            if (! is_string($markdownOrActor)) {
                throw new \InvalidArgumentException('Manual Wiki block edit requires markdown.');
            }

            if (! $actor instanceof User) {
                throw new \InvalidArgumentException('Manual Wiki block edit requires an actor.');
            }

            $edits = [];
            $this->putManualMixedBlockEdit($edits, $contentBlockKey, $markdownOrActor);
        }

        if ($edits === []) {
            throw new \InvalidArgumentException('Manual Wiki block edit requires at least one content block.');
        }

        return [$edits, $actor];
    }

    /**
     * @param  array<string, string>  $edits
     */
    private function putManualMixedBlockEdit(array &$edits, string $contentBlockKey, string $markdown): void
    {
        $contentBlockKey = trim($contentBlockKey);
        $markdown = trim($markdown);

        if ($contentBlockKey === '') {
            throw new \InvalidArgumentException('Manual Wiki block edit requires a content_block_key.');
        }

        if (array_key_exists($contentBlockKey, $edits)) {
            throw new \InvalidArgumentException("Manual Wiki block edit received duplicate content_block_key [{$contentBlockKey}].");
        }

        if ($markdown === '') {
            throw new \InvalidArgumentException("Manual Wiki block edit for content block [{$contentBlockKey}] requires non-empty markdown.");
        }

        $edits[$contentBlockKey] = $markdown;
    }

    /**
     * @param  list<string>  $submittedBlockKeys
     */
    private function assertManualMixedBlockEditScope(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        EnterpriseWikiClaim $reviewClaim,
        array $submittedBlockKeys,
        User $actor,
    ): void {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException("Run [{$run->id}] is not applied.");
        }

        if ($run->source_type !== EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            throw new \InvalidArgumentException("Run [{$run->id}] does not use an Enterprise Wiki document source.");
        }

        if ((int) $page->customer_id !== (int) $run->customer_id) {
            throw new \InvalidArgumentException("Page [{$page->id}] does not belong to run customer [{$run->customer_id}].");
        }

        $documentExists = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->whereKey($run->source_id)
            ->exists();

        if (! $documentExists) {
            throw new \InvalidArgumentException("Source document [{$run->source_id}] not found for run [{$run->id}].");
        }

        $actorExists = (int) ($actor->id ?? 0) > 0
            && User::query()
                ->whereKey($actor->id)
                ->where('customer_id', $run->customer_id)
                ->exists();

        if (! $actorExists
            || (int) ($actor->customer_id ?? 0) !== (int) $run->customer_id
            || ! $actor->canApproveWikiClaims()
        ) {
            throw new \InvalidArgumentException('User cannot manually edit this Wiki claim.');
        }

        $current = EnterpriseWikiPageVersion::query()->find($expectedCurrentVersion->id);

        if ($current === null
            || (int) $current->enterprise_wiki_page_id !== (int) $page->id
            || ! $current->is_current
            || $current->is_staged
        ) {
            throw new \InvalidArgumentException("Expected page version [{$expectedCurrentVersion->id}] is not the current published version for page [{$page->id}].");
        }

        $runPage = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->first();

        if ($runPage === null) {
            throw new \InvalidArgumentException("Page [{$page->id}] is not part of run [{$run->id}].");
        }

        if ((int) ($runPage->generated_page_version_id ?? 0) !== (int) $current->id) {
            throw new \InvalidArgumentException("Run [{$run->id}] page [{$page->id}] does not point to expected current page version [{$current->id}].");
        }

        $reviewClaimBlockKey = trim((string) ($reviewClaim->content_block_key ?? ''));

        if ((int) $reviewClaim->enterprise_wiki_page_id !== (int) $page->id
            || (int) $reviewClaim->enterprise_wiki_page_version_id !== (int) $current->id
            || $reviewClaimBlockKey === ''
            || ! in_array($reviewClaimBlockKey, $submittedBlockKeys, true)
        ) {
            throw new \InvalidArgumentException("Review claim [{$reviewClaim->id}] does not belong to one of the submitted current page blocks.");
        }

        $blocksByKey = $this->blocksByStableKey($current);

        foreach ($submittedBlockKeys as $blockKey) {
            if (! array_key_exists($blockKey, $blocksByKey)) {
                throw new \InvalidArgumentException("Content block [{$blockKey}] was not found in current page version [{$current->id}].");
            }
        }
    }

    /**
     * @param  array<string, string>  $submittedMarkdownByBlockKey
     * @return array{
     *     version: EnterpriseWikiPageVersion,
     *     changed_blocks: array<string, array<string, mixed>>,
     *     copied_claim_ids: list<int>
     * }
     */
    private function createStagedManualMixedBlockVersion(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        array $submittedMarkdownByBlockKey,
        User $actor,
    ): array {
        return DB::transaction(function () use ($run, $page, $expectedCurrentVersion, $submittedMarkdownByBlockKey, $actor): array {
            $lockedPage = EnterpriseWikiPage::query()
                ->whereKey($page->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPage === null || (int) $lockedPage->customer_id !== (int) $run->customer_id) {
                throw new \RuntimeException("Page [{$page->id}] is no longer available for run [{$run->id}].");
            }

            $current = EnterpriseWikiPageVersion::query()
                ->whereKey($expectedCurrentVersion->id)
                ->lockForUpdate()
                ->first();

            if ($current === null
                || (int) $current->enterprise_wiki_page_id !== (int) $lockedPage->id
                || ! $current->is_current
                || $current->is_staged
            ) {
                throw new \RuntimeException("Expected page version [{$expectedCurrentVersion->id}] is no longer current.");
            }

            $runPage = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $lockedPage->id)
                ->lockForUpdate()
                ->first();

            if ($runPage === null || (int) ($runPage->generated_page_version_id ?? 0) !== (int) $current->id) {
                throw new \RuntimeException("Run [{$run->id}] page [{$lockedPage->id}] no longer points to expected current page version [{$current->id}].");
            }

            $prepared = $this->manualMixedBlockEditedContent($current, $submittedMarkdownByBlockKey);

            $stagedVersion = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $lockedPage->id,
                'version_number' => (int) $current->version_number + 1,
                'is_current' => false,
                'is_staged' => true,
                'content_markdown' => $this->markdownFromBlocks($prepared['blocks']),
                'content_blocks_json' => $prepared['blocks'],
                'generated_by_model' => null,
                'generation_prompt_hash' => null,
                'created_by_user_id' => $actor->id,
            ]);

            return [
                'version' => $stagedVersion,
                'changed_blocks' => $prepared['changed_blocks'],
                'copied_claim_ids' => $this->copyClaimsForUnchangedBlocks(
                    $current,
                    $stagedVersion,
                    array_keys($prepared['changed_blocks']),
                    array_keys($this->blocksByStableKey($current)),
                ),
            ];
        });
    }

    /**
     * @param  array<string, string>  $submittedMarkdownByBlockKey
     * @return array{
     *     blocks: list<array<string, mixed>>,
     *     changed_blocks: array<string, array<string, mixed>>
     * }
     */
    private function manualMixedBlockEditedContent(EnterpriseWikiPageVersion $current, array $submittedMarkdownByBlockKey): array
    {
        $blocksByKey = $this->blocksByStableKey($current);
        $unknownKeys = array_values(array_diff(array_keys($submittedMarkdownByBlockKey), array_keys($blocksByKey)));

        if ($unknownKeys !== []) {
            throw new \InvalidArgumentException('Manual Wiki block edit referenced unknown content block(s): '.implode(', ', $unknownKeys));
        }

        $blocks = array_values((array) ($current->content_blocks_json ?? []));
        $changedBlocks = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                throw new \RuntimeException("Page version [{$current->id}] contains an invalid content block.");
            }

            $blockKey = trim((string) ($block['block_key'] ?? ''));

            if (! array_key_exists($blockKey, $submittedMarkdownByBlockKey)) {
                continue;
            }

            $nextMarkdown = $submittedMarkdownByBlockKey[$blockKey];

            if (trim((string) ($block['markdown'] ?? '')) === $nextMarkdown) {
                continue;
            }

            if ((string) ($block['content_origin'] ?? '') !== 'mixed') {
                throw new \InvalidArgumentException("Content block [{$blockKey}] is not a mixed-provenance block.");
            }

            $block['markdown'] = $nextMarkdown;
            $blocks[$index] = $block;
            $changedBlocks[$blockKey] = $block;
        }

        if ($changedBlocks === []) {
            throw new \InvalidArgumentException('Manual Wiki block edit did not change any content block.');
        }

        return [
            'blocks' => array_values($blocks),
            'changed_blocks' => $changedBlocks,
        ];
    }

    /**
     * @param  list<string>  $changedBlockKeys
     * @param  list<string>  $allCurrentBlockKeys
     * @return list<int>
     */
    private function copyClaimsForUnchangedBlocks(
        EnterpriseWikiPageVersion $previousVersion,
        EnterpriseWikiPageVersion $stagedVersion,
        array $changedBlockKeys,
        array $allCurrentBlockKeys,
    ): array {
        $unchangedBlockKeys = array_values(array_diff($allCurrentBlockKeys, $changedBlockKeys));

        if ($unchangedBlockKeys === []) {
            return [];
        }

        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $previousVersion->id)
            ->whereIn('content_block_key', $unchangedBlockKeys)
            ->with('sourceReferences')
            ->orderBy('position_order')
            ->get();

        $copiedClaimIds = [];

        foreach ($claims as $claim) {
            $copiedClaim = EnterpriseWikiClaim::query()->create($this->claimClonePayload($claim, $stagedVersion));

            foreach ($claim->sourceReferences as $sourceReference) {
                EnterpriseWikiSourceReference::query()->create(array_merge([
                    'enterprise_wiki_claim_id' => $copiedClaim->id,
                ], $this->sourceReferenceClonePayload($sourceReference)));
            }

            $copiedClaimIds[] = $copiedClaim->id;
        }

        return $copiedClaimIds;
    }

    /**
     * @param  list<string>  $changedBlockKeys
     * @param  list<int>  $newClaimIds
     */
    private function assertManualMixedBlockClaimsVerified(
        EnterpriseWikiPageVersion $stagedVersion,
        array $changedBlockKeys,
        array $newClaimIds,
    ): void {
        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $stagedVersion->id)
            ->whereIn('content_block_key', $changedBlockKeys)
            ->get();

        $actualIds = $claims->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $expectedIds = collect($newClaimIds)->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($actualIds !== $expectedIds) {
            throw new \RuntimeException('Manual Wiki block edit staged claims do not match the claims extracted for changed block(s).');
        }

        $unfinished = $claims->first(
            fn (EnterpriseWikiClaim $claim): bool => $claim->verified_at === null || $claim->verification_claimed_at !== null || $claim->verification_claim_token !== null
        );

        if ($unfinished !== null) {
            throw new \RuntimeException("Manual Wiki block edit claim [{$unfinished->id}] was not fully verified.");
        }
    }

    /**
     * @param  list<string>  $changedBlockKeys
     * @param  list<int>  $newClaimIds
     * @param  list<array{
     *     claim_id: int,
     *     original_content_origin: string,
     *     verification_status: string,
     *     reason: ?string,
     *     supporting_excerpt: ?string
     * }>  $canonicalRecordingCandidates
     * @return list<int>
     */
    private function promoteStagedManualMixedBlockVersion(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $expectedCurrentVersion,
        EnterpriseWikiPageVersion $stagedVersion,
        array $changedBlockKeys,
        array $newClaimIds,
        array $canonicalRecordingCandidates,
    ): array {
        return DB::transaction(function () use ($run, $page, $expectedCurrentVersion, $stagedVersion, $changedBlockKeys, $newClaimIds, $canonicalRecordingCandidates): array {
            $lockedPage = EnterpriseWikiPage::query()
                ->whereKey($page->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPage === null || (int) $lockedPage->customer_id !== (int) $run->customer_id) {
                throw new \RuntimeException("Page [{$page->id}] is no longer available for run [{$run->id}].");
            }

            $current = EnterpriseWikiPageVersion::query()
                ->whereKey($expectedCurrentVersion->id)
                ->lockForUpdate()
                ->first();
            $staged = EnterpriseWikiPageVersion::query()
                ->whereKey($stagedVersion->id)
                ->lockForUpdate()
                ->first();

            if ($current === null
                || (int) $current->enterprise_wiki_page_id !== (int) $lockedPage->id
                || ! $current->is_current
                || $current->is_staged
            ) {
                throw new \RuntimeException("Expected page version [{$expectedCurrentVersion->id}] is no longer current.");
            }

            if ($staged === null
                || (int) $staged->enterprise_wiki_page_id !== (int) $lockedPage->id
                || $staged->is_current
                || ! $staged->is_staged
                || $staged->generated_by_model !== null
                || (int) ($staged->created_by_user_id ?? 0) <= 0
                || (int) $staged->version_number !== (int) $current->version_number + 1
            ) {
                throw new \RuntimeException("Staged page version [{$stagedVersion->id}] is no longer promotable.");
            }

            $runPage = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $lockedPage->id)
                ->lockForUpdate()
                ->first();

            if ($runPage === null || (int) ($runPage->generated_page_version_id ?? 0) !== (int) $current->id) {
                throw new \RuntimeException("Run [{$run->id}] page [{$lockedPage->id}] no longer points to expected current page version [{$current->id}].");
            }

            $stagedPointerExists = EnterpriseWikiIngestRunPage::query()
                ->where('generated_page_version_id', $staged->id)
                ->exists();

            if ($stagedPointerExists) {
                throw new \RuntimeException("A run/page row already points to staged page version [{$staged->id}].");
            }

            $this->assertManualMixedBlockClaimsVerified($staged, $changedBlockKeys, $newClaimIds);
            $canonicalFactIds = $this->recordDeferredCanonicalFacts($run, $staged, $changedBlockKeys, $canonicalRecordingCandidates);

            $current->update(['is_current' => false]);
            $staged->update([
                'is_current' => true,
                'is_staged' => false,
            ]);
            $runPage->update([
                'generated_page_version_id' => $staged->id,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

            return $canonicalFactIds;
        });
    }

    /**
     * @param  list<string>  $changedBlockKeys
     * @param  list<array{
     *     claim_id: int,
     *     original_content_origin: string,
     *     verification_status: string,
     *     reason: ?string,
     *     supporting_excerpt: ?string
     * }>  $candidates
     * @return list<int>
     */
    private function recordDeferredCanonicalFacts(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPageVersion $stagedVersion,
        array $changedBlockKeys,
        array $candidates,
    ): array {
        $canonicalFactIds = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                throw new \RuntimeException('Manual mixed block verification returned an invalid canonical recording candidate.');
            }

            $claim = EnterpriseWikiClaim::query()
                ->with('sourceReferences')
                ->findOrFail((int) ($candidate['claim_id'] ?? 0));

            if ((int) $claim->enterprise_wiki_page_version_id !== (int) $stagedVersion->id
                || ! in_array((string) ($claim->content_block_key ?? ''), $changedBlockKeys, true)
            ) {
                throw new \RuntimeException("Canonical recording candidate claim [{$claim->id}] is outside the edited staged block scope.");
            }

            $verificationStatus = (string) ($candidate['verification_status'] ?? '');
            $recordingReason = $verificationStatus === EnterpriseWikiCanonicalFact::VERIFICATION_STATUS_SUPPORTED
                ? ($candidate['supporting_excerpt'] ?? null)
                : ($candidate['reason'] ?? null);

            $fact = $this->canonicalizationService->recordOutcome(
                $claim,
                $run->customer_id,
                (string) ($candidate['original_content_origin'] ?? ''),
                $verificationStatus,
                is_string($recordingReason) && trim($recordingReason) !== '' ? trim($recordingReason) : null,
            );

            if ($fact !== null) {
                $canonicalFactIds[] = $fact->id;
            }
        }

        return array_values(array_unique($canonicalFactIds));
    }

    private function cleanupStagedVersion(?EnterpriseWikiPageVersion $stagedVersion): void
    {
        if ($stagedVersion === null) {
            return;
        }

        DB::transaction(function () use ($stagedVersion): void {
            $locked = EnterpriseWikiPageVersion::query()
                ->whereKey($stagedVersion->id)
                ->lockForUpdate()
                ->first();

            if ($locked !== null && $locked->is_staged && ! $locked->is_current) {
                $locked->delete();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function claimClonePayload(EnterpriseWikiClaim $claim, EnterpriseWikiPageVersion $stagedVersion): array
    {
        return [
            'enterprise_wiki_page_id' => $claim->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $stagedVersion->id,
            'claim_text' => $claim->claim_text,
            'content_origin' => $claim->content_origin,
            'page_excerpt' => $claim->page_excerpt,
            'content_block_key' => $claim->content_block_key,
            'canonical_fact_id' => $claim->canonical_fact_id,
            'review_reason' => $claim->review_reason,
            'review_metadata' => $claim->review_metadata,
            'generation_issue' => $claim->generation_issue,
            'blocking_override' => $claim->blocking_override,
            'blocking_override_by_user_id' => $claim->blocking_override_by_user_id,
            'blocking_override_at' => $claim->blocking_override_at,
            'position_order' => $claim->position_order,
            'confidence' => $claim->confidence,
            'conflict_flag' => $claim->conflict_flag,
            'approval_status' => $claim->approval_status,
            'approved_by_user_id' => $claim->approved_by_user_id,
            'approved_at' => $claim->approved_at,
            'approval_comment' => $claim->approval_comment,
            'verified_at' => $claim->verified_at,
            'verification_claimed_at' => null,
            'verification_claim_token' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceReferenceClonePayload(EnterpriseWikiSourceReference $reference): array
    {
        return [
            'source_type' => $reference->source_type,
            'source_id' => $reference->source_id,
            'source_element_key' => $reference->source_element_key,
            'source_element_type' => $reference->source_element_type,
            'source_row_key' => $reference->source_row_key,
            'source_label' => $reference->source_label,
            'excerpt' => $reference->excerpt,
            'source_hash' => $reference->source_hash,
            'page_reference' => $reference->page_reference,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function markdownFromBlocks(array $blocks): string
    {
        return implode("\n\n", array_map(
            static fn (array $block): string => (string) ($block['markdown'] ?? ''),
            $blocks,
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function blocksByStableKey(EnterpriseWikiPageVersion $version): array
    {
        $blocks = [];

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (! is_array($block)) {
                throw new \RuntimeException("Page version [{$version->id}] contains an invalid content block.");
            }

            $blockKey = trim((string) ($block['block_key'] ?? ''));

            if ($blockKey === '') {
                throw new \RuntimeException("Page version [{$version->id}] contains a content block without block_key.");
            }

            if (array_key_exists($blockKey, $blocks)) {
                throw new \RuntimeException("Page version [{$version->id}] contains duplicate content block key [{$blockKey}].");
            }

            $blocks[$blockKey] = $block;
        }

        return $blocks;
    }

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
        $pageId = (int) $previousVersion->enterprise_wiki_page_id;

        $markdown = implode("\n\n", array_map(
            static fn (array $block): string => (string) ($block['markdown'] ?? ''),
            $blocks,
        ));

        $newVersion = $this->versionWriter->writeNewCurrentVersion($pageId, [
            'content_markdown' => $markdown,
            'content_blocks_json' => $blocks,
            'generated_by_model' => WikiSemanticReviserAiClient::MODEL.'/claim-content-repair',
        ]);

        $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($pageId);

        return $newVersion;
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
