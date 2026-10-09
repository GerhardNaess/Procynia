<?php

namespace App\Support\PrivateFiles;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Private customer files: write, read and delete, for any module that keeps files of its own
 * (config/private_files.php). It knows nothing about the module — no access rules, no rows. The
 * caller authorizes, records the StoredPrivateFile on its own row, and serves through
 * PrivateFileResponse.
 *
 * - A file is written under customers/{customer_id}/{area}/{ULID}.{ext}: never guessable, never the
 *   person's file name, and always inside the customer's own prefix.
 * - Type, extension and Content-Type come from PrivateFileType's check of the content.
 * - SHA-256 and size are computed here from the bytes written, not taken from the request.
 * - Only the Storage abstraction is used — no local paths — so the disk can become Azure Blob.
 *
 * Deleting is guarded twice: a path outside the customer's area prefix is never touched, and a file
 * still referenced by any row the area lists (by path or by key) is never deleted. A module calls
 * deleteAfterCommit() so a rolled-back transaction never loses a file; a deletion that fails leaves
 * an orphan that private-files:prune-orphans removes later.
 */
class PrivateFileStore
{
    private const KEY_PATTERN = '[0-9A-HJKMNP-TV-Z]{26}';

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('private_files.disk', 'local'));
    }

    /** Writes an uploaded file that PrivateFileType accepts. The caller validates first; this checks again. */
    public function store(UploadedFile $file, int $customerId, string $area): StoredPrivateFile
    {
        $this->assertArea($area);
        $source = (string) $file->getRealPath();
        $extension = PrivateFileType::detect($source, $file->getClientOriginalName());

        if ($extension === null) {
            throw new InvalidArgumentException('The file is not an allowed, intact file of the type its name says.');
        }

        $key = (string) Str::ulid();
        $path = "customers/{$customerId}/{$area}/{$key}.{$extension}";
        $sha256 = (string) hash_file('sha256', $source);
        $size = (int) filesize($source);

        $stream = fopen($source, 'rb');

        try {
            $written = $this->disk()->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false || $this->disk()->size($path) !== $size) {
            $this->disk()->delete($path);

            throw new InvalidArgumentException('The file could not be stored.');
        }

        return new StoredPrivateFile($key, $path, self::displayName($file->getClientOriginalName(), $extension), PrivateFileType::mimeType($extension), $size, $sha256);
    }

    /** Whether $path is a file of this customer's area, in the form store() writes. */
    public function belongsTo(string $path, int $customerId, string $area): bool
    {
        $extensions = implode('|', array_keys(PrivateFileType::TYPES));

        return preg_match('#^customers/'.$customerId.'/'.preg_quote($area, '#').'/'.self::KEY_PATTERN.'\.('.$extensions.')$#', $path) === 1;
    }

    /** The key (the ULID file name) of a stored path. */
    public static function keyOf(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /** Whether any row the area lists still holds this file's path or key. */
    public function isReferenced(string $path, string $area): bool
    {
        foreach ($this->assertArea($area)['references'] as $reference) {
            $value = $reference['holds'] === 'key' ? self::keyOf($path) : $path;

            if (DB::table($reference['table'])->where($reference['column'], $value)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a file that is inside the customer's area and no longer referenced. True when the file
     * is gone; false when it was refused or the storage failed (logged — the orphan job retries).
     */
    public function delete(string $path, int $customerId, string $area): bool
    {
        if (! $this->belongsTo($path, $customerId, $area)) {
            Log::warning('private-files: refused to delete a path outside its area', ['path' => $path, 'customer_id' => $customerId, 'area' => $area]);

            return false;
        }

        if ($this->isReferenced($path, $area)) {
            return false;
        }

        try {
            return $this->disk()->delete($path) || ! $this->disk()->exists($path);
        } catch (Throwable $exception) {
            Log::warning('private-files: could not delete a file; left for prune-orphans', ['path' => $path, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /** delete(), once the surrounding transaction has committed — never if it rolls back. */
    public function deleteAfterCommit(string $path, int $customerId, string $area): void
    {
        DB::afterCommit(fn () => $this->delete($path, $customerId, $area));
    }

    /**
     * Files of an area that no row references and that are older than the grace period — what an
     * interrupted upload or a failed deletion leaves behind, and what a deleted customer's rows no
     * longer point at.
     *
     * @return list<string>
     */
    public function orphans(string $area, int $graceHours): array
    {
        $this->assertArea($area);
        $cutoff = now()->subHours($graceHours)->getTimestamp();
        $orphans = [];

        foreach ($this->disk()->directories('customers') as $customerDirectory) {
            $customerId = (int) basename($customerDirectory);

            foreach ($this->disk()->files("{$customerDirectory}/{$area}") as $path) {
                if ($this->belongsTo($path, $customerId, $area)
                    && $this->disk()->lastModified($path) < $cutoff
                    && ! $this->isReferenced($path, $area)) {
                    $orphans[] = $path;
                }
            }
        }

        return $orphans;
    }

    /** The person's file name, made safe to keep and to send back: no folders, no control characters. */
    public static function displayName(string $originalName, string $extension): string
    {
        $name = str_replace(['\\', '/'], '_', $originalName);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        $name = Str::limit($name, 200, '');

        return $name !== '' && $name !== '.'.$extension ? $name : "fil.{$extension}";
    }

    /** @return array{references: list<array{table: string, column: string, holds: string}>} */
    private function assertArea(string $area): array
    {
        $config = config("private_files.areas.{$area}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown private file area «{$area}».");
        }

        return $config;
    }
}
