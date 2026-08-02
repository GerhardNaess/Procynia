<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the "delete a Wiki source awaiting document owner approval" E2E spec
 * (tests/e2e/wiki-delete-awaiting-approval.spec.js). Seeds two Kildedokumenter-tab rows side by
 * side, differing only in run status:
 *
 *   - one with a STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL run — the Delete button must be active,
 *     with no "aktiv kjøring" tooltip, and deletion must succeed directly;
 *   - one with a genuinely active STATUS_GENERATING_PAGES run — the Delete button must stay
 *     disabled with the "aktiv kjøring" tooltip, exactly as before this fix.
 */
class WikiDeleteAwaitingApprovalE2EFixture
{
    private const WAITING_DOCUMENT_FILENAME = 'E2E Delete Awaiting Approval Check.docx';

    private const ACTIVE_DOCUMENT_FILENAME = 'E2E Delete Active Run Check.docx';

    /**
     * @return array{waiting_document_id: int, waiting_run_id: int, active_document_id: int, active_run_id: int}
     */
    public static function seed(int $customerId): array
    {
        [$waitingDocumentId, $waitingRunId] = self::createDocumentWithRun(
            $customerId,
            self::WAITING_DOCUMENT_FILENAME,
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        );

        [$activeDocumentId, $activeRunId] = self::createDocumentWithRun(
            $customerId,
            self::ACTIVE_DOCUMENT_FILENAME,
            EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
        );

        return [
            'waiting_document_id' => $waitingDocumentId,
            'waiting_run_id' => $waitingRunId,
            'active_document_id' => $activeDocumentId,
            'active_run_id' => $activeRunId,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function createDocumentWithRun(int $customerId, string $filename, string $status): array
    {
        $filePath = 'e2e-delete-awaiting-approval/'.Str::random(8).'.docx';

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => $filename,
            'file_path' => $filePath,
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E delete-awaiting-approval check content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        Storage::disk('local')->put($filePath, 'E2E fixture file content.');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-delete-awaiting-approval-'.$status),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
            'started_at' => now(),
        ]);

        return [$document->id, $run->id];
    }

    public static function cleanup(int $customerId): void
    {
        foreach ([self::WAITING_DOCUMENT_FILENAME, self::ACTIVE_DOCUMENT_FILENAME] as $filename) {
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

                if ($document->file_path !== null && $document->file_path !== '') {
                    Storage::disk('local')->delete($document->file_path);
                }

                $document->delete();
            }
        }
    }
}
