<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Boot Laravel in a fresh process and report which commands the scheduler registers.
 *
 * Spawned by Tests\Feature\Operations\LegacyBackupRuntimeGuardTest. A separate process is used
 * deliberately: routes/console.php is evaluated once during boot, so the effect of
 * PROCYNIA_LEGACY_BACKUP_ENABLED on scheduler registration can only be observed by booting again
 * with a different environment. That also makes this an end-to-end check of the real chain —
 * environment variable → config/procynia.php → routes/console.php — rather than of a config key
 * someone set by hand in a test.
 *
 * Touches no database and writes nothing. Output is one JSON object on stdout.
 */

require __DIR__.'/../../../../vendor/autoload.php';

try {
    /** @var Application $app */
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $schedule = $app->make(Schedule::class);

    $commands = [];

    foreach ($schedule->events() as $event) {
        $commands[] = (string) ($event->command ?? $event->description ?? '');
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'legacy_enabled' => (bool) config('procynia.backup.legacy_enabled'),
        'commands' => $commands,
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]));
    exit(1);
}
