<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a focused, block-scoped Wiki text edit in the article context.
 *
 * The edit is intentionally narrow:
 * - only the selected content block is rewritten
 * - a new immutable page version is created
 * - claims from untouched blocks are copied forward as-is
 * - claims in the edited block are re-extracted from the edited block text only
 * - the edited block itself is re-marked as mixed provenance if it was previously source_based
 *
 * No whole-document regeneration, no cross-page side effects, and no classification/blocking
 * logic changes.
 */
class EnterpriseWikiPageBlockEditService
{
    public function __construct(
        private readonly WikiPageClaimExtractionAiClient $claimExtractionAiClient,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
        private readonly EnterpriseWikiBuildPageLinksService $buildPageLinksService,
    ) {}

    /**
     * @return array{page_version: EnterpriseWikiPageVersion, focus_claim_id: int|null}
     */
    public function edit(EnterpriseWikiPage $page, EnterpriseWikiClaim $focusClaim, User $user, string $markdown): array
    {
        $page->loadMissing('customer.language');

        $currentVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->with(['claims.sourceReferences'])
            ->first();

        if (! $currentVersion instanceof EnterpriseWikiPageVersion) {
            throw new ModelNotFoundException('Current Wiki page version not found.');
        }

        if ((int) $focusClaim->enterprise_wiki_page_id !== (int) $page->id
            || (int) $focusClaim->enterprise_wiki_page_version_id !== (int) $currentVersion->id
        ) {
            abort(422, 'The selected claim does not belong to the current Wiki page version.');
        }

        $blocks = array_values(array_filter((array) ($currentVersion->content_blocks_json ?? []), 'is_array'));

        if ($blocks === []) {
            abort(422, 'Wiki page version has no editable content blocks.');
        }

        $targetBlockIndex = $this->findBlockIndex($blocks, (string) $focusClaim->content_block_key);

        if ($targetBlockIndex === null) {
            abort(422, 'The selected Wiki block could not be found on the current page version.');
        }

        $languageCode = $page->customer?->language?->code ?? 'no';
        $extractedClaims = $this->extractClaimsForBlock($page, $markdown, $languageCode);

        if ($extractedClaims === []) {
            abort(422, 'The edited block did not produce any extractable claims.');
        }

        $targetBlock = $blocks[$targetBlockIndex];
        $targetBlockKey = (string) ($targetBlock['block_key'] ?? '');
        $originalBlockOrigin = (string) ($targetBlock['content_origin'] ?? EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);
        $editedBlockOrigin = $originalBlockOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED
            ? 'mixed'
            : $originalBlockOrigin;
        $now = now();
        $currentClaimsByBlock = $currentVersion->claims->groupBy(
            fn (EnterpriseWikiClaim $claim): string => (string) $claim->content_block_key,
        );
        $focusClaimId = $focusClaim->id;
        $newFocusClaimId = null;
        $firstTargetClaimId = null;

        $newBlocks = $blocks;
        $newBlocks[$targetBlockIndex] = array_merge($targetBlock, [
            'markdown' => $markdown,
            'content_origin' => $editedBlockOrigin,
        ]);

        DB::transaction(function () use (
            $currentVersion,
            $currentClaimsByBlock,
            $editedBlockOrigin,
            $extractedClaims,
            $focusClaimId,
            $newBlocks,
            $now,
            $page,
            $targetBlockKey,
            $user,
            &$firstTargetClaimId,
            &$newFocusClaimId,
        ): void {
            DB::table('enterprise_wiki_page_versions')
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => $now,
                ]);

            $newVersion = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => (int) $currentVersion->version_number + 1,
                'is_current' => true,
                'content_markdown' => trim(implode("\n\n", array_map(
                    static fn (array $block): string => trim((string) ($block['markdown'] ?? '')),
                    $newBlocks,
                ))),
                'content_blocks_json' => $newBlocks,
                'generated_by_model' => 'manual/wiki-block-edit',
                'edited_by_user_id' => $user->id,
                'edited_at' => $now,
            ]);

            $positionOrder = 0;

            foreach ($newBlocks as $block) {
                $blockKey = (string) ($block['block_key'] ?? '');
                $oldClaims = ($currentClaimsByBlock->get($blockKey) ?? new EloquentCollection)
                    ->sortBy('position_order')
                    ->values();

                if ($blockKey !== $targetBlockKey) {
                    foreach ($oldClaims as $oldClaim) {
                        $newClaim = $this->cloneClaimForNewVersion(
                            $oldClaim,
                            $newVersion,
                            $positionOrder++,
                            $now,
                            [],
                        );

                        $this->duplicateSourceReferences($oldClaim, $newClaim);
                    }

                    continue;
                }

                foreach ($extractedClaims as $index => $extractedClaim) {
                    $baseClaim = $oldClaims[$index] ?? null;
                    $isFocusClaim = $baseClaim instanceof EnterpriseWikiClaim && (int) $baseClaim->id === (int) $focusClaimId;
                    $claimText = trim((string) ($extractedClaim['text'] ?? ''));
                    $pageExcerpt = trim((string) ($extractedClaim['excerpt'] ?? ''));
                    $confidence = trim((string) ($extractedClaim['confidence'] ?? ''));
                    $conflictFlag = ($extractedClaim['conflict_note'] ?? null) !== null;
                    $contentOrigin = $baseClaim instanceof EnterpriseWikiClaim
                        ? (string) $baseClaim->content_origin
                        : $editedBlockOrigin;
                    $reviewMetadata = $baseClaim instanceof EnterpriseWikiClaim && is_array($baseClaim->review_metadata)
                        ? $baseClaim->review_metadata
                        : [];

                    $reviewMetadata = array_merge($reviewMetadata, [
                        're_extracted_after_block_edit' => true,
                        'edited_block_key' => $targetBlockKey,
                        'edited_by_user_id' => $user->id,
                        'edited_at' => $now->toIso8601String(),
                    ]);

                    if ($isFocusClaim) {
                        $reviewMetadata['edited_before_approval'] = true;
                    }

                    $newClaim = $this->cloneClaimForNewVersion(
                        $baseClaim,
                        $newVersion,
                        $positionOrder++,
                        $now,
                        [
                            'claim_text' => $claimText !== '' ? $claimText : (string) ($baseClaim?->claim_text ?? ''),
                            'page_excerpt' => $pageExcerpt !== '' ? $pageExcerpt : null,
                            'confidence' => in_array($confidence, EnterpriseWikiClaim::CONFIDENCES, true)
                                ? $confidence
                                : ($baseClaim?->confidence ?? EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN),
                            'content_origin' => $contentOrigin,
                            'content_block_key' => $targetBlockKey,
                            'conflict_flag' => $conflictFlag,
                            'review_metadata' => $reviewMetadata,
                            'verified_at' => $now,
                            'verification_claimed_at' => null,
                            'verification_claim_token' => null,
                        ],
                    );

                    if ($baseClaim instanceof EnterpriseWikiClaim) {
                        $this->duplicateSourceReferences($baseClaim, $newClaim);
                    } else {
                        $this->duplicateBlockSourceReferences($block, $newClaim, $pageExcerpt !== '' ? $pageExcerpt : $claimText);
                    }

                    if ($isFocusClaim) {
                        $newFocusClaimId = $newClaim->id;
                    }

                    if ($firstTargetClaimId === null) {
                        $firstTargetClaimId = $newClaim->id;
                    }

                    if ($isFocusClaim && $baseClaim instanceof EnterpriseWikiClaim && $baseClaim->isPending()) {
                        $newClaim->update([
                            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
                            'approved_by_user_id' => $user->id,
                            'approved_at' => $now,
                            'approval_comment' => $baseClaim->approval_comment,
                        ]);

                        EnterpriseWikiClaimDecision::query()->create([
                            'enterprise_wiki_claim_id' => $newClaim->id,
                            'decided_by_user_id' => $user->id,
                            'decision_type' => EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS,
                            'previous_state' => ['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING],
                            'new_state' => ['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED],
                            'comment' => $baseClaim->approval_comment,
                        ]);
                    }
                }
            }
        });

        $freshPage = $page->fresh();

        if ($freshPage !== null) {
            $this->wikiAnswerStalenessService->markAnswersStaleForWikiPageChange($freshPage);
            $this->buildPageLinksService->materializeWikilinksForPage($freshPage);
        }

        $newVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();

        return [
            'page_version' => $newVersion,
            'focus_claim_id' => $newFocusClaimId ?? $firstTargetClaimId,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function findBlockIndex(array $blocks, string $blockKey): ?int
    {
        foreach ($blocks as $index => $block) {
            if (is_array($block) && (string) ($block['block_key'] ?? '') === $blockKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<array{text: string, confidence: string, excerpt: string, conflict_note: string|null}>
     */
    private function extractClaimsForBlock(EnterpriseWikiPage $page, string $markdown, string $languageCode): array
    {
        $result = $this->claimExtractionAiClient->extractClaims(
            pageTitle: $page->title,
            pageType: $page->page_type,
            contentMarkdown: $markdown,
            languageCode: $languageCode,
        );

        $claims = $result['claims'] ?? [];

        return array_values(array_filter($claims, static fn (mixed $claim): bool => is_array($claim)));
    }

    private function cloneClaimForNewVersion(
        ?EnterpriseWikiClaim $baseClaim,
        EnterpriseWikiPageVersion $newVersion,
        int $positionOrder,
        Carbon $now,
        array $overrides,
    ): EnterpriseWikiClaim {
        $claim = $baseClaim instanceof EnterpriseWikiClaim
            ? $baseClaim->replicate()
            : new EnterpriseWikiClaim;

        $claim->forceFill(array_merge([
            'enterprise_wiki_page_id' => $newVersion->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $newVersion->id,
            'position_order' => $positionOrder,
            'verified_at' => $baseClaim?->verified_at,
            'verification_claimed_at' => null,
            'verification_claim_token' => null,
            'updated_at' => $now,
        ], $overrides));

        $claim->save();

        return $claim;
    }

    private function duplicateSourceReferences(EnterpriseWikiClaim $sourceClaim, EnterpriseWikiClaim $targetClaim): void
    {
        foreach ($sourceClaim->sourceReferences as $sourceReference) {
            $targetClaim->sourceReferences()->create([
                'source_type' => $sourceReference->source_type,
                'source_id' => $sourceReference->source_id,
                'source_element_key' => $sourceReference->source_element_key,
                'source_element_type' => $sourceReference->source_element_type,
                'source_row_key' => $sourceReference->source_row_key,
                'source_label' => $sourceReference->source_label,
                'excerpt' => $sourceReference->excerpt,
                'source_hash' => $sourceReference->source_hash,
                'page_reference' => $sourceReference->page_reference,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function duplicateBlockSourceReferences(array $block, EnterpriseWikiClaim $claim, string $excerpt): void
    {
        $sourceElements = (array) ($block['source_elements'] ?? []);

        if ($sourceElements === [] && ($block['source_id'] ?? null) !== null) {
            $sourceElements = [$block];
        }

        foreach ($sourceElements as $sourceElement) {
            if (! is_array($sourceElement)) {
                continue;
            }

            $sourceId = (int) ($sourceElement['source_id'] ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $claim->sourceReferences()->create([
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $sourceId,
                'source_element_key' => $sourceElement['source_element_key'] ?? null,
                'source_element_type' => $sourceElement['source_element_type'] ?? null,
                'source_row_key' => $sourceElement['source_row_key'] ?? null,
                'source_label' => (string) ($sourceElement['source_label'] ?? 'Kildedokument'),
                'excerpt' => (string) ($sourceElement['source_excerpt'] ?? $excerpt),
                'source_hash' => (string) ($sourceElement['source_hash'] ?? ''),
                'page_reference' => $sourceElement['page_reference'] ?? null,
            ]);
        }
    }
}
