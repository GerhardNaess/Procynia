<?php

namespace Tests\Feature\Console;

use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

class EnterpriseWikiBootstrapGraphCommandTest extends TestCase
{
    public function test_it_creates_the_projection_schema_when_neo4j_is_enabled(): void
    {
        config(['services.neo4j.enabled' => true]);
        $writer = new RecordingGraphProjectionService;
        $this->app->instance(GraphProjectionService::class, $writer);

        $this->artisan('wiki:graph-bootstrap')->assertSuccessful();

        $this->assertSame(1, $writer->schemaBootstraps);
    }

    public function test_it_is_idempotent(): void
    {
        config(['services.neo4j.enabled' => true]);
        $writer = new RecordingGraphProjectionService;
        $this->app->instance(GraphProjectionService::class, $writer);

        $this->artisan('wiki:graph-bootstrap')->assertSuccessful();
        $this->artisan('wiki:graph-bootstrap')->assertSuccessful();

        $this->assertSame(2, $writer->schemaBootstraps);
    }

    public function test_it_reports_that_nothing_was_created_when_neo4j_is_disabled(): void
    {
        config(['services.neo4j.enabled' => false]);
        $writer = new RecordingGraphProjectionService;
        $this->app->instance(GraphProjectionService::class, $writer);

        // The null projection would make ensureSchema() a silent no-op, so the command must not
        // report a constraint it never created.
        $this->artisan('wiki:graph-bootstrap')
            ->expectsOutputToContain('No schema was created')
            ->assertSuccessful();

        $this->assertSame(0, $writer->schemaBootstraps);
    }
}
