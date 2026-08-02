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
     * @return string[] Empty when the decision is internally consistent.
     */
    public function findIssues(array $decision, array $indexContext): array
    {
        $knownTitles = array_merge(
            $this->indexTitles($indexContext),
            $this->plannedTitles($decision),
        );

        return array_merge(
            $this->findDanglingRelatedPageGuidance($decision, $knownTitles),
            $this->findConceptCandidateContradictions($decision, $knownTitles),
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
