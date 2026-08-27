<?php

namespace Tests\Feature\Azure;

use App\Services\Operations\RuntimePreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The preflight check that gates a staging sign-off.
 *
 * `php artisan ops:runtime-check --azure` is meant to be run inside a container before an
 * environment is accepted. These tests cover the two properties that make it worth trusting:
 *
 *   * it actually fails when a precondition is broken, rather than reporting green because a check
 *     threw and was swallowed
 *   * it never prints a secret
 *
 * The checks themselves run against the real test database, the real Redis and the real poppler
 * binaries in this container — nothing is mocked, because a preflight that only inspects config
 * would not detect the failures it exists to catch.
 */
class RuntimePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_preflight_passes_in_a_correctly_configured_runtime(): void
    {
        $service = app(RuntimePreflightService::class);
        $checks = $service->run(azure: false);

        $failures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === RuntimePreflightService::STATUS_FAIL,
        ));

        $this->assertSame(
            [],
            array_map(static fn (array $c): string => $c['name'].': '.$c['detail'], $failures),
            'The development container should satisfy every critical precondition.',
        );
        $this->assertFalse($service->hasCriticalFailure($checks));
    }

    public function test_the_preflight_covers_every_area_the_runtime_contract_names(): void
    {
        $names = array_map(
            static fn (array $check): string => $check['name'],
            app(RuntimePreflightService::class)->run(),
        );

        foreach ([
            'APP_KEY',
            'APP_ENV / APP_DEBUG',
            'PHP extensions',
            'PostgreSQL',
            'pgvector',
            'Redis',
            'Storage disk',
            'Shared storage path',
            'pdftotext',
            'Logging',
            'Legacy backup',
            'Queue connection',
        ] as $expected) {
            $this->assertContains($expected, $names, sprintf('The preflight must check [%s].', $expected));
        }
    }

    /**
     * The Azure profile is the whole reason the --azure flag exists: legacy Compose backup enabled is
     * a critical failure there and a normal state everywhere else.
     */
    public function test_the_azure_profile_fails_when_the_legacy_backup_is_still_enabled(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);

        $service = app(RuntimePreflightService::class);

        $withoutAzure = $this->checkNamed($service->run(azure: false), 'Legacy backup');
        $withAzure = $this->checkNamed($service->run(azure: true), 'Legacy backup');

        $this->assertSame(RuntimePreflightService::STATUS_PASS, $withoutAzure['status']);
        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $withAzure['status']);
        $this->assertTrue($withAzure['critical'], 'A legacy backup left enabled in Azure must be critical.');
    }

    public function test_the_azure_profile_passes_when_the_legacy_backup_is_disabled(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);

        $check = $this->checkNamed(app(RuntimePreflightService::class)->run(azure: true), 'Legacy backup');

        $this->assertSame(RuntimePreflightService::STATUS_PASS, $check['status']);
    }

    /**
     * A broken precondition must be reported as broken. Without this, the preflight could report
     * green simply because nothing it checks is reachable.
     */
    public function test_a_missing_app_key_is_reported_as_a_critical_failure(): void
    {
        Config::set('app.key', '');

        $service = app(RuntimePreflightService::class);
        $checks = $service->run();

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $this->checkNamed($checks, 'APP_KEY')['status']);
        $this->assertTrue($service->hasCriticalFailure($checks));
    }

    public function test_debug_mode_in_a_production_environment_is_a_critical_failure(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.debug', true);

        $check = $this->checkNamed(app(RuntimePreflightService::class)->run(), 'APP_ENV / APP_DEBUG');

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
    }

    public function test_a_missing_poppler_binary_is_reported_as_a_critical_failure(): void
    {
        Config::set('services.pdftotext.binary', '/nonexistent/pdftotext');

        $check = $this->checkNamed(app(RuntimePreflightService::class)->run(), 'pdftotext');

        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);
    }

    /**
     * Azure Managed Redis exposes one logical database, so a cache/queue split has to surface.
     */
    public function test_a_split_redis_database_is_flagged(): void
    {
        Config::set('database.redis.default.database', 0);
        Config::set('database.redis.cache.database', 1);

        $check = $this->checkNamed(app(RuntimePreflightService::class)->run(), 'Redis');

        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertStringContainsString('only database 0', $check['detail']);
    }

    /**
     * The storage check writes a probe file. It must leave nothing behind.
     */
    public function test_the_storage_check_cleans_up_after_itself(): void
    {
        $before = Storage::disk('local')->allFiles('runtime-check');

        app(RuntimePreflightService::class)->run();

        $after = Storage::disk('local')->allFiles('runtime-check');

        $this->assertSame($before, $after, 'The preflight must not leave probe files on the storage disk.');
    }

    // -----------------------------------------------------------------------
    // Command
    // -----------------------------------------------------------------------

    public function test_the_command_exits_non_zero_when_a_critical_precondition_fails(): void
    {
        Config::set('app.key', '');

        $this->assertSame(1, Artisan::call('ops:runtime-check'));
        $this->assertStringContainsString('FAIL', Artisan::output());
    }

    public function test_the_command_exits_zero_in_a_healthy_runtime(): void
    {
        $this->assertSame(0, Artisan::call('ops:runtime-check'));

        $output = Artisan::output();
        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('All critical preconditions satisfied', $output);
    }

    /**
     * Nothing sensitive may reach the terminal or a log. The APP_KEY check reports presence and size;
     * the database and Redis checks report names and shapes.
     */
    public function test_the_command_never_prints_a_secret_value(): void
    {
        $appKey = (string) config('app.key');
        $dbPassword = (string) config('database.connections.pgsql.password');

        Artisan::call('ops:runtime-check');
        $output = Artisan::output();

        $this->assertNotSame('', $appKey, 'The test needs a configured APP_KEY to be meaningful.');
        $this->assertStringNotContainsString($appKey, $output, 'APP_KEY must never be printed.');
        $this->assertStringNotContainsString(substr($appKey, 7), $output, 'The APP_KEY material must never be printed.');

        if ($dbPassword !== '') {
            $this->assertStringNotContainsString($dbPassword, $output, 'The database password must never be printed.');
        }
    }

    public function test_the_command_can_emit_machine_readable_json(): void
    {
        Artisan::call('ops:runtime-check', ['--json' => true]);

        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('ok', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertNotEmpty($decoded['checks']);
    }

    /**
     * OpenAI connectivity costs an API request, so it must never run unless explicitly asked for.
     */
    public function test_openai_connectivity_is_skipped_unless_requested(): void
    {
        $check = $this->checkNamed(app(RuntimePreflightService::class)->run(), 'OpenAI connectivity');

        $this->assertSame(RuntimePreflightService::STATUS_SKIP, $check['status']);
        $this->assertStringContainsString('--with-openai', $check['detail']);
    }

    /**
     * @param  list<array{name: string, status: string, detail: string, critical: bool}>  $checks
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkNamed(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        $this->fail(sprintf('No preflight check named [%s].', $name));
    }
}
