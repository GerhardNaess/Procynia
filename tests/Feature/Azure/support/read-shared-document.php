<?php

use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;

/**
 * Azure migration readiness — separate-process document reader.
 *
 * This script is deliberately NOT part of the test process. Tests\Feature\Azure\
 * SharedStorageHandoffTest spawns it with Symfony Process so that the "worker" side of the
 * web → shared storage → worker handoff runs in its own PHP process, with its own Laravel
 * container, sharing nothing with the web side except:
 *
 *   * the configured storage root (the stand-in for the Azure Files mount), and
 *   * the relative file path the web layer persisted to PostgreSQL.
 *
 * That is exactly the pair of things Azure Files has to make work. If this script can resolve and
 * read the file, the handoff does not depend on anything in-process.
 *
 * It reads no database and writes nothing. Output is a single JSON object on stdout.
 *
 * Usage: php read-shared-document.php <storage-root> <relative-path>
 */
$storageRoot = $argv[1] ?? '';
$relativePath = $argv[2] ?? '';

$fail = static function (string $message): never {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
    exit(1);
};

if ($storageRoot === '' || $relativePath === '') {
    $fail('Usage: read-shared-document.php <storage-root> <relative-path>');
}

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Point the "local" disk at the shared root, the same way FILESYSTEM_DISK + an Azure Files mount
// would in a worker container.
config(['filesystems.disks.local.root' => $storageRoot]);
Storage::forgetDisk('local');

try {
    $disk = Storage::disk('local');

    if (! $disk->exists($relativePath)) {
        $fail(sprintf('The worker process cannot see [%s] under root [%s].', $relativePath, $storageRoot));
    }

    // The Azure-critical call: a physical path an external process (poppler) can open.
    $absolutePath = $disk->path($relativePath);

    if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
        $fail(sprintf('Resolved path [%s] is not a readable file.', $absolutePath));
    }

    /** @var DocumentTextExtractor $extractor */
    $extractor = $app->make(DocumentTextExtractor::class);

    // Real extraction, including the real pdftotext subprocess. Nothing here is stubbed.
    $extractedText = trim((string) $extractor->extractText($absolutePath));

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'pid' => getmypid(),
        'absolute_path' => $absolutePath,
        'size_bytes' => filesize($absolutePath),
        'sha256' => hash_file('sha256', $absolutePath),
        'extracted_text' => $extractedText,
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $fail(get_class($e).': '.$e->getMessage());
}
