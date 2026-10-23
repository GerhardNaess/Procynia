<?php

namespace Tests\Support;

use App\Models\Kpi;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: removes objectives or KPIs an E2E run created, measurements included. Not autoloaded
 * in production (autoload-dev only).
 *
 * A KPI with measurements or a status change is never deleted in Procynia, nor an objective that
 * has been closed or whose KPIs carry such history; PostgreSQL triggers refuse it unless the customer
 * itself is being deleted (kpis_keep_measurement_history, objectives_keep_decision_history). E2E runs
 * in the shared E2E customer still have to clean up after themselves, so this helper switches those
 * triggers off for its own transaction, deletes, and switches them on again — a table owner's explicit DDL step, the same one RiskTestDataPurge and
 * ImprovementTestDataPurge take. Measurements and status history go by cascade; their own triggers
 * allow that once the KPI or objective is gone. Nothing under app/ may do this.
 */
final class ObjectiveTestDataPurge
{
    /** @param Builder<Objective>|Builder<Kpi> $records */
    public static function delete(Builder $records): void
    {
        DB::transaction(function () use ($records): void {
            $pgsql = DB::getDriverName() === 'pgsql';

            if ($pgsql) {
                DB::statement('ALTER TABLE objectives DISABLE TRIGGER objectives_keep_decision_history');
                DB::statement('ALTER TABLE kpis DISABLE TRIGGER kpis_keep_measurement_history');
            }

            // Query-builder delete: no model events, so Objective::booted() and Kpi::booted() do not
            // refuse either.
            $records->toBase()->delete();

            if ($pgsql) {
                DB::statement('ALTER TABLE kpis ENABLE TRIGGER kpis_keep_measurement_history');
                DB::statement('ALTER TABLE objectives ENABLE TRIGGER objectives_keep_decision_history');
            }
        });
    }
}
