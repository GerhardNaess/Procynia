<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\RunBackup;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Services\Operations\BackupService;
use App\Services\Operations\LegacyBackupProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The runtime guard that keeps the legacy Compose backup out of Azure.
 *
 * procynia:backup ends in scripts/backup-production.sh, which runs
 * `docker compose exec -T postgres pg_dump`. Azure Container Apps has no Docker CLI and no Compose
 * project, so the mechanism has to be off there — while staying completely unchanged in the Compose
 * deployments that can still run it.
 *
 * Two separate flags are involved, and conflating them is the bug this guards against:
 *
 *   backup_settings.backup_enabled          "has an operator switched backup on?"   (database)
 *   procynia.backup.legacy_enabled          "can this runtime run the mechanism?"   (config)
 *
 * The database flag is not sufficient: a database migrated to Azure can arrive with
 * backup_enabled = true, and BackupService::runBackup() only honours that flag for scheduled runs —
 * a manual run from the admin panel bypassed it entirely.
 *
 * Nothing here executes pg_dump or docker compose. The only thing replaced is
 * LegacyBackupProcessRunner, the single class that starts the script, swapped for a spy so the tests
 * can assert whether it would have been invoked. All of the decision logic — config, database flag,
 * command, scheduler — is the real implementation.
 */
class LegacyBackupRuntimeGuardTest extends TestCase
{
    use RefreshDatabase;

    private LegacyBackupProcessRunnerSpy $runner;

    protected function setUp(): void
    {
        parent::setUp();

        // The one boundary that is faked: the class that would start the shell script.
        $this->runner = new LegacyBackupProcessRunnerSpy;
        $this->app->instance(LegacyBackupProcessRunner::class, $this->runner);
    }

    // -----------------------------------------------------------------------
    // 1. Legacy disabled + backup_enabled = true  →  script must not start
    // -----------------------------------------------------------------------

    /**
     * The migrated-database scenario, and the whole point of this fix.
     */
    public function test_a_migrated_database_with_backup_enabled_cannot_start_the_compose_script(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);
        BackupSetting::create(['backup_enabled' => true]);

        $run = $this->service()->runBackup(BackupRun::TYPE_SCHEDULED);

