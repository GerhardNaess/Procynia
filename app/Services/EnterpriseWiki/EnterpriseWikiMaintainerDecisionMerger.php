<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionMergeConflictException;

/**
 * Deterministic, AI-free merge of a split-flow global plan (Phase A,
 * EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan()) and its candidate batches (Phase B,
 * ::parseCandidateBatch(), one per batch, in batch order) into a single, complete maintainer
 * decision — the exact same shape ::parse() produces for a plain single_call decision, so
 * EnterpriseWikiMaintainerDecisionConsistencyValidator, its repair pass, and
 * EnterpriseWikiMaintainerDecisionApplyService need no knowledge that the decision was ever split.
 *
 * Only concern here is structural correctness of the union: title/slug identity uses the same
 * EnterpriseWikiConceptIdentityMatcher the consistency validator already relies on (conservative
 * subset matching, never broad fuzzy matching); an exact repeat of the same candidate/page across
 * batches is silently deduped; any genuine disagreement between batches for what is, by identity,
 * the same concept or page is a hard failure
 * (EnterpriseWikiMaintainerDecisionMergeConflictException) — never resolved by guessing or "last
 * writer wins". Logical consistency of the merged decision AS A WHOLE (e.g. a candidate deferring
 * to an owning page that a *different* batch happened to create) is intentionally left to the
 * existing consistency validator/repair pass, which runs after this merge, on the complete
 * picture — this class only guards against batches contradicting each other, not against the kind
 * of cross-page logical gaps that mechanism already exists to catch.
 */
class EnterpriseWikiMaintainerDecisionMerger
{
    /**
     * @param  array<string, mixed>  $globalPlan  From EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan().
     * @param  list<array<string, mixed>>  $batchResults  Each from ::parseCandidateBatch(), in batch order.
     * @return array<string, mixed> A complete decision matching EnterpriseWikiMaintainerDecisionPrompt::parse()'s contract.
     *
     * @throws EnterpriseWikiMaintainerDecisionMergeConflictException
     */
    public function merge(array $globalPlan, array $batchResults): array
    {
        $conceptCandidates = [];
        $conceptPages = [];
        /** @var list<array{name: string, decision: string, batch: int}> $seenCandidates */
        $seenCandidates = [];
        /** @var list<array{title: string, proposed_slug: string, batch: int}> $seenPages */
        $seenPages = [];

        foreach ($batchResults as $batchIndex => $batch) {
            foreach ($batch['concept_candidates'] ?? [] as $candidate) {
                $name = (string) ($candidate['name'] ?? '');
                $decision = (string) ($candidate['decision'] ?? '');
                $existingIndex = $this->findMatchingIndex($name, $seenCandidates, 'name');

                if ($existingIndex !== null) {
                    $existing = $seenCandidates[$existingIndex];

                    if ($existing['decision'] === $decision) {
                        continue;
                    }

                    throw EnterpriseWikiMaintainerDecisionMergeConflictException::conflictingCandidateDecision(
                        $name,
                        $existing['decision'],
                        $existing['batch'],
                        $decision,
                        $batchIndex,
                    );
                }

                $seenCandidates[] = ['name' => $name, 'decision' => $decision, 'batch' => $batchIndex];
                $conceptCandidates[] = $candidate;
            }

            foreach ($batch['concept_pages'] ?? [] as $page) {
                $title = (string) ($page['title'] ?? '');
                $slug = (string) ($page['proposed_slug'] ?? '');
                $existingIndex = $this->findMatchingIndex($title, $seenPages, 'title');

                if ($existingIndex !== null) {
                    $existing = $seenPages[$existingIndex];

                    if ($existing['proposed_slug'] === $slug) {
                        continue;
                    }

                    throw EnterpriseWikiMaintainerDecisionMergeConflictException::conflictingPageSlug(
                        $title,
                        $existing['proposed_slug'],
                        $existing['batch'],
                        $slug,
                        $batchIndex,
                    );
                }

                $seenPages[] = ['title' => $title, 'proposed_slug' => $slug, 'batch' => $batchIndex];
                $conceptPages[] = $page;
            }
        }

        $merged = [
            'source_article' => $globalPlan['source_article'],
            'source_summary' => $globalPlan['source_summary'],
            'concept_candidates' => $conceptCandidates,
            'concept_pages' => $conceptPages,
            'entity_pages' => $globalPlan['entity_pages'] ?? [],
            'patch_targets' => $this->mergePatchTargets($globalPlan, $batchResults),
            'no_action_reason' => $globalPlan['no_action_reason'] ?? null,
            'warnings' => $globalPlan['warnings'] ?? [],
        ];

        return $this->deduplicateFigures($merged);
    }

