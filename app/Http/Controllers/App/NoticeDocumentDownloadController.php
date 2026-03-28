<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeDocument;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeDocumentService;
use App\Support\CustomerContext;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class NoticeDocumentDownloadController extends Controller
{
    public function __construct(
        private readonly DoffinNoticeDocumentService $documentService,
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function download(Request $request, int $notice, int $document): BinaryFileResponse
    {
        $record = $this->resolveAccessibleNotice($request->user(), $notice);
        $this->documentService->syncFromNotice($record);

        $noticeDocument = $record->documents()->findOrFail($document);
        $download = $this->fetchDocument($noticeDocument);

        $tempPath = tempnam(sys_get_temp_dir(), 'procynia-doc-');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary file for document download.');
        }

        file_put_contents($tempPath, $download['contents']);

        $this->updateDocumentMetadata($noticeDocument, $download['mime_type'], strlen($download['contents']));

        return response()
            ->download($tempPath, $download['filename'])
            ->deleteFileAfterSend(true);
    }

    public function downloadAll(Request $request, int $notice): BinaryFileResponse
    {
        $record = $this->resolveAccessibleNotice($request->user(), $notice);
        $documents = $this->documentService->syncFromNotice($record);

        if ($documents->isEmpty()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'procynia-zip-');

        if ($zipPath === false) {
            throw new RuntimeException('Unable to create a temporary ZIP file.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Unable to open ZIP archive for document download.');
        }

        try {
            foreach ($documents as $document) {
                $download = $this->fetchDocument($document);
                $zip->addFromString($download['filename'], $download['contents']);
                $this->updateDocumentMetadata($document, $download['mime_type'], strlen($download['contents']));
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($zipPath);

            throw $exception;
        }

        $zip->close();

        return response()
            ->download($zipPath, $this->zipName($record))
            ->deleteFileAfterSend(true);
    }

    private function resolveAccessibleNotice(?User $user, int $noticeId): Notice
    {
        if (! $user instanceof User || $this->customerContext->currentCustomerId($user) === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->customerContext
            ->scopeNoticeDiscovery(Notice::query(), $user)
            ->whereKey($noticeId)
            ->with('documents')
            ->firstOrFail();
    }

    private function fetchDocument(NoticeDocument $document): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Accept-Language' => 'nb-NO,nb;q=0.9,en;q=0.8',
            ])
            ->get($document->source_url);

        if (! $response->successful()) {
            throw new RuntimeException("Document download failed for URL {$document->source_url}.");
        }

        $mimeType = $this->normalizeMimeType($response);
        $filename = $this->resolveFilename($document, $mimeType);

        return [
            'contents' => $response->body(),
            'filename' => $filename,
            'mime_type' => $mimeType,
        ];
    }

    private function updateDocumentMetadata(NoticeDocument $document, ?string $mimeType, int $fileSize): void
    {
        $document->forceFill([
            'mime_type' => $document->mime_type ?? $mimeType,
            'file_size' => $document->file_size ?? $fileSize,
        ])->save();
    }

    private function normalizeMimeType(HttpResponse $response): ?string
    {
        $contentType = trim((string) $response->header('Content-Type'));

        if ($contentType === '') {
            return null;
        }

        return strtolower(trim(strtok($contentType, ';')));
    }

    private function resolveFilename(NoticeDocument $document, ?string $mimeType): string
    {
        $path = parse_url($document->source_url, PHP_URL_PATH);
        $extension = pathinfo((string) $path, PATHINFO_EXTENSION);

        if ($extension === '') {
            $extension = match ($mimeType) {
                'application/pdf' => 'pdf',
                'text/html' => 'html',
                'application/zip' => 'zip',
                default => 'bin',
            };
        }

        $baseName = trim((string) $document->title);

        if ($baseName === '') {
            $baseName = trim((string) pathinfo((string) $path, PATHINFO_FILENAME));
        }

        if ($baseName === '') {
            $baseName = 'document-'.$document->id;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'document-'.$document->id;

        return str_ends_with(strtolower($sanitized), '.'.strtolower($extension))
            ? $sanitized
            : "{$sanitized}.{$extension}";
    }

    private function zipName(Notice $notice): string
    {
        $noticeId = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $notice->notice_id) ?: 'notice';

        return "{$noticeId}-documents.zip";
    }
}
