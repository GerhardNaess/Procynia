<?php

namespace Tests\Feature\Console;

use App\Models\DoffinImportSetting;
use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class DoffinWatchInboxDiscoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doffin.live_search_base_url' => 'https://api.doffin.no/webclient/api/v2/search-api',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_skips_scheduler_trigger_when_the_environment_flag_is_disabled(): void
    {
        config([
            'doffin.watch_inbox_discovery_enabled' => false,
            'doffin.api_key' => 'test-watch-key',
        ]);

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->never();
        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-inbox-discover', [
            '--trigger' => 'scheduler',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Doffin watch inbox discovery skipped.', $output);
        $this->assertStringContainsString('Miljøbryteren er av.', $output);
    }

    public function test_command_skips_scheduler_trigger_when_the_admin_toggle_is_disabled(): void
    {
        config([
            'doffin.watch_inbox_discovery_enabled' => true,
            'doffin.api_key' => 'test-watch-key',
        ]);

        DoffinImportSetting::query()->create([
            'scheduled_import_enabled' => false,
            'watch_inbox_discovery_enabled' => false,
        ]);

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->never();
        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-inbox-discover', [
            '--trigger' => 'scheduler',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Doffin watch inbox discovery skipped.', $output);
        $this->assertStringContainsString('Admin-bryteren er av.', $output);
    }

    public function test_command_skips_scheduler_trigger_when_the_api_key_is_missing(): void
    {
        config([
            'doffin.watch_inbox_discovery_enabled' => true,
            'doffin.api_key' => null,
        ]);

        DoffinImportSetting::query()->create([
            'scheduled_import_enabled' => false,
            'watch_inbox_discovery_enabled' => true,
        ]);

        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->never();
        $this->app->instance(DoffinLiveSearchService::class, $service);

        $exitCode = Artisan::call('doffin:watch-inbox-discover', [
            '--trigger' => 'scheduler',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Doffin watch inbox discovery skipped.', $output);
        $this->assertStringContainsString('Doffin API-konfigurasjonen er ufullstendig.', $output);
    }
}
