<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Azure migration readiness — boot Laravel with no .env file at all.
 *
 * The production image will not contain a .env: .dockerignore excludes it, and every value arrives
 * as a Container App environment variable or a Key Vault secret reference. This script proves the
 * application can boot that way, by assembling a throwaway base path that contains no .env and
 * symlinks everything else back to the real project.
 *
 * It is spawned as a separate process by Tests\Feature\Azure\StatelessRuntimeContractTest, with the
 * configuration supplied purely through the process environment.
 *
 * It never connects to a database and never writes to the project tree. Output is one JSON object
 * on stdout.
 */

require __DIR__.'/../../../../vendor/autoload.php';

$projectRoot = dirname(__DIR__, 4);
$base = sys_get_temp_dir().'/procynia-azure-envless-'.bin2hex(random_bytes(6));

$cleanup = static function (string $path): void {
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());

            continue;
        }

        @rmdir($item->getPathname());
    }

    @rmdir($path);
};

$emit = static function (array $payload) use ($cleanup, $base): never {
    $cleanup($base);
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE));
    exit($payload['ok'] ? 0 : 1);
};

try {
    foreach ([
        $base.'/bootstrap/cache',
        $base.'/storage/framework/views',
        $base.'/storage/framework/cache/data',
        $base.'/storage/framework/sessions',
        $base.'/storage/app/private',
        $base.'/storage/logs',
    ] as $directory) {
        mkdir($directory, 0775, true);
    }

    // bootstrap/ is copied rather than symlinked, because bootstrap/app.php derives the base path
    // from its own location — that is what makes the throwaway root the application root.
    copy($projectRoot.'/bootstrap/app.php', $base.'/bootstrap/app.php');

    if (is_file($projectRoot.'/bootstrap/providers.php')) {
        copy($projectRoot.'/bootstrap/providers.php', $base.'/bootstrap/providers.php');
    }

    foreach (['vendor', 'app', 'config', 'routes', 'resources', 'database', 'lang', 'public', 'composer.json'] as $entry) {
        symlink($projectRoot.'/'.$entry, $base.'/'.$entry);
    }

    if (file_exists($base.'/.env')) {
        $emit(['ok' => false, 'error' => 'The throwaway base path unexpectedly contains a .env file.']);
    }

    /** @var Application $app */
    $app = require $base.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $emit([
        'ok' => true,
        'pid' => getmypid(),
        'base_path' => $app->basePath(),
        'env_file_present' => file_exists($app->basePath('.env')),
        'environment' => $app->environment(),
        'app_debug' => (bool) config('app.debug'),
        'app_key_matches_env' => config('app.key') === getenv('APP_KEY'),
        'app_key_present' => is_string(config('app.key')) && config('app.key') !== '',
        'app_url' => (string) config('app.url'),
        'db_host' => (string) config('database.connections.pgsql.host'),
        'db_database' => (string) config('database.connections.pgsql.database'),
        'db_sslmode' => (string) config('database.connections.pgsql.sslmode'),
        'log_default' => (string) config('logging.default'),
        'log_level' => (string) config('logging.channels.stderr.level'),
        'cache_store' => (string) config('cache.default'),
        'session_driver' => (string) config('session.driver'),
        'queue_connection' => (string) config('queue.default'),
        'session_secure_cookie' => config('session.secure'),
        'openai_key_present' => is_string(config('services.openai.api_key')) && config('services.openai.api_key') !== '',
        'doffin_base_url' => (string) config('doffin.base_url'),
        'filesystem_disk' => (string) config('filesystems.default'),
    ]);
} catch (Throwable $e) {
    $emit(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
}
