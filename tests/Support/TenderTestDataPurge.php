<?php

namespace Tests\Support;

use App\Models\SavedNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: removes tender cases an E2E run created, decisions included. Not autoloaded in
 * production (autoload-dev only).
 *
 * A case with decisions or AI usage, and a requirement that has been approved, are never deleted in
 * Procynia (A42); PostgreSQL triggers refuse it unless the customer itself is being deleted. E2E
 * fixtures seed approved requirements into the shared E2E customer, which stays, so this helper
 * switches the two triggers off for its own transaction, deletes, and switches them on again — the
 * same explicit table-owner step as RiskTestDataPurge. Nothing under app/ may do this.
 */
final class TenderTestDataPurge
{
    /** @param Builder<SavedNotice> $cases */
    public static function delete(Builder $cases): void
    {
        DB::transaction(function () use ($cases): void {
            $pgsql = DB::getDriverName() === 'pgsql';

            if ($pgsql) {
                DB::statement('ALTER TABLE saved_notices DISABLE TRIGGER saved_notices_keep_decision_history');
                DB::statement('ALTER TABLE saved_notice_ai_requirements DISABLE TRIGGER saved_notice_ai_requirements_keep_approved');
            }

            // Query-builder delete: no model events, so the models' own guards do not refuse either.
            // Requirements, revisions and decision rows cascade; their own triggers allow that once
            // the case is gone.
            $cases->toBase()->delete();

            if ($pgsql) {
                DB::statement('ALTER TABLE saved_notice_ai_requirements ENABLE TRIGGER saved_notice_ai_requirements_keep_approved');
                DB::statement('ALTER TABLE saved_notices ENABLE TRIGGER saved_notices_keep_decision_history');
            }
        });
    }
}
