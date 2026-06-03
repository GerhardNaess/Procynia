<?php

namespace App\Services\Ai\Pricing;

use App\Models\AdminNotification;
use App\Models\AiModelPrice;
use App\Models\AiModelPriceSyncRun;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose: Synchronise AI model prices from a provider into ai_model_prices.
 * Historical prices are never deleted — changed prices close the old row and open a new one.
 */
class AiModelPriceSyncService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
    ) {
    }

    public function sync(AiModelPriceProviderInterface $provider): AiModelPriceSyncRun
    {
        $run = AiModelPriceSyncRun::query()->create([
            'provider'   => $provider->providerKey(),
            'started_at' => now(),
            'status'     => AiModelPriceSyncRun::STATUS_RUNNING,
        ]);

        $created   = 0;
        $changed   = 0;
        $unchanged = 0;
        $warnings  = 0;

        try {
            $prices = $provider->fetchPrices();
            $today  = now()->toDateString();

            foreach ($prices as $price) {
                try {
                    $result = $this->upsertPrice($provider->providerKey(), $price, $today);

                    match ($result) {
                        'created'   => $created++,
                        'changed'   => $changed++,
                        'unchanged' => $unchanged++,
                        default     => null,
                    };
                } catch (Throwable $e) {
                    $warnings++;
                    Log::warning('[PROCYNIA][AI_PRICE_SYNC] Failed to upsert price entry.', [
                        'provider' => $provider->providerKey(),
                        'model'    => $price['model'] ?? null,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $run->forceFill([
                'status'           => AiModelPriceSyncRun::STATUS_COMPLETED,
                'finished_at'      => now(),
                'models_seen'      => count($prices),
                'prices_created'   => $created,
                'prices_changed'   => $changed,
                'prices_unchanged' => $unchanged,
                'warnings_count'   => $warnings,
            ])->save();

            if ($warnings > 0) {
                $this->notifications->create(
                    AdminNotification::TYPE_AI_PRICE_SYNC_FAILED,
                    AdminNotification::SEVERITY_WARNING,
                    "AI-prissync fullført med {$warnings} advarsler",
                    "Leverandør: {$provider->providerKey()}. Sjekk logg for detaljer.",
                    ['sync_run_id' => $run->id, 'provider' => $provider->providerKey()],
                    "sync_warnings_{$provider->providerKey()}_{$run->id}",
                );
            }
        } catch (Throwable $e) {
            $run->forceFill([
                'status'        => AiModelPriceSyncRun::STATUS_FAILED,
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
                'warnings_count' => $warnings,
            ])->save();

            $this->notifications->create(
                AdminNotification::TYPE_AI_PRICE_SYNC_FAILED,
                AdminNotification::SEVERITY_CRITICAL,
                "AI-prissync feilet for {$provider->providerKey()}",
                $e->getMessage(),
                ['sync_run_id' => $run->id, 'provider' => $provider->providerKey()],
                "sync_failed_{$provider->providerKey()}_{$run->started_at}",
            );

            Log::error('[PROCYNIA][AI_PRICE_SYNC] Sync run failed.', [
                'provider'    => $provider->providerKey(),
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $price
     */
    private function upsertPrice(string $providerKey, array $price, string $today): string
    {
        $model          = (string) ($price['model'] ?? '');
        $deploymentName = $price['deployment_name'] ?? null;
        $providerRegion = $price['provider_region'] ?? null;

        $active = AiModelPrice::query()
            ->where('provider', $providerKey)
            ->where('model', $model)
            ->where(fn ($q) => $deploymentName !== null
                ? $q->where('deployment_name', $deploymentName)
                : $q->whereNull('deployment_name'))
            ->where(fn ($q) => $providerRegion !== null
                ? $q->where('provider_region', $providerRegion)
                : $q->whereNull('provider_region'))
            ->where('is_active', true)
            ->first();

        $newHash = $price['raw_payload_hash'] ?? null;

        if ($active === null) {
            AiModelPrice::query()->create(array_merge(
                $this->baseFields($providerKey, $price),
                ['valid_from' => $today, 'is_active' => true, 'sync_status' => AiModelPrice::SYNC_STATUS_NEW],
            ));

            $this->notifications->create(
                AdminNotification::TYPE_AI_PRICE_CREATED,
                AdminNotification::SEVERITY_INFO,
                "Ny AI-modellpris registrert: {$providerKey}/{$model}",
                "Input: {$price['input_price_per_1m_tokens']} USD/1M · Output: {$price['output_price_per_1m_tokens']} USD/1M",
                $price + ['provider' => $providerKey],
                "price_created_{$providerKey}_{$model}_".($deploymentName ?? 'null').'_'.($providerRegion ?? 'null'),
            );

            return 'created';
        }

        if ($newHash !== null && $active->source_hash === $newHash) {
            $active->forceFill([
                'last_seen_at' => now(),
                'sync_status'  => AiModelPrice::SYNC_STATUS_OK,
            ])->save();

            return 'unchanged';
        }

        if ($this->priceChanged($active, $price)) {
            $oldData = [
                'input_price_per_1m_tokens'        => (float) $active->input_price_per_1m_tokens,
                'cached_input_price_per_1m_tokens' => $active->cached_input_price_per_1m_tokens !== null
                    ? (float) $active->cached_input_price_per_1m_tokens : null,
                'output_price_per_1m_tokens'       => (float) $active->output_price_per_1m_tokens,
            ];

            $active->forceFill([
                'valid_to'     => $today,
                'is_active'    => false,
                'sync_status'  => AiModelPrice::SYNC_STATUS_CHANGED,
            ])->save();

            AiModelPrice::query()->create(array_merge(
                $this->baseFields($providerKey, $price),
                ['valid_from' => $today, 'is_active' => true, 'sync_status' => AiModelPrice::SYNC_STATUS_CHANGED],
            ));

            $dedupeKey = hash('sha256', implode('|', [
                $providerKey, $model,
                $deploymentName ?? 'null', $providerRegion ?? 'null',
                json_encode($oldData), json_encode($price), $today,
            ]));

            $this->notifications->create(
                AdminNotification::TYPE_AI_PRICE_CHANGED,
                AdminNotification::SEVERITY_WARNING,
                "AI-modellpris endret: {$providerKey}/{$model}",
                sprintf(
                    "Input: %s → %s USD/1M · Output: %s → %s USD/1M",
                    $oldData['input_price_per_1m_tokens'],
                    $price['input_price_per_1m_tokens'],
                    $oldData['output_price_per_1m_tokens'],
                    $price['output_price_per_1m_tokens'],
                ),
                array_merge($price, ['provider' => $providerKey, 'old' => $oldData, 'valid_from' => $today]),
                $dedupeKey,
            );

            return 'changed';
        }

        $active->forceFill([
            'last_seen_at' => now(),
            'source_hash'  => $newHash,
            'sync_status'  => AiModelPrice::SYNC_STATUS_OK,
        ])->save();

        return 'unchanged';
    }

    /**
     * @param array<string, mixed> $price
     */
    private function priceChanged(AiModelPrice $active, array $price): bool
    {
        $eps = 0.000001;

        return abs((float) $active->input_price_per_1m_tokens - (float) $price['input_price_per_1m_tokens']) > $eps
            || abs((float) $active->output_price_per_1m_tokens - (float) $price['output_price_per_1m_tokens']) > $eps;
    }

    /**
     * @param array<string, mixed> $price
     * @return array<string, mixed>
     */
    private function baseFields(string $providerKey, array $price): array
    {
        return [
            'provider'                        => $providerKey,
            'model'                           => (string) ($price['model'] ?? ''),
            'deployment_name'                 => $price['deployment_name'] ?? null,
            'provider_region'                 => $price['provider_region'] ?? null,
            'currency'                        => (string) ($price['currency'] ?? 'usd'),
            'input_price_per_1m_tokens'       => (float) ($price['input_price_per_1m_tokens'] ?? 0),
            'cached_input_price_per_1m_tokens' => isset($price['cached_input_price_per_1m_tokens'])
                ? (float) $price['cached_input_price_per_1m_tokens'] : null,
            'output_price_per_1m_tokens'      => (float) ($price['output_price_per_1m_tokens'] ?? 0),
            'source_url'                      => $price['source_url'] ?? null,
            'source_hash'                     => $price['raw_payload_hash'] ?? null,
            'last_seen_at'                    => now(),
        ];
    }
}
