<?php

namespace Tests\Support;

use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Test-only fixture for tests/e2e/quality-overview-sections.spec.js. Not autoloaded in production
 * (autoload-dev only) — invoked via `php artisan tinker --execute=...` from the Playwright spec.
 *
 * One quality item of each kind Oversikt lists in its own section — a policy, a process and a
 * control with its details and one piece of evidence — in the E2E System Owner's customer. Every
 * title starts with "E2E Oversikt" and ends with the run's suffix, which is what cleanup() matches.
 */
class QualityOverviewE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    /**
     * @return array{policy: string, process: string, control: string}
     */
    public static function seed(string $suffix): array
    {
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
        $titles = [
            'policy' => "E2E Oversikt policy {$suffix}",
            'process' => "E2E Oversikt prosess {$suffix}",
            'control' => "E2E Oversikt kontroll {$suffix}",
        ];

        DB::transaction(function () use ($customerId, $titles): void {
            $item = fn (string $type, string $status): QualityItem => QualityItem::create([
                'customer_id' => $customerId, 'quality_type' => $type, 'title' => $titles[$type], 'status' => $status,
            ]);

            $item(QualityItem::TYPE_POLICY, QualityItem::STATUS_ACTIVE);
            $item(QualityItem::TYPE_PROCESS, QualityItem::STATUS_DRAFT);
            $control = $item(QualityItem::TYPE_CONTROL, QualityItem::STATUS_ACTIVE);

            QualityControlDetail::create([
                'customer_id' => $customerId, 'quality_item_id' => $control->id,
                'criterion' => 'Fire øyne på leverandørens attester',
                'responsibility' => 'Innkjøpsleder',
                'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
                'method' => 'Stikkprøve',
            ]);
            QualityItemDocument::create([
                'customer_id' => $customerId, 'quality_item_id' => $control->id,
                'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE, 'title' => 'Signert kontrollskjema',
            ]);
        });

        return $titles;
    }

    /** Removes this run's items, or every run's when no suffix is given. Details and evidence cascade. */
    public static function cleanup(?string $suffix = null): void
    {
        QualityItem::query()
            ->where('title', 'like', 'E2E Oversikt %'.($suffix ?? ''))
            ->get()
            ->each->delete();
    }
}
