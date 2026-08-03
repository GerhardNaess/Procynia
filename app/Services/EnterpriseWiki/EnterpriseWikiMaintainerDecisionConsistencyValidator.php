<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic, backend-owned consistency check for a raw maintainer decision — runs after the
 * AI response is schema-validated (EnterpriseWikiMaintainerDecisionPrompt::parse()) and before it
 * is persisted/applied. Never calls the AI; never guesses a fix — it only reports what is
 * logically inconsistent so the caller can request a bounded repair or reject the decision.
 *
 * Built for the Wiki run-581 "ITIL Incident Management" incident: the article and summary both
 * pointed the reader onward to a concept that concept_pages never created, and no page in the
 * wiki index already covered it. Two independent, complementary checks catch this class of
 * self-contradiction:
 *
 *  1. Dangling related_page_guidance — a planned page explicitly names another page as the owner
 *     of a topic it is pushing out (reference_only/excluded), but that named page matches neither
 *     an existing wiki-index page nor any page this same decision is creating. This is the literal
 *     shape of the run-581 bug: the decision's own text pointed to a page it never planned.
 *  2. Concept-candidate self-contradiction — a concept_candidates entry (see
 *     EnterpriseWikiMaintainerDecisionPrompt) marked necessary_for_article=true but decided
 *     "reference_only"/"exclude" without naming a resolvable owning_page_title, or marked
 *     "create" without a matching concept_pages entry. This catches the same failure even when
 *     the AI drops the topic silently, without ever writing a dangling related_page_guidance
 *     reference for it.
 *
 * Title matching uses EnterpriseWikiConceptIdentityMatcher throughout — conservative subset
 * matching, not exact string equality, so title variants (e.g. "ITIL Incident Management" vs
 * "Incident Management") are recognised as the same concept without broad fuzzy matching that
 * could couple unrelated concepts.
 */
