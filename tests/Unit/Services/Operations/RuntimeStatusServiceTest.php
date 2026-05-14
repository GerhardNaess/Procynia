<?php

namespace Tests\Unit\Services\Operations;

use App\Services\Operations\RuntimeStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RuntimeStatusServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_snapshot_compiles_runtime_data_and_scheduler_tasks(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-05-13 17:15:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 17:15:00', 'UTC'));

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'app.url' => 'http://localhost',
        ]);

        $pdo = Mockery::mock(\PDO::class);
        $databaseConnection = Mockery::mock(Connection::class);
        $databaseConnection->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdo);

        DB::shouldReceive('getDefaultConnection')
            ->once()
            ->andReturn('pgsql');

        DB::shouldReceive('connection')
            ->once()
            ->andReturn($databaseConnection);

        $builder = Mockery::mock(Builder::class);
        DB::shouldReceive('table')
            ->once()
            ->with('failed_jobs')
            ->andReturn($builder);
        $builder->shouldReceive('count')
            ->once()
            ->andReturn(4);

        $redisConnection = Mockery::mock();
        $redisConnection->shouldReceive('ping')
            ->once()
            ->andReturn('PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($redisConnection);

        Artisan::shouldReceive('call')
            ->once()
            ->with('schedule:list', ['--json' => true, '--next' => true])
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn(json_encode([
                [
                    'expression' => '0 * * * *',
                    'command' => 'php artisan doffin:import-batch --trigger=scheduler',
                    'description' => null,
                    'next_due_date' => '2026-05-13 18:00:00 +00:00',
                    'next_due_date_human' => '44 minutes from now',
                    'timezone' => 'UTC',
                    'has_mutex' => false,
                    'repeat_seconds' => null,
                ],
            ]));

        $snapshot = app(RuntimeStatusService::class)->snapshot();

        $this->assertSame('testing', $snapshot['app_env']);
        $this->assertIsBool($snapshot['app_debug']);
        $this->assertSame(PHP_VERSION, $snapshot['php_version']);
        $this->assertSame('redis', $snapshot['queue']['driver']);
        $this->assertSame('default', $snapshot['queue']['queue']);
        $this->assertSame(4, $snapshot['failed_jobs_count']);
        $this->assertSame('Connected', $snapshot['database']['status_label']);
        $this->assertSame('Connected', $snapshot['redis']['status_label']);
        $this->assertSame('Configured', $snapshot['scheduler']['status_label']);
        $this->assertSame(1, $snapshot['scheduler']['task_count']);
        $this->assertNotEmpty($snapshot['generated_at']);
        $this->assertSame('2026-05-13T17:00:00+00:00', $snapshot['scheduler']['tasks'][0]['previous_run_at_iso']);
        $this->assertSame('2026-05-13T18:00:00+00:00', $snapshot['scheduler']['tasks'][0]['next_run_at_iso']);
        $this->assertEquals(3600, $snapshot['scheduler']['tasks'][0]['cycle_duration_seconds']);
        $this->assertEqualsWithDelta(0.25, (float) $snapshot['scheduler']['tasks'][0]['progress_ratio'], 0.01);
        $this->assertSame('om 45 minutter', $snapshot['scheduler']['tasks'][0]['next_run_at_human']);
        $this->assertSame('php artisan doffin:import-batch --trigger=scheduler', $snapshot['scheduler']['tasks'][0]['command']);
    }
}
