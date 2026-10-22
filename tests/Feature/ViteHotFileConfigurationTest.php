<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Tests\TestCase;

/**
 * config('app.vite_hot_file') — the isolated E2E environment's switch to the built assets
 * (docs/testing/e2e-environment.md). Unset, Laravel keeps public/hot, so development and production
 * are unchanged.
 */
class ViteHotFileConfigurationTest extends TestCase
{
    public function test_unset_keeps_public_hot(): void
    {
        $this->assertNull(config('app.vite_hot_file'));
        $this->assertSame(public_path('hot'), app(Vite::class)->hotFile());
    }

    public function test_set_points_vite_at_the_configured_file(): void
    {
        config(['app.vite_hot_file' => storage_path('framework/no-vite-dev-server')]);
        app()->forgetInstance(Vite::class);
        ViteFacade::clearResolvedInstance(Vite::class);

        (new AppServiceProvider(app()))->boot();

        $this->assertSame(storage_path('framework/no-vite-dev-server'), app(Vite::class)->hotFile());
        $this->assertFalse(app(Vite::class)->isRunningHot());
    }
}