class EnterpriseWikiMaintainerDecisionConsistencyValidator
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  string[]  $validFigureSourceElementKeys  Real, currently-extractable figure keys
     *                                                  (EnterpriseWikiDocumentSourceElementService, filtered to showable images) — passed by
     *                                                  the caller (EnterpriseWikiMaintainerDecisionService) so a dangling planned_figures
     *                                                  reference can be told apart from a genuinely nonexistent one. An empty list means the
     *                                                  caller did not supply a known set (e.g. a hand-built decision in a test) — the dangling-
     *                                                  key check is skipped entirely rather than flagging every figure as unknown.
     * @return string[] Empty when the decision is internally consistent.
     */
    public function findIssues(array $decision, array $indexContext, array $validFigureSourceElementKeys = []): array
    {
        $knownTitles = array_merge(
            $this->indexTitles($indexContext),
            $this->plannedTitles($decision),
        );

        return array_merge(
            $this->findDanglingRelatedPageGuidance($decision, $knownTitles),
            $this->findConceptCandidateContradictions($decision, $knownTitles),
            $this->findDanglingPlannedFigures($decision, $validFigureSourceElementKeys),
            $this->findConflictingPlannedFigureAssignments($decision),
        );
    }

    /** @return string[] */
    private function indexTitles(array $indexContext): array
    {
        return array_values(array_filter(array_map(
            fn (array $page): string => (string) ($page['title'] ?? ''),
            $indexContext,
        )));
    }

    /**
     * Only concept_pages/entity_pages titles count as valid "owning page" targets —
     * source_article/source_summary are deliberately excluded, even though they carry a title
     * too: a reference_only/excluded topic is, by definition, being pushed OUT of the
     * referencing page onward to whichever page owns it, and the page responsibility model never
     * lets an article/summary own a topic it is itself deferring. Counting the article's own
     * title as a match would let a source document titled e.g. "Illustrasjon av Incident
     * Management" silently satisfy a reference to "Incident Management" — the article merely
     * being ABOUT a concept is exactly the run-581 failure mode, not evidence the concept has its
     * own page.
     *
     * @return string[]
     */
    private function plannedTitles(array $decision): array
    {
        $titles = [];

        foreach (['concept_pages', 'entity_pages'] as $key) {
            foreach ((array) ($decision[$key] ?? []) as $entry) {
                $title = (string) ($entry['title'] ?? '');

                if ($title !== '') {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * @param  string[]  $knownTitles
     * @return string[]
     */
    private function findDanglingRelatedPageGuidance(array $decision, array $knownTitles): array
    {
        $issues = [];

        foreach ($this->entriesWithGuidance($decision) as [$label, $entry]) {
            foreach ((array) ($entry['related_page_guidance'] ?? []) as $guidance) {
                $pageTitle = (string) ($guidance['page_title'] ?? '');

                if ($pageTitle === '' || $this->titleIsKnown($pageTitle, $knownTitles)) {
                    continue;
                }

                $issues[] = "{$label} points readers to \"{$pageTitle}\" via related_page_guidance, ".
                    'but no existing or planned page matches that title.';
            }
        }

        return $issues;
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function entriesWithGuidance(array $decision): array
    {
        $entries = [];

        foreach (['source_article' => 'source_article', 'source_summary' => 'source_summary'] as $key => $label) {
            $entry = $decision[$key] ?? null;

            if (is_array($entry) && $entry !== []) {
                $entries[] = [$label, $entry];
            }
        }

        foreach (['concept_pages', 'entity_pages'] as $key) {
            foreach ((array) ($decision[$key] ?? []) as $i => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $title = (string) ($entry['title'] ?? '?');
                $entries[] = ["{$key}[{$i}] (\"{$title}\")", $entry];
            }
        }

        return $entries;
    }

    /**
     * @param  string[]  $knownTitles
     * @return string[]
     */
    private function findConceptCandidateContradictions(array $decision, array $knownTitles): array
    {
        $issues = [];

        foreach ((array) ($decision['concept_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $name = (string) ($candidate['name'] ?? '');
            $candidateDecision = (string) ($candidate['decision'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($candidateDecision === 'create' && ! $this->titleIsKnown($name, $knownTitles)) {
                $issues[] = "Concept candidate \"{$name}\" was decided \"create\" but no matching ".
                    'concept_pages entry exists.';
            }

            if (
                in_array($candidateDecision, ['reference_only', 'exclude'], true)
                && ($candidate['necessary_for_article'] ?? false) === true
            ) {
                $owningTitle = (string) ($candidate['owning_page_title'] ?? '');

                if ($owningTitle === '' || ! $this->titleIsKnown($owningTitle, $knownTitles)) {
                    $issues[] = "Concept candidate \"{$name}\" is marked necessary for the article but ".
                        "decided \"{$candidateDecision}\" without an existing or planned owning page.";
                }
            }
        }

        return $issues;
    }

    /**
     * Wiki run-587: a figure must not be planned without a valid, currently-extractable source
     * element — this is the "En figur skal ikke planlegges uten gyldig kildeelement" requirement,
     * enforced here (triggering the existing bounded AI repair pass) rather than only failing
     * outright at generation time.
     *
     * @param  string[]  $validFigureSourceElementKeys
     * @return string[]
     */
    private function findDanglingPlannedFigures(array $decision, array $validFigureSourceElementKeys): array
    {
        if ($validFigureSourceElementKeys === []) {
            return [];
        }

        $issues = [];

        foreach ($this->entriesWithGuidance($decision) as [$label, $entry]) {
            foreach ((array) ($entry['planned_figures'] ?? []) as $figure) {
                if (! is_array($figure)) {
                    continue;
                }

                $sourceElementKey = (string) ($figure['source_element_key'] ?? '');

                if ($sourceElementKey === '' || in_array($sourceElementKey, $validFigureSourceElementKeys, true)) {
                    continue;
                }

                $issues[] = "{$label} plans figure \"{$sourceElementKey}\" via planned_figures, but no ".
                    'such figure was extracted from the source document.';
            }
        }

        return $issues;
    }

    /**
     * The four distinct page roles a figure claim can carry — see
     * EnterpriseWikiMaintainerDecisionMerger's identical constants/docblock (Wiki run-591: never
     * lump concept_page and entity_page together as a generic 'other').
     */
    private const ROLE_SOURCE_ARTICLE = 'source_article';

    private const ROLE_SOURCE_SUMMARY = 'source_summary';

    private const ROLE_CONCEPT_PAGE = 'concept_page';

    private const ROLE_ENTITY_PAGE = 'entity_page';

    private const PRIMARY_ROLES = [self::ROLE_CONCEPT_PAGE, self::ROLE_ENTITY_PAGE];

    /**
     * Defense-in-depth companion to EnterpriseWikiMaintainerDecisionMerger's harder, split-flow-
     * specific figure-conflict check: catches the same shape of problem (an illegal combination of
     * pages claiming one figure) within a single, already-assembled decision — including a plain
     * single_call decision, which never goes through the merger at all.
     *
     * Wiki run-591: follows the EXACT same role model and isValidRoleCombination() rule as
     * EnterpriseWikiMaintainerDecisionMerger::dedupeAndCheckFiguresForPage() — a figure may have at
     * most one primary (concept_page/entity_page) owner, plus the run's own secondary
     * source_article and/or source_summary. Kept as separate, duplicated logic (not a shared
     * helper) — same precedent as entriesWithGuidance()'s own label-based role detection and the
     * rest of this class's relationship to the merger: two different call sites over different
     * inputs (a single, already-assembled decision here vs. a live batch merge there).
     *
     * @return string[]
     */
    private function findConflictingPlannedFigureAssignments(array $decision): array
    {
        $issues = [];
        /** @var array<string, list<array{title: string, role: string}>> $seenBySourceKey */
        $seenBySourceKey = [];

        foreach ($this->entriesWithGuidance($decision) as [$label, $entry]) {
            $pageTitle = (string) ($entry['title'] ?? $label);
            $pageRole = $this->roleForLabel($label);
            $seenOnThisPage = [];

            foreach ((array) ($entry['planned_figures'] ?? []) as $figure) {
                if (! is_array($figure)) {
                    continue;
                }

                $sourceElementKey = (string) ($figure['source_element_key'] ?? '');

                if ($sourceElementKey === '') {
                    continue;
                }

                if (in_array($sourceElementKey, $seenOnThisPage, true)) {
                    // Exact repeat within this same page's own planned_figures — not a
                    // cross-page conflict, matches EnterpriseWikiMaintainerDecisionMerger's
                    // identical same-page dedup.
                    continue;
                }

                $seenOnThisPage[] = $sourceElementKey;

                $existingClaims = $seenBySourceKey[$sourceElementKey] ?? [];

                if ($existingClaims !== []) {
                    $candidateRoles = [...array_column($existingClaims, 'role'), $pageRole];

                    if (! $this->isValidRoleCombination($candidateRoles)) {
                        $issues[] = "Figure \"{$sourceElementKey}\" is planned onto both ".
                            "\"{$existingClaims[0]['title']}\" and \"{$pageTitle}\" — a figure may have at ".
                            'most one primary (concept/entity) owner, plus the run\'s own source_article '.
                            'and/or source_summary.';

                        continue;
                    }
                }

                $seenBySourceKey[$sourceElementKey][] = ['title' => $pageTitle, 'role' => $pageRole];
            }
        }

        return $issues;
    }

    /**
     * Derives the page role from entriesWithGuidance()'s own label shape: 'source_article'/
     * 'source_summary' verbatim, or 'concept_pages[{i}] (...)' / 'entity_pages[{i}] (...)' for
     * shared pages — mirrors EnterpriseWikiMaintainerDecisionMerger's identical role constants.
     */
    private function roleForLabel(string $label): string
    {
        return match (true) {
            $label === self::ROLE_SOURCE_ARTICLE, $label === self::ROLE_SOURCE_SUMMARY => $label,
            str_starts_with($label, 'entity_pages[') => self::ROLE_ENTITY_PAGE,
            default => self::ROLE_CONCEPT_PAGE,
        };
    }

    /**
     * Wiki run-591: identical rule to EnterpriseWikiMaintainerDecisionMerger::isValidRoleCombination()
     * — at most one primary (concept_page/entity_page) owner, plus the run's own secondary
     * source_article and/or source_summary; summary alone (without article) never legitimises a
     * primary owner's figure.
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

    /** @param  string[]  $knownTitles */
    private function titleIsKnown(string $title, array $knownTitles): bool
    {
        foreach ($knownTitles as $known) {
            if ($known !== '' && EnterpriseWikiConceptIdentityMatcher::sameIdentity($title, $known)) {
                return true;
            }
        }

        return false;
    }
}
