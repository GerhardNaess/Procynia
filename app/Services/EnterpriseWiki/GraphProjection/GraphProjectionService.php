<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

interface GraphProjectionService
{
    /**
     * Create whatever schema the projection store needs, idempotently. Safe to run repeatedly.
     */
    public function ensureSchema(): void;

    /**
     * @param  array<string, mixed>  $page
     */
    public function upsertWikiPage(array $page): void;

    public function deleteWikiPage(int $customerId, int $pageId): void;

    /**
     * @param  list<array<string, mixed>>  $links
     */
    public function replaceOutgoingWikilinks(int $customerId, int $fromPageId, array $links): void;

    /**
     * A quality item as its own node, not a label on a Wiki page.
     *
     * The two domains project as two node kinds because they are two kinds of thing: a policy is a
     * governance object with an owner and a review cycle, a Wiki page is written knowledge. What
     * joins them is an edge — see replaceQualityItemWikiLinks — which is exactly as optional in the
     * graph as it is in SQL.
     *
     * @param  array<string, mixed>  $item
     */
    public function upsertQualityItem(array $item): void;

    public function deleteQualityItem(int $customerId, int $qualityItemId): void;

    /**
     * The quality edges that leave one item, as the complete set. Called with an empty list to
     * clear them, exactly like replaceOutgoingWikilinks.
     *
     * @param  list<array<string, mixed>>  $relations
     */
    public function replaceOutgoingQualityItemRelations(int $customerId, int $fromItemId, array $relations): void;

    /**
     * The Wiki pages one quality item draws on, as the complete set.
     *
     * @param  list<array<string, mixed>>  $links
     */
    public function replaceQualityItemWikiLinks(int $customerId, int $qualityItemId, array $links): void;

    /**
     * One process's activities and the Wiki articles each of them was the source of, as the
     * complete set.
     *
     * An activity is a node on the process's flow, and it is a node in the graph of its own:
     * `(:QualityItem)-[:HAS_ACTIVITY]->(:QualityActivity)-[:SOURCE_OF_ARTICLE]->(:EnterpriseWikiPage)`.
     * That is the question this exists to answer — which single step of which process is the reason
     * a given article exists — and an edge from the process itself could not answer it, because two
     * activities of one process routinely produce different articles.
     *
     * The knowledge is never copied into the graph. A SOURCE_OF_ARTICLE edge carries no content, no
     * excerpt and no title: Wiki/SQL owns what the page says, and this records only where it came
     * from.
     *
     * Called with empty lists to clear them, exactly like replaceOutgoingWikilinks — an activity
     * removed from a flow leaves no payload behind to carry its own removal.
     *
     * @param  list<array<string, mixed>>  $activities
     * @param  list<array<string, mixed>>  $articleLinks
     */
    public function replaceProcessActivities(
        int $customerId,
        int $qualityItemId,
        array $activities,
        array $articleLinks,
    ): void;

    /**
     * Rebuild everything this customer has in the graph, from SQL.
     *
     * Quality travels with the Wiki rebuild rather than in a call of its own because the rebuild
     * deletes the customer's nodes first: quality left out here would be dropped by a routine Wiki
     * rebuild and never come back.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $qualityItems
     * @param  list<array<string, mixed>>  $qualityItemRelations
     * @param  list<array<string, mixed>>  $qualityWikiLinks
     * @param  list<array<string, mixed>>  $processActivities
     * @param  list<array<string, mixed>>  $activityArticleLinks
     */
    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityItems = [],
        array $qualityItemRelations = [],
        array $qualityWikiLinks = [],
        array $processActivities = [],
        array $activityArticleLinks = [],
    ): void;
}
