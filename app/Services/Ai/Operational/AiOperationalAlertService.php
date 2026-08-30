<?php

namespace App\Services\Ai\Operational;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Operational\AiFxState;
use App\Data\Ai\Operational\AiPriceState;
use App\Models\Customer;
use App\Services\Admin\AdminNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Internal operations alerts for cost risk.
 *
 * These go to Procynia, never to the customer: a NOK safety budget, a missing model price and a
 * stale exchange rate are facts about how Procynia runs the platform, not about what the customer
 * bought. Every alert is deduplicated per period so a blocked customer retrying in a loop produces
 * one notification, not one per call.
 */
class AiOperationalAlertService
{
    public function __construct(private readonly AdminNotificationService $adminNotifications) {}

    public function reportMissingModelPrice(string $provider, string $model, AiCallContext $context): void
    {
        $this->notify(
            type: 'ai_model_price_missing',
            severity: 'critical',
            title: 'AI-modell uten kjent pris',
            message: sprintf(
                'Modellen «%s» (%s) har ingen gyldig pris i katalogen. Kall med denne modellen blokkeres til prisen er registrert.',
                $model,
                $provider,
            ),
            data: ['provider' => $provider, 'model' => $model, 'operation' => $context->operation],
            dedupeKey: sprintf('ai_model_price_missing:%s:%s:%s', $provider, $model, $this->today()),
        );
    }

    /**
     * No prices at all. Cost control cannot price anything, so the unknown-price stop is inert and
     * the NOK budgets have nothing to charge. This is a configuration failure, not a model gap.
     */
    public function reportPriceCatalogueEmpty(): void
    {
        $this->notify(
            type: 'ai_model_price_catalogue_empty',
            severity: 'critical',
            title: 'AI-priskatalogen er tom',
            message: 'Ingen modellpriser er registrert. Kostnadsestimering og NOK-sikkerhetsbudsjett er derfor uten virkning. Kjør ai:sync-model-prices.',
            data: [],
            dedupeKey: 'ai_model_price_catalogue_empty:'.$this->today(),
        );
    }

    public function reportPriceState(string $provider, string $model, AiPriceState $priceState): void
    {
        if ($priceState->state !== AiPriceState::STALE_CRITICAL) {
            return;
        }

        $this->notify(
            type: 'ai_model_price_stale_critical',
            severity: 'warning',
            title: 'AI-modellpris er svært gammel',
            message: sprintf(
                'Prisen for «%s» (%s) er %d dager gammel. Kostnadsestimatet brukes med sikkerhetsmargin til prisen er bekreftet.',
                $model,
                $provider,
                (int) $priceState->ageDays,
            ),
            data: ['provider' => $provider, 'model' => $model, 'age_days' => $priceState->ageDays],
            dedupeKey: sprintf('ai_model_price_stale:%s:%s:%s', $provider, $model, $this->today()),
        );
    }

    public function reportFxState(AiFxState $fxState, string $currency): void
    {
        if ($fxState->isMissing()) {
            $this->notify(
                type: 'ai_fx_missing',
                severity: 'critical',
                title: 'Ingen valutakurs tilgjengelig',
                message: sprintf(
                    'Det finnes ingen registrert %s/NOK-kurs. Kostnad beregnes med en konservativ fallback-kurs til kursdata er på plass.',
                    $currency,
                ),
                data: ['currency' => $currency, 'fallback_rate' => $fxState->rate],
                dedupeKey: sprintf('ai_fx_missing:%s:%s', $currency, $this->today()),
            );

            return;
        }

        if ($fxState->state !== AiFxState::STALE_CRITICAL) {
            return;
        }

        $this->notify(
            type: 'ai_fx_stale_critical',
            severity: 'warning',
            title: 'Valutakursen er utdatert',
            message: sprintf(
                'Siste %s/NOK-kurs er fra %s (%d dager gammel). Kostnadsestimatet padres med sikkerhetsmargin.',
                $currency,
                (string) $fxState->rateDate,
                (int) $fxState->ageDays,
            ),
            data: ['currency' => $currency, 'rate_date' => $fxState->rateDate, 'age_days' => $fxState->ageDays],
            dedupeKey: sprintf('ai_fx_stale:%s:%s', $currency, $this->today()),
        );
    }

