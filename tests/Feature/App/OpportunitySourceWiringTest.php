<?php

namespace Tests\Feature\App;

use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use App\Services\OpportunitySources\Exceptions\UnknownOpportunitySourceException;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\OpportunitySources\WatchProfileInboxDiscoveryCoordinator;
use App\Services\Ted\TedSourceAdapter;
use App\Services\Ted\TedWatchProfileInboxDiscoveryService;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * How the application gets hold of a source, now that there is more than one conceivable answer.
 *
 * The container used to bind OpportunitySourceAdapter to DoffinSourceAdapter. Everything
 * source-neutral therefore held Doffin and called it "the source" — correct while there was one
 * register, and silently wrong the moment there were two. Nothing resolves the interface globally
 * any more; a caller says which source it has and the registry answers for that one.
 */
class OpportunitySourceWiringTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_registry_holds_both_registered_sources(): void
    {
        $registry = app(OpportunitySourceRegistry::class);

        $this->assertSame(['doffin', 'ted'], $registry->keys());
        $this->assertInstanceOf(DoffinSourceAdapter::class, $registry->get(DoffinSourceAdapter::SOURCE_KEY));
        $this->assertInstanceOf(TedSourceAdapter::class, $registry->get(TedSourceAdapter::SOURCE_KEY));
    }

    /** Each answers for itself, which is the whole point of addressing them by key. */
    public function test_each_source_answers_under_its_own_key(): void
    {
        $registry = app(OpportunitySourceRegistry::class);

        $this->assertSame('doffin', $registry->get('doffin')->sourceKey());
        $this->assertSame('ted', $registry->get('ted')->sourceKey());
        $this->assertNotSame($registry->get('doffin'), $registry->get('ted'));
    }

    /**
     * The binding that made "the adapter" mean Doffin is gone. Resolving the interface from the
     * container is now an error rather than a quiet answer — which is the point: a consumer that
     * still expects one global source will say so loudly instead of working by accident.
     */
    public function test_the_interface_is_no_longer_a_global_default(): void
    {
        $this->expectException(BindingResolutionException::class);

        app(OpportunitySourceAdapter::class);
    }

    public function test_an_unknown_source_is_refused_rather_than_answered_with_a_registered_one(): void
    {
        $registry = app(OpportunitySourceRegistry::class);

        $this->expectException(UnknownOpportunitySourceException::class);

        // A register Procynia does not have. 'ted' used to serve as the example here and no longer
        // can, which is the most concrete evidence this phase changed anything.
        $registry->get('eu-funding-and-tenders');
    }

    /** The registry is one object, so an adapter's state is not rebuilt per consumer. */
    public function test_the_registry_is_shared(): void
    {
        $this->assertSame(app(OpportunitySourceRegistry::class), app(OpportunitySourceRegistry::class));
    }

    // ------------------------------------------------------------- orchestration

    /**
     * Every registered source with a worker runs, once, in one sweep.
     *
     * This asserted a single run and a TED reported as having no worker. TED has one now, which is
     * the whole of phase 4C2 in one assertion — and the nightly sweep covering a second register
     * is a change to what the coordinator does, not merely to what it reports.
     */
    public function test_discovery_runs_once_per_registered_source(): void
    {
        $doffin = Mockery::mock(DoffinWatchProfileInboxDiscoveryService::class);
        $doffin->shouldReceive('run')
            ->once()
            ->with(null, 'scheduler')
            ->andReturn(['status' => 'success', 'profiles_processed' => 1]);

        $ted = Mockery::mock(TedWatchProfileInboxDiscoveryService::class);
        $ted->shouldReceive('run')
            ->once()
            ->with(null, 'scheduler')
            ->andReturn(['status' => 'success', 'profiles_processed' => 1]);

        $coordinator = new WatchProfileInboxDiscoveryCoordinator(
            app(OpportunitySourceRegistry::class),
            $doffin,
            $ted,
        );

        $result = $coordinator->run(null, 'scheduler');

        $this->assertSame(['doffin', 'ted'], $result['sources_run']);
        $this->assertSame(1, $result['runs']['doffin']['profiles_processed']);
        $this->assertSame(1, $result['runs']['ted']['profiles_processed']);
        $this->assertSame([], $result['sources_without_worker']);
    }

    public function test_the_watch_profile_id_and_trigger_reach_every_worker(): void
    {
        $doffin = Mockery::mock(DoffinWatchProfileInboxDiscoveryService::class);
        $doffin->shouldReceive('run')->once()->with(42, 'manual')->andReturn(['status' => 'success']);

        $ted = Mockery::mock(TedWatchProfileInboxDiscoveryService::class);
        $ted->shouldReceive('run')->once()->with(42, 'manual')->andReturn(['status' => 'success']);

        $coordinator = new WatchProfileInboxDiscoveryCoordinator(
            app(OpportunitySourceRegistry::class),
            $doffin,
            $ted,
        );

        $this->assertSame(['doffin', 'ted'], $coordinator->run(42, 'manual')['sources_run']);
    }

    /**
     * The reason the coordinator is worth its four lines: a registered source with no discovery
     * worker is reported. "TED discovery is not built" and "TED discovery ran and found nothing"
     * must not look the same from the outside.
     */
    public function test_a_registered_source_without_a_worker_is_reported_not_skipped(): void
    {
        $registry = new OpportunitySourceRegistry([
            app(DoffinSourceAdapter::class),
            $this->standInAdapter('some-register'),
        ]);

        $discovery = Mockery::mock(DoffinWatchProfileInboxDiscoveryService::class);
        $discovery->shouldReceive('run')->once()->andReturn(['status' => 'success']);

        $ted = Mockery::mock(TedWatchProfileInboxDiscoveryService::class);
        $ted->shouldReceive('run')->never();

        $result = (new WatchProfileInboxDiscoveryCoordinator($registry, $discovery, $ted))->run();

        $this->assertSame(['doffin'], $result['sources_run'], 'only the source with a worker runs');
        $this->assertSame(['some-register'], $result['sources_without_worker']);
        $this->assertArrayNotHasKey('some-register', $result['runs']);
    }

    private function standInAdapter(string $sourceKey): OpportunitySourceAdapter
    {
        $adapter = Mockery::mock(OpportunitySourceAdapter::class);
        $adapter->shouldReceive('sourceKey')->andReturn($sourceKey);

        return $adapter;
    }
}
