<?php

namespace Tests\Feature\Azure;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Azure migration readiness — stateless runtime contract.
 *
 * Container Apps gives the application no .env file, no persistent local disk and no guarantee that
 * the replica serving the next request is the one that served the last. This class covers the three
 * consequences:
 *
 *   * Configuration and secrets must arrive entirely through environment variables.
 *   * Logging must work without a writable log directory.
 *   * Nothing may assume a local database host or a local filesystem for state.
 *
 * The .env-less boot is a real separate process booting a real Laravel application from a base path
 * that genuinely has no .env file — not an assertion about what config() returns in a test process
 * that does have one.
 */
class StatelessRuntimeContractTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Secrets / configuration from the environment only
    // -----------------------------------------------------------------------

    public function test_the_application_boots_with_no_env_file_when_environment_variables_are_supplied(): void
    {
        $result = $this->bootWithoutEnvFile([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:YXp1cmUtcmVhZGluZXNzLXRlc3Qta2V5LTAwMDAwMDA=',
            'APP_URL' => 'https://procynia-stg-web.example.azurecontainerapps.io',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'psql-procynia-staging.postgres.database.azure.com',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'procynia',
            'DB_USERNAME' => 'procynia_admin',
            'DB_PASSWORD' => 'not-a-real-password',
            'DB_SSLMODE' => 'require',
            'REDIS_URL' => 'tls://default:not-a-real-key@redis.example.net:10000/0',
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'SESSION_SECURE_COOKIE' => 'true',
            'QUEUE_CONNECTION' => 'redis',
            'FILESYSTEM_DISK' => 'local',
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'info',
            'OPENAI_API_KEY' => 'not-a-real-openai-key',
            'DOFFIN_API_KEY' => 'not-a-real-doffin-key',
            'DOFFIN_BASE_URL' => 'https://api.doffin.no',
            'PROCYNIA_HEALTH_TOKEN' => 'not-a-real-health-token',
        ]);

        $this->assertTrue($result['ok'], 'Boot without a .env file failed: '.($result['error'] ?? 'unknown'));
        $this->assertFalse($result['env_file_present'], 'The probe must boot from a base path with no .env file.');

        $this->assertSame('production', $result['environment']);
        $this->assertFalse($result['app_debug'], 'APP_DEBUG=false must be honoured.');
        $this->assertTrue($result['app_key_present'], 'APP_KEY must resolve from the environment.');
        $this->assertTrue($result['app_key_matches_env'], 'APP_KEY must come from the environment, not from a file.');
        $this->assertStringStartsWith('https://', $result['app_url'], 'APP_URL must be HTTPS in Azure.');

        $this->assertSame('psql-procynia-staging.postgres.database.azure.com', $result['db_host']);
        $this->assertSame('require', $result['db_sslmode'], 'DB_SSLMODE must reach the pgsql connection config.');

        $this->assertSame('redis', $result['cache_store']);
        $this->assertSame('redis', $result['session_driver']);
        $this->assertSame('redis', $result['queue_connection']);
        $this->assertTrue((bool) $result['session_secure_cookie'], 'SESSION_SECURE_COOKIE must be honoured.');

        $this->assertSame('stderr', $result['log_default']);
        $this->assertSame('info', $result['log_level']);

        $this->assertTrue($result['openai_key_present'], 'OPENAI_API_KEY must resolve from the environment.');
        $this->assertSame('https://api.doffin.no', $result['doffin_base_url']);
    }

    /**
     * A missing APP_KEY must fail loudly, not be silently regenerated. A regenerated key in Azure
     * would invalidate every session and every encrypted column on every restart, differently on
     * every replica.
     */
    public function test_app_key_is_never_generated_at_runtime(): void
    {
        foreach (['app/Providers', 'app/Http', 'app/Services', 'app/Console'] as $directory) {
            $matches = [];
            exec(
                sprintf('grep -rn %s %s 2>/dev/null', escapeshellarg('key:generate'), escapeshellarg(base_path($directory))),
                $matches,
            );

            $this->assertSame(
                [],
                $matches,
                sprintf('%s must never invoke key:generate at runtime: %s', $directory, implode("\n", $matches)),
            );
        }

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach (['post-autoload-dump', 'post-update-cmd'] as $hook) {
            $commands = (array) ($composer['scripts'][$hook] ?? []);

            foreach ($commands as $command) {
                $this->assertStringNotContainsString(
                    'key:generate',
                    (string) $command,
                    sprintf(
                        'composer "%s" must not run key:generate — it executes during image build and '
                        .'would bake a throwaway key into the image.',
                        $hook,
                    ),
                );
            }
        }
    }

    /**
     * The image must not ship a .env, or a stale local value would silently win over a Key Vault
     * secret for any variable the Container App does not set.
     */
    public function test_the_docker_build_context_excludes_the_env_file(): void
    {
        $dockerignore = array_map('trim', file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES));

        $this->assertContains(
            '.env',
            $dockerignore,
            '.dockerignore must exclude .env so no environment file is baked into the image.',
        );
    }

    // -----------------------------------------------------------------------
    // Logging without a persistent disk
    // -----------------------------------------------------------------------

    public function test_the_stderr_channel_writes_to_php_stderr(): void
    {
        $logger = Log::channel('stderr');
        $handlers = $logger->getLogger()->getHandlers();

        $this->assertNotEmpty($handlers, 'The stderr channel has no handlers.');

        $streamHandlers = array_values(array_filter(
            $handlers,
            static fn ($handler) => $handler instanceof StreamHandler,
        ));

        $this->assertNotEmpty($streamHandlers, 'The stderr channel must use a StreamHandler.');
        $this->assertSame(
            'php://stderr',
            $streamHandlers[0]->getUrl(),
            'The stderr channel must write to php://stderr so Container Apps can collect it.',
        );
    }

    /**
     * A real write through the stderr channel must actually reach STDERR, not a file.
     */
    public function test_a_log_line_really_reaches_stderr(): void
    {
        $marker = 'azure-readiness-stderr-probe-'.bin2hex(random_bytes(4));

        $script = <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Log::channel('stderr')->error($argv[2]);
        PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'procynia-stderr-probe-');
        file_put_contents($scriptPath, $script);

        try {
            $process = new Process([PHP_BINARY, $scriptPath, base_path(), $marker], base_path(), null, null, 60);
            $process->run();

            $this->assertStringContainsString(
                $marker,
                $process->getErrorOutput(),
                'A log line written to the stderr channel must appear on the process STDERR stream. '
                .'stdout was: '.$process->getOutput(),
            );
            $this->assertStringNotContainsString(
                $marker,
                $process->getOutput(),
                'The stderr channel must not write to stdout.',
            );
        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Nothing in the application may read storage/logs/laravel.log. If it did, that file would
     * become state the container has to keep, and it would differ per replica.
     */
    public function test_no_application_code_reads_the_laravel_log_file(): void
    {
        $matches = [];
        exec(
            sprintf(
                'grep -rn %s %s %s 2>/dev/null',
                escapeshellarg('laravel.log'),
                escapeshellarg(base_path('app')),
                escapeshellarg(base_path('routes')),
            ),
            $matches,
        );

        $this->assertSame(
            [],
            $matches,
            'Application code must not depend on the local log file: '.implode("\n", $matches),
        );
    }

    // -----------------------------------------------------------------------
    // No local-host assumptions
    // -----------------------------------------------------------------------

    /**
     * Runtime code must never hardcode a database or Redis host. In Azure these are external
     * managed services whose hostnames are only known at deployment time.
     */
    public function test_no_runtime_code_hardcodes_a_database_or_redis_host(): void
    {
        $forbidden = [
            "'localhost'",
            "'127.0.0.1'",
            "'postgres'",
            "'redis'",
        ];

        $sourceFiles = [];
        exec(sprintf('find %s -name "*.php"', escapeshellarg(base_path('app'))), $sourceFiles);

        $this->assertNotEmpty($sourceFiles, 'Found no application source files to scan.');

        $offenders = [];

        foreach ($sourceFiles as $file) {
            $source = file_get_contents($file);

            foreach ($forbidden as $needle) {
                if (! str_contains($source, $needle)) {
                    continue;
                }

                // Only flag it when it sits next to a host/connection assignment.
                if (preg_match('/(host|HOST|DB_HOST|REDIS_HOST)[^\n]{0,40}'.preg_quote($needle, '/').'/', $source) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file).' → '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Runtime code must read hosts from config/env, never hardcode them: '.implode(', ', $offenders),
        );
    }

    /**
     * DB_SSLMODE must be a real, plumbed-through option rather than documentation.
     */
    public function test_db_sslmode_is_wired_into_the_postgres_connection(): void
    {
        $this->assertStringContainsString(
            "'sslmode' => env('DB_SSLMODE'",
            file_get_contents(config_path('database.php')),
            'config/database.php must read DB_SSLMODE for the pgsql connection.',
        );

        $this->assertMatchesRegularExpression(
            "/name: 'DB_SSLMODE'\s*\n\s*value: 'require'/",
            file_get_contents(base_path('infra/main.bicep')),
            'The Azure environment contract must set DB_SSLMODE=require.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function bootWithoutEnvFile(array $environment): array
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Azure/support/boot-without-env-file.php')],
            base_path(),
            $environment,
            null,
            120,
        );

        $process->run();

        $output = trim($process->getOutput());

        $this->assertNotSame(
            '',
            $output,
            'The boot probe produced no output. stderr: '.$process->getErrorOutput(),
        );

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'The boot probe did not return JSON. Output: '.$output);

        return $decoded;
    }
}
