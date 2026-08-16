<?php

namespace App\Services\EnterpriseWiki;

use Closure;

/**
 * Works out WHICH objects of a maintainer decision a validation issue actually concerns, so a
 * repair can be scoped to those objects instead of to the whole decision.
 *
 * Two mechanisms, in this order:
 *
 *  1. STRUCTURAL — the issue text already names an object the way
 *     EnterpriseWikiMaintainerDecisionObjectIndex does (`concept_candidates[3]`,
 *     `patch_targets[0]`). Every issue from EnterpriseWikiCanonicalOwnershipValidator and
 *     EnterpriseWikiPatchTargetResolver carries such a token by construction, so the
 *     DB-authoritative half never needs to be re-run to attribute its own findings.
 *  2. DIFFERENTIAL — for issues that read naturally instead ("Concept candidate "X" was decided
 *     …"), the decision is re-validated once per object with that object removed. An issue that
 *     disappears when object O is removed is, by definition, an issue about O. This is deliberately
 *     NOT string parsing: it depends on the validators' actual behaviour rather than on their
 *     wording, so a reworded message can never silently mis-scope a repair.
 *
 * An issue that neither mechanism can attribute is returned in `unattributed`. The caller MUST
 * fail closed on those (see EnterpriseWikiMaintainerDecisionService): repairing an issue whose
 * object is unknown would mean handing the model the whole decision again, which is exactly the
 * unbounded pass this design replaces.
 *
 * Objects that share an issue are returned in the SAME group. That is a correctness requirement,
 * not an optimisation: "these two candidates are near-duplicates — keep at most one" cannot be
 * resolved by looking at either candidate alone.
 */
