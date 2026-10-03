<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\Quality\QualityActivityArticleService;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

class EnterpriseWikiGraphProjectionDispatchTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_wikilink_materialization_dispatches_graph_projection_after_success(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();
        $target = $this->createWikiPageWithVersion($customer, 'Target', 'Target text.');
        $source = $this->createWikiPageWithVersion($customer, 'Source', 'See [[target|Target]].');
        $target->forceFill(['slug' => 'target'])->save();

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($source);

        Queue::assertPushed(
            ProjectEnterpriseWikiPageToGraph::class,
            fn (ProjectEnterpriseWikiPageToGraph $job): bool => $job->pageId === $source->id
                && $job->queue === ProjectEnterpriseWikiPageToGraph::QUEUE,
        );
    }

    public function test_projection_dispatch_failure_does_not_rollback_materialized_sql_wikilinks(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $customer = $this->createWikiCustomer();
        $target = $this->createWikiPageWithVersion($customer, 'Target', 'Target text.');
        $source = $this->createWikiPageWithVersion($customer, 'Source', 'See [[target|Target]].');
        $target->forceFill(['slug' => 'target'])->save();

        $result = app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($source);

        $this->assertSame(1, $result['valid_links']);
        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $source->id,
            'to_page_id' => $target->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    /**
     * An article created from a prosessaktivitet reaches the graph the ordinary way.
     *
     * It used to reach it only as a bare node: Kvalitet wrote the page version and stopped, so the
     * one step that materializes a page's relations — and, with them, dispatches this projection —
     * never ran, and the article stood isolated in the Wiki graph however much its text linked.
     * Kvalitet has no projection path of its own and must not grow one; what this holds onto is
     * that it goes through the same one every other page goes through.
     */
    public function test_an_article_created_from_a_process_activity_dispatches_graph_projection(): void
    {
        Queue::fake();

        $customer = $this->createWikiCustomer();
        $target = $this->createWikiPageWithVersion($customer, 'Leverandørregisteret', 'Slik føres registeret.');

        $actor = User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'System Owner',
            'email' => 'owner-'.uniqid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Leverandørkontroll',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);

        $blueprint = app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            [
                'lanes' => [['key' => 'sikkerhet', 'label' => 'Sikkerhetsansvarlig']],
                'nodes' => [
                    ['key' => 'start', 'lane' => 'sikkerhet', 'type' => 'start', 'label' => 'Ny leverandør'],
                    ['key' => 'kontroller', 'lane' => 'sikkerhet', 'type' => 'step', 'label' => 'Kontroller leverandøren'],
                    ['key' => 'ferdig', 'lane' => 'sikkerhet', 'type' => 'end', 'label' => 'Ferdig'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'kontroller'],
                    ['from' => 'kontroller', 'to' => 'ferdig'],
                ],
            ],
            QualityProcessBlueprint::SOURCE_MANUAL,
        );

        $created = app(QualityActivityArticleService::class)->create(
            $process,
            $blueprint,
            'kontroller',
            'Sikkerhetskrav ved vurdering av leverandører',
            "## Dokumentasjon og resultat\n\nResultatet føres i [[{$target->slug}|leverandørregisteret]].",
            $actor,
        );

        /** @var EnterpriseWikiPage $article */
        $article = $created['page'];

        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $target->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);

        Queue::assertPushed(
            ProjectEnterpriseWikiPageToGraph::class,
            fn (ProjectEnterpriseWikiPageToGraph $job): bool => $job->pageId === (int) $article->id
                && $job->queue === ProjectEnterpriseWikiPageToGraph::QUEUE,
        );
    }
}
