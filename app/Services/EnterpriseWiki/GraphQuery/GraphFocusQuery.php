<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

use InvalidArgumentException;

/**
 * A validated request for the neighbourhood around one page.
 *
 * Validation lives in the constructor rather than at the HTTP edge because the two parameters
 * Cypher cannot bind — the relationship types and the depth — are written straight into the
 * query pattern. Neo4j has no parameter form for either, so the only thing standing between a
 * request parameter and the query text is this whitelist. A GraphFocusQuery that exists is
 * therefore safe to interpolate, and nothing else may build the pattern.
 */
class GraphFocusQuery
{
    /**
     * The relationship types the projection actually writes. Projecting more relation kinds is a
     * decision for the write side first; until then an unknown type is a client error, not an
     * empty result that looks like a page with no neighbours.
     *
     * @var list<string>
     */
    public const ALLOWED_RELATION_TYPES = ['WIKILINK'];

    /**
     * Two hops is what a reviewer can still read. It is also the ceiling that keeps a focus query
     * bounded without a result limit: beyond it, a well-linked Wiki returns most of itself.
     */
    public const MAX_DEPTH = 2;

    /** @var list<string> */
    public readonly array $relationTypes;

    /**
     * @param  list<string>|null  $relationTypes  Null means every allowed type.
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $pageId,
        public readonly int $depth = 1,
        public readonly GraphDirection $direction = GraphDirection::Both,
        ?array $relationTypes = null,
    ) {
        if ($customerId < 1) {
            throw new InvalidArgumentException('A graph focus query must be scoped to a customer.');
        }

        if ($pageId < 1) {
            throw new InvalidArgumentException('A graph focus query must name a page.');
        }

        if ($depth < 1 || $depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'Graph focus depth must be between 1 and %d, got [%d].',
                self::MAX_DEPTH,
                $depth,
            ));
        }

        $this->relationTypes = $this->normalizeRelationTypes($relationTypes);
    }

    /**
     * The relationship pattern fragment, e.g. `WIKILINK` or `WIKILINK|MENTIONS`. Every value here
     * came from ALLOWED_RELATION_TYPES, never from the request.
     */
    public function relationTypePattern(): string
    {
        return implode('|', $this->relationTypes);
    }

    /**
     * @param  list<string>|null  $relationTypes
     * @return list<string>
     */
    private function normalizeRelationTypes(?array $relationTypes): array
    {
        if ($relationTypes === null) {
            return self::ALLOWED_RELATION_TYPES;
        }

        $normalized = [];

        foreach ($relationTypes as $type) {
            $candidate = is_string($type) ? strtoupper(trim($type)) : '';

            if (! in_array($candidate, self::ALLOWED_RELATION_TYPES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown graph relation type [%s]. Allowed: %s.',
                    is_string($type) ? $type : get_debug_type($type),
                    implode(', ', self::ALLOWED_RELATION_TYPES),
                ));
            }

            $normalized[$candidate] = true;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('A graph focus query must ask for at least one relation type.');
        }

        return array_keys($normalized);
    }
}
