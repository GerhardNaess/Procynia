<?php

namespace App\Support\PrivateFiles;

/**
 * A file PrivateFileStore has written: what a module records about it. The path is internal and
 * never shown to a person; the key is the file's identity (also the stored file name).
 */
final readonly class StoredPrivateFile
{
    public function __construct(
        public string $key,
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
