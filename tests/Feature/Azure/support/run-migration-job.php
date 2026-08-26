<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Azure migration readiness — the migration job, run as its own process.
 *
 * This is the local stand-in for the Azure Container Apps Job that runs `php artisan migrate
 * --force` between the platform deployment and the workload deployment. It is spawned by
 * Tests\Feature\Azure\MigrationJobAndPgvectorContractTest.
 *
 * Safety: it refuses to do anything unless the LIVE connection reports current_database() =
 * procynia_test. A config value can lie about which database is connected; this check cannot.
 */

require __DIR__.'/../../../../vendor/autoload.php';

$emit = static function (array $payload): never {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE));
    exit($payload['ok'] ? 0 : 1);
};

try {
    /** @var Application $app */
    $app = require __DIR__.'/../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $database = (string) DB::selectOne('select current_database() as db')->db;

    if ($database !== 'procynia_test') {
        $emit([
            'ok' => false,
            'database' => $database,
            'error' => sprintf(
                'Refusing to migrate: live connection reports current_database() = [%s], not procynia_test.',
                $database,
            ),
        ]);
    }

    // How many migrations are outstanding before this run.
    $statusCode = Artisan::call('migrate:status');
    $statusOutput = Artisan::output();
    $pendingBefore = preg_match_all('/\bPending\b/i', $statusOutput);

    $exitCode = Artisan::call('migrate', ['--force' => true]);
    $output = Artisan::output();

    $emit([
        'ok' => true,
        'pid' => getmypid(),
        'database' => $database,
        'status_exit_code' => $statusCode,
        'pending_before' => $pendingBefore,
        'exit_code' => $exitCode,
        'output' => trim($output),
    ]);
} catch (Throwable $e) {
    $emit(['ok' => false, 'database' => null, 'error' => get_class($e).': '.$e->getMessage()]);
}
