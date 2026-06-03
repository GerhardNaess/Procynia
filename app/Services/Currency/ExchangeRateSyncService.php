<?php

namespace App\Services\Currency;

use App\Models\AdminNotification;
use App\Models\ExchangeRate;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Purpose: Synchronise exchange rates from authoritative sources into exchange_rates.
 * Inputs: Currency pair and optional target date.
 * Returns: The upserted ExchangeRate or null on failure.
 * Side effects: Writes exchange_rates rows, creates AdminNotification entries on changes/errors.
 */
class ExchangeRateSyncService
{
    public function __construct(
        private readonly NorgesBankExchangeRateClient $client,
        private readonly AdminNotificationService $notifications,
    ) {
    }

    /**
     * Purpose: Fetch and persist the current exchange rate for a currency pair.
     * Inputs: Base/quote currency and optional target date string (YYYY-MM-DD).
     * Returns: The persisted ExchangeRate or null on failure.
     * Side effects: Upserts one exchange_rates row; may create admin notifications.
     */
    public function sync(string $baseCurrency, string $quoteCurrency, ?string $targetDate = null): ?ExchangeRate
    {
        $dto = $this->client->fetch($baseCurrency, $quoteCurrency, $targetDate);

        if ($dto === null) {
            $this->notifications->create(
                AdminNotification::TYPE_AI_PRICE_SYNC_FAILED,
                AdminNotification::SEVERITY_WARNING,
                "Valutakurs-henting feilet: {$baseCurrency}/{$quoteCurrency}",
                'Norges Bank API returnerte ingen kurs. Sjekk om API er tilgjengelig og om riktig valutapar er konfigurert.',
                ['base' => $baseCurrency, 'quote' => $quoteCurrency, 'target_date' => $targetDate],
                'exchange_rate_sync_failed_'.$baseCurrency.'_'.$quoteCurrency.'_'.($targetDate ?? now()->toDateString()),
            );

            Log::warning('[PROCYNIA][EXCHANGE_RATE_SYNC] Failed to fetch rate.', [
                'base'  => $baseCurrency,
                'quote' => $quoteCurrency,
                'date'  => $targetDate,
            ]);

            return null;
        }

        $existing = ExchangeRate::query()
            ->where('base_currency', $dto['base_currency'])
            ->where('quote_currency', $dto['quote_currency'])
            ->where('rate_date', $dto['rate_date'])
            ->where('source', $dto['source'])
            ->first();

        if ($existing !== null) {
            $oldRate = (float) $existing->rate;
            $newRate = $dto['rate'];

            if (abs($oldRate - $newRate) < 0.00000001) {
                $existing->forceFill(['fetched_at' => now()])->save();

                return $existing;
            }

            // Rate changed for same date — notify admin and update.
            $this->notifications->create(
                'exchange_rate_changed',
                AdminNotification::SEVERITY_WARNING,
                "Valutakurs endret: {$dto['base_currency']}/{$dto['quote_currency']} ({$dto['rate_date']})",
                sprintf('Gammel kurs: %s · Ny kurs: %s', number_format($oldRate, 4, '.', ''), number_format($newRate, 4, '.', '')),
                $dto + ['old_rate' => $oldRate],
                hash('sha256', $dto['base_currency'].'|'.$dto['quote_currency'].'|'.$dto['rate_date'].'|'.$dto['source'].'|'.(string) $oldRate.'|'.(string) $newRate),
            );

            $existing->forceFill([
                'rate'                => $newRate,
                'source_payload_hash' => $dto['raw_payload_hash'],
                'fetched_at'          => now(),
            ])->save();

            return $existing;
        }

        try {
            $rate = ExchangeRate::query()->create([
                'base_currency'       => $dto['base_currency'],
                'quote_currency'      => $dto['quote_currency'],
                'rate'                => $dto['rate'],
                'rate_date'           => $dto['rate_date'],
                'source'              => $dto['source'],
                'source_payload_hash' => $dto['raw_payload_hash'],
                'fetched_at'          => now(),
            ]);

            Log::info('[PROCYNIA][EXCHANGE_RATE_SYNC] New rate stored.', [
                'base'      => $dto['base_currency'],
                'quote'     => $dto['quote_currency'],
                'rate'      => $dto['rate'],
                'rate_date' => $dto['rate_date'],
            ]);

            return $rate;
        } catch (UniqueConstraintViolationException) {
            // Race condition — another process inserted first; re-fetch.
            return ExchangeRate::query()
                ->where('base_currency', $dto['base_currency'])
                ->where('quote_currency', $dto['quote_currency'])
                ->where('rate_date', $dto['rate_date'])
                ->where('source', $dto['source'])
                ->first();
        }
    }
}
