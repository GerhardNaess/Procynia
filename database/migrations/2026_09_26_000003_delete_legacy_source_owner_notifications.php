<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear alerts left over from a step that no longer exists.
 *
 * Two event types asked about a decision that used to gate publishing a Wiki page:
 * `wiki.source_owner_review_required` told a document owner their material was in use and had to be
 * checked, and `wiki.source_owner_gate_ready` told the reviewer the last owner had signed off and
 * the page could finally be approved.
 *
 * Neither describes anything any more. A source document is provenance, not a level of approval, so
 * nobody is waiting on that sign-off and no gate opens when it arrives. Left in place the messages
 * read as outstanding obligations — the bell is for things that happened or things still to do, and
 * these are neither.
 *
 * Deleted rather than marked read: a read alert is still a record of a real event, and these are
 * records of a step that was removed. Nothing is lost that the domain does not still hold — who
 * owns which source document, and what each of them has said about it, lives on the approval rows
 * and is untouched here.
 *
 * Scoped to those two event types by name. Review, quality assurance, publication and bid alerts
 * are not touched.
 */
return new class extends Migration
{
    private const LEGACY_EVENT_TYPES = [
        'wiki.source_owner_review_required',
        'wiki.source_owner_gate_ready',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('user_notifications')) {
            return;
        }

        DB::table('user_notifications')
            ->whereIn('event_type', self::LEGACY_EVENT_TYPES)
            ->delete();
    }

    /**
     * Deliberately irreversible.
     *
     * The messages could only be rebuilt by re-deriving them from approval rows, which would
     * recreate obligations that no longer exist — inventing the wait rather than restoring it.
     */
    public function down(): void
    {
        // No safe inverse; see the note above.
    }
};
