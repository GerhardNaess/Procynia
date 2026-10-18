<?php

namespace App\Services\EnterpriseWiki;

/**
 * Builds a page's link_assessment (docs/enterprise-wiki-knowledge-integration-plan.md §6.3) from
 * what the backend itself observed: the catalog it offered, the journal of what the materializer
 * did, and the content blocks that were actually persisted.
 *
 * The outcome is DERIVED, never reported by the model, and says only what was technically checked:
 *
 *   linked              at least one link to existing or run knowledge was materialized;
 *   no_relevant_links   the generator was offered candidates and proposed none — a completed
 *                       assessment, not proof that no subject-matter relation exists;
 *   no_candidates       there was nothing to assess against (empty catalog);
 *   rejected_only       links were proposed, none could be materialized (all rejected, or their
 *                       anchors were not in the prose) — needs follow-up;
 *   unchanged_by_patch  the page was patched, not generated; its blocks and their links were kept
 *                       byte-identical, and new patch substance had no link assessment of its own.
 *
 * "Not assessed" and "failed" are not outcomes stored here: they are read from the absence of
 * links_assessed_at and from the pivot's generation_status.
 */
class EnterpriseWikiLinkAssessmentRecorder
{
    public const SCHEMA = 1;

    public const OUTCOME_LINKED = 'linked';

    public const OUTCOME_NO_RELEVANT_LINKS = 'no_relevant_links';

    public const OUTCOME_NO_CANDIDATES = 'no_candidates';

    public const OUTCOME_REJECTED_ONLY = 'rejected_only';

    public const OUTCOME_UNCHANGED_BY_PATCH = 'unchanged_by_patch';

    /** Outcomes under which a page with zero new links still counts as integrated (§6.3). */
    public const INTEGRATED_OUTCOMES = [
        self::OUTCOME_LINKED,
        self::OUTCOME_NO_RELEVANT_LINKS,
        self::OUTCOME_NO_CANDIDATES,
        self::OUTCOME_UNCHANGED_BY_PATCH,
    ];

    /**
     * @param  array{catalog: list<array<string, mixed>>, tier_counts?: array<string, int>, planned_references?: array{page_ids: list<int>, rejected: list<array<string, mixed>>}}  $catalogResult
     * @param  list<array<string, mixed>>  $contentBlocks  the blocks of the version being persisted
     * @return array<string, mixed>
     */
    public function forGeneratedPage(array $catalogResult, EnterpriseWikiLinkAssessmentJournal $journal, array $contentBlocks): array
    {
        $catalog = (array) ($catalogResult['catalog'] ?? []);
        $catalogIds = array_values(array_map(static fn (array $entry): int => (int) $entry['page_id'], $catalog));
        $materialized = $this->materializedIntents($contentBlocks, $catalogIds);
        $rejected = $journal->rejected();
        $skipped = $journal->skippedAnchorNotFound();

        $outcome = match (true) {
            $materialized !== [] => self::OUTCOME_LINKED,
            $catalog === [] => self::OUTCOME_NO_CANDIDATES,
            $journal->proposed() === 0 => self::OUTCOME_NO_RELEVANT_LINKS,
            default => self::OUTCOME_REJECTED_ONLY,
        };

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'catalog_size' => count($catalog),
            'catalog_by_tier' => $this->tierCounts($catalogResult),
            'offered_page_ids' => $catalogIds,
            'planned_references' => [
                'page_ids' => array_values((array) ($catalogResult['planned_references']['page_ids'] ?? [])),
                'rejected' => array_values((array) ($catalogResult['planned_references']['rejected'] ?? [])),
            ],
            'proposed' => $journal->proposed(),
            'materialized' => $materialized,
            'rejected' => $rejected,
            'skipped_anchor_not_found' => $skipped,
            'has_rejections' => $rejected !== [] || $skipped !== [],
        ];
    }

    /** @return array<string, mixed> */
    public function forPatchedPage(): array
    {
        return [
            'schema' => self::SCHEMA,
            'outcome' => self::OUTCOME_UNCHANGED_BY_PATCH,
            'catalog_size' => 0,
            'catalog_by_tier' => [],
            'offered_page_ids' => [],
            'planned_references' => ['page_ids' => [], 'rejected' => []],
            'proposed' => 0,
            'materialized' => [],
            'rejected' => [],
            'skipped_anchor_not_found' => [],
            'has_rejections' => false,
        ];
    }

    /**
     * Links are read from the persisted blocks, not from the journal: a link the materializer
     * placed but a later step dropped (a replaced section, a removed duplicate) is not counted.
     *
     * @param  list<array<string, mixed>>  $contentBlocks
     * @param  list<int>  $catalogIds
     * @return list<array{target_page_id: int, intent_id: string, anchor_text: string, reason: ?string}>
     */
    private function materializedIntents(array $contentBlocks, array $catalogIds): array
    {
        $materialized = [];

        foreach ($contentBlocks as $block) {
            foreach ((array) ($block['link_intents'] ?? []) as $intent) {
                if (! is_array($intent) || ! is_int($intent['target_page_id'] ?? null)) {
                    continue;
                }

                if (! in_array($intent['target_page_id'], $catalogIds, true)) {
                    continue;
                }

                $intentId = (string) ($intent['intent_id'] ?? '');
                $materialized[$intentId.'#'.$intent['target_page_id']] = [
                    'target_page_id' => $intent['target_page_id'],
                    'intent_id' => $intentId,
                    'anchor_text' => (string) ($intent['anchor_text'] ?? ''),
                    'reason' => is_string($intent['reason'] ?? null) && trim($intent['reason']) !== '' ? $intent['reason'] : null,
                ];
            }
        }

        return array_values($materialized);
    }

    /**
     * @param  array<string, mixed>  $catalogResult
     * @return array<string, int>
     */
    private function tierCounts(array $catalogResult): array
    {
        if (is_array($catalogResult['tier_counts'] ?? null)) {
            return array_map('intval', $catalogResult['tier_counts']);
        }

        $counts = [];

        foreach ((array) ($catalogResult['catalog'] ?? []) as $entry) {
            $tier = (string) ($entry['tier'] ?? 'unknown');
            $counts[$tier] = ($counts[$tier] ?? 0) + 1;
        }

        return $counts;
    }
}
