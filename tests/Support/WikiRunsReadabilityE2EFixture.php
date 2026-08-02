<?php

namespace Tests\Support;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only fixture for the Kjøringer readability E2E spec (tests/e2e/wiki-runs-readability.spec.js).
 * Seeds one "running" ingest run whose last progress is old enough to also trigger the "Ser ut
 * til å stå stille" pill and its explanation text — this exercises every piece of Kjøringer-tab
 * typography this task touched (main status pill, secondary pill, detail text, stalled warning,
 * step-chip timeline) in a single row, without touching any real customer data.
 */
class WikiRunsReadabilityE2EFixture
{
    private const DOCUMENT_FILENAME = 'E2E Readability Check.docx';

    public static function seed(int $customerId): int
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customerId,
            'original_filename' => self::DOCUMENT_FILENAME,
            'file_path' => 'e2e-readability/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'E2E readability check content.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'e2e-readability-check'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(20),
        ]);

        // The frontend's "seems stalled" check (RunActivityBlock) falls back to updated_at when
        // there is no last_progress_at column — Eloquent always stamps updated_at to "now" on
        // create(), so it must be backdated directly to make the row actually render as stalled.
        DB::table('enterprise_wiki_ingest_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        return $run->id;
    }

    public static function cleanup(int $customerId): void
    {
        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('original_filename', self::DOCUMENT_FILENAME)
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
