<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ProjectEnterpriseWikiPageToGraph;
use App\Models\EnterpriseWikiPageLink;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
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
}
