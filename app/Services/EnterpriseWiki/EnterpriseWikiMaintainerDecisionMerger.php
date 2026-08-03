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
     * The four distinct page roles a figure claim can carry — Wiki run-591 (never lump concept_page
     * and entity_page together as a generic 'other'; the merger and
     * EnterpriseWikiMaintainerDecisionConsistencyValidator must reason about them explicitly and
     * identically).
     *
     * source_article/source_summary are the SECONDARY display roles: the run's own main article and
     * its companion summary may both show a figure that "belongs" elsewhere, as a secondary
     * presentation. concept_page/entity_page are the PRIMARY scholarly roles: the one, deeper-detail
     * home a figure is actually explained in. See PRIMARY_ROLES and isValidRoleCombination().
     */
    private const ROLE_SOURCE_ARTICLE = 'source_article';

    private const ROLE_SOURCE_SUMMARY = 'source_summary';

    private const ROLE_CONCEPT_PAGE = 'concept_page';

    private const ROLE_ENTITY_PAGE = 'entity_page';

    private const PRIMARY_ROLES = [self::ROLE_CONCEPT_PAGE, self::ROLE_ENTITY_PAGE];

    /**
     * Wiki run-587: figures are offered identically to the global plan and every batch (see
     * EnterpriseWikiMaintainerDecisionSplitCoordinator's class docblock — all batches see the same
     * truncated source text), so two independent batches — or a batch and the global plan — can
     * each plan the same source_element_key onto a different page without either side ever seeing
     * the other's decision. This pass runs once, on the fully assembled decision, across every
     * page-bearing field: an exact repeat of the same key on the SAME page is silently deduped
     * (keeping the first occurrence); an ILLEGAL combination of pages claiming the same key is a
     * hard failure, never "last writer wins" — identical philosophy to the candidate/page conflict
     * checks above, just keyed on source_element_key instead of name/title. See
     * isValidRoleCombination() for exactly which combinations are legal (Wiki run-588/591).
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

        foreach ([
            'concept_pages' => self::ROLE_CONCEPT_PAGE,
            'entity_pages' => self::ROLE_ENTITY_PAGE,
        ] as $listKey => $role) {
            foreach ($decision[$listKey] as $i => $page) {
                $decision[$listKey][$i]['planned_figures'] = $this->dedupeAndCheckFiguresForPage(
                    (array) ($page['planned_figures'] ?? []),
                    (string) ($page['title'] ?? ''),
                    $role,
                    $seenBySourceKey,
                );
            }
        }

        return $decision;
    }

    /**
     * A figure already claimed by one or more pages may gain another claimant only when the
     * RESULTING full set of roles is still legal — see isValidRoleCombination() for the exact rule
     * (Wiki run-591: article + exactly one concept/entity page is legitimate; concept + concept,
     * summary + concept without article, or a second primary owner are not). Never "last writer
     * wins": an illegal combination always throws, regardless of processing order.
     *
     * Differing section_placement/caption_hint/purpose between different pages' own planned_figures
     * entries is NOT a conflict and is never inspected here: each page keeps its own complete entry
     * untouched (nothing is merged/collapsed into a shared record), so no side's
     * classification/required/purpose value is ever hidden or overwritten by another's.
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
                $candidateRoles = [...array_column($existingClaims, 'role'), $pageRole];

                if (! $this->isValidRoleCombination($candidateRoles)) {
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

    /**
     * Wiki run-591: a figure may have AT MOST ONE primary scholarly owner (concept_page or
     * entity_page) — the one page that explains it in depth — plus, in addition, the run's own
     * secondary display pages (source_article and/or source_summary). This makes legal exactly:
     * {article}, {summary}, {concept}, {entity}, {article, summary}, {article, concept},
     * {article, entity}, {article, summary, concept}, {article, summary, entity}. Illegal: two
     * distinct primary owners in any combination (concept+concept, entity+entity, concept+entity,
     * article+two concepts, ...), and summary+primary WITHOUT article also present — the summary
     * secondary display is only allowed to piggyback on the article's, never to stand in for it.
     *
     * @param  list<string>  $roles
     */
    private function isValidRoleCombination(array $roles): bool
    {
        $primaryCount = count(array_intersect($roles, self::PRIMARY_ROLES));

        if ($primaryCount > 1) {
            return false;
        }

        if ($primaryCount === 1
            && in_array(self::ROLE_SOURCE_SUMMARY, $roles, true)
            && ! in_array(self::ROLE_SOURCE_ARTICLE, $roles, true)
        ) {
            return false;
        }

        return true;
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
