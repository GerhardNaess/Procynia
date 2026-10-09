<?php

namespace App\Support\PrivateFiles;

use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends a private file as a download — always as an attachment, never shown in the browser:
 *
 * - Content-Disposition: attachment, with the person's file name (UTF-8) and an ASCII fallback;
 * - Content-Type from the stored, server-decided type — never what the browser said at upload;
 * - X-Content-Type-Options: nosniff, so the browser cannot reinterpret it;
 * - Cache-Control: private, no-store, so no shared or local cache keeps a copy.
 *
 * Authorization is the caller's; this only streams from the private disk.
 */
final class PrivateFileResponse
{
    public static function download(Filesystem $disk, string $path, string $name, string $mimeType): StreamedResponse
    {
        $fallback = preg_replace('/[^\x20-\x7E]|["%\/\\\\]/', '_', $name) ?: 'fil';

        $response = new StreamedResponse(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if ($stream === null) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $disk->size($path),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
