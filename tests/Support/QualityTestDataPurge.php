<?php

namespace Tests\Support;

use App\Models\QualityItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: removes Kvalitet items an E2E run created, history included. Not autoloaded in
 * production (autoload-dev only).
 *
 * A process with an approved version, an item with registered evidence and an item another module
 * links to are never deleted in Procynia; PostgreSQL triggers refuse it unless the customer itself is
 * being deleted
 * (quality_items_keep_approved_versions, quality_items_keep_control_history,
 * quality_items_keep_cross_module_links — phase 0 step 12, A19, A28).
 * E2E runs in the shared E2E customer still have to clean up after themselves, so this helper
 * switches those triggers off for its own transaction, deletes, and switches them on again — a table
 * owner's explicit DDL step, the same one ImprovementTestDataPurge and RiskTestDataPurge take.
 * Revisions, evidence and links go by cascade; their own triggers allow that once the item is gone.
 * Nothing under app/ may do this.
 */
final class QualityTestDataPurge
{
    private const TRIGGERS = ['quality_items_keep_approved_versions', 'quality_items_keep_control_history', 'quality_items_keep_cross_module_links'];

    /** @param Builder<QualityItem> $items */
    public static function delete(Builder $items): void
    {
        DB::transaction(function () use ($items): void {
            $pgsql = DB::getDriverName() === 'pgsql';

            foreach ($pgsql ? self::TRIGGERS : [] as $trigger) {
                DB::statement("ALTER TABLE quality_items DISABLE TRIGGER {$trigger}");
            }

            // Query-builder delete: no model events.
            $items->toBase()->delete();

            foreach ($pgsql ? self::TRIGGERS : [] as $trigger) {
                DB::statement("ALTER TABLE quality_items ENABLE TRIGGER {$trigger}");
            }
        });
    }
}
