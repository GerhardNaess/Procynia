<?php

namespace App\Services\EnterpriseWiki;

/**
 * Addressable identity for the individual objects a maintainer decision is made of.
 *
 * A maintainer decision is not one thing — it is a set of independently decided objects
 * (concept candidates, concept/entity pages, patch targets, and the two source pages). Until now
 * nothing could NAME one of them, so the only repair granularity available was "the whole
 * decision". That is exactly what made the repair pass unbounded: a decision large enough to need
 * the split flow can never be re-emitted inside one call's output budget (run 51: 31 599 chars of
 * decision, ~9 500 output tokens, against a 9 000 ceiling).
 *
 * The object id is deliberately the SAME token the deterministic validators already print in their
 * issue messages — `concept_candidates[3]`, `patch_targets[0]`, `source_article` — so an issue and
 * the object it concerns are visibly the same thing in the repair prompt, with no separate mapping
 * for the model (or a later reader) to reconstruct.
 *
 * Ids are positional within ONE decision snapshot. That is safe precisely because every consumer
 * here — attribution, the repair prompt, and the delta merge — resolves them against that same
 * immutable snapshot; the merged result is produced in one deterministic pass, never by mutating
 * the snapshot while ids are still being resolved.
 */
class EnterpriseWikiMaintainerDecisionObjectIndex
{
    public const COLLECTION_CONCEPT_CANDIDATES = 'concept_candidates';

    public const COLLECTION_CONCEPT_PAGES = 'concept_pages';

    public const COLLECTION_ENTITY_PAGES = 'entity_pages';

    public const COLLECTION_PATCH_TARGETS = 'patch_targets';

    public const SLOT_SOURCE_ARTICLE = 'source_article';

    public const SLOT_SOURCE_SUMMARY = 'source_summary';

    /** The list-shaped collections, in the order a repair prompt renders them. */
    public const LIST_COLLECTIONS = [
        self::COLLECTION_CONCEPT_CANDIDATES,
        self::COLLECTION_CONCEPT_PAGES,
        self::COLLECTION_ENTITY_PAGES,
        self::COLLECTION_PATCH_TARGETS,
    ];

    /** The single-object slots. They can only ever be replaced — never added or removed. */
    public const SINGLETON_SLOTS = [
        self::SLOT_SOURCE_ARTICLE,
        self::SLOT_SOURCE_SUMMARY,
    ];

    /**
     * Every addressable object in the decision, in a stable, deterministic order.
     *
     * @param  array<string, mixed>  $decision
     * @return list<string> Object ids.
     */
    public static function objectIds(array $decision): array
    {
        $ids = [];

        foreach (self::SINGLETON_SLOTS as $slot) {
            if (is_array($decision[$slot] ?? null) && $decision[$slot] !== []) {
                $ids[] = $slot;
            }
        }

        foreach (self::LIST_COLLECTIONS as $collection) {
            foreach ((array) ($decision[$collection] ?? []) as $index => $entry) {
                if (is_array($entry)) {
                    $ids[] = self::objectId($collection, (int) $index);
                }
            }
        }

        return $ids;
    }

    public static function objectId(string $collection, int $index): string
    {
        return "{$collection}[{$index}]";
    }

    /**
     * @return array{collection: string, index: int|null}|null Null when the id is not a
     *                                                         well-formed reference to this decision shape.
     */
    public static function parseObjectId(string $objectId): ?array
    {
        $objectId = trim($objectId);

        if (in_array($objectId, self::SINGLETON_SLOTS, true)) {
            return ['collection' => $objectId, 'index' => null];
        }

        if (preg_match('/^(?<collection>[a-z_]+)\[(?<index>\d+)\]$/', $objectId, $matches) !== 1) {
            return null;
        }

        if (! in_array($matches['collection'], self::LIST_COLLECTIONS, true)) {
            return null;
        }

        return ['collection' => $matches['collection'], 'index' => (int) $matches['index']];
    }

    /**
     * The object an id names, or null when the id does not resolve against this decision.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>|null
     */
    public static function object(array $decision, string $objectId): ?array
    {
        $ref = self::parseObjectId($objectId);

        if ($ref === null) {
            return null;
        }

        if ($ref['index'] === null) {
            $entry = $decision[$ref['collection']] ?? null;

            return is_array($entry) && $entry !== [] ? $entry : null;
        }

        $entry = ($decision[$ref['collection']] ?? [])[$ref['index']] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public static function exists(array $decision, string $objectId): bool
    {
        return self::object($decision, $objectId) !== null;
    }

    /**
     * The same decision with one object removed — used by issue attribution to find out which
     * object an issue actually depends on. Never mutates the input.
     *
     * List indices are NOT re-keyed here: attribution compares issue messages, and re-indexing
     * would renumber every following object's id inside those messages, making the comparison
     * meaningless. The removed slot is dropped from the array, so `array_values()` is deliberately
     * not applied.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function withoutObject(array $decision, string $objectId): array
    {
        $ref = self::parseObjectId($objectId);

        if ($ref === null) {
            return $decision;
        }

        if ($ref['index'] === null) {
            $decision[$ref['collection']] = [];

            return $decision;
        }

        unset($decision[$ref['collection']][$ref['index']]);

        return $decision;
    }

    /**
     * A short, human-readable label for an object — what a repair prompt and an audit log show
     * next to the id so a reader can tell which concept/page/target is meant without decoding JSON.
     *
     * @param  array<string, mixed>  $object
     */
    public static function label(string $objectId, array $object): string
    {
        $ref = self::parseObjectId($objectId);
        $collection = $ref['collection'] ?? '';

        return match ($collection) {
            self::COLLECTION_CONCEPT_CANDIDATES => (string) ($object['name'] ?? '?'),
            self::COLLECTION_PATCH_TARGETS => sprintf(
                'page %s · %s',
                (string) ($object['target_page_id'] ?? '?'),
                (string) ($object['target_topic'] ?? '?'),
            ),
            default => (string) ($object['title'] ?? '?'),
        };
    }
}
