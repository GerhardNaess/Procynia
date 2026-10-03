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
use InvalidArgumentException;
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
 *
 * A source does not have to arrive as a file — see storeAuthoredText(). Text written inside
 * Procynia enters the same store, with the same identity and the same reconciliation, because
 * what makes something a Wiki source is that the Wiki's ingest run can read it.
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
     * The customer's existing copy of this authored text, if they have one.
     *
     * Same identity question as a file's, asked of bytes that never were one — see
     * storeAuthoredText().
     */
    public function existingAuthoredText(int $customerId, string $text): ?EnterpriseWikiDocument
    {
        return $this->existingDocument($customerId, $this->hashForText($text));
    }

    public function hashForText(string $text): string
    {
        return hash('sha256', $text);
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

        $ext = Str::lower(trim((string) $file->getClientOriginalExtension()));
        if ($ext === '') {
            $ext = 'bin';
        }

        return $this->persist(
            customerId: $customerId,
            filename: $file->getClientOriginalName(),
            fileHash: $fileHash,
            extension: $ext,
            ownerUserId: $ownerUserId,
            uploadedByUserId: $uploadedByUserId,
            write: static fn (string $target): string|false => Storage::disk('local')->putFileAs(
                sprintf('customers/%d/wiki-documents', $customerId),
                $file,
                $target,
            ),
            // A file's text is whatever the extractor can get out of it, which is a property of
            // the format and not of the caller.
            extract: fn (string $absolutePath): string => trim($this->documentTextExtractor->extractText($absolutePath)),
        );
    }

    /**
     * Putting text that was WRITTEN IN PROCYNIA into the same document store.
     *
     * A source does not have to arrive as a file. Kvalitet's prosessaktiviteter are places where
     * the virksomhet knows something no document records; the article a person writes there is a
     * genuine new source, and it has to enter the Wiki the way every other source does — as an
     * EnterpriseWikiDocument that the ordinary ingest run reads. Anything less and the Wiki's own
     * pipeline never sees it, which is precisely why articles created from an activity arrived
     * without the concepts, entities and summaries every other source produces.
     *
     * Deliberately a sibling of store() and not a second service: same customer-scoped private
     * path, same SHA-256 identity, same UTF-8 guard, same claim-source reconciliation, same
     * deduplication answer. The ONE difference is that there is nothing to extract — the text is
     * already text, so it is written to disk and recorded as-is rather than parsed back out of a
     * format it was never in. The stored `.md` file is the document: it is what the download, the
     * source list and any later re-ingest read.
     *
     * @param  string  $filename  What the source is called in the Wiki's document list. Given by
     *                            the caller, because only the caller knows what produced it.
     * @return array{document: EnterpriseWikiDocument, reused: bool}
     */
    public function storeAuthoredText(
        int $customerId,
        string $filename,
        string $text,
        ?int $ownerUserId,
        ?int $uploadedByUserId,
    ): array {
        $text = trim($text);

        // Before anything is written, for the same reason the uploaded path guards after
        // extraction: malformed bytes must never become grounded evidence.
        $this->utf8Guard->assertValid([
            'extracted_text' => $text,
        ], 'enterprise_wiki_document_authored_text');

        if ($text === '') {
            throw new InvalidArgumentException('An authored Wiki source cannot be empty.');
        }

        $fileHash = $this->hashForText($text);

        $existing = $this->existingDocument($customerId, $fileHash);

        if ($existing instanceof EnterpriseWikiDocument) {
            return ['document' => $existing, 'reused' => true];
        }

        return $this->persist(
            customerId: $customerId,
            filename: $filename,
            fileHash: $fileHash,
            extension: 'md',
            ownerUserId: $ownerUserId,
            uploadedByUserId: $uploadedByUserId,
            write: static fn (string $target): string|false => Storage::disk('local')->put(
                $path = sprintf('customers/%d/wiki-documents/%s', $customerId, $target),
                $text,
            ) ? $path : false,
            extract: static fn (): string => $text,
        );
    }

    /**
     * The half both entry points share: write the bytes, record the row, undo the write if the
     * row cannot be made, and hand the new source to claim-source reconciliation.
     *
     * @param  callable(string): (string|false)  $write  Writes the file under the given basename, returning its stored path.
     * @param  callable(string): string  $extract  The document's text, given its absolute path.
     * @return array{document: EnterpriseWikiDocument, reused: bool}
     */
    private function persist(
        int $customerId,
        string $filename,
        string $fileHash,
        string $extension,
        ?int $ownerUserId,
        ?int $uploadedByUserId,
        callable $write,
        callable $extract,
    ): array {
        $storedPath = null;

        try {
            $storedPath = $write(Str::ulid().'.'.$extension);

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store the document.');

            $extractedText = $extract(Storage::disk('local')->path($storedPath));
            $this->utf8Guard->assertValid([
                'extracted_text' => $extractedText,
            ], 'enterprise_wiki_document_extraction');

            $document = DB::transaction(static fn (): EnterpriseWikiDocument => EnterpriseWikiDocument::query()->create([
                'customer_id' => $customerId,
                'uploaded_by_user_id' => $uploadedByUserId,
                'owner_user_id' => $ownerUserId,
                'original_filename' => $filename,
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
