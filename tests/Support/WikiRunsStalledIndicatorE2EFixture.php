<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the stalled-indicator E2E spec (tests/e2e/wiki-runs-stalled-indicator.spec.js).
 * Seeds one long-idle run in a genuinely active status (generating_pages), which must show the
 * "Ser ut til å stå stille" warning. The waiting run it used to seed beside it was in
 * awaiting_document_owner_approval, a status 5d26caf2 retired; nothing can enter it any more.
 */
class WikiRunsStalledIndicatorE2EFixture
{
    private const ACTIVE_DOCUMENT_FILENAME = 'E2E Stalled Indicator Active Check.docx';

    /**
     * @return array{active_run_id: int}
     */
    public static function seed(int $customerId): array
    {
        self::cleanup($customerId);

        return ['active_run_id' => self::createIdleRun($customerId, self::ACTIVE_DOCUMENT_FILENAME, EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES)];
    }

    private static function createIdleRun(int $customerId, string $filename, string $status): int
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => $filename,
            'file_path' => 'e2e-stalled-indicator/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E stalled indicator check content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-stalled-indicator-'.$status),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
            'started_at' => now()->subMinutes(40),
        ]);

        // Backdate updated_at directly — the frontend's stalled check falls back to it when there
        // is no dedicated last_progress_at column (see EnterpriseWikiIngestRun's schema), and
        // Eloquent always stamps updated_at to "now" on create().
        DB::table('enterprise_wiki_ingest_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(40)]);

        return $run->id;
    }

    public static function cleanup(int $customerId): void
    {
        foreach ([self::ACTIVE_DOCUMENT_FILENAME] as $filename) {
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
