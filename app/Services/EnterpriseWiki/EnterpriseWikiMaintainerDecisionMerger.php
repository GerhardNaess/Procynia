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

        return [
            'source_article' => $globalPlan['source_article'],
            'source_summary' => $globalPlan['source_summary'],
            'concept_candidates' => $conceptCandidates,
            'concept_pages' => $conceptPages,
            'entity_pages' => $globalPlan['entity_pages'] ?? [],
            'no_action_reason' => $globalPlan['no_action_reason'] ?? null,
            'warnings' => $globalPlan['warnings'] ?? [],
        ];
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
