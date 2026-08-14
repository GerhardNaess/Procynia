<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionDeltaRejectedException;

/**
 * Applies bounded repair deltas to a maintainer decision — deterministically, in PHP, with no
 * model judgement involved in the merge itself.
 *
 * The whole point of splitting repair into deltas is that the parts of a decision the validators
 * already accepted must survive the repair untouched. That guarantee cannot come from asking the
 * model nicely (the previous whole-decision repair prompt spent five paragraphs asking exactly
 * that); it has to be structural. Here it is: an object the delta does not name is copied by
 * reference into the result, byte for byte.
 *
 * Fail-closed rules, applied to ALL deltas before ANY of them is applied — a partially merged
 * decision is never produced:
 *
 *  1. An `object_id` must resolve against the pre-repair snapshot.
 *  2. An `object_id` must belong to the repair group that call was given. A repair shown candidate
 *     [3] may not rewrite candidate [7]; that object was validated, and this call never saw it.
 *  3. Two deltas may not both edit the same object (groups are disjoint by construction, so this
 *     can only mean an attribution or model error).
 *  4. `remove` is refused for source_article/source_summary — a source document always has both.
 *  5. An `add` may not collide with an existing object's identity (same normalised page title, or
 *     the same patch-target identity), which would create the duplicate canonical ownership the
 *     decision contract exists to prevent.
 *
 * What this class does NOT do is decide whether the merged decision is correct. That stays with
 * the existing validators, which the caller re-runs over the complete merged decision afterwards.
 */
