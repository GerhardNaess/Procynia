<?php

namespace Tests\Support;

use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Test-only fixture for tests/e2e/a11y-grc-quality.spec.js (WCAG Fase 6, Kvalitet). Not autoloaded
 * in production (autoload-dev only) — invoked via `php artisan tinker --execute=...` from the spec.
 *
 * Like QualityOverviewE2EFixture it works in the E2E System Owner's customer, which carries Kvalitet
 * (E2ETestSeeder activates the ISO package), and marks everything with the run's suffix:
 *
 *   - a process with a flow in two roles — start, two activities, a decision with two branches, end —
 *     so the diagram, the step list and the structure editor all have something to show;
 *   - a control with two pieces of evidence, so removing one has a next row to land on.
 *
 * Every title starts with "E2E A11y" and ends with the suffix, which is what cleanup() matches. The
 * flow, the control details and the evidence go with their item through the database cascade.
 */
class A11yGrcQualityE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    /**
     * @return array{process: string, process_id: int, control: string, control_id: int, evidence: list<string>}
     */
    public static function seed(string $suffix): array
    {
        $owner = User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->firstOrFail();
        $customerId = (int) $owner->customer_id;

        $titles = [
            'process' => "E2E A11y prosess {$suffix}",
            'control' => "E2E A11y kontroll {$suffix}",
            'evidence' => ["E2E A11y evidens én {$suffix}", "E2E A11y evidens to {$suffix}"],
        ];

        return DB::transaction(function () use ($customerId, $owner, $titles): array {
            $process = QualityItem::query()->create([
                'customer_id' => $customerId,
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => $titles['process'],
                'purpose' => 'Prosess for universell utforming-testene.',
                'status' => QualityItem::STATUS_DRAFT,
                'created_by_user_id' => $owner->id,
            ]);

            QualityProcessBlueprint::query()->create([
                'customer_id' => $customerId,
                'quality_item_id' => $process->id,
                'payload' => [
                    'lanes' => [
                        ['key' => 'innkjoper', 'label' => 'Innkjøper'],
                        ['key' => 'leder', 'label' => 'Leder'],
                    ],
                    'nodes' => [
                        ['key' => 'start', 'lane' => 'innkjoper', 'type' => 'start', 'label' => 'Behov meldes', 'description' => null, 'subprocess_quality_item_id' => null],
                        ['key' => 'registrer', 'lane' => 'innkjoper', 'type' => 'step', 'label' => 'Registrer behovet', 'description' => null, 'subprocess_quality_item_id' => null],
                        ['key' => 'stor', 'lane' => 'leder', 'type' => 'decision', 'label' => 'Over terskelverdi?', 'description' => null, 'subprocess_quality_item_id' => null],
                        ['key' => 'godkjenn', 'lane' => 'leder', 'type' => 'step', 'label' => 'Godkjenn innkjøpet', 'description' => null, 'subprocess_quality_item_id' => null],
                        ['key' => 'slutt', 'lane' => 'leder', 'type' => 'end', 'label' => 'Bestillingen er sendt', 'description' => null, 'subprocess_quality_item_id' => null],
                    ],
                    'edges' => [
                        ['from' => 'start', 'to' => 'registrer', 'label' => null],
                        ['from' => 'registrer', 'to' => 'stor', 'label' => null],
                        ['from' => 'stor', 'to' => 'godkjenn', 'label' => 'Ja'],
                        ['from' => 'stor', 'to' => 'slutt', 'label' => 'Nei'],
                        ['from' => 'godkjenn', 'to' => 'slutt', 'label' => null],
                    ],
                ],
                'status' => QualityProcessBlueprint::STATUS_DRAFT,
                'source' => QualityProcessBlueprint::SOURCE_MANUAL,
                'generated_at' => now(),
                'generated_by_user_id' => $owner->id,
            ]);

            $control = QualityItem::query()->create([
                'customer_id' => $customerId,
                'quality_type' => QualityItem::TYPE_CONTROL,
                'title' => $titles['control'],
                'status' => QualityItem::STATUS_ACTIVE,
                'created_by_user_id' => $owner->id,
            ]);

            QualityControlDetail::query()->create([
                'customer_id' => $customerId,
                'quality_item_id' => $control->id,
                'criterion' => 'Attestene er kontrollert.',
                'responsibility' => 'Innkjøpsleder',
                'frequency' => QualityControlDetail::FREQUENCY_QUARTERLY,
                'method' => 'Stikkprøve',
            ]);

            foreach ($titles['evidence'] as $title) {
                QualityItemDocument::query()->create([
                    'customer_id' => $customerId,
                    'quality_item_id' => $control->id,
                    'relation_type' => QualityItemDocument::RELATION_TYPE_EVIDENCE,
                    'title' => $title,
                ]);
            }

            return [
                'process' => $titles['process'],
                'process_id' => (int) $process->id,
                'control' => $titles['control'],
                'control_id' => (int) $control->id,
                'evidence' => $titles['evidence'],
            ];
        });
    }

    /** Removes this run's items, or every run's when no suffix is given. Flow, details and evidence cascade. */
    public static function cleanup(?string $suffix = null): void
    {
        // The control has evidence, which is retired rather than deleted in Procynia (A19).
        QualityTestDataPurge::delete(QualityItem::query()
            ->where('title', 'like', 'E2E A11y %'.($suffix ?? ''))
            ->whereIn('quality_type', [QualityItem::TYPE_PROCESS, QualityItem::TYPE_CONTROL]));
    }
}