        $this->assertFalse(
            $this->runner->wasInvoked,
            'The Compose backup script must never be started when the runtime does not support it, '
            .'even though backup_enabled is true in the database.',
        );
        $this->assertSame(BackupRun::STATUS_SKIPPED, $run->status);
        $this->assertStringContainsString('Legacy Compose backup is disabled', (string) $run->error_message);
    }

    /**
     * A manual run is the path that the database flag never protected: runBackup() only short-circuits
     * on backup_enabled for scheduled runs.
     */
    public function test_a_manual_run_cannot_start_the_compose_script_when_the_runtime_is_unsupported(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);
        BackupSetting::create(['backup_enabled' => true]);

        $run = $this->service()->runBackup(BackupRun::TYPE_MANUAL);

        $this->assertFalse($this->runner->wasInvoked, 'A manual backup must also be blocked by the runtime guard.');
        $this->assertSame(BackupRun::STATUS_SKIPPED, $run->status);
    }

    /**
     * Proof that the manual path really was unprotected before, so this test is guarding a live hole
     * rather than a hypothetical one: with the runtime supported, a manual run reaches the script
     * even though backup_enabled is false.
     */
    public function test_the_database_flag_alone_never_protected_manual_runs(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);
        BackupSetting::create(['backup_enabled' => false]);

        $this->service()->runBackup(BackupRun::TYPE_MANUAL);

        $this->assertTrue(
            $this->runner->wasInvoked,
            'This documents existing behaviour: backup_enabled = false does not stop a manual run. '
            .'That is exactly why the runtime guard cannot be expressed through the database flag.',
        );
    }

    // -----------------------------------------------------------------------
    // 2. Legacy disabled + backup_enabled = false  →  nothing runs
    // -----------------------------------------------------------------------

    public function test_nothing_runs_when_both_the_runtime_guard_and_the_database_flag_are_off(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);
        BackupSetting::create(['backup_enabled' => false]);

        $run = $this->service()->runBackup(BackupRun::TYPE_SCHEDULED);

        $this->assertFalse($this->runner->wasInvoked);
        $this->assertSame(BackupRun::STATUS_SKIPPED, $run->status);
    }

    // -----------------------------------------------------------------------
    // 3. Legacy enabled + backup_enabled = true  →  existing behaviour intact
    // -----------------------------------------------------------------------

    /**
     * The regression guard. Existing Compose deployments must be completely unaffected.
     */
    public function test_the_existing_backup_flow_still_reaches_the_script_when_the_runtime_supports_it(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);
        BackupSetting::create(['backup_enabled' => true]);

        $run = $this->service()->runBackup(BackupRun::TYPE_SCHEDULED);

        $this->assertTrue(
            $this->runner->wasInvoked,
            'Existing Compose environments must keep their current backup behaviour.',
        );
        $this->assertStringEndsWith('scripts/backup-production.sh', (string) $this->runner->scriptPath);
        $this->assertNotSame(BackupRun::STATUS_SKIPPED, $run->status);
    }

    /**
     * The pre-existing database-flag semantics must survive untouched: a scheduled run with
     * backup_enabled = false is still skipped, for the original reason.
     */
    public function test_the_database_flag_still_skips_scheduled_runs_when_the_runtime_is_supported(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);
        BackupSetting::create(['backup_enabled' => false]);

        $run = $this->service()->runBackup(BackupRun::TYPE_SCHEDULED);

        $this->assertFalse($this->runner->wasInvoked);
        $this->assertSame(BackupRun::STATUS_SKIPPED, $run->status);
        $this->assertNull(
            $run->error_message,
            'A run skipped by the database flag must not be relabelled with the runtime-guard reason.',
        );
    }

    // -----------------------------------------------------------------------
    // 4. Manual artisan invocation
    // -----------------------------------------------------------------------

    public function test_running_the_artisan_command_by_hand_does_not_start_the_script(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);
        BackupSetting::create(['backup_enabled' => true]);

        $exitCode = Artisan::call('procynia:backup');
        $output = Artisan::output();

        $this->assertFalse(
            $this->runner->wasInvoked,
            'php artisan procynia:backup must not reach the Compose script in an unsupported runtime.',
        );
        $this->assertSame(
            RunBackup::SUCCESS,
            $exitCode,
            'Skipping is a controlled outcome, not a system failure, so the exit code must stay 0.',
        );
        $this->assertStringContainsString('Legacy Compose backup is disabled', $output);
        $this->assertStringContainsString('point-in-time restore', $output);
    }

    public function test_the_artisan_command_still_backs_up_when_the_runtime_supports_it(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);
        BackupSetting::create(['backup_enabled' => true]);

        Artisan::call('procynia:backup');

        $this->assertTrue($this->runner->wasInvoked, 'Existing Compose behaviour must be unchanged.');
    }

    // -----------------------------------------------------------------------
    // 5. Scheduler registration
    // -----------------------------------------------------------------------

    /**
     * Defence in depth: with the runtime unsupported the command is not even scheduled, so the hourly
     * trigger disappears rather than firing into a guard every hour.
     *
     * Both cases boot a separate process with the real environment variable set, because
     * routes/console.php is only evaluated once per boot. That makes this a test of the whole chain —
     * PROCYNIA_LEGACY_BACKUP_ENABLED → config → scheduler — not just of the config key.
     */
    public function test_the_scheduler_does_not_register_the_backup_command_when_the_runtime_is_unsupported(): void
    {
        $result = $this->scheduledCommands('false');

        $this->assertFalse($result['legacy_enabled'], 'The environment variable must reach the config.');
        $this->assertNotContains(
            'procynia:backup',
            $result['commands'],
            'The hourly backup must not be scheduled where the runtime cannot run it.',
        );

        // The rest of the schedule must be untouched.
        $this->assertContains('ops:scheduler-heartbeat', $result['commands']);
        $this->assertContains('wiki:maintenance-cycle', $result['commands']);
    }

    public function test_the_scheduler_still_registers_the_backup_command_in_a_supported_runtime(): void
    {
        $result = $this->scheduledCommands('true');

        $this->assertTrue($result['legacy_enabled']);
        $this->assertContains(
            'procynia:backup',
            $result['commands'],
            'Existing Compose deployments must keep the hourly backup schedule.',
        );
    }

    // -----------------------------------------------------------------------
    // 6. Operator-facing status
    // -----------------------------------------------------------------------

    /**
     * A migrated database in Azure will have backup_enabled = true and no recent successful backup.
     * The status page must explain that calmly instead of raising "overdue" and "no heartbeat".
     */
    public function test_the_status_page_explains_the_disabled_runtime_instead_of_raising_false_alarms(): void
    {
        Config::set('procynia.backup.legacy_enabled', false);

        $setting = BackupSetting::create(['backup_enabled' => true]);
        $setting->last_scheduler_heartbeat_at = now()->subDays(3);
        $setting->save();

        $status = $this->service()->evaluateStatus();

        $this->assertSame(['legacy_backup_disabled'], $status['warnings']);
        $this->assertFalse($status['legacy_backup_supported']);
        $this->assertNotContains('backup_overdue', $status['warnings']);
        $this->assertNotContains('no_scheduler_heartbeat', $status['warnings']);
    }

    public function test_the_status_page_keeps_its_existing_warnings_in_a_supported_runtime(): void
    {
        Config::set('procynia.backup.legacy_enabled', true);

        $setting = BackupSetting::create(['backup_enabled' => true]);
        $setting->last_scheduler_heartbeat_at = now()->subDays(3);
        $setting->save();

        $status = $this->service()->evaluateStatus();

        $this->assertTrue($status['legacy_backup_supported']);
        $this->assertContains('no_scheduler_heartbeat', $status['warnings']);
        $this->assertContains('backup_overdue', $status['warnings']);
    }

    // -----------------------------------------------------------------------
    // Config contract
    // -----------------------------------------------------------------------

    /**
     * The default has to be "enabled": an unset value means an existing Compose deployment, and
     * silently stopping its backups would be a worse outcome than requiring Azure to be explicit.
     */
    public function test_the_config_default_preserves_existing_compose_behaviour(): void
    {
        $config = require base_path('config/procynia.php');

        $this->assertTrue(
            $config['backup']['legacy_enabled'],
            'With PROCYNIA_LEGACY_BACKUP_ENABLED unset the legacy mechanism must stay enabled, so no '
            .'existing deployment loses its backups by upgrading.',
        );
    }

    /**
     * The value arrives from Container Apps as the string "false". A plain (bool) cast would turn
     * that into true, which is precisely the failure this whole change exists to prevent.
     */
    #[DataProvider('falseyEnvironmentValues')]
    public function test_falsey_environment_values_really_disable_the_legacy_mechanism(string $value): void
    {
        $original = getenv('PROCYNIA_LEGACY_BACKUP_ENABLED');
        putenv("PROCYNIA_LEGACY_BACKUP_ENABLED={$value}");
        $_ENV['PROCYNIA_LEGACY_BACKUP_ENABLED'] = $value;

        try {
            $config = require base_path('config/procynia.php');

            $this->assertFalse(
                $config['backup']['legacy_enabled'],
                sprintf('PROCYNIA_LEGACY_BACKUP_ENABLED="%s" must disable the legacy mechanism.', $value),
            );
        } finally {
            if ($original === false) {
                putenv('PROCYNIA_LEGACY_BACKUP_ENABLED');
                unset($_ENV['PROCYNIA_LEGACY_BACKUP_ENABLED']);
            } else {
                putenv("PROCYNIA_LEGACY_BACKUP_ENABLED={$original}");
                $_ENV['PROCYNIA_LEGACY_BACKUP_ENABLED'] = $original;
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function falseyEnvironmentValues(): array
    {
        return [
            'false' => ['false'],
            'zero' => ['0'],
            'off' => ['off'],
            'no' => ['no'],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function service(): BackupService
    {
        return new BackupService($this->runner);
    }

    /**
     * Boot a fresh process with the given PROCYNIA_LEGACY_BACKUP_ENABLED value and report what the
     * scheduler registered.
     *
     * @return array{ok: bool, legacy_enabled: bool, commands: list<string>}
     */
    private function scheduledCommands(string $legacyEnabled): array
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Operations/support/list-scheduled-commands.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'PROCYNIA_LEGACY_BACKUP_ENABLED' => $legacyEnabled,
                // Pinned to the test database purely so a future change to the probe can never reach
                // the development database. The probe itself opens no connection.
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'postgres',
                'DB_DATABASE' => 'procynia_test',
            ],
            null,
            120,
        );

        $process->run();

        $output = trim($process->getOutput());

        $this->assertNotSame(
            '',
            $output,
            'The scheduler probe produced no output. stderr: '.$process->getErrorOutput(),
        );

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'The scheduler probe did not return JSON. Output: '.$output);
        $this->assertTrue($decoded['ok'], 'The scheduler probe failed: '.($decoded['error'] ?? 'unknown'));

        // The command strings include the full artisan invocation; reduce them to bare names.
        $decoded['commands'] = array_map(
            static function (string $command): string {
                if (preg_match("/'([a-z0-9:_-]+)'\\s*$/i", trim($command), $m) === 1) {
                    return $m[1];
                }

                $parts = preg_split('/\\s+/', trim($command)) ?: [];

                return trim((string) end($parts), "'\"");
            },
            $decoded['commands'],
        );

        return $decoded;
    }
}

/**
 * Records whether the legacy backup script would have been started, and never starts it.
 */
class LegacyBackupProcessRunnerSpy extends LegacyBackupProcessRunner
{
    public bool $wasInvoked = false;

    public ?string $scriptPath = null;

    public ?string $directory = null;

    public function run(string $scriptPath, string $directory, string $workingDirectory, int $timeoutSeconds = 600): Process
    {
        $this->wasInvoked = true;
        $this->scriptPath = $scriptPath;
        $this->directory = $directory;

        // A successful no-op process, so the surrounding success path stays exercised without any
        // pg_dump, tar or docker compose ever running.
        $process = new Process(['true']);
        $process->run();

        return $process;
    }
}
