<?php

namespace App\Services\Doffin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DoffinHarvestWindowService
{
    /**
     * Harvest notices and supplier records for a deterministic date range.
     */
    public function __construct(
        private readonly DoffinPublicClient $client,
        private readonly DoffinNoticeParser $parser,
    ) {
    }

    /**
     * Harvest notices and flattened supplier records across partitioned windows.
     */
    public function harvest(
        mixed $from,
        mixed $to,
        array $filters = [],
        ?int $windowDays = null,
    ): array {
        $rangeStart = $this->normalizeDate($from);
        $rangeEnd = $this->normalizeDate($to);

        if ($rangeStart->greaterThan($rangeEnd)) {
            throw new RuntimeException('Doffin harvest requires a start date before or equal to the end date.');
        }

        $windows = $this->chunkWindows(
            $rangeStart,
            $rangeEnd,
            $windowDays ?? max(1, (int) config('doffin.public_client.default_window_days', 7)),
        );
        $harvestedNotices = [];
        $records = [];
        $summary = [
            'windows_processed' => 0,
            'windows_split' => 0,
            'notices_seen' => 0,
            'records_built' => 0,
        ];

        foreach ($windows as $window) {
            $windowResult = $this->harvestWindow($window['from'], $window['to'], $filters, $summary);

            foreach ($windowResult['notices'] as $notice) {
                $noticeId = (string) ($notice['notice_id'] ?? '');

                if ($noticeId === '') {
                    continue;
                }

                $harvestedNotices[$noticeId] = $notice;
            }

            $records = [...$records, ...$windowResult['records']];
        }

        $summary['records_built'] = count($records);

        return [
            'notices' => array_values($harvestedNotices),
            'records' => $records,
            'stats' => $summary,
        ];
    }

    private function harvestWindow(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $filters,
        array &$summary,
    ): array {
        $summary['windows_processed']++;

        Log::info('[DOFFIN][harvest] Processing harvest window.', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        $firstPage = $this->client->search(
            $this->searchFilters($from, $to, $filters),
            1,
            $this->perPage(),
        );

        if ($this->isCapped($firstPage)) {
            if ($from->isSameDay($to)) {
                Log::warning('[DOFFIN][harvest] Single-day window exceeded accessible result cap.', [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'num_hits_total' => $firstPage['numHitsTotal'] ?? null,
                    'num_hits_accessible' => $firstPage['numHitsAccessible'] ?? null,
                ]);

                throw new RuntimeException('Doffin harvest window exceeded the accessible result cap for a single day.');
            }

            $summary['windows_split']++;

            [$leftStart, $leftEnd, $rightStart, $rightEnd] = $this->splitWindow($from, $to);
            $left = $this->harvestWindow($leftStart, $leftEnd, $filters, $summary);
            $right = $this->harvestWindow($rightStart, $rightEnd, $filters, $summary);

            return [
                'notices' => [...$left['notices'], ...$right['notices']],
                'records' => [...$left['records'], ...$right['records']],
            ];
        }

        $responses = [$firstPage];
        $lastPage = $this->lastPage($firstPage);

        for ($page = 2; $page <= $lastPage; $page++) {
            $this->throttle();

            $responses[] = $this->client->search(
                $this->searchFilters($from, $to, $filters),
                $page,
                $this->perPage(),
            );
        }

        $seenNoticeIds = [];
        $parsedNotices = [];
        $records = [];

        foreach ($responses as $response) {
            foreach ($response['hits'] ?? [] as $hit) {
                if (! is_array($hit)) {
                    continue;
                }

                $noticeId = trim((string) ($hit['id'] ?? ''));

                if ($noticeId === '' || in_array($noticeId, $seenNoticeIds, true)) {
                    continue;
                }

                $seenNoticeIds[] = $noticeId;
                $summary['notices_seen']++;

                $this->throttle();

                $detail = $this->client->noticeDetail($noticeId);
                $parsedNotice = $this->parser->parse($detail);
                $storedNotice = [
                    ...$parsedNotice,
                    'raw_payload_json' => $detail,
                ];

                $parsedNotices[] = $storedNotice;
                $records = [...$records, ...$this->parser->supplierRecords($parsedNotice)];
            }
        }

        return [
            'notices' => $parsedNotices,
            'records' => $records,
        ];
    }

    private function searchFilters(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'sort_by' => (string) ($filters['sort_by'] ?? 'RELEVANCE'),
            'types' => $filters['types'] ?? ['RESULT'],
            'statuses' => $filters['statuses'] ?? [],
            'cpv_codes' => $filters['cpv_codes'] ?? [],
            'buyer_ids' => $filters['buyer_ids'] ?? [],
            'winner_ids' => $filters['winner_ids'] ?? [],
            'contract_natures' => $filters['contract_natures'] ?? [],
            'location_ids' => $filters['location_ids'] ?? [],
            'publication_from' => $from->toDateString(),
            'publication_to' => $to->toDateString(),
        ];
    }

    private function chunkWindows(CarbonImmutable $from, CarbonImmutable $to, int $windowDays): array
    {
        $windows = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $windowEnd = $cursor->addDays($windowDays - 1);

            if ($windowEnd->greaterThan($to)) {
                $windowEnd = $to;
            }

            $windows[] = [
                'from' => $cursor,
                'to' => $windowEnd,
            ];

            $cursor = $windowEnd->addDay();
        }

        return $windows;
    }

    private function splitWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $diffDays = $from->diffInDays($to);
        $leftEnd = $from->addDays(max(0, intdiv($diffDays, 2)));
        $rightStart = $leftEnd->addDay();

        return [$from, $leftEnd, $rightStart, $to];
    }

    private function isCapped(array $response): bool
    {
        $numHitsTotal = (int) ($response['numHitsTotal'] ?? 0);
        $numHitsAccessible = (int) ($response['numHitsAccessible'] ?? $numHitsTotal);

        return $numHitsAccessible > 0 && $numHitsAccessible < $numHitsTotal;
    }

    private function lastPage(array $response): int
    {
        $accessible = (int) ($response['numHitsAccessible'] ?? $response['numHitsTotal'] ?? 0);

        if ($accessible === 0) {
            return 1;
        }

        if ($this->isCapped($response)) {
            return max(1, intdiv($accessible, $this->perPage()));
        }

        return max(1, (int) ceil($accessible / $this->perPage()));
    }

    private function perPage(): int
    {
        return max(1, (int) config('doffin.public_client.per_page', 50));
    }

    private function throttle(): void
    {
        $throttleMs = max(0, (int) config('doffin.public_client.throttle_ms', 100));

        if ($throttleMs === 0) {
            return;
        }

        usleep($throttleMs * 1000);
    }

    private function normalizeDate(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
