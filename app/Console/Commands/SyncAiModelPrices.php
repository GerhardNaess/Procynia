<?php

namespace App\Console\Commands;

use App\Models\AiModelPriceSyncRun;
use App\Services\Admin\AdminNotificationService;
use App\Services\Ai\Pricing\AiModelPriceSyncService;
use App\Services\Ai\Pricing\ConfigAiModelPriceProvider;
use Illuminate\Console\Command;

class SyncAiModelPrices extends Command
{
    protected $signature = 'ai:sync-model-prices
                            {--provider= : Provider key to sync (default: all configured providers)}';

    protected $description = 'Sync AI model prices from configured providers into ai_model_prices.';

    public function handle(
        AiModelPriceSyncService $service,
        AdminNotificationService $notifications,
    ): int {
        $providerFilter = $this->option('provider');
        $allProviders   = array_keys(config('ai_model_prices.providers', []));

        if ($allProviders === []) {
            $this->warn('No providers configured in config/ai_model_prices.php.');

            return self::SUCCESS;
        }

        $providers = $providerFilter !== null
            ? [$providerFilter]
            : $allProviders;

        $hardFail = false;

        foreach ($providers as $providerKey) {
            if (! in_array($providerKey, $allProviders, true)) {
                $this->error("Provider [{$providerKey}] is not configured.");
                $hardFail = true;
                continue;
            }

            $this->line("Syncing prices for <info>{$providerKey}</info>...");

            $provider = new ConfigAiModelPriceProvider($providerKey);
            $run      = $service->sync($provider);

            if ($run->status === AiModelPriceSyncRun::STATUS_FAILED) {
                $this->error("  Failed: {$run->error_message}");
                $hardFail = true;
                continue;
            }

            $this->line(sprintf(
                '  Done: %d seen, %d created, %d changed, %d unchanged, %d warnings',
                $run->models_seen,
                $run->prices_created,
                $run->prices_changed,
                $run->prices_unchanged,
                $run->warnings_count,
            ));
        }

        if ($hardFail) {
            $this->error('One or more provider syncs failed.');

            return self::FAILURE;
        }

        $this->info('AI model price sync completed.');

        return self::SUCCESS;
    }
}