    /**
     * Fase 8K-2: union of Phase A's patch targets and every batch's own.
     *
     * A batch discovers candidate disposition, so it is the only place that can find out that one of
     * its candidates changes substance an existing page owns — Phase A never evaluates candidates and
     * therefore cannot produce that target. Both contribute, and the union is what the validators
     * and the apply layer see.
     *
     * Deduplicated on EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity() — (page, topic,
     * heading) — first occurrence kept, so Phase A's target wins if a batch restates it. This is the
     * SAME identity the validator's conflict check uses; the two must never drift apart, or one rule
     * starts meaning two things. Two targets for the same page are kept when they differ in topic OR
     * in heading: run 27 showed a page stating one superseded requirement under two duplicated
     * headings, which needs one target per occurrence.
     *
     * A genuine disagreement about the same identity is deliberately NOT resolved here: it is left
     * for EnterpriseWikiCanonicalOwnershipValidator to report as a conflict, exactly like the merger
     * leaves other semantic conflicts to validation rather than silently picking a winner.
     *
     * @param  array<string, mixed>  $globalPlan
     * @param  list<array<string, mixed>>  $batchResults
     * @return list<array<string, mixed>>
     */
    private function mergePatchTargets(array $globalPlan, array $batchResults): array
    {
        $targets = [];
        $seen = [];

        $lists = [(array) ($globalPlan['patch_targets'] ?? [])];

        foreach ($batchResults as $batch) {
            $lists[] = (array) ($batch['patch_targets'] ?? []);
        }

        foreach ($lists as $list) {
            foreach ($list as $target) {
                if (! is_array($target)) {
                    continue;
                }

                $key = EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($target);

                if (in_array($key, $seen, true)) {
                    continue;
                }

                $seen[] = $key;
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Wiki run-593: a figure belongs to the source document, not to any single page — the same
     * source_element_key may legitimately appear on any number of pages generated from this
     * document (source_article, source_summary, concept_pages, entity_pages), in any combination.
     * There is no "primary owner" role and no cross-page conflict to detect here (see the removed
     * run-591 role-combination rule — superseded by this simplification). The only thing this pass
     * still does is dedupe an exact repeat of the same key WITHIN one page's own planned_figures
     * list (e.g. listed twice in one batch's own response), keeping the first occurrence.
     *
     * Differing section_placement/caption_hint/purpose between different pages' own planned_figures
     * entries is fine and untouched: each page keeps its own complete entry (nothing is merged or
     * collapsed into a shared record), so no page's classification/required/purpose value is ever
     * hidden or overwritten by another's.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function deduplicateFigures(array $decision): array
    {
        foreach (['source_article', 'source_summary'] as $key) {
            $decision[$key]['planned_figures'] = $this->dedupeFiguresForPage(
                (array) ($decision[$key]['planned_figures'] ?? []),
            );
        }

        foreach (['concept_pages', 'entity_pages'] as $listKey) {
            foreach ($decision[$listKey] as $i => $page) {
                $decision[$listKey][$i]['planned_figures'] = $this->dedupeFiguresForPage(
                    (array) ($page['planned_figures'] ?? []),
                );
            }
        }

        return $decision;
    }

    /**
     * @param  list<array<string, mixed>>  $plannedFigures
     * @return list<array<string, mixed>>
     */
    private function dedupeFiguresForPage(array $plannedFigures): array
    {
        $deduped = [];
        $seenOnThisPage = [];

        foreach ($plannedFigures as $figure) {
            if (! is_array($figure)) {
                continue;
            }

            $sourceElementKey = (string) ($figure['source_element_key'] ?? '');

            if ($sourceElementKey === '') {
                continue;
            }

            if (in_array($sourceElementKey, $seenOnThisPage, true)) {
                // Exact repeat on the same page (e.g. listed twice within one batch's own
                // response) — silently deduped, keeping the first occurrence.
                continue;
            }

            $seenOnThisPage[] = $sourceElementKey;
            $deduped[] = $figure;
        }

        return $deduped;
    }

    /**
     * @param  list<array<string, mixed>>  $seen
     */
    private function findMatchingIndex(string $value, array $seen, string $key): ?int
    {
        if ($value === '') {
            return null;
        }

        foreach ($seen as $i => $entry) {
            if (EnterpriseWikiConceptIdentityMatcher::sameIdentity($value, $entry[$key])) {
                return $i;
            }
        }

        return null;
    }
}
