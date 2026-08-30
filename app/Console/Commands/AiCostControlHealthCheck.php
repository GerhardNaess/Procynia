<?php

namespace App\Console\Commands;

use App\Models\AiUsageAttempt;
use App\Models\CustomerAiUsageReservation;
use App\Services\Admin\AdminNotificationService;
use App\Services\Ai\Operational\AiOperationalPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic sweep for cost-control states that are safe but must not stay unnoticed.
 *
 * The guards deliberately hold capacity when they cannot prove it was not spent — an uncertain
 * reservation after a timeout, an attempt Procynia could not price. That is the right call in the
 * moment, but nothing releases those holds on its own, so without a sweep they would quietly
 * accumulate until a customer or the platform ran out of capacity for no visible reason.
 *
 * Read-only: it reports and alerts, and never releases a hold or edits history. Deciding what an
 * uncertain reservation really cost is a human judgement, documented in the runbook.
 */
#[AsCommand(name: 'ai:cost-control-health')]
class AiCostControlHealthCheck extends Command
{
    protected $signature = 'ai:cost-control-health
                            {--hours=24 : How old an uncertain hold must be before it is reported}';

    protected $description = 'Report ageing uncertain AI reservations and unpriced provider attempts to internal admins.';

    public function handle(AdminNotificationService $adminNotifications, AiOperationalPricingService $pricing): int
    {
        if (! Schema::hasTable('customer_ai_usage_reservations') || ! Schema::hasTable('ai_usage_attempts')) {
            $this->warn('[AI_COST_HEALTH] Cost-control schema is not migrated; nothing to check.');

            return self::SUCCESS;
        }

        $hours = max(1, (int) $this->option('hours'));
        $cutoff = CarbonImmutable::now(config('app.timezone') ?: 'UTC')->subHours($hours);
        $today = $cutoff->toDateString();

        $ageingHolds = CustomerAiUsageReservation::query()
            ->where('status', CustomerAiUsageReservation::STATUS_UNCERTAIN)
            ->where('reserved_at', '<', $cutoff)
            ->count();

        if ($ageingHolds > 0) {
            $customers = CustomerAiUsageReservation::query()
                ->where('status', CustomerAiUsageReservation::STATUS_UNCERTAIN)
                ->where('reserved_at', '<', $cutoff)
                ->distinct()
                ->count('customer_id');

            $adminNotifications->create(
                type: 'ai_uncertain_reservations_ageing',
                severity: 'warning',
                title: 'Usikre AI-reservasjoner holder kapasitet',
                message: sprintf(
                    '%d reservasjon(er) fordelt på %d kunde(r) har stått som usikre i mer enn %d timer. De holder fortsatt kapasitet og frigis aldri automatisk. Se runbook for manuell vurdering.',
                    $ageingHolds,
                    $customers,
                    $hours,
                ),
                data: ['count' => $ageingHolds, 'customers' => $customers, 'older_than_hours' => $hours],
                dedupeKey: sprintf('ai_uncertain_reservations_ageing:%s', $today),
            );
        }

        $unpriced = AiUsageAttempt::query()
            ->where('cost_status', 'unknown')
            ->where('started_at', '>=', $cutoff)
            ->count();

        if ($unpriced > 0) {
            $adminNotifications->create(
                type: 'ai_unpriced_attempts',
                severity: 'warning',
                title: 'AI-kall uten kjent pris',
                message: sprintf(
                    '%d AI-kall de siste %d timene kunne ikke prises. Kostnaden er registrert som ukjent, ikke som null. Kontroller modellkatalogen.',
                    $unpriced,
                    $hours,
                ),
                data: ['count' => $unpriced, 'window_hours' => $hours],
                dedupeKey: sprintf('ai_unpriced_attempts:%s', $today),
            );
        }

        if (! $pricing->catalogueIsConfigured()) {
            $adminNotifications->create(
                type: 'ai_model_price_catalogue_empty',
                severity: 'critical',
                title: 'AI-priskatalogen er tom',
                message: 'Ingen modellpriser er registrert. Kostnadsestimering og NOK-sikkerhetsbudsjett er uten virkning. Kjør ai:sync-model-prices.',
                data: [],
                dedupeKey: 'ai_model_price_catalogue_empty:'.$today,
            );
        }

        $this->line(sprintf(
            '[AI_COST_HEALTH] Ageing uncertain holds: %d. Unpriced attempts (%dh): %d. Price catalogue: %s.',
            $ageingHolds,
            $hours,
            $unpriced,
            $pricing->catalogueIsConfigured() ? 'configured' : 'EMPTY',
        ));

        return self::SUCCESS;
    }
}
