<?php

namespace Tests\Support;

use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Services\Quality\QualityProcessBlueprintService;

/**
 * Test-only: the version hash of a process's working flow as stored right now.
 *
 * Every flow write carries the version it was made against (A32). A fixture that just wants a flow
 * to exist has no editor that was opened on one, so it reads the version in place — the same
 * value the page hands the editor.
 */
final class QualityFlowVersion
{
    public static function of(QualityItem|int $item): string
    {
        $id = $item instanceof QualityItem ? (int) $item->id : $item;

        return QualityProcessBlueprintService::versionHash(
            QualityProcessBlueprint::query()->where('quality_item_id', $id)->first(),
        );
    }
}
