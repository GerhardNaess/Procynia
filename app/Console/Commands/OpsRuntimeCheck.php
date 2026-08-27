<?php

namespace App\Console\Commands;

use App\Services\Operations\RuntimePreflightService;
use Illuminate\Console\Command;

/**
 * Preflight check for a Procynia container.
 *
 * Run inside a container before signing off a staging environment, and after any deployment that
 * changes the runtime contract:
 *
 *   php artisan ops:runtime-check --azure
 *
 * Read-only apart from a scratch file on the storage disk, which it removes again. It never runs
 * migrations, never dispatches a job, and never prints a secret value.
 *
 * Exits 1 if any critical precondition fails, so it can gate a deployment step.
 */
class OpsRuntimeCheck extends Command
{
    protected $signature = 'ops:runtime-check
        {--azure : Apply the Azure runtime contract — legacy Compose backup must be disabled}
        {--with-openai : Also verify OpenAI connectivity. Costs one API request, so it is off by default}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Verify that this container can actually run Procynia. Exits non-zero if a critical precondition fails.';

    public function handle(RuntimePreflightService $service): int
    {
        $azure = (bool) $this->option('azure');
        $checks = $service->run($azure, (bool) $this->option('with-openai'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $service->hasCriticalFailure($checks),
                'azure_profile' => $azure,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $service->hasCriticalFailure($checks) ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Procynia runtime check</> — profile: %s',
            $azure ? 'azure' : 'default',
        ));
        $this->newLine();

        $counts = [
            RuntimePreflightService::STATUS_PASS => 0,
            RuntimePreflightService::STATUS_FAIL => 0,
            RuntimePreflightService::STATUS_WARN => 0,
            RuntimePreflightService::STATUS_SKIP => 0,
        ];

        foreach ($checks as $check) {
            $counts[$check['status']]++;

            $label = match ($check['status']) {
                RuntimePreflightService::STATUS_PASS => '<fg=green;options=bold>PASS</>',
                RuntimePreflightService::STATUS_FAIL => '<fg=red;options=bold>FAIL</>',
                RuntimePreflightService::STATUS_WARN => '<fg=yellow;options=bold>WARN</>',
                default => '<fg=gray;options=bold>SKIP</>',
            };

            $this->line(sprintf('  %s  %-24s %s', $label, $check['name'], $check['detail']));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d passed, %d failed, %d warnings, %d skipped',
            $counts[RuntimePreflightService::STATUS_PASS],
            $counts[RuntimePreflightService::STATUS_FAIL],
            $counts[RuntimePreflightService::STATUS_WARN],
            $counts[RuntimePreflightService::STATUS_SKIP],
        ));

        if ($service->hasCriticalFailure($checks)) {
            $this->newLine();
            $this->error('A critical precondition failed. This runtime is not ready.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All critical preconditions satisfied.');

        return self::SUCCESS;
    }
}
