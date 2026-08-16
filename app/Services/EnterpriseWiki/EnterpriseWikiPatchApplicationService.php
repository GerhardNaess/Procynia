<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiPatchApplicationException;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 8K-3: applies the validated `patch_targets` of a maintainer decision to the existing pages
 * they name, writing one new current version per patched page.
 *
 * This replaces 8K-2's temporary defer behaviour. What it does NOT do is reuse the ordinary
 * generation path: EnterpriseWikiGenerateAppliedPagesService::generatePageForRun() writes a page from
 * the new source document plus owned_topics alone, never receiving the existing content, so for a
 * page needing one corrected requirement it is a full-page rewrite. Run 26 measured that cost on a
 * real page — 1 of 4 blocks survived, the entity's own definition and an unrelated section were lost,
 * all original provenance was reallocated to the change note, and two wikilinks disappeared. QA
 * passed it. That path is still blocked for patch targets, by construction and by an explicit guard.
 *
 * THE ENGINE IS FULLY DETERMINISTIC — NO AI, AND NONE IS NEEDED.
 *
 * This is the single most important safety property here, and it falls out of what 8K-2 already
 * guarantees: the maintainer decision carries `superseded_substance` (verified verbatim-present in
 * the target page) and `replacement_substance` (finished prose, authorised by named source elements),
 * both already validated. The patch is therefore a bounded text operation over content the decision
 * fully specifies — there is nothing left for a model to decide. No patch AI client exists, so no
 * model can widen a patch, restyle neighbouring prose, or return a whole page: full-page rewrite is
 * not guarded against here so much as unrepresentable.
 *
 * THE INVARIANT: patch only what the patch target authorizes.
 *
 *  - `replace` rewrites exactly one substring inside exactly one block, inside the resolved section
 *  - `amend` appends one NEW block at the end of the resolved section
 *  - `preserve` mutates nothing at all
 *  - every other block is carried over BYTE-IDENTICALLY, including its provenance and its wikilinks
 *
 * That last point is what makes preservation verifiable rather than hopeful: untouched blocks are the
 * same PHP arrays, not regenerated equivalents, so their markdown, `source_elements`, `content_origin`
 * and any links inside them cannot drift. assertPreserveInvariant() re-checks it before the write.
 *
 * Markdown/blocks consistency reuses the existing serialization — `content_markdown` is
 * implode("\n\n", every block's markdown), exactly as ordinary generation writes it — so no parallel
 * content representation is introduced.
 */
class EnterpriseWikiPatchApplicationService
{
    /**
     * Distinguishes a patched version from a full generation in existing metadata, following the
     * `deterministic/<operation>` convention already used by
     * EnterpriseWikiArticleSummaryLinkRepairService and EnterpriseWikiOrphanConceptLinkService. No
     * migration: `generated_by_model` is an existing nullable string column.
     */
    public const GENERATED_BY = 'deterministic/section-patch';

    public function __construct(
        private readonly EnterpriseWikiPatchSectionResolver $sectionResolver,
        private readonly EnterpriseWikiPatchTargetResolver $targetResolver,
        private readonly EnterpriseWikiPageVersionWriter $versionWriter,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
    ) {}

    /**
     * Apply every patch target of this run, grouped per page.
     *
     * @return array{pages_patched: int, pages_skipped: int, targets_applied: int, failures: list<string>}
     */
    public function applyForRun(EnterpriseWikiIngestRun $run): array
    {
        $decision = (array) ($run->maintainer_decision_json ?? []);
        $targets = array_values(array_filter(
            (array) ($decision['patch_targets'] ?? []),
            static fn (mixed $target): bool => is_array($target),
        ));

        return $this->applyTargetsForRun($run, $targets);
    }

    /**
     * Apply additional targets derived by the bounded cross-page reconciliation stage.
     *
     * Kept separate from applyForRun() so the immutable maintainer decision remains the authority
     * for primary targets. These targets have already been derived from one of those authorised
     * changes and independently checked by EnterpriseWikiPatchTargetResolver.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array{pages_patched: int, pages_skipped: int, targets_applied: int, failures: list<string>}
     */
    public function applyAdditionalTargetsForRun(EnterpriseWikiIngestRun $run, array $targets): array
    {
        return $this->applyTargetsForRun($run, array_values(array_filter(
            $targets,
            static fn (mixed $target): bool => is_array($target),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return array{pages_patched: int, pages_skipped: int, targets_applied: int, failures: list<string>}
     */
    private function applyTargetsForRun(EnterpriseWikiIngestRun $run, array $targets): array
    {

        if ($targets === []) {
            return ['pages_patched' => 0, 'pages_skipped' => 0, 'targets_applied' => 0, 'failures' => []];
        }

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', $run->source_id)
            ->first();

        if (! $document instanceof EnterpriseWikiDocument) {
            throw new EnterpriseWikiPatchApplicationException(
                "Run [{$run->id}]: source document [{$run->source_id}] not found for customer [{$run->customer_id}]."
            );
        }

        $validSourceElements = $this->sourceElementsByKey($document);

        $patched = 0;
        $skipped = 0;
        $applied = 0;
        $failures = [];

        foreach ($this->groupTargetsByPage($targets) as $pageId => $pageTargets) {
            try {
                $result = $this->applyForPage($run, $document, $pageId, $pageTargets, $validSourceElements);

                if ($result['wrote_version']) {
                    $patched++;
                    $applied += $result['targets_applied'];
                } else {
                    $skipped++;
                }
            } catch (EnterpriseWikiPatchApplicationException $e) {
                // Controlled stop for THIS page only. No version was written for it, and the other
                // pages' patches are independent — one unlocatable target must not silently abandon
                // the rest, nor be retried as a rewrite.
                $failures[] = $e->getMessage();

                Log::error('[WIKI_PATCH] Patch failed for page — no version written.', [
                    'run_id' => $run->id,
                    'page_id' => $pageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['pages_patched' => $patched, 'pages_skipped' => $skipped, 'targets_applied' => $applied, 'failures' => $failures];
    }

    /**
     * All targets naming one page, applied together against ONE reading of its current version and
     * committed as at most ONE new version.
     *
     * Planning against the original version (rather than re-reading after each target) is what makes
     * several targets on one page safe: every section is resolved against the same block list, so an
     * earlier mutation can never shift the bounds a later target was validated against.
     *
     * @param  list<array<string, mixed>>  $pageTargets
     * @param  array<string, array<string, mixed>>  $validSourceElements
     * @return array{wrote_version: bool, targets_applied: int}
     */
    private function applyForPage(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiDocument $document,
        int $pageId,
        array $pageTargets,
        array $validSourceElements,
    ): array {
        // Idempotency, reusing the established primitive: a pivot row for this run/page whose
        // generated_page_version_id is already set means this page was patched by this run. A retry
        // after a successful write is a no-op, and no second version can appear.
        $existingPivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $pageId)
            ->first();

        if ($existingPivot !== null && $existingPivot->generated_page_version_id !== null) {
            return ['wrote_version' => false, 'targets_applied' => 0];
        }

        $resolution = $this->targetResolver->resolveForCustomer(
            (int) $run->customer_id,
            ['patch_targets' => $pageTargets],
        );

        if ($resolution['errors'] !== []) {
            throw new EnterpriseWikiPatchApplicationException(
                "Run [{$run->id}] page [{$pageId}]: ".implode(' | ', $resolution['errors'])
            );
        }

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereKey($pageId)
            ->first();

        if (! $page instanceof EnterpriseWikiPage) {
            throw new EnterpriseWikiPatchApplicationException("Run [{$run->id}]: patch target page [{$pageId}] not found.");
        }

        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $pageId)
            ->where('is_current', true)
            ->first();

        if (! $version instanceof EnterpriseWikiPageVersion) {
            throw new EnterpriseWikiPatchApplicationException("Run [{$run->id}]: page [{$pageId}] has no current version to patch.");
        }

        $originalBlocks = $this->normalizedBlocks($version);

        if ($originalBlocks === []) {
            throw EnterpriseWikiPatchApplicationException::noContentBlocks("Run [{$run->id}] page [{$pageId}]");
        }

        // --- Plan every mutation against the ORIGINAL blocks, mutating nothing yet. ---
        $plan = [];

        foreach ($pageTargets as $index => $target) {
            $context = "Run [{$run->id}] page [{$pageId}] patch_targets[{$index}]";
            $operation = (string) ($target['operation'] ?? '');

            if ($operation === 'preserve') {
                continue; // an explicit "leave this untouched" assertion; contributes no mutation
            }

            $area = $this->sectionResolver->resolve(
                $originalBlocks,
                is_string($target['target_heading'] ?? null) ? (string) $target['target_heading'] : null,
                (string) ($target['target_topic'] ?? ''),
                $context,
            );

            $newElements = $this->resolveTargetSourceElements($target, $validSourceElements, $document, $context);

            $plan[] = match ($operation) {
                'replace' => $this->planReplace($originalBlocks, $area, $target, $newElements, $document, $context),
                'amend' => $this->planAmend($originalBlocks, $area, $target, $newElements, $document, $context),
                default => throw new EnterpriseWikiPatchApplicationException("{$context}: unsupported operation [{$operation}]."),
            };
        }

        if ($plan === []) {
            // preserve-only page: the decision examined it and asked for no change. Writing a version
            // that changes nothing would be noise and would falsely mark the page as revised.
            return ['wrote_version' => false, 'targets_applied' => 0];
        }

        // --- Execute the plan. Deterministic order: replacements (by block index) before
        // insertions, so an insertion's index is never invalidated by an earlier edit. ---
        $blocks = $this->executePlan($originalBlocks, $plan, "Run [{$run->id}] page [{$pageId}]");

        $this->assertPreserveInvariant($originalBlocks, $blocks, $plan, "Run [{$run->id}] page [{$pageId}]");
        $this->assertNoBrokenWikilinks($plan, (int) $run->customer_id, "Run [{$run->id}] page [{$pageId}]");

        $markdown = $this->serialize($blocks);

        $newVersion = DB::transaction(function () use ($run, $page, $blocks, $markdown): ?EnterpriseWikiPageVersion {
            $lockedRun = EnterpriseWikiIngestRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof EnterpriseWikiIngestRun || $lockedRun->isTerminal()) {
                return null;
            }

            // Re-check idempotency under the run lock: a concurrent worker that got here first has
            // already set generated_page_version_id, and this call must not write a second version.
            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->lockForUpdate()
                ->first();

            if ($pivot !== null && $pivot->generated_page_version_id !== null) {
                return null;
            }

            $written = $this->versionWriter->writeNewCurrentVersion($page->id, [
                'content_markdown' => $markdown,
                'content_blocks_json' => $blocks,
                'generated_by_model' => self::GENERATED_BY,
            ]);

            // The pivot is created WITH generated_page_version_id already set, so it can never be
            // picked up as pending work by EnterpriseWikiDocumentFlowService::beginGeneratingPages()
            // or FinalizeEnterpriseWikiPageGeneration — both select on a null version id. It exists
            // to record idempotency and to make the patched page visible to the run's later steps.
            EnterpriseWikiIngestRunPage::query()->updateOrCreate(
                [
                    'enterprise_wiki_ingest_run_id' => $run->id,
                    'enterprise_wiki_page_id' => $page->id,
                ],
                [
                    'action' => EnterpriseWikiIngestRunPage::ACTION_PATCHED,
                    'generated_page_version_id' => $written->id,
                    'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                    'generation_completed_at' => now(),
                ],
            );

            return $written;
        });

        if (! $newVersion instanceof EnterpriseWikiPageVersion) {
            return ['wrote_version' => false, 'targets_applied' => 0];
        }

        Log::info('[WIKI_PATCH] Page patched.', [
            'run_id' => $run->id,
            'page_id' => $page->id,
            'page_type' => $page->page_type,
            'version_id' => $newVersion->id,
            'version_number' => $newVersion->version_number,
            'targets_applied' => count($plan),
            'operations' => array_count_values(array_column($plan, 'operation')),
            'blocks_before' => count($originalBlocks),
            'blocks_after' => count($blocks),
        ]);

        return ['wrote_version' => true, 'targets_applied' => count($plan)];
    }

    /**
     * A `replace`: find the one block inside the resolved section that states the superseded
     * substance, and rewrite exactly that substring.
     *
     * The search is confined to the section, and to the in-section part of a block that shares its
     * heading with the previous section — so a page stating the same requirement in two sections gets
     * one target per section and each patches only its own occurrence.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $target
     * @param  list<array<string, mixed>>  $newElements
     * @return array<string, mixed>
     */
    private function planReplace(array $blocks, array $area, array $target, array $newElements, EnterpriseWikiDocument $document, string $context): array
    {
        $superseded = trim((string) ($target['superseded_substance'] ?? ''));
        $replacement = trim((string) ($target['replacement_substance'] ?? ''));

        if ($replacement === '') {
            throw EnterpriseWikiPatchApplicationException::missingReplacementSubstance($context, 'replace');
        }

        $hits = [];

        for ($i = $area['start_index']; $i <= $area['end_index']; $i++) {
            $blockMarkdown = (string) ($blocks[$i]['markdown'] ?? '');
            $inSection = $this->sectionResolver->inSectionText($area, $i, $blockMarkdown);

            if ($superseded === '' || $inSection === '') {
                continue;
            }

            $offsetInSection = mb_strpos($inSection, $superseded);

            if ($offsetInSection === false) {
                continue;
            }

            // The offset is recorded relative to the WHOLE block, so execution splits the very
            // occurrence found here. Without it, a block that shares its opening with an excluded
            // prefix — content above a shared heading, or a flat page's H1 title line — could have an
            // earlier, out-of-area occurrence rewritten instead.
            $excludedPrefixLength = mb_strlen($blockMarkdown) - mb_strlen($inSection);

            $hits[] = ['block_index' => $i, 'offset' => $excludedPrefixLength + $offsetInSection];
        }

        if ($hits === []) {
            throw EnterpriseWikiPatchApplicationException::supersededSubstanceNotFound($context, $superseded);
        }

        if (count($hits) > 1) {
            throw EnterpriseWikiPatchApplicationException::supersededSubstanceAmbiguous($context, $superseded, count($hits));
        }

        $blockIndex = $hits[0]['block_index'];
        $origin = (string) ($blocks[$blockIndex]['content_origin'] ?? '');

        // Only prose carrying document substance can be superseded, and only such a block can be
        // split into provenance atoms. A heading, a figure or a Procynia recommendation is not
        // source content — replacing one is a decision error, not something to execute.
        if ($origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
            throw EnterpriseWikiPatchApplicationException::supersededBlockNotSourceBased($context, $origin !== '' ? $origin : 'unknown');
        }

        return [
            'operation' => 'replace',
            'block_index' => $blockIndex,
            'offset' => $hits[0]['offset'],
            'superseded' => $superseded,
            'replacement' => $replacement,
            'new_elements' => $newElements,
            'document' => $document,
            'area' => $area,
        ];
    }

    /**
     * An `amend`: append the new substance as ONE new block at the end of the resolved section.
     *
     * Appending rather than rewriting is what keeps an amend non-destructive — every existing
     * sentence in the section is carried over untouched, and the new statement sits after them.
     *
     * Idempotent by construction: if the section already states this substance, the amend is a no-op
     * rather than a second copy, so a retry cannot duplicate content.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $target
     * @param  list<array<string, mixed>>  $newElements
     * @return array<string, mixed>
     */
    private function planAmend(
        array $blocks,
        array $area,
        array $target,
        array $newElements,
        EnterpriseWikiDocument $document,
        string $context,
    ): array {
        $replacement = trim((string) ($target['replacement_substance'] ?? ''));

        if ($replacement === '') {
            throw EnterpriseWikiPatchApplicationException::missingReplacementSubstance($context, 'amend');
        }

        for ($i = $area['start_index']; $i <= $area['end_index']; $i++) {
            $inSection = $this->sectionResolver->inSectionText($area, $i, (string) ($blocks[$i]['markdown'] ?? ''));

            if (mb_strpos($inSection, $replacement) !== false) {
                return ['operation' => 'amend', 'block_index' => null, 'no_op' => true, 'area' => $area];
            }
        }

        return [
            'operation' => 'amend',
            'insert_after_index' => $area['end_index'],
            'markdown' => $replacement,
            'new_elements' => $newElements,
            'document' => $document,
            'area' => $area,
        ];
    }

    /**
     * Carry every block over unchanged except the ones the plan authorizes, then insert amends.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    private function executePlan(array $blocks, array $plan, string $context): array
    {
        // Replacements first. Each one SPLITS its block into up to three provenance atoms — the
        // untouched text before it (document A), the new substance (document B), the untouched text
        // after it (document A) — so no block ever holds substance from two documents. Applied from
        // the highest block index down, so an earlier split never shifts a later target's index.
        $replacements = array_values(array_filter(
            $plan,
            static fn (array $step): bool => $step['operation'] === 'replace',
        ));

        // More than one replace inside the SAME original block would have to be planned as one
        // multi-way split, with every offset re-based after each cut. That is real complexity for a
        // case the maintainer can always express as two targets on two paragraphs, so it fails
        // closed instead. Overlapping targets are rejected by the same rule.
        $byBlock = array_count_values(array_map(
            static fn (array $step): int => (int) $step['block_index'],
            $replacements,
        ));

        foreach ($byBlock as $blockIndex => $count) {
            if ($count > 1) {
                throw EnterpriseWikiPatchApplicationException::severalReplacesInOneBlock($context, (int) $blockIndex, (int) $count);
            }
        }

        usort($replacements, static fn (array $a, array $b): int => $b['block_index'] <=> $a['block_index']);

        foreach ($replacements as $step) {
            $index = (int) $step['block_index'];

            array_splice($blocks, $index, 1, $this->splitBlockForReplacement($blocks[$index], $step));
        }

        // Insertions after, from the highest index down, so each insertion leaves lower indexes intact.
        $insertions = array_values(array_filter(
            $plan,
            static fn (array $step): bool => $step['operation'] === 'amend' && ! ($step['no_op'] ?? false),
        ));

        usort($insertions, static fn (array $a, array $b): int => $b['insert_after_index'] <=> $a['insert_after_index']);

        foreach ($insertions as $step) {
            $newBlock = $this->buildPatchBlock($step['markdown'], $step['new_elements'], $step['document']);

            array_splice($blocks, ((int) $step['insert_after_index']) + 1, 0, [$newBlock]);
        }

        return $this->renumber($blocks);
    }

    /**
     * One replace, as up to three provenance atoms.
     *
     * The untouched text before and after the superseded substance stays with the document that
     * authored it — byte for byte, taken by offset out of the original markdown, never rewritten and
     * never handed to a model to retype. The new substance becomes its own block owned by the patch
     * document alone. That is the point: knowledge is preserved MECHANICALLY while provenance stays
     * atomic, and neither has to be traded for the other.
     *
     * The visible cost is accepted deliberately: one corrected paragraph renders as up to three,
     * because blocks serialize with a blank line between them. Correct provenance is worth more than
     * paragraph grouping — a wrong citation cannot be recovered later, presentation can.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $step
     * @return list<array<string, mixed>>
     */
    private function splitBlockForReplacement(array $block, array $step): array
    {
        $markdown = (string) ($block['markdown'] ?? '');
        $offset = (int) $step['offset'];
        $supersededLength = mb_strlen((string) $step['superseded']);

        $prefix = trim(mb_substr($markdown, 0, $offset));
        $suffix = trim(mb_substr($markdown, $offset + $supersededLength));

        $segments = [];

        if ($prefix !== '') {
            $segments[] = $this->carriedSegment($block, $prefix);
        }

        $replacement = $this->buildPatchBlock((string) $step['replacement'], $step['new_elements'], $step['document']);
        $replacement['_patch_split'] = true;
        $segments[] = $replacement;

        if ($suffix !== '') {
            $segments[] = $this->carriedSegment($block, $suffix);
        }

        return $this->distributeLinkIntents($block, $segments);
    }

    /**
     * A surviving fragment of the original block: its own text, everything else about it unchanged —
     * same document, same source elements, same hashes. Provenance is inherited wholesale because it
     * is still true of this fragment; what changed is only how much of the block the fragment is.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function carriedSegment(array $block, string $markdown): array
    {
        $block['markdown'] = $markdown;
        // renumber() assigns these over the final list; a segment must not claim the original's slot.
        // The marker distinguishes a SPLIT segment from an amend's inserted block: both arrive without
        // a key, but only the latter is new substance on the page.
        $block['block_key'] = null;
        $block['position'] = null;
        $block['_patch_split'] = true;

        return $block;
    }

    /**
     * Sends every materialized link intent to the segment that actually contains its link.
     *
     * Matched on the canonical slug the backend itself wrote into the markdown ([[slug|anchor]]),
     * never on anchor text and never on similarity: the slug is the one identifier present both in
     * the structured intent (via target_page_id) and in the text. An intent whose link ends up in no
     * segment is dropped with a log — the same treatment an unplaceable intent already gets in
     * EnterpriseWikiLinkIntentMaterializer, and the honest one, since the link it described is no
     * longer on the page.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function distributeLinkIntents(array $block, array $segments): array
    {
        $intents = array_values(array_filter((array) ($block['link_intents'] ?? []), 'is_array'));

        foreach (array_keys($segments) as $index) {
            $segments[$index]['link_intents'] = [];
        }

        if ($intents === []) {
            return $segments;
        }

        $slugsByPageId = EnterpriseWikiPage::query()
            ->whereIn('id', array_values(array_filter(array_map(
                static fn (array $intent): mixed => $intent['target_page_id'] ?? null,
                $intents,
            ), 'is_int')))
            ->pluck('slug', 'id');

        foreach ($intents as $intent) {
            $slug = $slugsByPageId[$intent['target_page_id'] ?? null] ?? null;
            $placed = false;

            if (is_string($slug) && $slug !== '') {
                foreach ($segments as $index => $segment) {
                    $segmentMarkdown = (string) ($segment['markdown'] ?? '');

                    if (str_contains($segmentMarkdown, '[['.$slug.'|') || str_contains($segmentMarkdown, '[['.$slug.']]')) {
                        $segments[$index]['link_intents'][] = $intent;
                        $placed = true;

                        break;
                    }
                }
            }

            if (! $placed) {
                Log::info('[WIKI_PATCH] Link intent dropped — its link is not in any segment of the split block.', [
                    'target_page_id' => $intent['target_page_id'] ?? null,
                    'intent_id' => $intent['intent_id'] ?? null,
                ]);
            }
        }

        return $segments;
    }

    /**
     * A block for newly added substance, in the same shape ordinary generation produces (see
     * EnterpriseWikiPageContentBlockService) so nothing downstream can tell a patched block apart
     * structurally. `block_key`/`position` are assigned by renumber().
     *
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private function buildPatchBlock(string $markdown, array $elements, EnterpriseWikiDocument $document): array
    {
        $primary = $elements[0] ?? [];

        return [
            'block_key' => null,
            'position' => null,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_type' => $primary['source_type'] ?? null,
            'source_id' => $primary['source_id'] ?? $document->id,
            'source_label' => $primary['source_label'] ?? $document->original_filename,
            'source_hash' => $primary['source_hash'] ?? ($document->file_hash_sha256 ?? ''),
            'document_version_hash' => $primary['document_version_hash'] ?? ($document->file_hash_sha256 ?? ''),
            'source_element_key' => $primary['source_element_key'] ?? null,
            'source_element_type' => $primary['source_element_type'] ?? null,
            'source_row_key' => $primary['source_row_key'] ?? null,
            'source_excerpt' => $primary['source_excerpt'] ?? null,
            'page_reference' => $primary['page_reference'] ?? null,
            'source_elements' => $elements,
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /**
     * The patch target's authorising source elements, resolved against the patch document's real
     * catalog. An unknown key is a controlled failure, never silently dropped: new substance whose
     * authority cannot be resolved must not be written.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, array<string, mixed>>  $validSourceElements
     * @return list<array<string, mixed>>
     */
    private function resolveTargetSourceElements(
        array $target,
        array $validSourceElements,
        EnterpriseWikiDocument $document,
        string $context,
    ): array {
        // A document with no structured catalog at all (an unstructured format, or a source whose file
        // is not addressable) gets the whole-document element instead — the SAME fallback ordinary
        // generation uses via EnterpriseWikiPageContentBlockService::sourceElementsForGeneration().
        // This deliberately mirrors EnterpriseWikiCanonicalOwnershipValidator, which also skips key
        // checking when there is no catalog to check against: being stricter here than the validation
        // that admitted the decision would reject patches 8K-2 already accepted, and new substance
        // would still end up with correct provenance to the patch document either way.
        if ($validSourceElements === []) {
            return [$this->contentBlockService->sourceElementPayload(
                $document,
                $this->contentBlockService->sourceElementsForGeneration($document, [])[0],
            )];
        }

        $elements = [];

        foreach ((array) ($target['source_element_keys'] ?? []) as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $key = trim($key);

            if (! array_key_exists($key, $validSourceElements)) {
                throw EnterpriseWikiPatchApplicationException::unknownSourceElementKey($context, $key);
            }

            $elements[] = $this->contentBlockService->sourceElementPayload($document, $validSourceElements[$key]);
        }

        return $elements;
    }

    /**
     * THE PRESERVE INVARIANT, re-checked on the finished block list before anything is written.
     *
     * Chosen level: BLOCK-STRUCTURAL EQUALITY of every block the plan did not authorize — the whole
     * array, so markdown, `content_origin`, every provenance field, `source_elements` and any
     * wikilinks inside that block must be identical. Not normalized-markdown equality, which would
     * miss a provenance reallocation, and not whole-page byte equality, which a patch necessarily
     * breaks. This is strong enough to catch the run-26 failure class: losing an unrelated section,
     * or rewriting an untouched block's provenance, both fail here.
     *
     * Inside an authorized block, the check is that the block still contains its own untouched
     * remainder: everything except the replaced substring survives.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  list<array<string, mixed>>  $plan
     */
    private function assertPreserveInvariant(array $before, array $after, array $plan, string $context): void
    {
        $authorized = [];

        foreach ($plan as $step) {
            if ($step['operation'] !== 'replace') {
                continue;
            }

            $blockMarkdown = (string) ($before[(int) $step['block_index']]['markdown'] ?? '');
            $offset = (int) $step['offset'];
            $hasPrefix = trim(mb_substr($blockMarkdown, 0, $offset)) !== '';
            $hasSuffix = trim(mb_substr($blockMarkdown, $offset + mb_strlen((string) $step['superseded']))) !== '';

            $authorized[(int) $step['block_index']] = [
                'offset' => $offset,
                'superseded' => (string) $step['superseded'],
                'replacement' => (string) $step['replacement'],
                'segments' => 1 + ($hasPrefix ? 1 : 0) + ($hasSuffix ? 1 : 0),
            ];
        }

        $insertedCount = count(array_filter(
            $plan,
            static fn (array $step): bool => $step['operation'] === 'amend' && ! ($step['no_op'] ?? false),
        ));

        $splitGrowth = array_sum(array_map(
            static fn (array $authorization): int => $authorization['segments'] - 1,
            $authorized,
        ));

        if (count($after) !== count($before) + $insertedCount + $splitGrowth) {
            throw EnterpriseWikiPatchApplicationException::preserveInvariantViolated(
                $context,
                'block count changed by '.(count($after) - count($before)).', expected +'.($insertedCount + $splitGrowth).'.',
            );
        }

        // Every original block must still be present. An unauthorised one byte-identical; an
        // authorised one as its own segments, whose untouched fragments must match the original text
        // exactly.
        $segmentsByOrigin = [];
        $originIndex = 0;

        foreach ($after as $block) {
            if (($block['_patch_inserted'] ?? false) === true) {
                continue;
            }

            $segmentsByOrigin[$originIndex][] = $block;

            // A split block contributes several consecutive segments; they are grouped by counting
            // how many the plan authorised for this original, in the order executePlan produced them.
            if (count($segmentsByOrigin[$originIndex]) >= ($authorized[$originIndex]['segments'] ?? 1)) {
                $originIndex++;
            }
        }

        if (count($segmentsByOrigin) !== count($before)) {
            throw EnterpriseWikiPatchApplicationException::preserveInvariantViolated(
                $context,
                'the number of carried-over blocks does not match the original.',
            );
        }

        foreach ($before as $index => $originalBlock) {
            $segments = $segmentsByOrigin[$index] ?? [];

            if (! isset($authorized[$index])) {
                if (count($segments) !== 1 || $this->comparableBlock($originalBlock) !== $this->comparableBlock($segments[0])) {
                    throw EnterpriseWikiPatchApplicationException::preserveInvariantViolated(
                        $context,
                        "block {$index} changed but was not authorized by any patch target.",
                    );
                }

                continue;
            }

            $this->assertSplitPreservedOriginalText($originalBlock, $segments, $authorized[$index], $index, $context);
        }
    }

    /**
     * The preserve proof for a split: the fragments the patch did NOT authorise changing must still
     * be there, byte for byte, in order.
     *
     * Deterministic and total — it recomputes the prefix and suffix from the original markdown by
     * offset and compares them to what was written. No normalisation, no similarity, no tolerance for
     * "close enough": the fragments were never rewritten, so anything but an exact match means the
     * split lost or altered text it had no authority to touch. Trimming is the one allowance, because
     * a block's markdown is always stored trimmed and a cut lands mid-whitespace.
     *
     * @param  array<string, mixed>  $originalBlock
     * @param  list<array<string, mixed>>  $segments
     * @param  array{offset: int, superseded: string, replacement: string, segments: int}  $authorization
     */
    private function assertSplitPreservedOriginalText(
        array $originalBlock,
        array $segments,
        array $authorization,
        int $index,
        string $context,
    ): void {
        $markdown = (string) ($originalBlock['markdown'] ?? '');
        $expectedPrefix = trim(mb_substr($markdown, 0, $authorization['offset']));
        $expectedSuffix = trim(mb_substr($markdown, $authorization['offset'] + mb_strlen($authorization['superseded'])));

        $expected = [];

        if ($expectedPrefix !== '') {
            $expected[] = $expectedPrefix;
        }

        $expected[] = trim($authorization['replacement']);

        if ($expectedSuffix !== '') {
            $expected[] = $expectedSuffix;
        }

        $actual = array_map(static fn (array $segment): string => trim((string) ($segment['markdown'] ?? '')), $segments);

        if ($actual !== $expected) {
            throw EnterpriseWikiPatchApplicationException::preserveInvariantViolated(
                $context,
                "block {$index} lost or altered content the patch did not authorize changing.",
            );
        }
    }

    private function comparableBlock(array $block): string
    {
        unset($block['block_key'], $block['position'], $block['_patch_inserted'], $block['_patch_split']);
        ksort($block);

        return (string) json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A patch must not introduce a broken wikilink. Existing links inside carried-over blocks are safe
     * by construction — those blocks are byte-identical, which assertPreserveInvariant() enforces — so
     * only the text the patch itself introduces needs checking.
     *
     * Resolved by direct customer-scoped slug lookup rather than through
     * EnterpriseWikiLinkResolver::resolve(), which is built around a source page and parsed link
     * occurrences; all that is needed here is "is this slug a live page for this customer".
     *
     * @param  list<array<string, mixed>>  $plan
     */
    private function assertNoBrokenWikilinks(array $plan, int $customerId, string $context): void
    {
        $introduced = [];

        foreach ($plan as $step) {
            foreach ([$step['replacement'] ?? null, $step['markdown'] ?? null] as $text) {
                if (is_string($text) && $text !== '') {
                    $introduced[] = $text;
                }
            }
        }

        foreach ($introduced as $text) {
            if (preg_match_all('/\[\[([^\]|]+)(?:\|[^\]]*)?\]\]/u', $text, $matches) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $slug) {
                $slug = trim((string) $slug);

                if ($slug === '') {
                    continue;
                }

                $exists = EnterpriseWikiPage::query()
                    ->where('customer_id', $customerId)
                    ->where('slug', $slug)
                    ->whereNotIn('status', [
                        EnterpriseWikiPage::STATUS_ARCHIVED,
                        EnterpriseWikiPage::STATUS_SUPERSEDED,
                        EnterpriseWikiPage::STATUS_REJECTED,
                    ])
                    ->exists();

                if (! $exists) {
                    throw EnterpriseWikiPatchApplicationException::unresolvableWikilink($context, $slug);
                }
            }
        }
    }

    /**
     * `content_markdown` from blocks, using the exact serialization ordinary generation uses, so the
     * two representations of a version can never describe different content.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function serialize(array $blocks): string
    {
        return trim(implode("\n\n", array_map(
            static fn (array $block): string => trim((string) ($block['markdown'] ?? '')),
            $blocks,
        )));
    }

    /**
     * Reassign block_key/position over the final list and strip the internal insertion marker.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function renumber(array $blocks): array
    {
        $out = [];

        foreach (array_values($blocks) as $index => $block) {
            $wasInserted = ($block['block_key'] ?? null) === null && ($block['_patch_split'] ?? false) !== true;
            $block['block_key'] = 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $block['position'] = $index;

            if ($wasInserted) {
                $block['_patch_inserted'] = true;
            }

            $out[] = $block;
        }

        return $out;
    }

    /**
     * The version's blocks, normalized to a plain ordered list. A version whose blocks are missing or
     * unusable is not patchable — deriving blocks from markdown here would invent a representation
     * the rest of the pipeline does not share.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizedBlocks(EnterpriseWikiPageVersion $version): array
    {
        $blocks = [];

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (is_array($block) && trim((string) ($block['markdown'] ?? '')) !== '') {
                unset($block['_patch_inserted'], $block['_patch_split']);
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Targets grouped per page, deterministically: pages in ascending id, and each page's targets in
     * the order the decision lists them.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupTargetsByPage(array $targets): array
    {
        $grouped = [];

        foreach ($targets as $target) {
            $pageId = $target['target_page_id'] ?? null;

            if (is_int($pageId) && $pageId > 0) {
                $grouped[$pageId][] = $target;
            }
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceElementsByKey(EnterpriseWikiDocument $document): array
    {
        $byKey = [];

        foreach ($this->sourceElementService->inspect($document)['elements'] as $element) {
            $key = (string) ($element['source_element_key'] ?? '');

            if ($key !== '') {
                $byKey[$key] = $element;
            }
        }

        return $byKey;
    }
}
