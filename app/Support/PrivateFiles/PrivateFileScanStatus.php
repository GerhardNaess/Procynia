<?php

namespace App\Support\PrivateFiles;

/**
 * Malware scan status of a private file. Procynia has no scanner yet, so every file is stored as
 * NOT_SCANNED. When a scanner is connected it writes PENDING/CLEAN/INFECTED, and
 * config('private_files.require_clean_scan') makes anything but CLEAN undownloadable. INFECTED is
 * never served.
 */
final class PrivateFileScanStatus
{
    public const NOT_SCANNED = 'not_scanned';

    public const PENDING = 'pending';

    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const ALL = [self::NOT_SCANNED, self::PENDING, self::CLEAN, self::INFECTED];

    public static function isDownloadable(?string $status): bool
    {
        if ($status === self::INFECTED) {
            return false;
        }

        return ! config('private_files.require_clean_scan') || $status === self::CLEAN;
    }
}
