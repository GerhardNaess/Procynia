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
            'no_action_reason' => $globalPlan['no_action_reason'] ?? null,
            'warnings' => $globalPlan['warnings'] ?? [],
        ];

        return $this->deduplicateAndValidateFigures($merged);
    }

    /**
     * Roles eligible for the article+summary figure-pairing exception — see
     * dedupeAndCheckFiguresForPage()'s docblock. Any other page (concept, entity) is tracked under
     * the generic 'other' role and is never eligible for the exception.
     */
    private const ROLE_SOURCE_ARTICLE = 'source_article';

    private const ROLE_SOURCE_SUMMARY = 'source_summary';

    private const ROLE_OTHER = 'other';

    /**
     * Wiki run-587: figures are offered identically to the global plan and every batch (see
     * EnterpriseWikiMaintainerDecisionSplitCoordinator's class docblock — all batches see the same
     * truncated source text), so two independent batches — or a batch and the global plan — can
     * each plan the same source_element_key onto a different page without either side ever seeing
     * the other's decision. This pass runs once, on the fully assembled decision, across every
     * page-bearing field: an exact repeat of the same key on the SAME page is silently deduped
     * (keeping the first occurrence); the same key claimed by two DIFFERENT pages is a hard
     * failure, never "last writer wins" — identical philosophy to the candidate/page conflict
     * checks above, just keyed on source_element_key instead of name/title.
     *
     * Wiki run-588: the ONE narrow exception is the run's own source_article + source_summary pair
     * — EnterpriseWikiMaintainerDecisionAiClient::figurePlanningRules() explicitly tells the model
     * this pairing is legitimate ("a summary and its full article both showing the same key
     * diagram"), so the merger must not reject exactly the case it instructed the model to produce.
     * See dedupeAndCheckFiguresForPage() for the exact rule.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     *
     * @throws EnterpriseWikiMaintainerDecisionMergeConflictException
     */
    private function deduplicateAndValidateFigures(array $decision): array
    {
        /** @var array<string, list<array{title: string, role: string}>> $seenBySourceKey */
        $seenBySourceKey = [];

        foreach ([self::ROLE_SOURCE_ARTICLE, self::ROLE_SOURCE_SUMMARY] as $role) {
            $decision[$role]['planned_figures'] = $this->dedupeAndCheckFiguresForPage(
                (array) ($decision[$role]['planned_figures'] ?? []),
                (string) ($decision[$role]['title'] ?? ''),
                $role,
                $seenBySourceKey,
            );
        }

        foreach (['concept_pages', 'entity_pages'] as $listKey) {
            foreach ($decision[$listKey] as $i => $page) {
                $decision[$listKey][$i]['planned_figures'] = $this->dedupeAndCheckFiguresForPage(
                    (array) ($page['planned_figures'] ?? []),
                    (string) ($page['title'] ?? ''),
                    self::ROLE_OTHER,
                    $seenBySourceKey,
                );
            }
        }

        return $decision;
    }

    /**
     * A figure already claimed by exactly one OTHER page is a conflict UNLESS that other page and
     * this page are the run's own source_article + source_summary pair — the one professionally
     * legitimate case (e.g. the same key diagram shown in both the full article and its summary),
     * which the prompt itself explicitly allows. Once a THIRD page (any role) claims the same key —
     * including a third attempt at article/summary once that pair already exists — it is always a
     * conflict, since a figure planned onto more than two pages is never covered by the narrow
     * article+summary exception.
     *
     * Differing section_placement/caption_hint/purpose between the article's and summary's own
     * planned_figures entries is NOT a conflict and is never inspected here: each page keeps its own
     * complete entry untouched (nothing is merged/collapsed into a shared record), so neither side's
     * classification/required/purpose value is ever hidden or overwritten by the other's.
     *
     * @param  list<array<string, mixed>>  $plannedFigures
     * @param  array<string, list<array{title: string, role: string}>>  $seenBySourceKey  source_element_key
     *                                                                                    => pages that have already legitimately claimed it, shared and mutated across every
     *                                                                                    page processed by deduplicateAndValidateFigures() in this merge() call
     * @return list<array<string, mixed>>
     *
     * @throws EnterpriseWikiMaintainerDecisionMergeConflictException
     */
    private function dedupeAndCheckFiguresForPage(array $plannedFigures, string $pageTitle, string $pageRole, array &$seenBySourceKey): array
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

            $existingClaims = $seenBySourceKey[$sourceElementKey] ?? [];

            if ($existingClaims !== []) {
                $isLegitimateArticleSummaryPair = count($existingClaims) === 1
                    && $this->isArticleSummaryPair($existingClaims[0]['role'], $pageRole);

                if (! $isLegitimateArticleSummaryPair) {
                    throw EnterpriseWikiMaintainerDecisionMergeConflictException::conflictingFigureAssignment(
                        $sourceElementKey,
                        $existingClaims[0]['title'],
                        $pageTitle,
                    );
                }
            }

            $seenBySourceKey[$sourceElementKey][] = ['title' => $pageTitle, 'role' => $pageRole];
            $deduped[] = $figure;
        }

        return $deduped;
    }

    private function isArticleSummaryPair(string $roleA, string $roleB): bool
    {
        $roles = [$roleA, $roleB];
        sort($roles);

        return $roles === [self::ROLE_SOURCE_ARTICLE, self::ROLE_SOURCE_SUMMARY];
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