    public function reportBudgetThreshold(string $scope, ?Customer $customer, string $window, int $percentage, float $limit): void
    {
        $level = $percentage >= 90 ? 'critical' : 'warning';
        $label = $customer instanceof Customer ? $customer->name : 'plattformen';

        $this->notify(
            type: sprintf('ai_%s_budget_%s', $scope, $level),
            severity: $level === 'critical' ? 'critical' : 'warning',
            title: sprintf('AI-sikkerhetsbudsjett %d %% brukt', $percentage),
            message: sprintf(
                '%s har brukt %d %% av det operasjonelle %s AI-budsjettet (%s kr).',
                ucfirst($label),
                $percentage,
                $window === 'daily' ? 'daglige' : 'månedlige',
                number_format($limit, 2, ',', ' '),
            ),
            data: ['scope' => $scope, 'customer_id' => $customer?->id, 'window' => $window, 'percentage' => $percentage],
            dedupeKey: sprintf('ai_budget_%s:%s:%s:%s:%s', $level, $scope, $customer?->id ?? 'global', $window, $this->periodKey($window)),
        );
    }

    public function reportBudgetBlocked(string $reason, ?Customer $customer): void
    {
        $isGlobal = in_array($reason, [
            \App\Exceptions\Ai\AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED,
            \App\Exceptions\Ai\AiCostControlException::GLOBAL_MONTHLY_BUDGET_EXHAUSTED,
        ], true);

        $window = str_contains($reason, 'DAILY') ? 'daily' : 'monthly';

        $this->notify(
            type: $isGlobal ? 'ai_global_budget_blocked' : 'ai_customer_budget_blocked',
            severity: 'critical',
            title: $isGlobal ? 'Globalt AI-budsjett er brukt opp' : 'Kundens AI-budsjett er brukt opp',
            message: $isGlobal
                ? 'Det operasjonelle AI-sikkerhetsbudsjettet er nådd. Ingen nye AI-kall utføres for noen kunde.'
                : sprintf('AI-kall for «%s» blokkeres av kundens operasjonelle sikkerhetsbudsjett.', $customer?->name ?? 'ukjent kunde'),
            data: ['reason' => $reason, 'customer_id' => $customer?->id],
            // One notification per blocked state per period — not one per denied request.
            dedupeKey: sprintf('ai_budget_blocked:%s:%s:%s', $reason, $customer?->id ?? 'global', $this->periodKey($window)),
        );
    }

    /** @param array{state: string, grace_ends_at: string|null} $evaluation */
    public function reportPaymentGrace(Customer $customer, array $evaluation): void
    {
        $this->notify(
            type: 'ai_payment_grace',
            severity: 'warning',
            title: 'Kunde i betalingsfrist',
            message: sprintf(
                'Abonnementet til «%s» har status %s. AI er fortsatt tilgjengelig fram til %s.',
                $customer->name,
                $evaluation['state'],
                $evaluation['grace_ends_at'] ?? 'fristen utløper',
            ),
            data: ['customer_id' => $customer->id, 'state' => $evaluation['state'], 'grace_ends_at' => $evaluation['grace_ends_at']],
            dedupeKey: sprintf('ai_payment_grace:%d:%s', $customer->id, $this->periodKey('daily')),
        );
    }

    /** @param array{state: string, reason: string|null} $evaluation */
    public function reportPaymentBlocked(Customer $customer, array $evaluation): void
    {
        $this->notify(
            type: 'ai_payment_blocked',
            severity: 'critical',
            title: 'AI blokkert av betalingsstatus',
            message: sprintf(
                'AI-kall for «%s» blokkeres. Abonnementsstatus: %s.',
                $customer->name,
                $evaluation['state'],
            ),
            data: ['customer_id' => $customer->id, 'state' => $evaluation['state'], 'reason' => $evaluation['reason']],
            dedupeKey: sprintf('ai_payment_blocked:%d:%s:%s', $customer->id, $evaluation['state'], $this->periodKey('daily')),
        );
    }

    /** @param array<string, mixed> $data */
    private function notify(string $type, string $severity, string $title, string $message, array $data, string $dedupeKey): void
    {
        try {
            $this->adminNotifications->create($type, $severity, $title, $message, $data, $dedupeKey);
        } catch (Throwable $throwable) {
            // Alerting must never be the reason an AI guard fails.
            Log::warning('[PROCYNIA][AI_OPERATIONAL_ALERT] Could not raise operational alert.', [
                'type' => $type,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('app.timezone') ?: 'UTC')->toDateString();
    }

    private function periodKey(string $window): string
    {
        $now = CarbonImmutable::now(config('app.timezone') ?: 'UTC');

        return $window === 'daily' ? $now->toDateString() : $now->format('Y-m');
    }
}
