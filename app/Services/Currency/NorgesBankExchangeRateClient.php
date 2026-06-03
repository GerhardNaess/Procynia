<?php

namespace App\Services\Currency;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Purpose: Fetch daily exchange rates from Norges Bank open data API.
 * Inputs: Currency pair (e.g. USD/NOK) and optional date range.
 * Returns: Normalised rate DTOs or null when no rate is available.
 * Side effects: Makes HTTP requests to data.norges-bank.no.
 */
class NorgesBankExchangeRateClient
{
    private const BASE_URL = 'https://data.norges-bank.no/api/data/EXR';

    private const SOURCE = 'norges_bank';

    /**
     * Purpose: Fetch the most recent available exchange rate for a currency pair.
     * Inputs: Base currency (e.g. 'USD'), quote currency (e.g. 'NOK'),
     *         optional target date (fetches recent business-day rates when null).
     * Returns: Normalised rate array or null when no rate could be fetched.
     * Side effects: Makes one HTTP request; logs warnings on failure.
     *
     * @return array{base_currency:string,quote_currency:string,rate:float,rate_date:string,source:string,raw_payload_hash:string|null}|null
     */
    public function fetch(string $baseCurrency, string $quoteCurrency, ?string $targetDate = null): ?array
    {
        try {
            $url = sprintf('%s/B.%s.%s.SP', self::BASE_URL, strtoupper($baseCurrency), strtoupper($quoteCurrency));

            $params = ['format' => 'sdmx-json', 'lastNObservations' => 5];

            if ($targetDate !== null) {
                $params['startPeriod'] = $targetDate;
                $params['endPeriod']   = $targetDate;
                unset($params['lastNObservations']);
            }

            $response = Http::timeout(15)
                ->acceptJson()
                ->get($url, $params);

            if ($response->failed()) {
                Log::warning('[PROCYNIA][EXCHANGE_RATE] Norges Bank API returned non-200 response.', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->body();
            $hash = hash('sha256', $body);
            $data = $response->json();

            return $this->parseResponse($data, strtoupper($baseCurrency), strtoupper($quoteCurrency), $hash);
        } catch (Throwable $e) {
            Log::warning('[PROCYNIA][EXCHANGE_RATE] Exception while fetching from Norges Bank.', [
                'error' => $e->getMessage(),
                'base'  => $baseCurrency,
                'quote' => $quoteCurrency,
            ]);

            return null;
        }
    }

    /**
     * Purpose: Parse the SDMX-JSON response from Norges Bank into a normalised rate array.
     * Inputs: Decoded SDMX-JSON array, base/quote currency and payload hash.
     * Returns: Normalised rate array using the most recent observation, or null when empty.
     * Side effects: None.
     *
     * @param array<string, mixed> $data
     * @return array{base_currency:string,quote_currency:string,rate:float,rate_date:string,source:string,raw_payload_hash:string|null}|null
     */
    private function parseResponse(array $data, string $baseCurrency, string $quoteCurrency, string $hash): ?array
    {
        $observations = data_get($data, 'data.dataSets.0.series.0:0:0:0.observations', []);

        if (! is_array($observations) || $observations === []) {
            return null;
        }

        $timePeriods = data_get($data, 'data.structure.dimensions.observation.0.values', []);

        if (! is_array($timePeriods) || $timePeriods === []) {
            return null;
        }

        // Take the last (most recent) observation index.
        $lastIndex = (string) (count($timePeriods) - 1);

        $rateValue = data_get($observations, "{$lastIndex}.0");
        $datePeriod = data_get($timePeriods, "{$lastIndex}.id");

        if ($rateValue === null || $datePeriod === null) {
            // Fall back to first available observation.
            foreach (array_keys($observations) as $idx) {
                $rateValue  = data_get($observations, "{$idx}.0");
                $datePeriod = data_get($timePeriods, "{$idx}.id");
                if ($rateValue !== null && $datePeriod !== null) {
                    break;
                }
            }
        }

        if ($rateValue === null || $datePeriod === null) {
            return null;
        }

        return [
            'base_currency'  => $baseCurrency,
            'quote_currency' => $quoteCurrency,
            'rate'           => (float) $rateValue,
            'rate_date'      => $datePeriod,
            'source'         => self::SOURCE,
            'raw_payload_hash' => $hash,
        ];
    }
}
