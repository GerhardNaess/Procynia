<?php

namespace App\Services\Doffin;

use App\Jobs\Doffin\PrepareDoffinSupplierHarvestRun;
use App\Jobs\Doffin\ProcessDoffinSupplierHarvestNotice;
use App\Models\DoffinNotice;
use App\Models\DoffinSupplier;
use App\Models\DoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRunNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Purpose:
 * Coordinate asynchronous harvesting of all suppliers found in Doffin notices for a date range.
 *
 * Inputs:
 * Harvest payloads, run models, and Doffin notice ids.
 *
 * Returns:
 * Supplier harvest run models with persisted progress state.
 *
 * Side effects:
 * Creates run rows, fetches Doffin search results and notice details, upserts suppliers,
 * writes notice work rows, and updates progress plus ETA in the database.
 */
class DoffinSupplierHarvestService
{
    /**
     * Purpose:
     * Create the supplier harvest coordinator.
     *
     * Inputs:
     * Existing Doffin client, parser, and persistence dependencies.
     *
     * Returns:
     * New DoffinSupplierHarvestService instance.
     *
     * Side effects:
     * None.
     */
    public function __construct(
        private readonly DoffinPublicClient $publicClient,
        private readonly DoffinNoticeParser $noticeParser,
        private readonly DoffinPersistenceService $persistenceService,
    ) {
    }

