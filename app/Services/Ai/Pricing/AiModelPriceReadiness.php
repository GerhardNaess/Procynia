<?php

namespace App\Services\Ai\Pricing;

use App\Models\AiModelPrice;
use App\Support\Ai\AiOperationCatalog;
use Carbon\CarbonImmutable;

/**
 * Whether every model Procynia actually uses has a synced price.
 *
 * "Actually uses" is the operation registry (config/ai_operations.php): every model some
 * registered operation resolves to, after env overrides. "Synced" means the price in force in
 * ai_model_prices today equals the reviewed source price in config/ai_model_prices.php — so a
 * corrected price that has not reached the database yet (the gpt-5 correction until the next
 * `ai:sync-model-prices`) is caught as well as a model with no price at all.
 *
 * An environment is not AI-ready while any active model fails this. The point is to find out at
 * deploy time and in the hourly sweep, not from the first customer call that gets refused.
 */
class AiModelPriceReadiness
{
    public const READY = 'ready';

    public const MISSING = 'missing';

    public const UNSYNCED = 'unsynced';

    // Priced in the database, but config/ai_model_prices.php has no entry to compare it with — a
    // manually maintained price (an Azure deployment, say). Usable, so not a readiness problem.
    public const NO_SOURCE = 'no_source';

    public function __construct(private readonly AiModelPriceSyncService $sync) {}

    /** @return list<array{model: string, state: string}> */
    public function check(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $provider = trim((string) config('services.openai.provider_key', 'openai')) ?: 'openai';
        $deploymentName = $this->nullable(config('services.openai.deployment_name'));
        $providerRegion = $this->nullable(config('services.openai.provider_region'));
        $sources = collect((new ConfigAiModelPriceProvider($provider))->fetchPrices())
            ->filter(fn (array $price): bool => ($price['deployment_name'] ?? null) === $deploymentName
                && ($price['provider_region'] ?? null) === $providerRegion)
            ->keyBy('model');

        $results = [];

        foreach (AiOperationCatalog::activeModels() as $model) {
            $source = $sources->get($model);
            $current = AiModelPrice::findForEvent($provider, $model, $deploymentName, $providerRegion, $at);

            $results[] = ['model' => $model, 'state' => match (true) {
                $current === null => self::MISSING,
                $source === null => self::NO_SOURCE,
                $this->sync->priceChanged($current, $source) => self::UNSYNCED,
                default => self::READY,
            }];
        }

        return $results;
    }

    /** @return list<array{model: string, state: string}> */
    public function problems(?CarbonImmutable $at = null): array
    {
        return array_values(array_filter(
            $this->check($at),
            fn (array $row): bool => in_array($row['state'], [self::MISSING, self::UNSYNCED], true),
        ));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
