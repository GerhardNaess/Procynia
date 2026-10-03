<?php

namespace App\Support;

use App\Models\EnterpriseWikiDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serving a file from the virksomhet's document archive.
 *
 * One place for how the bytes go out, so Wiki → Kildedokumenter and Kvalitet → Verktøy cannot
 * drift apart on content type or disposition. Who may fetch the file is the caller's question and
 * is answered before this is reached.
 */
final class EnterpriseWikiDocumentFileResponse
{
    public static function make(EnterpriseWikiDocument $document, bool $asAttachment = false): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->file_path), 404);

        $mimeType = match (strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };

        $response = response()->file($disk->path($document->file_path), ['Content-Type' => $mimeType]);
        $response->setContentDisposition(
            $asAttachment ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $document->original_filename,
        );

        return $response;
    }
}
