<?php

namespace Tests\Feature\Console;

use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

class EnterpriseWikiProjectGraphCommandTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_it_rebuilds_one_customers_graph_projection_from_sql(): void
    {
        $writer = new RecordingGraphProjectionService;
        $this->app->instance(GraphProjectionService::class, $writer);

        $customer = $this->createWikiCustomer();
        $source = $this->createWikiPageWithVersion($customer, 'Source', '[[target|Target]]');
        $target = $this->createWikiPageWithVersion($customer, 'Target', 'Target text.');
        $this->createWikilink($customer, $source, $target);

        $this->artisan('wiki:graph-project', ['--customer' => $customer->id])
            ->assertSuccessful();

        $this->assertCount(1, $writer->rebuilds);
        $this->assertSame($customer->id, $writer->rebuilds[0]['customer_id']);
        $this->assertCount(2, $writer->rebuilds[0]['pages']);
        $this->assertCount(1, $writer->rebuilds[0]['links']);
    }
}
