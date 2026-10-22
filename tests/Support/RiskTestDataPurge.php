<?php

namespace Tests\Support;

use App\Models\Risk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: removes risks an E2E run created, history included. Not autoloaded in production
 * (autoload-dev only).
 *
 * A risk with assessments, acceptances or tiltak is never deleted in Procynia (decision B3); a
 * PostgreSQL trigger refuses it unless the customer itself is being deleted. E2E runs still have
 * to clean up after themselves — some in a shared E2E customer that stays — so this helper
 * switches the trigger off for its own transaction, deletes, and switches it on again. That is a
 * table owner's explicit DDL step, visible as exactly what it is; the trigger itself has no way
 * round it, and nothing under app/ may do this (RiskHistoryProtectionTest checks).
 */
final class RiskTestDataPurge
{
    /** @param Builder<Risk> $risks */
    public static function delete(Builder $risks): void
    {
        DB::transaction(function () use ($risks): void {
            $pgsql = DB::getDriverName() === 'pgsql';

            if ($pgsql) {
                DB::statement('ALTER TABLE risks DISABLE TRIGGER risks_keep_decision_history');
            }

            // Query-builder delete: no model events, so Risk::booted() does not refuse either.
            // Assessments, acceptances and tiltak cascade; their own triggers allow that once the
            // risk is gone.
            $risks->toBase()->delete();

            if ($pgsql) {
                DB::statement('ALTER TABLE risks ENABLE TRIGGER risks_keep_decision_history');
            }
        });
    }
}