class EnterpriseWikiMaintainerDecisionIssueAttributor
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  string[]  $issues  The complete issue list for $decision.
     * @param  Closure(array<string, mixed>): string[]  $revalidatePure  Re-runs the deterministic,
     *                                                                   pure-array validators for a modified decision. Must NOT hit the database: it is called
     *                                                                   once per addressable object, and DB-backed findings are attributed structurally instead.
     * @return array{groups: list<array{object_ids: list<string>, issues: list<string>}>, unattributed: list<string>}
     */
    public function attribute(array $decision, array $issues, Closure $revalidatePure): array
    {
        $issues = array_values(array_unique($issues));
        $objectIds = EnterpriseWikiMaintainerDecisionObjectIndex::objectIds($decision);

        /** @var array<int, list<string>> $issueObjects issue index => object ids */
        $issueObjects = [];
        $needsDifferential = [];

        foreach ($issues as $i => $issue) {
            $structural = $this->structuralObjectIds($issue, $decision);

            if ($structural !== []) {
                $issueObjects[$i] = $structural;

                continue;
            }

            $needsDifferential[] = $i;
        }

        if ($needsDifferential !== []) {
            $baseline = $this->normalizeIssues($revalidatePure($decision));

            foreach ($objectIds as $objectId) {
                $withoutObject = $this->normalizeIssues(
                    $revalidatePure(EnterpriseWikiMaintainerDecisionObjectIndex::withoutObject($decision, $objectId))
                );
                $resolvedByRemoval = array_diff($baseline, $withoutObject);

                if ($resolvedByRemoval === []) {
                    continue;
                }

                foreach ($needsDifferential as $i) {
                    if (in_array($issues[$i], $resolvedByRemoval, true)) {
                        $issueObjects[$i][] = $objectId;
                    }
                }
            }
        }

        $unattributed = [];

        foreach ($issues as $i => $issue) {
            if (($issueObjects[$i] ?? []) === []) {
                $unattributed[] = $issue;
            }
        }

        return [
            'groups' => $this->buildGroups($issues, $issueObjects, $this->coupledObjectPairs($decision)),
            'unattributed' => array_values($unattributed),
        ];
    }

    /**
     * Objects that cannot be repaired apart from each other, independently of which one an issue
     * happens to name: a concept candidate and the page created FOR that candidate.
     *
     * Overfragmentation is the case that proves the need. "This candidate should not have its own
     * page" is reported against the candidate — removing the candidate resolves it — but the fix is
     * to demote the candidate AND drop its concept_pages entry. Without the pairing, the repair
     * would be handed the candidate alone and its correction would be rejected for touching an
     * object it was not given, which is a fail-closed run over a fault that was perfectly fixable.
     *
     * Pairing is deterministic title matching only (the directed candidate -> page rule from
     * EnterpriseWikiConceptIdentityMatcher), never semantic similarity.
     *
     * @param  array<string, mixed>  $decision
     * @return list<array{0: string, 1: string}>
     */
    private function coupledObjectPairs(array $decision): array
    {
        $pairs = [];

        foreach ((array) ($decision[EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_CANDIDATES] ?? []) as $candidateIndex => $candidate) {
            $name = is_array($candidate) ? trim((string) ($candidate['name'] ?? '')) : '';

            if ($name === '') {
                continue;
            }

            $candidateId = EnterpriseWikiMaintainerDecisionObjectIndex::objectId(
                EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_CANDIDATES,
                (int) $candidateIndex,
            );

            foreach ([
                EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_PAGES,
                EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_ENTITY_PAGES,
            ] as $collection) {
                foreach ((array) ($decision[$collection] ?? []) as $pageIndex => $page) {
                    $title = is_array($page) ? trim((string) ($page['title'] ?? '')) : '';

                    if ($title === '' || ! EnterpriseWikiConceptIdentityMatcher::titleCoversConcept($name, $title)) {
                        continue;
                    }

                    $pairs[] = [$candidateId, EnterpriseWikiMaintainerDecisionObjectIndex::objectId($collection, (int) $pageIndex)];
                }
            }
        }

        return $pairs;
    }

    /**
     * Object ids named literally in the issue text, kept only when they resolve against this
     * decision — a stale or malformed reference must fall through to differential attribution
     * rather than scope a repair at an object that is not there.
     *
     * @param  array<string, mixed>  $decision
     * @return list<string>
     */
    private function structuralObjectIds(string $issue, array $decision): array
    {
        if (preg_match_all('/\b(?<collection>[a-z_]+)\[(?<index>\d+)\]/', $issue, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $ids = [];

        foreach ($matches as $match) {
            $objectId = EnterpriseWikiMaintainerDecisionObjectIndex::objectId($match['collection'], (int) $match['index']);

            if (EnterpriseWikiMaintainerDecisionObjectIndex::exists($decision, $objectId)) {
                $ids[$objectId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Connected components over "these objects appear in the same issue", so every object a single
     * issue depends on is repaired in one call, together with every issue those objects appear in.
     *
     * @param  list<string>  $issues
     * @param  array<int, list<string>>  $issueObjects
     * @param  list<array{0: string, 1: string}>  $coupledPairs
     * @return list<array{object_ids: list<string>, issues: list<string>}>
     */
    private function buildGroups(array $issues, array $issueObjects, array $coupledPairs = []): array
    {
        $parent = [];

        $find = function (string $id) use (&$parent, &$find): string {
            $parent[$id] ??= $id;

            return $parent[$id] === $id ? $id : $parent[$id] = $find($parent[$id]);
        };

        $union = static function (array $objects) use ($find, &$parent): void {
            $root = $find($objects[0]);

            foreach ($objects as $objectId) {
                $parent[$find($objectId)] = $root;
            }
        };

        foreach ($issueObjects as $objects) {
            $union($objects);
        }

        // Coupled objects join the component only when one of them is already in it: an object
        // nothing complains about never becomes a group of its own here.
        foreach ($coupledPairs as [$left, $right]) {
            if (isset($parent[$left]) || isset($parent[$right])) {
                $union([$left, $right]);
            }
        }

        /** @var array<string, array{object_ids: list<string>, issues: list<string>}> $groups */
        $groups = [];

        foreach ($issueObjects as $i => $objects) {
            $root = $find($objects[0]);
            $groups[$root]['object_ids'] = array_values(array_unique(array_merge(
                $groups[$root]['object_ids'] ?? [],
                $objects,
            )));
            $groups[$root]['issues'][] = $issues[$i];
        }

        foreach ($coupledPairs as [$left, $right]) {
            foreach ([$left, $right] as $objectId) {
                if (! isset($parent[$objectId])) {
                    continue;
                }

                $root = $find($objectId);

                if (isset($groups[$root]) && ! in_array($objectId, $groups[$root]['object_ids'], true)) {
                    $groups[$root]['object_ids'][] = $objectId;
                }
            }
        }

        return array_values(array_map(
            static fn (array $group): array => [
                'object_ids' => $group['object_ids'],
                'issues' => array_values(array_unique($group['issues'])),
            ],
            $groups,
        ));
    }

    /** @param string[] $issues @return list<string> */
    private function normalizeIssues(array $issues): array
    {
        return array_values(array_filter($issues, static fn (mixed $issue): bool => is_string($issue)));
    }
}
