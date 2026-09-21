<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPageVersion;
use App\Support\EnterpriseWiki\EnterpriseWikiQueueTrace;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EnterpriseWikiIngestService
{
    public const MAX_EXTRACTED_TEXT_CHARS = 500_000;

    /**
     * Assert that the extracted text does not exceed the maximum allowed size.
     * Called before any AI processing to prevent runaway token costs.
     *
     * @throws InvalidArgumentException when the text exceeds MAX_EXTRACTED_TEXT_CHARS
     */
    public function validateExtractedTextSize(string $text): void
    {
        $length = mb_strlen($text);

        if ($length > self::MAX_EXTRACTED_TEXT_CHARS) {
            throw new InvalidArgumentException(sprintf(
                'Extracted text exceeds the maximum allowed size of %d characters (got %d). Reduce document size or split before ingesting.',
                self::MAX_EXTRACTED_TEXT_CHARS,
                $length,
            ));
        }
    }

    /**
     * Find the most recent completed ingest run for a given source.
     * Used to detect whether a re-ingest is needed when --force is not set.
     */
    public function findCompletedRun(int $customerId, string $sourceType, int $sourceId): ?EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', EnterpriseWikiIngestRun::STATUS_COMPLETED)
            ->latest()
            ->first();
    }

    /**
     * Assemble a deterministic Markdown document from all claims stored for a page version.
     * Claims are listed in insertion order (by id) with their source references.
     * Returns an empty string when no claims exist; callers should treat that as a failure.
     */
    public function assembleContentMarkdown(
        EnterpriseWikiPageVersion $pageVersion,
        string $pageTitle,
        int $runId,
    ): string {
        $claims = EnterpriseWikiClaim::query()
            ->with('sourceReferences')
            ->where('enterprise_wiki_page_version_id', $pageVersion->id)
            ->orderBy('id')
            ->get();

        if ($claims->isEmpty()) {
            return '';
        }

        $lines = [];
        $lines[] = "# {$pageTitle}";
        $lines[] = '';
        $lines[] = sprintf('<!-- wiki-ingest-run:%d -->', $runId);
        $lines[] = '';

        foreach ($claims as $claim) {
            $lines[] = $claim->claim_text;
            $lines[] = '';

            foreach ($claim->sourceReferences as $ref) {
                $excerpt = ($ref->excerpt !== null && $ref->excerpt !== '')
                    ? sprintf(' — «%s»', $ref->excerpt)
                    : '';
                $lines[] = sprintf('> *Kilde: %s%s*', $ref->source_label, $excerpt);
            }

            $lines[] = '';
        }

        // Remove trailing blank line(s)
        while (! empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve an EnterpriseWikiDocument eligible for wiki ingest.
     * Requires document_status = 'extracted' and non-empty extracted_text.
     *
     * @throws InvalidArgumentException for any validation failure
     */
    public function resolveDocumentForIngest(int $customerId, int $documentId): EnterpriseWikiDocument
    {
        $document = EnterpriseWikiDocument::query()
            ->where('id', $documentId)
            ->where('customer_id', $customerId)
            ->select(['id', 'customer_id', 'original_filename', 'file_hash_sha256', 'extracted_text', 'document_status'])
            ->first();

        if ($document === null) {
            throw new InvalidArgumentException(
                "EnterpriseWikiDocument [{$documentId}] not found for customer [{$customerId}]."
            );
        }

        if ($document->document_status !== EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
            throw new InvalidArgumentException(
                "EnterpriseWikiDocument [{$documentId}] has document_status '{$document->document_status}', expected 'extracted'."
            );
        }

        if (blank($document->extracted_text)) {
            throw new InvalidArgumentException(
                "EnterpriseWikiDocument [{$documentId}] has no extracted text."
            );
        }

        return $document;
    }

    /**
     * Create a new ingest run in the QUEUED state for an EnterpriseWikiDocument.
     */
    public function createQueuedRunForDocument(int $customerId, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        EnterpriseWikiQueueTrace::log('run_create_before', [
            'run_id' => null,
            'customer_id' => $customerId,
            'document_id' => $document->id,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => $this->computeDocumentSourceHash($document->id, (string) $document->file_hash_sha256),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);

        EnterpriseWikiQueueTrace::log('run_create_after', [
            'run_id' => $run->id,
            'customer_id' => $customerId,
            'document_id' => $document->id,
        ]);

        return $run;
    }

    public function computeDocumentSourceHash(int $documentId, string $fileHash): string
    {
        return hash('sha256', "enterprise_wiki_document:{$documentId}:{$fileHash}");
    }
}