class EnterpriseWikiMaintainerDecisionDeltaMerger
{
    /**
     * @param  array<string, mixed>  $decision  The pre-repair snapshot every object id resolves against.
     * @param  list<array{group: array{object_ids: list<string>, issues: list<string>}, delta: array{operations: list<array<string, mixed>>, notes: ?string}}>  $repairs
     * @return array{decision: array<string, mixed>, applied: list<array{object_id: ?string, operation: string, collection: string, label: string}>}
     *
     * @throws EnterpriseWikiMaintainerDecisionDeltaRejectedException
     */
    public function merge(array $decision, array $repairs): array
    {
        $reasons = [];
        $plannedEdits = [];
        $additions = [];
        $applied = [];

        foreach ($repairs as $repair) {
            $allowedIds = array_flip($repair['group']['object_ids']);

            foreach ($repair['delta']['operations'] as $operation) {
                $objectId = $operation['object_id'];
                $op = $operation['operation'];

                if ($op === EnterpriseWikiMaintainerDecisionDeltaPrompt::OPERATION_ADD) {
                    $additions[] = $operation;
                    $applied[] = [
                        'object_id' => null,
                        'operation' => $op,
                        'collection' => $operation['collection'],
                        'label' => EnterpriseWikiMaintainerDecisionObjectIndex::label(
                            $operation['collection'].'[0]',
                            (array) $operation['object'],
                        ),
                    ];

                    continue;
                }

                if ($objectId === null || ! EnterpriseWikiMaintainerDecisionObjectIndex::exists($decision, $objectId)) {
                    $reasons[] = sprintf(
                        'operation "%s" names object_id [%s], which is not an object of this decision.',
                        $op,
                        $objectId ?? 'null',
                    );

                    continue;
                }

                if (! isset($allowedIds[$objectId])) {
                    $reasons[] = sprintf(
                        'operation "%s" edits object_id [%s], which was not part of this repair group [%s] — '
                        .'objects outside the group were already validated and must not be rewritten.',
                        $op,
                        $objectId,
                        implode(', ', $repair['group']['object_ids']),
                    );

                    continue;
                }

                if (isset($plannedEdits[$objectId])) {
                    $reasons[] = "object_id [{$objectId}] is edited by more than one repair delta.";

                    continue;
                }

                if (
                    $op === EnterpriseWikiMaintainerDecisionDeltaPrompt::OPERATION_REMOVE
                    && in_array($objectId, EnterpriseWikiMaintainerDecisionObjectIndex::SINGLETON_SLOTS, true)
                ) {
                    $reasons[] = "object_id [{$objectId}] cannot be removed — a source document always has an article and a summary.";

                    continue;
                }

                $plannedEdits[$objectId] = $operation;
                $applied[] = [
                    'object_id' => $objectId,
                    'operation' => $op,
                    'collection' => $operation['collection'],
                    'label' => EnterpriseWikiMaintainerDecisionObjectIndex::label(
                        $objectId,
                        (array) EnterpriseWikiMaintainerDecisionObjectIndex::object($decision, $objectId),
                    ),
                ];
            }
        }

        $reasons = array_merge($reasons, $this->additionCollisions($decision, $plannedEdits, $additions));

        if ($reasons !== []) {
            throw new EnterpriseWikiMaintainerDecisionDeltaRejectedException(array_values(array_unique($reasons)));
        }

        return [
            'decision' => $this->apply($decision, $plannedEdits, $additions),
            'applied' => $applied,
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, array<string, mixed>>  $plannedEdits
     * @param  list<array<string, mixed>>  $additions
     * @return array<string, mixed>
     */
    private function apply(array $decision, array $plannedEdits, array $additions): array
    {
        foreach ($plannedEdits as $objectId => $operation) {
            $ref = EnterpriseWikiMaintainerDecisionObjectIndex::parseObjectId((string) $objectId);

            if ($ref === null) {
                continue;
            }

            if ($ref['index'] === null) {
                $decision[$ref['collection']] = (array) $operation['object'];

                continue;
            }

            if ($operation['operation'] === EnterpriseWikiMaintainerDecisionDeltaPrompt::OPERATION_REMOVE) {
                unset($decision[$ref['collection']][$ref['index']]);

                continue;
            }

            $decision[$ref['collection']][$ref['index']] = (array) $operation['object'];
        }

        foreach ($additions as $addition) {
            $decision[$addition['collection']][] = (array) $addition['object'];
        }

        // Removals leave gaps; re-key so the merged decision is a clean list again — exactly the
        // shape EnterpriseWikiMaintainerDecisionPrompt::parse() and every downstream consumer
        // expect. This happens once, after every id has already been resolved.
        foreach (EnterpriseWikiMaintainerDecisionObjectIndex::LIST_COLLECTIONS as $collection) {
            if (isset($decision[$collection]) && is_array($decision[$collection])) {
                $decision[$collection] = array_values($decision[$collection]);
            }
        }

        return $decision;
    }

    /**
     * An added page/target must not duplicate one that already exists in the decision. The only
     * exception is an object this same merge removes or replaces: re-adding under a corrected
     * shape is a legitimate fix, not a duplicate.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, array<string, mixed>>  $plannedEdits
     * @param  list<array<string, mixed>>  $additions
     * @return string[]
     */
    private function additionCollisions(array $decision, array $plannedEdits, array $additions): array
    {
        $reasons = [];
        $existing = [];

        foreach (EnterpriseWikiMaintainerDecisionObjectIndex::LIST_COLLECTIONS as $collection) {
            foreach ((array) ($decision[$collection] ?? []) as $index => $entry) {
                $objectId = EnterpriseWikiMaintainerDecisionObjectIndex::objectId($collection, (int) $index);

                if (! is_array($entry) || isset($plannedEdits[$objectId])) {
                    continue;
                }

                $identity = $this->identity($collection, $entry);

                if ($identity !== null) {
                    $existing[$collection][$identity] = $objectId;
                }
            }
        }

        foreach ($additions as $addition) {
            $collection = $addition['collection'];
            $identity = $this->identity($collection, (array) $addition['object']);

            if ($identity === null) {
                continue;
            }

            if (isset($existing[$collection][$identity])) {
                $reasons[] = sprintf(
                    'operation "add" would create a second %s for [%s], which %s already covers.',
                    $collection,
                    $identity,
                    $existing[$collection][$identity],
                );

                continue;
            }

            $existing[$collection][$identity] = 'added';
        }

        return $reasons;
    }

    /** @param array<string, mixed> $object */
    private function identity(string $collection, array $object): ?string
    {
        if ($collection === EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_PATCH_TARGETS) {
            return EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($object);
        }

        $key = $collection === EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_CANDIDATES
            ? 'name'
            : 'title';

        $value = trim((string) ($object[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $value));
    }
}
