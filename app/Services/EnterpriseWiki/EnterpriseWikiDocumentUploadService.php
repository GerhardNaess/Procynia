<?php

namespace App\Services\EnterpriseWiki;

use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Models\EnterpriseWikiDocument;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Putting one uploaded file into the virksomhet's document store.
 *
 * Lifted out of WikiSourceController::store() unchanged in behaviour, because Kvalitet needs the
 * same thing and "the same thing" has to mean the same code: the same private, customer-scoped
 * path, the same SHA-256 identity, the same text extraction and UTF-8 guard, the same claim-source
 * reconciliation afterwards. A second upload path would be a second answer to "is this file already
 * here", which is precisely the question that keeps duplicates off disk.
 *
 * The one judgement this class makes is deduplication: a file whose hash the customer already has
 * is NOT written again — the existing row is returned and `reused` says so. Callers decide what
 * that means to them. Wiki refuses the upload outright (re-uploading a source it has already
 * ingested is almost always a mistake); Kvalitet attaches the file it already had, which is the
 * useful answer when somebody drags the quality manual onto a second process.
 */
class EnterpriseWikiDocumentUploadService
{
    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly EnterpriseWikiUtf8Guard $utf8Guard,
    ) {}

    public function hashFor(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }

    /**
     * The customer's existing copy of this file, if they have one.
     */
    public function existingDocument(int $customerId, string $fileHash): ?EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_hash_sha256', $fileHash)
            ->first();
    }

    /**
     * @return array{document: EnterpriseWikiDocument, reused: bool}
     */
    public function store(
        int $customerId,
        UploadedFile $file,
        ?int $ownerUserId,
        ?int $uploadedByUserId,
    ): array {
        $fileHash = $this->hashFor($file);

        $existing = $this->existingDocument($customerId, $fileHash);

        if ($existing instanceof EnterpriseWikiDocument) {
            return ['document' => $existing, 'reused' => true];
        }

        $storedPath = null;

        try {
            $ext = Str::lower(trim((string) $file->getClientOriginalExtension()));
            if ($ext === '') {
                $ext = 'bin';
            }

            $storedPath = Storage::disk('local')->putFileAs(
                sprintf('customers/%d/wiki-documents', $customerId),
                $file,
                Str::ulid().'.'.$ext,
            );

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store the document.');

            $absolutePath = Storage::disk('local')->path($storedPath);
            $extractedText = trim($this->documentTextExtractor->extractText($absolutePath));
            $this->utf8Guard->assertValid([
                'extracted_text' => $extractedText,
            ], 'enterprise_wiki_document_extraction');

            $document = DB::transaction(static fn (): EnterpriseWikiDocument => EnterpriseWikiDocument::query()->create([
                'customer_id' => $customerId,
                'uploaded_by_user_id' => $uploadedByUserId,
                'owner_user_id' => $ownerUserId,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'file_hash_sha256' => $fileHash,
                'extracted_text' => $extractedText !== '' ? $extractedText : null,
                'document_status' => $extractedText !== ''
                    ? EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                    : EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
            ]));
        } catch (Throwable $e) {
            // The row is what makes the file findable; without one the bytes are unreachable
            // litter, so a failure anywhere after the write takes the file back out.
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            Log::error('[PROCYNIA][WIKI_SOURCE] Failed to store wiki document.', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($document->document_status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
            ReconcileEnterpriseWikiClaimSourcesForDocument::dispatch($document->id);
        }

        return ['document' => $document, 'reused' => false];
    }
}
