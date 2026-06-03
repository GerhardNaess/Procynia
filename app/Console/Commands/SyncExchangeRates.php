<?php

namespace App\Console\Commands;

use App\Services\Currency\ExchangeRateSyncService;
use Illuminate\Console\Command;

class SyncExchangeRates extends Command
{
    protected $signature = 'exchange-rates:sync
                            {--base=USD : Base currency to fetch}
                            {--quote=NOK : Quote currency to fetch}
                            {--date= : Specific date YYYY-MM-DD (default: latest available)}';

    protected $description = 'Sync exchange rates from Norges Bank into exchange_rates.';

    public function handle(ExchangeRateSyncService $service): int
    {
        $base  = strtoupper((string) $this->option('base'));
        $quote = strtoupper((string) $this->option('quote'));
        $date  = $this->option('date') ?: null;

        $this->line("Fetching <info>{$base}/{$quote}</info>" . ($date ? " for {$date}" : ' (latest available)') . '...');

        $rate = $service->sync($base, $quote, $date);

        if ($rate === null) {
            $this->error("Failed to fetch or store {$base}/{$quote} rate.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  Stored: 1 %s = %s %s (date: %s, source: %s)',
            $base,
            number_format((float) $rate->rate, 4, '.', ''),
            $quote,
            $rate->rate_date->toDateString(),
            $rate->source,
        ));

        $this->info('Exchange rate sync completed.');

        return self::SUCCESS;
    }
}
