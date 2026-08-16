<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionGlobalPlanMergeException;
use Illuminate\Support\Facades\Log;

/**
 * Joins phase 1A (the document's own pages) and phase 1B (candidates, entity pages, patch targets)
 * back into the one global-plan shape everything downstream already expects.
 *
 * Pure and deterministic — no AI round, no heuristics, no similarity. The two halves are field-
 * disjoint by construction (documentPlanSchema() ∪ candidatePlanSchema() = globalPlanSchema()), so
 * the union itself needs no arbitration. What this class exists for is the two conflicts the union
 * cannot express, and one rule that must hold whatever the model returned:
 *
 *  1. FIGURE EXCLUSIVITY. planned_figures is a whole-document contract: the consistency validator
 *     rejects a figure planned onto two pages, and generation materialises each planned figure once.
 *     Phase 1A owns it. Phase 1B's schema has no such field at all, so this is normally a no-op —
 *     it is enforced here as well because a schema is a contract with the model, and this is an
 *     invariant of the merged plan.
 *  2. IDENTITY COLLISION. Phase 1B plans entity pages; phase 1A plans the article and summary. An
 *     entity page carrying the same title, the same slug or the same page_id as one of those two
 *     would produce a decision that EnterpriseWikiPlannedPageSlotValidator and the consistency
 *     validator reject — one page, two owners. Sequential execution lets phase 1B be TOLD the
 *     document pages' titles, so this is a backstop rather than the primary defence; when it fires,
 *     it drops the entity page and says which page it collided with and why.
 *
 * Phase 1 contributes no patch targets at all. Neither half can see an existing page's content, and
 * a patch target rewrites one — see EnterpriseWikiMaintainerDecisionPrompt::candidatePlanSchema()
 * for the probe that made that concrete. The merged plan therefore carries an empty patch_targets,
 * which phase-2 batches may still add to.
 *
 * Fail-closed throughout: a missing half, a half of the wrong shape, or a collision on a page that
 * phase 1B claims to UPDATE (a real existing page, not a new one) aborts the decision. Dropping a
 * newly proposed duplicate is safe — the concept survives as a phase-2 candidate; silently
 * discarding an intended edit to an existing page is not.
 */
class EnterpriseWikiMaintainerDecisionGlobalPlanMerger
{
    /**
     * @param  array<string, mixed>  $documentPlan  Parsed phase 1A.
     * @param  array<string, mixed>  $candidatePlan  Parsed phase 1B.
     * @return array<string, mixed> The global-plan shape, ready for parseGlobalPlan().
     */
    public function merge(array $documentPlan, array $candidatePlan): array
    {
        foreach (['source_article', 'source_summary'] as $key) {
            if (! isset($documentPlan[$key]) || ! is_array($documentPlan[$key])) {
                throw EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::missingDocumentHalf($key);
            }
        }

        foreach (['entity_pages', 'concept_candidate_mentions', 'warnings'] as $key) {
            if (! isset($candidatePlan[$key]) || ! is_array($candidatePlan[$key])) {
                throw EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::missingCandidateHalf($key);
            }
        }

        $documentPages = [
            'source_article' => $documentPlan['source_article'],
            'source_summary' => $documentPlan['source_summary'],
        ];

        return [
            'source_article' => $documentPlan['source_article'],
            'source_summary' => $documentPlan['source_summary'],
            'entity_pages' => $this->entityPages($candidatePlan['entity_pages'], $documentPages),
            // Phase 1 no longer decides patch targets: neither half is given the existing pages a
            // patch would rewrite (see EnterpriseWikiMaintainerDecisionPrompt::candidatePlanSchema()).
            // The key stays, empty, because the merged plan is an ordinary global plan and phase 2
            // batches — which decide the candidate a change belongs to — still contribute their own.
            'patch_targets' => [],
            'concept_candidate_mentions' => array_values($candidatePlan['concept_candidate_mentions']),
            'no_action_reason' => $documentPlan['no_action_reason'] ?? null,
            'warnings' => array_values($candidatePlan['warnings']),
        ];
    }

    /**
     * @param  list<mixed>  $entityPages
     * @param  array<string, array<string, mixed>>  $documentPages
     * @return list<array<string, mixed>>
     */
    private function entityPages(array $entityPages, array $documentPages): array
    {
        $kept = [];

        foreach ($entityPages as $index => $page) {
            if (! is_array($page)) {
                throw EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::malformedEntityPage($index);
            }

            $collision = $this->collidingDocumentPage($page, $documentPages);

            if ($collision !== null) {
                [$slot, $field] = $collision;

                // An UPDATE names a real page by id. If phase 1B wanted to edit the very page phase
                // 1A is already writing, the two halves genuinely disagree about what that page is,
                // and there is no honest way to pick one.
                if ((string) ($page['action'] ?? '') === 'update') {
                    throw EnterpriseWikiMaintainerDecisionGlobalPlanMergeException::conflictingExistingPage(
                        (string) ($page['title'] ?? ''),
                        $slot,
                        $field,
                    );
                }

                Log::warning('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Entity page dropped — it collides with a planned document page.', [
                    'entity_page_title' => $page['title'] ?? null,
                    'entity_page_slug' => $page['proposed_slug'] ?? null,
                    'collides_with' => $slot,
                    'on_field' => $field,
                    'reason' => 'phase 1A owns the document article/summary; an entity page may not claim the same identity',
                ]);

                continue;
            }

            // Figure exclusivity: phase 1A owns planned_figures for the whole document. The field is
            // absent from phase 1B's schema, so this normalises rather than removes — the merged
            // shared-page contract still requires the key to be present.
            if (($page['planned_figures'] ?? []) !== []) {
                Log::warning('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Entity page planned figures discarded — figures are owned by the document plan.', [
                    'entity_page_title' => $page['title'] ?? null,
                    'discarded_figures' => count((array) $page['planned_figures']),
                ]);
            }

            $page['planned_figures'] = [];
            $kept[] = $page;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, array<string, mixed>>  $documentPages
     * @return array{0: string, 1: string}|null [document slot, colliding field]
     */
    private function collidingDocumentPage(array $page, array $documentPages): ?array
    {
        $title = $this->normalize((string) ($page['title'] ?? ''));
        $slug = $this->normalize((string) ($page['proposed_slug'] ?? ''));
        $pageId = $page['page_id'] ?? null;

        foreach ($documentPages as $slot => $documentPage) {
            if ($title !== '' && $title === $this->normalize((string) ($documentPage['title'] ?? ''))) {
                return [$slot, 'title'];
            }

            if ($slug !== '' && $slug === $this->normalize((string) ($documentPage['proposed_slug'] ?? ''))) {
                return [$slot, 'proposed_slug'];
            }

            if (
                is_int($pageId)
                && is_int($documentPage['page_id'] ?? null)
                && $pageId === $documentPage['page_id']
            ) {
                return [$slot, 'page_id'];
            }
        }

        return null;
    }

    /**
     * Case- and whitespace-insensitive only. Deliberately NOT fuzzy: this decides whether two names
     * are the same name, never whether they are similar.
     */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)) ?? mb_strtolower($value));
    }
}
