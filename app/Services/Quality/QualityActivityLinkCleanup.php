<?php

namespace App\Services\Quality;

use App\Models\KpiActivity;
use App\Models\QualityProcessBlueprint;
use App\Models\RiskActivity;

/**
 * Removes other modules' links to a Kvalitet activity once the step is no longer in the process's
 * working flow — risk_activities (Risiko) and kpi_activities (Mål og KPI).
 *
 * Activity keys are slugs of step labels. Left in place, a link would be inherited by a later step
 * that happens to get the same label. A whole-process link, and a removed process, are not this
 * class's concern: the first is unaffected by the flow, the second cascades in the database.
 *
 * Called from the blueprint's saved/deleted model events (AppServiceProvider), so Kvalitet's own
 * code never mentions Risiko or Mål og KPI. It only deletes, and returns nothing to Kvalitet.
 * Quality's own activity links (controls, articles) follow their own rules and are not touched.
 */
class QualityActivityLinkCleanup
{
    public function __construct(
        private readonly QualityProcessContextReader $quality,
    ) {}

    /** With no flow at all, every activity link of the process goes. */
    public function prune(int $processId, ?QualityProcessBlueprint $blueprint): void
    {
        $keys = array_keys($this->quality->steps($blueprint));

        RiskActivity::query()
            ->where('quality_item_id', $processId)
            ->whereNotIn('activity_key', $keys)
            ->delete();

        KpiActivity::query()
            ->where('quality_process_id', $processId)
            ->whereNotIn('activity_key', $keys)
            ->delete();
    }
}
