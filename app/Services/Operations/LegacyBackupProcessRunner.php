<?php

namespace App\Services\Operations;

use Symfony\Component\Process\Process;

/**
 * The single boundary between Procynia and the legacy Compose backup script.
 *
 * scripts/backup-production.sh is the one place the application shells out to `docker compose`.
 * Isolating that call behind a class does two things:
 *
 *   1. It gives BackupService exactly one place that can start the script, so the runtime guard has
 *      a single chokepoint to protect.
 *   2. It lets tests prove that the script is never started, by swapping this runner for a spy —
 *      without stubbing the decision logic that decides whether to start it. A test that mocked
 *      BackupService itself would prove nothing about the guard.
 *
 * The implementation is unchanged: same script, same arguments, same timeout as before.
 */
class LegacyBackupProcessRunner
{
    public function run(string $scriptPath, string $directory, string $workingDirectory, int $timeoutSeconds = 600): Process
    {
        $process = new Process(
            ['bash', $scriptPath, $directory],
            $workingDirectory,
            null,
            null,
            $timeoutSeconds,
        );

        $process->run();

        return $process;
    }
}
