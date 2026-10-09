<?php

namespace App\Support\PrivateFiles;

use ZipArchive;

/**
 * The file types a private customer file may be, and the check that a file really is one.
 *
 * The type is decided from the content on the server — never from the browser's MIME type. The
 * name's extension must agree with the content: a PNG named «avtale.pdf» or a PDF renamed «.docx»
 * is refused, and so is a file whose content is broken (a PDF without its end marker, an image that
 * does not decode, an Office file that is not a readable package). Macro-enabled Office files are
 * refused even when named .docx/.xlsx. HTML, SVG and scripts are never allowed.
 *
 * The extension and Content-Type a file is stored and served with come from this list, not from the
 * upload.
 */
final class PrivateFileType
{
    /** @var array<string, string> extension => the Content-Type it is served with */
    public const TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
    ];

    /** The largest [Content_Types].xml read from an Office package; a real one is a few kB (zip-bomb guard). */
    private const MAX_CONTENT_TYPES_BYTES = 1_048_576;

    /** The extensions a person may pick, for the file input's accept attribute. */
    public const ACCEPT = '.pdf,.docx,.xlsx,.png,.jpg,.jpeg';

    /**
     * The canonical extension of the file at $path when its content is an allowed, intact file of
     * the type its name says; null otherwise.
     */
    public static function detect(string $path, string $originalName): ?string
    {
        $claimed = self::claimedExtension($originalName);

        if ($claimed === null || ! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $intact = match ($claimed) {
            'pdf' => self::isPdf($path),
            'png' => self::isImage($path, "\x89PNG\r\n\x1a\n", IMAGETYPE_PNG),
            'jpg' => self::isImage($path, "\xFF\xD8\xFF", IMAGETYPE_JPEG),
            'docx' => self::isOfficePackage($path, 'word/document.xml', 'word/'),
            'xlsx' => self::isOfficePackage($path, 'xl/workbook.xml', 'xl/'),
        };

        return $intact ? $claimed : null;
    }

    public static function mimeType(string $extension): string
    {
        return self::TYPES[$extension] ?? 'application/octet-stream';
    }

    /** The allowed extension the name ends in («.jpeg» is «jpg»), or null. */
    public static function claimedExtension(string $originalName): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return array_key_exists($extension, self::TYPES) ? $extension : null;
    }

    private static function head(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, $bytes);
        fclose($handle);

        return $head;
    }

    private static function isPdf(string $path): bool
    {
        if (! str_starts_with(self::head($path, 5), '%PDF-')) {
            return false;
        }

        // The end-of-file marker sits in the last bytes of an intact PDF (trailing whitespace allowed).
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, -min(2048, (int) filesize($path)), SEEK_END);
        $tail = (string) fread($handle, 2048);
        fclose($handle);

        return str_contains($tail, '%%EOF');
    }

    private static function isImage(string $path, string $magic, int $imageType): bool
    {
        if (! str_starts_with(self::head($path, strlen($magic)), $magic)) {
            return false;
        }

        $info = @getimagesize($path);

        return is_array($info) && $info[2] === $imageType && $info[0] > 0 && $info[1] > 0;
    }

    private static function isOfficePackage(string $path, string $mainPart, string $folder): bool
    {
        if (! str_starts_with(self::head($path, 4), "PK\x03\x04")) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $stat = $zip->statName('[Content_Types].xml');

            if ($stat === false || $stat['size'] > self::MAX_CONTENT_TYPES_BYTES) {
                return false;
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');

            return $contentTypes !== false
                && $zip->locateName($mainPart) !== false
                && $zip->locateName($folder.'vbaProject.bin') === false
                && ! str_contains(strtolower($contentTypes), 'macroenabled');
        } finally {
            $zip->close();
        }
    }
}
