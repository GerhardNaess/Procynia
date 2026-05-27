<?php

namespace Tests\Feature\Console;

use App\Models\DoffinImportRun;
use App\Services\Doffin\DoffinBatchImportService;
use App\Services\Doffin\DoffinImportService;
use App\Services\Doffin\DoffinNoticePipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DoffinBatchImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doffin.base_url' => 'https://betaapi.doffin.no',
            'doffin.search_endpoint' => '/public/v2/search',
            'doffin.connect_timeout' => 1,
            'doffin.batch_search.retry_backoff_ms' => [0, 0],
            'doffin.api_key' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_completes_successfully_when_search_and_import_succeed(): void
    {
        Http::fake([
            'https://betaapi.doffin.no/public/v2/search' => Http::response([
                'hits' => [
                    ['notice_id' => '2026-100001'],
                ],
            ], 200),
        ]);

        $importService = Mockery::mock(DoffinImportService::class)->makePartial();
        $importService->shouldReceive('importNoticeById')
            ->once()
            ->with('2026-100001')
            ->andReturn([
                'notice_id' => '2026-100001',
                'operation' => 'created',
                'created' => true,
                'updated' => false,
                'xml_stored' => true,
            ]);

        $pipelineService = Mockery::mock(DoffinNoticePipelineService::class)->makePartial();
        $pipelineService->shouldReceive('process')
            ->once()
            ->with('2026-100001')
            ->andReturn([
                'relevance_score' => 12,
                'relevance_level' => 'medium',
            ]);

        $this->app->instance(DoffinImportService::class, $importService);
        $this->app->instance(DoffinNoticePipelineService::class, $pipelineService);

        $exitCode = Artisan::call('doffin:import-batch', [
            '--limit' => 1,
            '--trigger' => 'manual',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Doffin batch import completed.', $output);
        $this->assertStringContainsString('success_count: 1', $output);

        $this->assertDatabaseHas('doffin_import_runs', [
            'status' => 'success',
            'fetched_count' => 1,
            'created_count' => 1,
            'updated_count' => 0,
            'failed_count' => 0,
        ]);

        Http::assertSentCount(1);
    }

    public function test_service_marks_run_failed_after_transient_search_failures_and_logs_warning(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Failed to connect to betaapi.doffin.no port 443 after 10001 ms: Timeout was reached.');
        });

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context): bool {
                return $level === 'warning'
                    && $message === 'Doffin batch import failed before completion due to a transient transport error.'
                    && ($context['transient_transport_failure'] ?? false) === true
                    && ($context['exception_class'] ?? null) === ConnectionException::class;
            });

        try {
            app(DoffinBatchImportService::class)->importBatch(1, null, 'manual');

            $this->fail('The Doffin batch import should have failed after exhausting retries.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Doffin batch import failed:', $exception->getMessage());
        }

        $this->assertSame(3, $attempts);

        $run = DoffinImportRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('cURL error 28', (string) $run->error_message);
        $this->assertSame(0, (int) $run->fetched_count);
        $this->assertSame(0, (int) $run->created_count);
        $this->assertSame(0, (int) $run->updated_count);
        $this->assertSame(0, (int) $run->failed_count);
    }
}
