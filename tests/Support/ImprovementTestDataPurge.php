<?php

namespace Tests\Support;

use App\Models\ImprovementCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: removes Avvik og forbedringer cases an E2E run created, history included. Not
 * autoloaded in production (autoload-dev only).
 *
 * A case with status changes or tiltak is never deleted in Procynia; a PostgreSQL trigger refuses
 * it unless the customer itself is being deleted (improvement_cases_keep_history). E2E runs in the
 * shared E2E customer still have to clean up after themselves, so this helper switches that trigger
 * off for its own transaction, deletes, and switches it on again — a table owner's explicit DDL
 * step, the same one ComplianceE2EFixture and RiskTestDataPurge take. Tiltak and history rows go by
 * cascade; their own triggers allow that once the case is gone. Nothing under app/ may do this.
 */
final class ImprovementTestDataPurge
{
    /** @param Builder<ImprovementCase> $cases */
    public static function delete(Builder $cases): void
    {
        DB::transaction(function () use ($cases): void {
            $pgsql = DB::getDriverName() === 'pgsql';

            if ($pgsql) {
                DB::statement('ALTER TABLE improvement_cases DISABLE TRIGGER improvement_cases_keep_history');
            }

            // Query-builder delete: no model events, so ImprovementCase::booted() does not refuse either.
            $cases->toBase()->delete();

            if ($pgsql) {
                DB::statement('ALTER TABLE improvement_cases ENABLE TRIGGER improvement_cases_keep_history');
            }
        });
    }
}
