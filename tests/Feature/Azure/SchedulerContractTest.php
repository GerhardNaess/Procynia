<?php

namespace Tests\Feature\Azure;

use App\Models\BackupSetting;
use App\Services\Operations\BackupService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Azure migration readiness — scheduler contract.
 *
 * The scheduler is the one workload that must never run twice. Container Apps expresses that as
 * minReplicas = maxReplicas = 1, but the application side has to hold up its end too: anything
 * scheduled must be safe if a deployment briefly overlaps the old and new revision.
 *
 * It also carried the single clearest Azure blocker in the codebase. procynia:backup runs hourly and
 * ends up executing scripts/backup-production.sh, which requires the docker CLI and runs
 * `docker compose exec -T postgres pg_dump`. There is no docker CLI inside a Container App, and
 * there is no compose project to exec into. Azure-native PostgreSQL backup with point-in-time
 * restore replaces it.
 *
 * That blocker is now closed by an explicit runtime guard: config('procynia.backup.legacy_enabled'),
 * fed by PROCYNIA_LEGACY_BACKUP_ENABLED, which the Azure IaC sets to false in every environment. The
 * tests below verify the Azure side of that contract — that the environment really is configured to
 * disable it, and that the reason it had to exist is still true. The behavioural proof that the
 * script cannot start lives in Tests\Feature\Operations\LegacyBackupRuntimeGuardTest.
 */
class SchedulerContractTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Exactly one scheduler
    // -----------------------------------------------------------------------

    public function test_the_azure_scheduler_is_pinned_to_exactly_one_replica(): void
    {
        $module = file_get_contents(base_path('infra/modules/container-apps.bicep'));

        $schedulerSection = substr($module, (int) strpos($module, "resource scheduler 'Microsoft.App/containerApps"));

        $this->assertNotSame('', $schedulerSection, 'No scheduler Container App found in the IaC.');

        $this->assertMatchesRegularExpression(
            '/scale:\s*\{\s*minReplicas:\s*1\s*maxReplicas:\s*1\s*\}/',
            $schedulerSection,
            'The scheduler Container App must be pinned to exactly one replica. Two schedulers would '
            .'double every scheduled job, including the Doffin import.',
        );
    }

    public function test_the_scheduler_runs_the_laravel_scheduler_in_both_runtimes(): void
    {
        $this->assertStringContainsString(
            'php artisan schedule:work',
            file_get_contents(base_path('docker-compose.yml')),
            'docker-compose.yml must run the Laravel scheduler.',
        );

        $this->assertStringContainsString(
            'php artisan schedule:work',
            file_get_contents(base_path('infra/modules/container-apps.bicep')),
            'The Azure scheduler Container App must run the same Laravel scheduler command.',
        );
    }

    /**
     * Europe/Oslo has to survive the move: routes/console.php schedules an exchange-rate sync with
     * an explicit Oslo timezone, and the PHP image sets date.timezone to Oslo.
     */
    public function test_the_application_timezone_is_preserved_in_azure(): void
    {
        $this->assertStringContainsString(
            'Europe/Oslo',
            file_get_contents(base_path('docker/php/conf.d/local.ini')),
            'The PHP image must keep Europe/Oslo as date.timezone.',
        );

        $this->assertStringContainsString(
            'value: applicationTimezone',
            file_get_contents(base_path('infra/main.bicep')),
            'The Azure environment contract must set TZ from the applicationTimezone parameter.',
        );

        $this->assertStringContainsString(
            "param applicationTimezone string = 'Europe/Oslo'",
            file_get_contents(base_path('infra/main.bicep')),
            'The Azure applicationTimezone default must remain Europe/Oslo.',
        );
    }

    /**
     * Single-instance safety. Every scheduled *command* must use withoutOverlapping, so that a
     * revision changeover — where the old and new scheduler replica can briefly coexist — cannot
     * run the same command twice.
     *
     * The per-minute heartbeats are excluded: they are idempotent cache writes whose whole purpose
     * is to be cheap, and withoutOverlapping on a one-minute cadence would add a mutex round-trip
     * per queue per minute for no benefit.
     */
    public function test_every_scheduled_command_guards_against_overlapping_runs(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $unguarded = [];

        foreach ($schedule->events() as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $description = (string) ($event->description ?? $event->command ?? '');

            if ($description === '' || str_contains($description, 'heartbeat')) {
                continue;
            }

            if ($event->expression === '* * * * *') {
                continue;
            }

            if (! $event->withoutOverlapping) {
                $unguarded[] = $description;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These scheduled commands can overlap themselves, which is unsafe across an Azure revision '
            .'changeover: '.implode(', ', $unguarded),
        );
    }

    public function test_the_scheduler_has_at_least_the_expected_scheduled_work(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $this->assertNotEmpty(
            $schedule->events(),
            'The scheduler has no registered events, which would make the Azure scheduler pointless.',
        );

        $commands = implode(' ', array_map(
            static fn (Event $event) => (string) ($event->command ?? $event->description ?? ''),
            $schedule->events(),
        ));

        foreach (['ops:scheduler-heartbeat', 'wiki:maintenance-cycle', 'exchange-rates:sync'] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $commands,
                sprintf('The scheduler must still register [%s] after the migration.', $expected),
            );
        }
    }

    // -----------------------------------------------------------------------
    // procynia:backup — closed by the runtime guard
    // -----------------------------------------------------------------------

    /**
     * The concrete, verifiable reason procynia:backup cannot run in Azure.
     */
    public function test_the_backup_script_requires_a_docker_cli_that_azure_containers_do_not_have(): void
    {
        $script = file_get_contents(base_path('scripts/backup-production.sh'));

        $this->assertStringContainsString(
            'command -v docker',
            $script,
            'scripts/backup-production.sh is expected to require the docker CLI.',
        );
        $this->assertStringContainsString(
            'docker compose exec',
            $script,
            'scripts/backup-production.sh is expected to shell into the compose postgres service.',
        );

        // And it is genuinely reachable from the scheduler.
        $this->assertStringContainsString(
            "Schedule::command('procynia:backup')",
            file_get_contents(base_path('routes/console.php')),
            'procynia:backup is expected to still be scheduled hourly.',
        );
        $this->assertStringContainsString(
            'scripts/backup-production.sh',
            file_get_contents(app_path('Services/Operations/BackupService.php')),
            'BackupService is expected to invoke the docker-based backup script.',
        );
    }

    /**
     * The mitigating fact, verified rather than assumed: the backup is opt-in, stored in the
     * database, and defaults to disabled. A freshly provisioned Azure database therefore starts with
     * the backup off, and the hourly schedule is a no-op.
     *
     * This is what makes the risk "do not migrate a backup_enabled = true row into Azure" rather
     * than "the scheduler will fail immediately".
     */
    public function test_the_scheduled_backup_is_disabled_by_default_and_is_a_no_op(): void
    {
        $this->assertSame(
            0,
            BackupSetting::query()->count(),
            'A fresh database must start with no backup setting row.',
        );

        $exitCode = Artisan::call('procynia:backup');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, 'The backup command must not fail when it is disabled.');
        $this->assertStringContainsString(
            'Backup is disabled',
            $output,
            'On a fresh database the hourly backup must skip instead of shelling out to docker.',
        );

        $setting = BackupSetting::query()->first();
        $this->assertNotNull($setting, 'The command is expected to create the setting row.');
        $this->assertFalse(
            (bool) $setting->backup_enabled,
            'The default must be disabled, so a new Azure environment cannot start running '
            .'docker-based backups on its own.',
        );
    }

    /**
     * The Azure side of the contract: every Azure environment must explicitly disable the legacy
     * mechanism. This is what closes the blocker — not the database flag, which a migrated database
     * can carry as true.
     */
    public function test_every_azure_environment_explicitly_disables_the_legacy_backup(): void
    {
        $main = file_get_contents(base_path('infra/main.bicep'));

        $this->assertStringContainsString(
            'param legacyBackupEnabled bool = false',
            $main,
            'The Azure template default for the legacy backup must be disabled.',
        );

        $this->assertMatchesRegularExpression(
            "/name: 'PROCYNIA_LEGACY_BACKUP_ENABLED'\s*\n\s*value: string\(legacyBackupEnabled\)/",
            $main,
            'The Azure environment contract must expose PROCYNIA_LEGACY_BACKUP_ENABLED to the containers.',
        );

        foreach (['staging', 'production'] as $environment) {
            $this->assertStringContainsString(
                'param legacyBackupEnabled = false',
                file_get_contents(base_path("infra/environments/{$environment}.bicepparam")),
                sprintf('%s.bicepparam must explicitly disable the legacy Compose backup.', $environment),
            );
        }
    }

    /**
     * The variable must reach every workload, not only the scheduler. A manual
     * `php artisan procynia:backup` — or an admin clicking the manual backup button, which the
     * database flag never guarded — could otherwise reach the Compose script from the web app or any
     * worker.
     */
    public function test_the_legacy_backup_flag_reaches_every_container_not_just_the_scheduler(): void
    {
        $main = file_get_contents(base_path('infra/main.bicep'));

        $sharedBlockStart = strpos($main, 'var sharedEnvironmentVariables = [');
        $this->assertNotFalse($sharedBlockStart, 'The shared environment variable contract is missing.');

        $sharedBlock = substr($main, $sharedBlockStart, (int) strpos($main, "\nvar resolvedWebImage") - $sharedBlockStart);

        $this->assertStringContainsString(
            'PROCYNIA_LEGACY_BACKUP_ENABLED',
            $sharedBlock,
            'PROCYNIA_LEGACY_BACKUP_ENABLED must be part of the shared environment contract, so web, '
            .'every worker and the scheduler all receive it.',
        );
    }

    /**
     * The application must honour that contract: with the Azure value in effect, the scheduler must
     * not register the backup command at all.
     */
    public function test_the_azure_configuration_prevents_the_scheduler_from_triggering_compose_backup(): void
    {
        $this->assertStringContainsString(
            "if (config('procynia.backup.legacy_enabled'))",
            file_get_contents(base_path('routes/console.php')),
            'The scheduler must gate procynia:backup on the runtime guard.',
        );

        $this->assertTrue(
            method_exists(BackupService::class, 'legacyBackupIsSupported'),
            'BackupService must expose the runtime guard used by the scheduler, the command and the '
            .'admin panel.',
        );
    }

    /**
     * The precondition is still documented, but now as a closed item rather than an open blocker.
     */
    public function test_the_backup_precondition_is_documented_in_both_places(): void
    {
        $readiness = base_path('docs/azure-migration-test-readiness.md');
        $infraReadme = base_path('infra/README.md');

        $this->assertFileExists($readiness);

        foreach ([$readiness, $infraReadme] as $document) {
            $contents = file_get_contents($document);

            $this->assertStringContainsString(
                'procynia:backup',
                $contents,
                sprintf('%s must still explain what happened to the backup task.', basename($document)),
            );

            $this->assertStringContainsString(
                'PROCYNIA_LEGACY_BACKUP_ENABLED',
                $contents,
                sprintf('%s must document the runtime guard that closed the blocker.', basename($document)),
            );
        }

        $this->assertMatchesRegularExpression(
            '/PROCYNIA_LEGACY_BACKUP_ENABLED/',
            file_get_contents(base_path('scripts/azure-readiness/azure-smoke.sh')),
            'The readiness smoke script must verify the runtime guard, not just the database flag.',
        );
    }
}
