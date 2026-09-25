<?php

use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settle runs left waiting for an approval that no longer gates anything.
 *
 * `awaiting_document_owner_approval` meant: all automatic processing is done, but a document owner
 * has not yet confirmed that the content drawn from their files is represented correctly. That
 * confirmation stopped being a condition for publishing a Wiki page, so nothing will ever move
 * these runs on — they would sit as "Avventer godkjenning" for good, describing a step that no
 * longer exists.
 *
 * They were complete all along. The status said so itself: it is only ever reached from
 * `completed`, or from a QA-passed run that had nothing left to do. So this restores the state they
 * would have been in, and clears the message explaining a wait that is over.
 *
 * `finished_at` is set to `updated_at` rather than `now()`: the run finished when it stopped
 * processing, not when this migration ran, and dating it today would put a fictional timestamp into
 * every progress view that reads it.
 *
 * Nothing else is touched. The approval rows, who owns which document, the source references and
 * every generated page stay exactly as they are — this is about a run's own lifecycle, not about
 * the provenance it recorded.
 */
return new class extends Migration
{
    private const LEGACY_STATUS = 'awaiting_document_owner_approval';

    public function up(): void
    {
        if (! Schema::hasTable('enterprise_wiki_ingest_runs')) {
            return;
        }

        DB::table('enterprise_wiki_ingest_runs')
            ->where('status', self::LEGACY_STATUS)
            ->update([
                'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
                'finished_at' => DB::raw('COALESCE(finished_at, updated_at)'),
                'error_message' => null,
                'failed_phase' => null,
            ]);
    }

    /**
     * Deliberately irreversible.
     *
     * A completed run carries no record of whether it once sat in the legacy status, and guessing
     * which ones to send back — by re-reading approval rows that no longer decide anything — would
     * invent a wait rather than restore one. Rolling this back would also reintroduce a state the
     * code no longer produces or understands.
     */
    public function down(): void
    {
        // No safe inverse; see the note above.
    }
};
