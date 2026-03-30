<?php

namespace App\Services\Doffin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DoffinSupplierLookupService
{
    /**
     * Resolve supplier winner candidates and harvest matching result notices.
     */
    public function __construct(
        private readonly DoffinPublicClient $client,
        private readonly DoffinHarvestWindowService $harvestWindowService,
    ) {
    }

    /**
     * Run a supplier lookup against Doffin and harvest matching result notices.
     */
    public function lookup(
        string $supplierName,
        mixed $from,
        mixed $to,
        array $filters = [],
        ?int $windowDays = null,
    ): array
    {
        $resolution = $this->resolveCandidate($supplierName);
        $candidates = $resolution['winner_candidates'];
        $selectedCandidate = $resolution['selected_candidate'];

        if ($selectedCandidate === null) {
            return [
                'selected_candidate' => null,
                'winner_candidates' => $candidates,
                'notices' => [],
                'records' => [],
                'stats' => [
                    'windows_processed' => 0,
                    'windows_split' => 0,
                    'notices_seen' => 0,
                    'records_built' => 0,
                ],
            ];
        }

        $harvest = $this->harvestWindowService->harvest(
            CarbonImmutable::parse((string) $from),
            CarbonImmutable::parse((string) $to),
            [
                ...$filters,
                'types' => $filters['types'] ?? ['RESULT'],
                'winner_ids' => [$selectedCandidate['id']],
            ],
            $windowDays,
        );

        return [
            'selected_candidate' => $selectedCandidate,
            'winner_candidates' => $candidates,
            'notices' => $harvest['notices'],
            'records' => $harvest['records'],
            'stats' => $harvest['stats'],
        ];
    }

    /**
     * Purpose:
     * Resolve Doffin winner candidates and the best selected candidate for a supplier query.
     *
     * Inputs:
     * Supplier search text.
     *
     * Returns:
     * Array<string, mixed>
     *
     * Side effects:
     * Performs a Doffin suggest lookup through the public client.
     */
    public function resolveCandidate(string $supplierName): array
    {
        $candidates = $this->winnerCandidates($supplierName);

        return [
            'selected_candidate' => $this->selectCandidate($supplierName, $candidates),
            'winner_candidates' => $candidates,
        ];
    }

    /**
     * Return Doffin winner suggestions for a supplier name.
     */
    public function winnerCandidates(string $supplierName): array
    {
        $response = $this->client->suggest($supplierName);

        return collect(data_get($response, 'winner.items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'value' => trim((string) ($item['value'] ?? '')),
                'total' => (int) ($item['total'] ?? 0),
                'highlight' => trim((string) ($item['highlight'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['id'] !== '' && $item['value'] !== '')
            ->values()
            ->all();
    }

    private function selectCandidate(string $supplierName, array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $normalizedNeedle = $this->normalizeName($supplierName);
        $collection = collect($candidates);

        $exact = $collection->first(
            fn (array $candidate): bool => $this->normalizeName($candidate['value']) === $normalizedNeedle
        );

        if ($exact !== null) {
            return $exact;
        }

        $prefix = $collection->first(
            fn (array $candidate): bool => Str::startsWith($this->normalizeName($candidate['value']), $normalizedNeedle)
        );

        return $prefix ?? $collection->first();
    }

    private function normalizeName(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
}
