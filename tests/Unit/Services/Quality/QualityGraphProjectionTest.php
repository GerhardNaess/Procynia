<?php

namespace Tests\Unit\Services\Quality;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\QualityActivityWikiPage;
use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Models\QualityProcessBlueprint;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use App\Services\Quality\QualityActivityKnowledgeResolver;
use App\Services\Quality\QualityGraphProjector;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

/**
 * The quality domain as a projection.
 *
 * SQL owns the kvalitetssystem; the graph is a derived view of it. What these tests hold onto is
 * that the two stay the same shape, and specifically that the rebuild did what it set out to do:
 * a quality item is its own node, a Wiki page is never typed or labelled by being attached to one,
 * and a Wiki rebuild cannot quietly drop the quality half on its way past.
 */
class QualityGraphProjectionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_a_quality_item_projects_as_its_own_node(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy', 'POL-01');

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        $this->assertCount(1, $writer->upsertedQualityItems);
        $this->assertSame($policy->id, $writer->upsertedQualityItems[0]['quality_item_id']);
        $this->assertSame(QualityItem::TYPE_POLICY, $writer->upsertedQualityItems[0]['quality_type']);
        $this->assertSame('POL-01', $writer->upsertedQualityItems[0]['code']);

        // The item exists without a single Wiki page behind it, which is the normal state of a
        // policy somebody has just registered.
        $this->assertSame([], $writer->upsertedPages);
    }

    public function test_a_wiki_page_carries_no_quality_type_however_many_items_point_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->link($customer->id, $policy->id, $page->id);
        $this->link($customer->id, $process->id, $page->id);

        (new EnterpriseWikiGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectPage($page->id);

        // The retired model could not express this at all: one page, two quality objects, and the
        // page itself unchanged by either.
        $this->assertArrayNotHasKey('quality_type', $writer->upsertedPages[0]);
    }

    public function test_quality_relations_are_projected_as_the_items_outgoing_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        $replaced = $writer->replacedQualityItemRelations[0];

        $this->assertSame($policy->id, $replaced['from_item_id']);
        $this->assertCount(1, $replaced['relations']);
        $this->assertSame(QualityItemRelation::TYPE_GOVERNS, $replaced['relations'][0]['relation_type']);
        $this->assertSame($process->id, $replaced['relations'][0]['to_item_id']);
    }

    public function test_the_target_item_is_projected_before_the_edge_pointing_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        $methods = array_column($writer->calls, 'method');
        $targetUpsert = null;

        foreach ($writer->calls as $index => $call) {
            if ($call['method'] === 'upsertQualityItem' && ($call['quality_item_id'] ?? null) === $process->id) {
                $targetUpsert = $index;
            }
        }

        // The graph writer MATCHes the target rather than creating it, so an edge written before
        // its target node exists is dropped without a trace.
        $this->assertNotNull($targetUpsert);
        $this->assertLessThan(
            array_search('replaceOutgoingQualityItemRelations', $methods, true),
            $targetUpsert,
        );
    }

    public function test_an_edge_to_another_customers_item_never_reaches_the_graph(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $otherCustomer = $this->createWikiCustomer('Annen kunde AS');

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $foreignProcess = $this->item($otherCustomer->id, QualityItem::TYPE_PROCESS, 'Fremmed prosess');

        // Written straight to the table: the service would refuse it, and the point here is that
        // the projector does not depend on that having happened.
        $this->relate($customer->id, $policy->id, $foreignProcess->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        $this->assertSame([], $writer->replacedQualityItemRelations[0]['relations']);
    }

    public function test_a_wiki_link_is_projected_as_an_edge_from_the_item_to_the_page(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $this->link($customer->id, $process->id, $page->id, QualityItemWikiLink::LINK_TYPE_DOCUMENTS);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        $replaced = $writer->replacedQualityWikiLinks[0];

        $this->assertSame($process->id, $replaced['quality_item_id']);
        $this->assertCount(1, $replaced['links']);
        $this->assertSame($page->id, $replaced['links'][0]['page_id']);
        $this->assertSame(QualityItemWikiLink::LINK_TYPE_DOCUMENTS, $replaced['links'][0]['link_type']);

        // The page node has to exist before the edge is written, same as the relation targets.
        $this->assertSame($page->id, $writer->upsertedPages[0]['page_id']);
    }

    public function test_an_item_with_nothing_attached_still_clears_its_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        // Always called, including with an empty list: a relation or link removed in SQL leaves no
        // payload behind to carry its own removal.
        $this->assertSame([], $writer->replacedQualityItemRelations[0]['relations']);
        $this->assertSame([], $writer->replacedQualityWikiLinks[0]['links']);
    }

    public function test_a_customer_rebuild_carries_the_whole_quality_domain(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);
        $this->link($customer->id, $process->id, $page->id);

        (new EnterpriseWikiGraphProjector($writer, new QualityActivityKnowledgeResolver))->rebuildCustomer($customer->id);

        $rebuild = $writer->rebuilds[0];

        // The rebuild deletes the customer's nodes first, so anything left out of it is dropped by
        // a routine Wiki rebuild and never comes back.
        $this->assertCount(2, $rebuild['quality_items']);
        $this->assertCount(1, $rebuild['quality_item_relations']);
        $this->assertCount(1, $rebuild['quality_wiki_links']);
        $this->assertSame(
            [QualityItem::TYPE_POLICY, QualityItem::TYPE_PROCESS],
            array_column($rebuild['quality_items'], 'quality_type'),
        );
    }

    /**
     * The requested model, as the projection writes it: prosess -> aktivitet -> Wiki-artikkel.
     *
     * An activity is a node on the process's flow and a node in the graph of its own. The edge
     * cannot leave the process instead, because two activities of one process routinely produce
     * different articles and an edge from the process could not tell them apart.
     */
    public function test_an_activity_is_projected_between_the_process_and_the_wiki_page(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $rutine = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');
        $terskler = $this->createWikiPageWithVersion($customer, 'Terskelverdier', 'Text.');

        $this->flowFor($customer->id, $process->id);
        $this->articleFrom($customer->id, $process->id, 'vurder', $rutine->id);
        $this->articleFrom($customer->id, $process->id, 'vurder', $terskler->id);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        $replaced = $writer->replacedProcessActivities[0];

        $this->assertSame($process->id, $replaced['quality_item_id']);
        $this->assertSame(
            ['start', 'vurder', 'ferdig'],
            array_column($replaced['activities'], 'activity_key'),
        );

        // The lane's label, not its key: "who does this" is the question the graph is asked.
        $this->assertSame('Saksbehandler', $replaced['activities'][1]['role']);
        $this->assertSame('step', $replaced['activities'][1]['activity_type']);

        // One activity, two articles it was the source of.
        $this->assertSame(
            [['vurder', $rutine->id], ['vurder', $terskler->id]],
            array_map(
                static fn (array $link): array => [$link['activity_key'], $link['page_id']],
                $replaced['article_links'],
            ),
        );

        // Nothing of what the articles say reaches the graph — only their identity.
        $this->assertSame(
            ['customer_id', 'quality_item_id', 'activity_key', 'page_id', 'updated_at'],
            array_keys($replaced['article_links'][0]),
        );

        // And the page nodes exist before the edges pointing at them are written.
        $this->assertSame([$rutine->id, $terskler->id], array_column($writer->upsertedPages, 'page_id'));
    }

    public function test_an_article_whose_activity_left_the_flow_leaves_no_relation_behind(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $this->flowFor($customer->id, $process->id);
        $this->articleFrom($customer->id, $process->id, 'kontroller', $page->id);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        $replaced = $writer->replacedProcessActivities[0];

        // The provenance row stands and the Wiki page is untouched — but "kontroller" is not a step
        // on this flow, so there is no activity node for the edge to leave.
        $this->assertCount(3, $replaced['activities']);
        $this->assertSame([], $replaced['article_links']);
    }

    /**
     * An activity is behind every page the run built from its source, not one.
     *
     * This is what the source direction buys. One article becomes an article page, a summary, and
     * the concepts and entities the maintainer decision found in it — and all of them came out of
     * the step somebody was standing on. A page the run merely patched did not.
     */
    public function test_an_activity_is_the_source_of_every_page_its_run_created(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $this->flowFor($customer->id, $process->id);

        $article = $this->createWikiPageWithVersion($customer, 'Terskelverdier', 'Slik vurderes de.');
        $concept = $this->createWikiPageWithVersion($customer, 'Anskaffelsesverdi', 'Hva det betyr.');
        $patched = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Fantes fra for.');

        $this->sourceFrom(
            $customer->id,
            $process->id,
            'vurder',
            [$article->id, $concept->id],
            $patched->id,
        );

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        $replaced = $writer->replacedProcessActivities[0];

        $this->assertSame(
            [['vurder', (int) $article->id], ['vurder', (int) $concept->id]],
            array_map(
                static fn (array $link): array => [$link['activity_key'], (int) $link['page_id']],
                $replaced['article_links'],
            ),
        );
    }

    public function test_an_activity_that_has_produced_nothing_is_still_an_activity(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->flowFor($customer->id, $process->id);

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        // The graph has to be able to answer "which activities have produced nothing", which it
        // cannot do if an activity with no articles is left out of it.
        $this->assertCount(3, $writer->replacedProcessActivities[0]['activities']);
        $this->assertSame([], $writer->replacedProcessActivities[0]['article_links']);
    }

    public function test_a_process_with_no_flow_still_clears_the_activities_it_had(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($process->id);

        $this->assertSame([], $writer->replacedProcessActivities[0]['activities']);
        $this->assertSame([], $writer->replacedProcessActivities[0]['article_links']);
    }

    public function test_a_document_without_a_flow_has_no_activities_to_project(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->projectItem($policy->id);

        // quality_type is immutable, so a policy can never have left activities behind to clear.
        $this->assertSame([], $writer->replacedProcessActivities);
    }

    public function test_a_customer_rebuild_carries_the_activities_and_the_articles_they_produced(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $this->flowFor($customer->id, $process->id);
        $this->articleFrom($customer->id, $process->id, 'vurder', $page->id);

        (new EnterpriseWikiGraphProjector($writer, new QualityActivityKnowledgeResolver))->rebuildCustomer($customer->id);

        $rebuild = $writer->rebuilds[0];

        // The rebuild deletes the customer's QualityActivity nodes first, so activities left out of
        // it are dropped by a routine Wiki rebuild and never come back.
        $this->assertSame(
            ['start', 'vurder', 'ferdig'],
            array_column($rebuild['process_activities'], 'activity_key'),
        );
        $this->assertSame(
            [['vurder', $page->id]],
            array_map(
                static fn (array $link): array => [$link['activity_key'], $link['page_id']],
                $rebuild['activity_article_links'],
            ),
        );
    }

    public function test_deleting_an_item_from_the_graph_names_the_customer_it_belonged_to(): void
    {
        $writer = new RecordingGraphProjectionService;

        // The row is already gone from SQL by the time the job runs, so the customer cannot be
        // looked up — it has to travel with the delete.
        (new QualityGraphProjector($writer, new QualityActivityKnowledgeResolver))->deleteItem(7, 99);

        $this->assertSame([['customer_id' => 7, 'quality_item_id' => 99]], $writer->deletedQualityItems);
    }

    private function item(int $customerId, string $type, string $title, ?string $code = null): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customerId,
            'quality_type' => $type,
            'title' => $title,
            'code' => $code,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function relate(int $customerId, int $fromItemId, int $toItemId, string $relationType): void
    {
        QualityItemRelation::query()->create([
            'customer_id' => $customerId,
            'from_item_id' => $fromItemId,
            'to_item_id' => $toItemId,
            'relation_type' => $relationType,
            'source' => QualityItemRelation::SOURCE_MANUAL,
        ]);
    }

    /**
     * A three-step flow.
     *
     * Written through the one gate every blueprint write goes through, so the payload under test is
     * the payload the application would actually store.
     */
    private function flowFor(int $customerId, int $itemId): void
    {
        app(QualityProcessBlueprintService::class)->store(
            $customerId,
            QualityItem::query()->findOrFail($itemId),
            [
                'lanes' => [['key' => 'saksbehandler', 'label' => 'Saksbehandler']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'saksbehandler', 'type' => 'start', 'label' => 'Behov oppstar'],
                    [
                        'key' => 'vurder',
                        'lane' => 'saksbehandler',
                        'type' => 'step',
                        'label' => 'Vurder anskaffelsen',
                    ],
                    ['key' => 'ferdig', 'lane' => 'saksbehandler', 'type' => 'end', 'label' => 'Ferdig'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'vurder'],
                    ['from' => 'vurder', 'to' => 'ferdig'],
                ],
            ],
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    /**
     * One article recorded as having come out of the named activity, in the direction that
     * predates sources: a single page written by hand. Still resolved, still projected.
     */
    private function articleFrom(int $customerId, int $itemId, string $activityKey, int $pageId): void
    {
        QualityActivityWikiPage::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $itemId,
            'activity_key' => $activityKey,
            'enterprise_wiki_page_id' => $pageId,
        ]);
    }

    /**
     * The current direction: the activity produced a SOURCE, and the ingest run made these pages
     * of it. All of them are what the activity is behind.
     *
     * @param  list<int>  $createdPageIds
     */
    private function sourceFrom(
        int $customerId,
        int $itemId,
        string $activityKey,
        array $createdPageIds,
        int $updatedPageId = 0,
    ): void {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => 'Kunnskapsartikkel.md',
            'file_path' => 'customers/'.$customerId.'/wiki-documents/'.Str::ulid().'.md',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => '# Kunnskapsartikkel',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        QualityActivityWikiPage::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $itemId,
            'activity_key' => $activityKey,
            'enterprise_wiki_document_id' => $document->id,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', (string) $document->id),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        foreach ($createdPageIds as $pageId) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $pageId,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);
        }

        if ($updatedPageId !== 0) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $updatedPageId,
                'action' => EnterpriseWikiIngestRunPage::ACTION_PATCHED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);
        }
    }

    private function link(
        int $customerId,
        int $itemId,
        int $pageId,
        string $linkType = QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
    ): void {
        QualityItemWikiLink::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $itemId,
            'enterprise_wiki_page_id' => $pageId,
            'link_type' => $linkType,
            'source' => QualityItemWikiLink::SOURCE_MANUAL,
        ]);
    }
}
