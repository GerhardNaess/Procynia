<?php

namespace Tests\Unit\Services\EnterpriseWiki\GraphQuery;

use App\Services\EnterpriseWiki\GraphQuery\GraphDirection;
use App\Services\EnterpriseWiki\GraphQuery\GraphFocusQuery;
use InvalidArgumentException;
use Tests\TestCase;

class GraphFocusQueryTest extends TestCase
{
    public function test_it_defaults_to_one_hop_in_both_directions_over_every_allowed_relation_type(): void
    {
        $query = new GraphFocusQuery(10, 20);

        $this->assertSame(1, $query->depth);
        $this->assertSame(GraphDirection::Both, $query->direction);
        $this->assertSame(GraphFocusQuery::ALLOWED_RELATION_TYPES, $query->relationTypes);
    }

    public function test_it_rejects_a_depth_beyond_the_ceiling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 2');

        new GraphFocusQuery(10, 20, 3);
    }

    public function test_it_rejects_a_zero_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphFocusQuery(10, 20, 0);
    }

    public function test_it_rejects_a_relation_type_outside_the_whitelist(): void
    {
        // The whitelist is what makes interpolating the type into the Cypher pattern safe — Neo4j
        // has no parameter form for a relationship type.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown graph relation type [WIKILINK]-()-[r]-()');

        new GraphFocusQuery(10, 20, 1, GraphDirection::Both, ['WIKILINK]-()-[r]-()']);
    }

    public function test_it_rejects_an_empty_relation_type_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphFocusQuery(10, 20, 1, GraphDirection::Both, []);
    }

    public function test_it_requires_a_customer_and_a_page(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphFocusQuery(0, 20);
    }

    public function test_it_normalizes_and_deduplicates_relation_types(): void
    {
        $query = new GraphFocusQuery(10, 20, 1, GraphDirection::Both, ['wikilink', 'WIKILINK']);

        $this->assertSame(['WIKILINK'], $query->relationTypes);
        $this->assertSame('WIKILINK', $query->relationTypePattern());
    }
}