    /**
     * Purpose:
     * Create and queue a new asynchronous Doffin supplier harvest run.
     *
     * Inputs:
     * Sanitized harvest payload and optional creator.
     *
     * Returns:
     * DoffinSupplierHarvestRun
     *
     * Side effects:
     * Persists a queued run row and dispatches the prepare job.
     */
    public function startRun(array $payload, ?User $creator = null): DoffinSupplierHarvestRun
    {
        $run = DoffinSupplierHarvestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => DoffinSupplierHarvestRun::STATUS_QUEUED,
            'source_from_date' => $this->normalizeDateValue($payload['from'] ?? null),
            'source_to_date' => $this->normalizeDateValue($payload['to'] ?? null),
            'notice_type_filters' => $this->normalizeNoticeTypes($payload['types'] ?? ['RESULT']),
            'total_items' => 0,
            'processed_items' => 0,
            'harvested_suppliers' => 0,
            'failed_items' => 0,
            'progress_percent' => 0,
            'created_by' => $creator?->getKey(),
        ]);

        Log::info('[PROCYNIA][SUPPLIER_HARVEST][START] Queued supplier harvest run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'from' => optional($run->source_from_date)?->toDateString(),
            'to' => optional($run->source_to_date)?->toDateString(),
            'types' => $run->notice_type_filters,
        ]);

        PrepareDoffinSupplierHarvestRun::dispatch($run->id)->onQueue($this->queueName());

        return $run->fresh();
    }

    /**
     * Purpose:
     * Plan and queue all per-notice jobs for a supplier harvest run.
     *
     * Inputs:
     * Existing supplier harvest run.
     *
     * Returns:
     * DoffinSupplierHarvestRun
     *
     * Side effects:
     * Collects notice ids, stores run items, and dispatches a queue batch.
     */
    public function prepareRun(DoffinSupplierHarvestRun $run): DoffinSupplierHarvestRun
    {
        $run->refresh();

        if ($run->isTerminal()) {
            return $run;
        }

        $run->forceFill([
            'status' => DoffinSupplierHarvestRun::STATUS_PREPARING,
            'error_message' => null,
            'last_heartbeat_at' => now(),
        ])->save();

        Log::info('[PROCYNIA][SUPPLIER_HARVEST][PREPARE] Preparing supplier harvest run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
        ]);

        try {
            $noticeIds = $this->collectNoticeIds($run);

            $this->storeRunNotices($run, $noticeIds);

            $run->forceFill([
                'status' => empty($noticeIds)
                    ? DoffinSupplierHarvestRun::STATUS_COMPLETED
                    : DoffinSupplierHarvestRun::STATUS_RUNNING,
                'total_items' => count($noticeIds),
                'processed_items' => 0,
                'harvested_suppliers' => 0,
                'failed_items' => 0,
                'progress_percent' => empty($noticeIds) ? 100 : 0,
                'started_at' => now(),
                'finished_at' => empty($noticeIds) ? now() : null,
                'last_heartbeat_at' => now(),
                'estimated_seconds_remaining' => empty($noticeIds) ? 0 : null,
                'error_message' => null,
            ])->save();

            if (empty($noticeIds)) {
                Log::info('[PROCYNIA][SUPPLIER_HARVEST][COMPLETE] Supplier harvest run completed with no notice matches.', [
                    'run_id' => $run->id,
                    'run_uuid' => $run->uuid,
                ]);

                return $run->fresh();
            }

            $runId = $run->id;
            $batch = Bus::batch(
                collect($noticeIds)
                    ->map(fn (string $noticeId): ProcessDoffinSupplierHarvestNotice => new ProcessDoffinSupplierHarvestNotice($runId, $noticeId))
                    ->all()
            )
                ->name("doffin_supplier_harvest_run_{$run->uuid}")
                ->allowFailures()
                ->onQueue($this->queueName())
                ->finally(function (Batch $batch) use ($runId): void {
                    app(self::class)->completeRun($runId, $batch->id);
                })
                ->dispatch();

            $run->forceFill([
                'batch_id' => $batch->id,
                'last_heartbeat_at' => now(),
            ])->save();

            Log::info('[PROCYNIA][SUPPLIER_HARVEST][PREPARE] Queued supplier harvest notice jobs.', [
                'run_id' => $run->id,
                'run_uuid' => $run->uuid,
                'batch_id' => $batch->id,
                'total_items' => count($noticeIds),
            ]);

            return $run->fresh();
        } catch (Throwable $throwable) {
            return $this->failRun($run, $throwable->getMessage());
        }
    }

    /**
     * Purpose:
     * Process and persist suppliers from a single Doffin notice inside a harvest run.
     *
     * Inputs:
     * Existing supplier harvest run and the Doffin notice id to process.
     *
     * Returns:
     * DoffinSupplierHarvestRun
     *
     * Side effects:
     * Fetches notice detail, upserts suppliers, updates one run-notice row, and refreshes run counters.
     */
    public function processNotice(DoffinSupplierHarvestRun $run, string $noticeId): DoffinSupplierHarvestRun
    {
        $run->refresh();

        if ($run->isTerminal()) {
            return $run;
        }

        $item = $this->markNoticeAsRunning($run, $noticeId);

        if (! $item instanceof DoffinSupplierHarvestRunNotice || $item->status === DoffinSupplierHarvestRunNotice::STATUS_COMPLETED) {
            return $this->refreshRunProgress($run);
        }

        try {
            $detail = $this->publicClient->noticeDetail($noticeId);
            $parsedNotice = $this->noticeParser->parse($detail);
            $storedNotice = [
                ...$parsedNotice,
                'raw_payload_json' => $detail,
            ];
            $records = $this->noticeParser->supplierRecords($parsedNotice);
            $supplierCount = $this->persistHarvestedNotice($storedNotice, $records);

            $this->completeNoticeItem($run, $noticeId, $supplierCount);

            Log::info('[PROCYNIA][SUPPLIER_HARVEST][PROCESS] Processed supplier harvest notice.', [
                'run_id' => $run->id,
                'run_uuid' => $run->uuid,
                'notice_id' => $noticeId,
                'supplier_count' => $supplierCount,
            ]);
        } catch (Throwable $throwable) {
            $this->failNoticeItem($run, $noticeId, $throwable->getMessage());

            Log::error('[PROCYNIA][SUPPLIER_HARVEST][FAIL] Failed supplier harvest notice.', [
                'run_id' => $run->id,
                'run_uuid' => $run->uuid,
                'notice_id' => $noticeId,
                'message' => $throwable->getMessage(),
            ]);
        }

        return $this->refreshRunProgress($run);
    }

    /**
     * Purpose:
     * Mark a harvest run as completed after all queued notice jobs have finished.
     *
     * Inputs:
     * Harvest run id and optional batch id.
     *
     * Returns:
     * DoffinSupplierHarvestRun|null
     *
     * Side effects:
     * Updates final status, timestamps, and progress fields.
     */
    public function completeRun(int $runId, ?string $batchId = null): ?DoffinSupplierHarvestRun
    {
        $run = DoffinSupplierHarvestRun::query()->find($runId);

        if (! $run instanceof DoffinSupplierHarvestRun) {
            return null;
        }

        $run = $this->refreshRunProgress($run);

        if ($run->status === DoffinSupplierHarvestRun::STATUS_FAILED) {
            return $run;
        }

        $run->forceFill([
            'status' => DoffinSupplierHarvestRun::STATUS_COMPLETED,
            'batch_id' => $batchId ?? $run->batch_id,
            'finished_at' => $run->finished_at ?? now(),
            'last_heartbeat_at' => now(),
            'estimated_seconds_remaining' => 0,
            'progress_percent' => 100,
        ])->save();

        Log::info('[PROCYNIA][SUPPLIER_HARVEST][COMPLETE] Completed supplier harvest run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'batch_id' => $run->batch_id,
            'processed_items' => $run->processed_items,
            'harvested_suppliers' => $run->harvested_suppliers,
            'failed_items' => $run->failed_items,
        ]);

        return $run->fresh();
    }

    /**
     * Purpose:
     * Mark a harvest run as failed with a terminal error message.
     *
     * Inputs:
     * Existing run and error message.
     *
     * Returns:
     * DoffinSupplierHarvestRun
     *
     * Side effects:
     * Persists a failed status and final timestamps.
     */
    public function failRun(DoffinSupplierHarvestRun $run, string $message): DoffinSupplierHarvestRun
    {
        $run->refresh();

        $run->forceFill([
            'status' => DoffinSupplierHarvestRun::STATUS_FAILED,
            'error_message' => trim($message) === '' ? 'Supplier harvest failed.' : $message,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'estimated_seconds_remaining' => null,
        ])->save();

        Log::error('[PROCYNIA][SUPPLIER_HARVEST][FAIL] Supplier harvest run failed.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'message' => $run->error_message,
        ]);

        return $run->fresh();
    }

    /**
     * Purpose:
     * Refresh aggregate progress fields for a supplier harvest run.
     *
     * Inputs:
     * Existing supplier harvest run.
     *
     * Returns:
     * DoffinSupplierHarvestRun
     *
     * Side effects:
     * Updates processed counts, harvested supplier counts, progress, ETA, and heartbeat.
     */
    public function refreshRunProgress(DoffinSupplierHarvestRun $run): DoffinSupplierHarvestRun
    {
        $run->refresh();

        $baseQuery = DoffinSupplierHarvestRunNotice::query()
            ->where('doffin_supplier_harvest_run_id', $run->id);
        $totalItems = max($run->total_items, (clone $baseQuery)->count());
        $processedItems = (clone $baseQuery)
            ->whereIn('status', [
                DoffinSupplierHarvestRunNotice::STATUS_COMPLETED,
                DoffinSupplierHarvestRunNotice::STATUS_FAILED,
            ])
            ->count();
        $failedItems = (clone $baseQuery)
            ->where('status', DoffinSupplierHarvestRunNotice::STATUS_FAILED)
            ->count();
        $harvestedSuppliers = (int) (clone $baseQuery)
            ->where('status', DoffinSupplierHarvestRunNotice::STATUS_COMPLETED)
            ->sum('supplier_count');

        $progressPercent = $this->progressPercent($run, $processedItems, $totalItems);
        $estimatedSecondsRemaining = $this->estimatedSecondsRemaining($run, $processedItems, $totalItems);

        $run->forceFill([
            'total_items' => $totalItems,
            'processed_items' => $processedItems,
            'harvested_suppliers' => $harvestedSuppliers,
            'failed_items' => $failedItems,
            'progress_percent' => $progressPercent,
            'estimated_seconds_remaining' => $estimatedSecondsRemaining,
            'last_heartbeat_at' => now(),
        ])->save();

        return $run->fresh();
    }

    /**
     * Purpose:
     * Return the queue name used by the supplier harvest flow.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    public function queueName(): string
    {
        return (string) config('doffin.supplier_harvest.queue', 'supplier-harvests');
    }

    /**
     * Purpose:
     * Collect all unique notice ids for the run date range and notice types.
     *
     * Inputs:
     * Existing supplier harvest run.
     *
     * Returns:
     * array<int, string>
     *
     * Side effects:
     * Performs Doffin search requests and may recursively split capped date ranges.
     */
    private function collectNoticeIds(DoffinSupplierHarvestRun $run): array
    {
        $from = CarbonImmutable::parse($run->source_from_date?->toDateString() ?? now()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($run->source_to_date?->toDateString() ?? now()->toDateString())->startOfDay();

        if ($from->greaterThan($to)) {
            throw new RuntimeException('Supplier harvest requires a start date before or equal to the end date.');
        }

        return array_values(array_unique($this->collectNoticeIdsForRange($from, $to, [
            'types' => $run->notice_type_filters ?? ['RESULT'],
        ])));
    }

    /**
     * Purpose:
     * Collect all notice ids for one deterministic date partition.
     *
     * Inputs:
     * Start date, end date, and search filters.
     *
     * Returns:
     * array<int, string>
     *
     * Side effects:
     * Performs Doffin search requests and may recurse if the result set is capped.
     */
    private function collectNoticeIdsForRange(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        Log::info('[PROCYNIA][SUPPLIER_HARVEST][PREPARE] Processing supplier harvest partition.', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        $firstPage = $this->publicClient->search(
            $this->searchFilters($from, $to, $filters),
            1,
            $this->perPage(),
        );

        if ($this->isCapped($firstPage)) {
            if ($from->isSameDay($to)) {
                throw new RuntimeException('Supplier harvest window exceeded the accessible result cap for a single day.');
            }

            [$leftStart, $leftEnd, $rightStart, $rightEnd] = $this->splitWindow($from, $to);

            return array_values(array_unique([
                ...$this->collectNoticeIdsForRange($leftStart, $leftEnd, $filters),
                ...$this->collectNoticeIdsForRange($rightStart, $rightEnd, $filters),
            ]));
        }

        $noticeIds = $this->extractNoticeIds($firstPage);
        $lastPage = $this->lastPage($firstPage);

        for ($page = 2; $page <= $lastPage; $page++) {
            $this->throttle();

            $response = $this->publicClient->search(
                $this->searchFilters($from, $to, $filters),
                $page,
                $this->perPage(),
            );

            $noticeIds = [...$noticeIds, ...$this->extractNoticeIds($response)];
        }

        return array_values(array_unique($noticeIds));
    }

    /**
     * Purpose:
     * Persist all per-notice rows for the supplier harvest run.
     *
     * Inputs:
     * Existing run and unique notice ids.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Inserts or updates run-notice rows in the database.
     */
    private function storeRunNotices(DoffinSupplierHarvestRun $run, array $noticeIds): void
    {
        if ($noticeIds === []) {
            return;
        }

        $timestamp = now();

        DoffinSupplierHarvestRunNotice::query()->upsert(
            collect($noticeIds)
                ->map(fn (string $noticeId): array => [
                    'doffin_supplier_harvest_run_id' => $run->id,
                    'notice_id' => $noticeId,
                    'status' => DoffinSupplierHarvestRunNotice::STATUS_QUEUED,
                    'supplier_count' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all(),
            ['doffin_supplier_harvest_run_id', 'notice_id'],
            ['updated_at'],
        );
    }

    /**
     * Purpose:
     * Mark one notice work item as running.
     *
     * Inputs:
     * Existing run and Doffin notice id.
     *
     * Returns:
     * DoffinSupplierHarvestRunNotice|null
     *
     * Side effects:
     * Updates the per-notice row state before processing.
     */
    private function markNoticeAsRunning(DoffinSupplierHarvestRun $run, string $noticeId): ?DoffinSupplierHarvestRunNotice
    {
        $item = DoffinSupplierHarvestRunNotice::query()
            ->where('doffin_supplier_harvest_run_id', $run->id)
            ->where('notice_id', $noticeId)
            ->first();

        if (! $item instanceof DoffinSupplierHarvestRunNotice) {
            return null;
        }

        if ($item->status === DoffinSupplierHarvestRunNotice::STATUS_COMPLETED) {
            return $item;
        }

        $item->forceFill([
            'status' => DoffinSupplierHarvestRunNotice::STATUS_RUNNING,
            'error_message' => null,
        ])->save();

        return $item->fresh();
    }

    /**
     * Purpose:
     * Mark one notice work item as successfully completed.
     *
     * Inputs:
     * Existing run, notice id, and harvested supplier count.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Persists the completed state and harvested supplier count for one notice row.
     */
    private function completeNoticeItem(DoffinSupplierHarvestRun $run, string $noticeId, int $supplierCount): void
    {
        DoffinSupplierHarvestRunNotice::query()
            ->where('doffin_supplier_harvest_run_id', $run->id)
            ->where('notice_id', $noticeId)
            ->update([
                'status' => DoffinSupplierHarvestRunNotice::STATUS_COMPLETED,
                'supplier_count' => max(0, $supplierCount),
                'error_message' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Purpose:
     * Mark one notice work item as failed.
     *
     * Inputs:
     * Existing run, notice id, and error message.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Persists a failed state and error details for one notice row.
     */
    private function failNoticeItem(DoffinSupplierHarvestRun $run, string $noticeId, string $message): void
    {
        DoffinSupplierHarvestRunNotice::query()
            ->where('doffin_supplier_harvest_run_id', $run->id)
            ->where('notice_id', $noticeId)
            ->update([
                'status' => DoffinSupplierHarvestRunNotice::STATUS_FAILED,
                'supplier_count' => 0,
                'error_message' => trim($message) === '' ? 'Supplier harvest notice processing failed.' : $message,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Purpose:
     * Persist one parsed notice and all extracted suppliers deterministically.
     *
     * Inputs:
     * Parsed notice payload and flattened supplier records.
     *
     * Returns:
     * int
     *
     * Side effects:
     * Writes doffin_notices, doffin_suppliers, and doffin_notice_suppliers rows.
     */
    private function persistHarvestedNotice(array $storedNotice, array $records): int
    {
        $supplierIds = [];

        DB::transaction(function () use ($storedNotice, $records, &$supplierIds): void {
            [$notice] = $this->persistenceService->persistNotice($storedNotice);

            if (! $notice instanceof DoffinNotice) {
                return;
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $supplier = $this->persistSupplierDeterministically($record);

                if (! $supplier instanceof DoffinSupplier) {
                    continue;
                }

                $supplierIds[$supplier->id] = true;
                $this->persistenceService->persistNoticeSupplierLink($notice, $supplier, $record);
            }
        });

        return count($supplierIds);
    }

    /**
     * Purpose:
     * Persist one supplier using a deterministic identity lock to avoid duplicates.
     *
     * Inputs:
     * Flattened supplier record.
     *
     * Returns:
     * DoffinSupplier|null
     *
     * Side effects:
     * Acquires a PostgreSQL advisory lock and writes or reuses a supplier row.
     */
    private function persistSupplierDeterministically(array $record): ?DoffinSupplier
    {
        $supplierName = trim((string) ($record['supplier_name'] ?? ''));

        if ($supplierName === '') {
            return null;
        }

        $organizationNumber = $this->normalizeOrganizationNumber($record['organization_number'] ?? null);
        $normalizedName = $this->normalizeSupplierName($supplierName);

        return DB::transaction(function () use ($record, $organizationNumber, $normalizedName): ?DoffinSupplier {
            $this->acquireSupplierIdentityLock($organizationNumber, $normalizedName);

            try {
                [$supplier] = $this->persistenceService->persistSupplier($record);

                if ($supplier instanceof DoffinSupplier) {
                    return $supplier;
                }
            } catch (QueryException $exception) {
                $existing = $this->findExistingSupplier($organizationNumber, $normalizedName);

                if ($existing instanceof DoffinSupplier) {
                    return $existing;
                }

                throw $exception;
            }

            return $this->findExistingSupplier($organizationNumber, $normalizedName);
        });
    }

    /**
     * Purpose:
     * Reuse an existing supplier row by organization number first, then normalized name.
     *
     * Inputs:
     * Normalized organization number and normalized supplier name.
     *
     * Returns:
     * DoffinSupplier|null
     *
     * Side effects:
     * Reads supplier rows from the database.
     */
    private function findExistingSupplier(?string $organizationNumber, string $normalizedName): ?DoffinSupplier
    {
        if ($organizationNumber !== null) {
            $supplier = DoffinSupplier::query()
                ->where('organization_number', $organizationNumber)
                ->first();

            if ($supplier instanceof DoffinSupplier) {
                return $supplier;
            }
        }

        return DoffinSupplier::query()
            ->where('normalized_name', $normalizedName)
            ->orderBy('id')
            ->first();
    }

    /**
     * Purpose:
     * Acquire a PostgreSQL advisory lock for one supplier identity.
     *
     * Inputs:
     * Normalized organization number and normalized supplier name.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Serializes concurrent supplier upserts inside the current transaction.
     */
    private function acquireSupplierIdentityLock(?string $organizationNumber, string $normalizedName): void
    {
        $identity = $organizationNumber !== null
            ? "doffin_supplier_harvest:org:{$organizationNumber}"
            : "doffin_supplier_harvest:name:{$normalizedName}";
        [$firstKey, $secondKey] = $this->advisoryLockKeys($identity);

        DB::select('select pg_advisory_xact_lock(?, ?)', [$firstKey, $secondKey]);
    }

    /**
     * Purpose:
     * Generate two signed 32-bit advisory lock integers from a supplier identity string.
     *
     * Inputs:
     * Supplier identity string.
     *
     * Returns:
     * array<int, int>
     *
     * Side effects:
     * None.
     */
    private function advisoryLockKeys(string $identity): array
    {
        $bytes = hash('sha256', $identity, true);
        $parts = unpack('Nfirst/Nsecond', substr($bytes, 0, 8));

        return [
            $this->signed32((int) ($parts['first'] ?? 0)),
            $this->signed32((int) ($parts['second'] ?? 0)),
        ];
    }

    /**
     * Purpose:
     * Convert an unsigned 32-bit integer to a signed 32-bit integer.
     *
     * Inputs:
     * Unsigned 32-bit integer.
     *
     * Returns:
     * int
     *
     * Side effects:
     * None.
     */
    private function signed32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    /**
     * Purpose:
     * Extract unique notice ids from one Doffin search response.
     *
     * Inputs:
     * Raw Doffin search response.
     *
     * Returns:
     * array<int, string>
     *
     * Side effects:
     * None.
     */
    private function extractNoticeIds(array $response): array
    {
        return collect($response['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->map(fn (array $hit): string => trim((string) ($hit['id'] ?? '')))
            ->filter(fn (string $noticeId): bool => $noticeId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Purpose:
     * Build search filters for Doffin notice listing by date range and types.
     *
     * Inputs:
     * Start date, end date, and optional filter overrides.
     *
     * Returns:
     * array<string, mixed>
     *
     * Side effects:
     * None.
     */
    private function searchFilters(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        return [
            'q' => '',
            'sort_by' => 'RELEVANCE',
            'types' => $filters['types'] ?? ['RESULT'],
            'statuses' => [],
            'cpv_codes' => [],
            'buyer_ids' => [],
            'winner_ids' => [],
            'contract_natures' => [],
            'location_ids' => [],
            'publication_from' => $from->toDateString(),
            'publication_to' => $to->toDateString(),
        ];
    }

    /**
     * Purpose:
     * Determine whether a Doffin search response is capped by accessible hits.
     *
     * Inputs:
     * Raw Doffin search response.
     *
     * Returns:
     * bool
     *
     * Side effects:
     * None.
     */
    private function isCapped(array $response): bool
    {
        $numHitsTotal = (int) ($response['numHitsTotal'] ?? 0);
        $numHitsAccessible = (int) ($response['numHitsAccessible'] ?? $numHitsTotal);

        return $numHitsAccessible > 0 && $numHitsAccessible < $numHitsTotal;
    }

    /**
     * Purpose:
     * Determine the last accessible search page for one Doffin response.
     *
     * Inputs:
     * Raw Doffin search response.
     *
     * Returns:
     * int
     *
     * Side effects:
     * None.
     */
    private function lastPage(array $response): int
    {
        $accessible = (int) ($response['numHitsAccessible'] ?? $response['numHitsTotal'] ?? 0);

        if ($accessible === 0) {
            return 1;
        }

        if ($this->isCapped($response)) {
            return max(1, (int) ceil($accessible / $this->perPage()));
        }

        return max(1, (int) ceil($accessible / $this->perPage()));
    }

    /**
     * Purpose:
     * Return the configured number of search hits per page.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * int
     *
     * Side effects:
     * None.
     */
    private function perPage(): int
    {
        return max(1, (int) config('doffin.public_client.per_page', 50));
    }

    /**
     * Purpose:
     * Pause between Doffin requests using the existing client throttle configuration.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Sleeps the current process for the configured number of milliseconds.
     */
    private function throttle(): void
    {
        $throttleMs = max(0, (int) config('doffin.public_client.throttle_ms', 100));

        if ($throttleMs === 0) {
            return;
        }

        usleep($throttleMs * 1000);
    }

    /**
     * Purpose:
     * Split a capped date range into two deterministic subranges.
     *
     * Inputs:
     * Start date and end date.
     *
     * Returns:
     * array<int, CarbonImmutable>
     *
     * Side effects:
     * None.
     */
    private function splitWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $diffDays = $from->diffInDays($to);
        $leftEnd = $from->addDays(max(0, intdiv($diffDays, 2)));
        $rightStart = $leftEnd->addDay();

        return [$from, $leftEnd, $rightStart, $to];
    }

    /**
     * Purpose:
     * Normalize a user-provided date value for persistence.
     *
     * Inputs:
     * Mixed date value.
     *
     * Returns:
     * string|null
     *
     * Side effects:
     * None.
     */
    private function normalizeDateValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : Carbon::parse($normalized)->toDateString();
    }

    /**
     * Purpose:
     * Normalize the requested notice type filters.
     *
     * Inputs:
     * Mixed type list.
     *
     * Returns:
     * array<int, string>
     *
     * Side effects:
     * None.
     */
    private function normalizeNoticeTypes(mixed $types): array
    {
        $normalized = collect(is_array($types) ? $types : [$types])
            ->map(fn (mixed $type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $normalized === [] ? ['RESULT'] : $normalized;
    }

    /**
     * Purpose:
     * Normalize supplier organization numbers to digits only.
     *
     * Inputs:
     * Mixed organization number value.
     *
     * Returns:
     * string|null
     *
     * Side effects:
     * None.
     */
    private function normalizeOrganizationNumber(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Purpose:
     * Normalize supplier names for deterministic identity matching.
     *
     * Inputs:
     * Supplier name string.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    private function normalizeSupplierName(string $value): string
    {
        return Str::lower(Str::squish($value));
    }

    /**
     * Purpose:
     * Calculate the current progress percentage for a harvest run.
     *
     * Inputs:
     * Existing run, processed item count, and total item count.
     *
     * Returns:
     * float|int
     *
     * Side effects:
     * None.
     */
    private function progressPercent(DoffinSupplierHarvestRun $run, int $processedItems, int $totalItems): float|int
    {
        if ($totalItems <= 0) {
            return $run->isTerminal() ? 100 : 0;
        }

        return round(($processedItems / $totalItems) * 100, 2);
    }

    /**
     * Purpose:
     * Calculate the estimated remaining seconds for a harvest run.
     *
     * Inputs:
     * Existing run, processed item count, and total item count.
     *
     * Returns:
     * int|null
     *
     * Side effects:
     * None.
     */
    private function estimatedSecondsRemaining(DoffinSupplierHarvestRun $run, int $processedItems, int $totalItems): ?int
    {
        if ($processedItems <= 0 || $run->started_at === null || $totalItems <= 0) {
            return null;
        }

        $startedAt = $run->started_at instanceof DateTimeInterface
            ? Carbon::instance($run->started_at)
            : Carbon::parse((string) $run->started_at);
        $elapsedSeconds = max(0, now()->getTimestamp() - $startedAt->getTimestamp());

        if ($elapsedSeconds < 1) {
            return null;
        }

        $remainingItems = max(0, $totalItems - $processedItems);

        if ($remainingItems <= 0) {
            return 0;
        }

        $throughput = $processedItems / $elapsedSeconds;

        if ($throughput <= 0) {
            return null;
        }

        return max(0, (int) ceil($remainingItems / $throughput));
    }
}
