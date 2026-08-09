<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic, backend-owned overfragmentation check for a maintainer decision — runs after the
 * AI response is schema-validated and, for a split (batch) decision, after
 * EnterpriseWikiMaintainerDecisionMerger has combined every batch into one complete decision. Never
 * calls the AI; only reports what looks overfragmented so the caller
 * (EnterpriseWikiMaintainerDecisionService) can request a bounded repair or reject the decision —
 * exactly the same contract as EnterpriseWikiMaintainerDecisionConsistencyValidator, which this
 * class is a sibling to (one checks logical self-contradiction, this one checks page breadth).
 *
 * Built for the "short ITIL document exploded into 9 pages" incident: a short document produced 1
 * source article, 1 source summary, and 7 concept pages — six of those concept pages were
 * practices that, in that document's context, should have been sections under one overarching
 * framework page instead. The batch grid limited candidates per AI call, and the consistency
 * validator checked structural self-contradiction, but nothing checked whether a "create" decision
 * was actually warranted by the source material.
 *
 * Deliberately NOT a hard page-count cap — the primary rule is evidence + reuse value + hierarchy,
 * never an arbitrary "max N pages per document" (a document with several genuinely independent
 * concepts must still be allowed to produce several pages). Three complementary checks:
 *
 *  1. Insufficient evidence — a concept_candidates entry decided "create" while reporting
 *     has_separate_source_evidence=false AND has_reuse_value=false (see
 *     EnterpriseWikiMaintainerDecisionPrompt) is, by the model's own admission, not a good
 *     standalone-page candidate. Requiring BOTH to be missing is deliberate: a term with reuse
 *     potential earns its page on first sight even when this one document only devotes a short
 *     passage to it, which is the normal shape of a reusable Enterprise Wiki concept.
 *  2. Shared-source cluster — three or more "create" candidates sharing the same (normalized)
 *     mentioned_context are very likely fragments of one short passage or bullet list, regardless
 *     of what their evidence flags say (a deterministic backstop against the model asserting
 *     evidence=true for each item in a list purely because each item has a name).
 *  3. Near-duplicate/overlapping siblings — two or more "create" candidates whose titles
 *     EnterpriseWikiConceptIdentityMatcher considers the same identity (conservative subset
 *     matching, the same mechanism the consistency validator already uses) are naming variants of
 *     one underlying concept, not two.
 *
 * A candidate decided "reuse" is never flagged by any of these checks — reusing an already-created,
 * already-legitimate existing page is always allowed regardless of how short the source document
 * is (existing-page reuse must never be forced back into a section).
 */
class EnterpriseWikiMaintainerDecisionHierarchyValidator
{
    /** Shared-source-cluster check only fires at this many or more "create" candidates. */
    private const SHARED_CONTEXT_CLUSTER_THRESHOLD = 3;

    /**
     * @param  array<string, mixed>  $decision
     * @return string[] Empty when the decision shows no overfragmentation signal.
     */
    public function findIssues(array $decision): array
    {
        $createCandidates = $this->createCandidates($decision);

        return array_merge(
            $this->findInsufficientEvidence($createCandidates),
            $this->findSharedContextClusters($createCandidates),
            $this->findNearDuplicateSiblings($createCandidates),
        );
    }

    /** @return list<array{index: int, name: string, mentioned_context: string}> */
    private function createCandidates(array $decision): array
    {
        $candidates = [];

        foreach ((array) ($decision['concept_candidates'] ?? []) as $index => $candidate) {
            if (! is_array($candidate) || (string) ($candidate['decision'] ?? '') !== 'create') {
                continue;
            }

            $name = trim((string) ($candidate['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $candidates[] = [
                'index' => (int) $index,
                'name' => $name,
                'mentioned_context' => trim((string) ($candidate['mentioned_context'] ?? '')),
                'has_separate_source_evidence' => $candidate['has_separate_source_evidence'] ?? true,
                'has_reuse_value' => $candidate['has_reuse_value'] ?? true,
            ];
        }

        return $candidates;
    }

    /** @return string[] */
    private function findInsufficientEvidence(array $createCandidates): array
    {
        $issues = [];

        foreach ($createCandidates as $candidate) {
            // Run 16: this used to reject a "create" candidate that was missing EITHER signal,
            // which excluded every reusable subject-matter term a short source document mentions
            // once — Hendelseshåndtering, Endringsstyring and SLA were all dropped that way. A
            // thin passage is a weak signal, not a disqualification: an Enterprise Wiki concept
            // page is worth creating on first sight of a term that other pages will plausibly link
            // to later. Only a candidate with NEITHER its own source substance NOR reuse potential
            // is genuinely just a local detail, and that is the one this still stops.
            if ($candidate['has_separate_source_evidence'] === true || $candidate['has_reuse_value'] === true) {
                continue;
            }

            $issues[] = "Concept candidate \"{$candidate['name']}\" was decided \"create\" but has ".
                'neither separate substantial source evidence nor independent reuse value'.
                ' — it should be a section under its natural owning page, or decided "reference_only"/'.
                '"exclude" with an owning page named, not created as its own standalone page.';
        }

        return $issues;
    }

    /** @return string[] */
    private function findSharedContextClusters(array $createCandidates): array
    {
        $byContext = [];

        foreach ($createCandidates as $candidate) {
            $normalized = $this->normalizeContext($candidate['mentioned_context']);

            if ($normalized === '') {
                continue;
            }

            $byContext[$normalized][] = $candidate['name'];
        }

        $issues = [];

        foreach ($byContext as $normalizedContext => $names) {
            if (count($names) < self::SHARED_CONTEXT_CLUSTER_THRESHOLD) {
                continue;
            }

            $namesText = implode('", "', $names);
            $issues[] = 'Concept candidates "'.$namesText.'" ('.count($names).' total) were all decided '.
                '"create" from the same source location ("'.$normalizedContext.'") — likely fragments of '.
                'one short passage or bullet list; consolidate into sections of a single owning page '.
                'instead of separate standalone pages.';
        }

        return $issues;
    }

    /** @return string[] */
    private function findNearDuplicateSiblings(array $createCandidates): array
    {
        $issues = [];
        $count = count($createCandidates);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (! EnterpriseWikiConceptIdentityMatcher::sameIdentity($createCandidates[$i]['name'], $createCandidates[$j]['name'])) {
                    continue;
                }

                $issues[] = "Concept candidates \"{$createCandidates[$i]['name']}\" and \"{$createCandidates[$j]['name']}\" ".
                    'are near-duplicate/overlapping concepts both decided "create" — consolidate into one '.
                    'page (keep "create" for at most one, decide "reuse" or "reference_only" for the other).';
            }
        }

        return $issues;
    }

    private function normalizeContext(string $context): string
    {
        $normalized = mb_strtolower($context);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
