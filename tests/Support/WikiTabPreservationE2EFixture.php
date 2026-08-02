<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the Wiki tab-preservation E2E spec (tests/e2e/wiki-tab-preservation.spec.js).
 * Seeds two independent documents so the Kjøringer-tab and Kildedokumenter-tab actions can each be
 * exercised without interfering with each other:
 *
 *   - a document with a STATUS_RUNNING run — safe to cancel from the Kjøringer tab (cancelling
 *     only stops further automatic processing; it never deletes anything);
 *   - a document with no owner assigned — safe to assign an owner to from the Kildedokumenter tab.
 */
class WikiTabPreservationE2EFixture
{
    private const RUN_DOCUMENT_FILENAME = 'E2E Tab Preservation Run Check.docx';

    private const SOURCE_DOCUMENT_FILENAME = 'E2E Tab Preservation Source Check.docx';

    /**
     * @return array{run_document_id: int, run_id: int, source_document_id: int}
     */
    public static function seed(int $customerId): array
    {
        $runDocument = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::RUN_DOCUMENT_FILENAME,
            'file_path' => 'e2e-tab-preservation/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E tab preservation run check content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $runDocument->id,
            'source_hash' => hash('sha256', 'e2e-tab-preservation-running'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $sourceDocument = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::SOURCE_DOCUMENT_FILENAME,
            'file_path' => 'e2e-tab-preservation/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E tab preservation source check content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'owner_user_id' => null,
        ]);

        return [
            'run_document_id' => $runDocument->id,
            'run_id' => $run->id,
            'source_document_id' => $sourceDocument->id,
        ];
    }

    public static function cleanup(int $customerId): void
    {
        foreach ([self::RUN_DOCUMENT_FILENAME, self::SOURCE_DOCUMENT_FILENAME] as $filename) {
            $documents = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->where('original_filename', $filename)
                ->get();

            foreach ($documents as $document) {
                EnterpriseWikiIngestRun::query()
                    ->where('customer_id', $customerId)
                    ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                    ->where('source_id', $document->id)
                    ->delete();

                $document->delete();
            }
        }
    }
}
