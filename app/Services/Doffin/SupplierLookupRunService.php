<?php

namespace App\Services\Doffin;

use App\Jobs\Doffin\PrepareSupplierLookupRun;
use App\Jobs\Doffin\ProcessSupplierLookupNotice;
use App\Models\SupplierLookupRun;
use App\Models\SupplierLookupRunNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Purpose:
 * Coordinate asynchronous supplier lookup runs, progress updates, and persistence.
 *
 * Inputs:
 * Supplier lookup payloads, run models, and per-notice identifiers.
 *
 * Returns:
 * SupplierLookupRun models and normalized UI status payloads.
 *
 * Side effects:
 * Creates run rows, queues background jobs, fetches Doffin notices, persists supplier data,
 * and updates progress/ETA fields in the database.
 */
class SupplierLookupRunService
{
    /**
     * Purpose:
     * Create the supplier lookup run coordinator.
     *
     * Inputs:
     * Existing Doffin service dependencies.
     *
     * Returns:
     * New SupplierLookupRunService instance.
     *
     * Side effects:
     * None.
     */
    public function __construct(
        private readonly DoffinSupplierLookupService $supplierLookupService,
        private readonly DoffinPublicClient $publicClient,
        private readonly DoffinNoticeParser $noticeParser,
        private readonly DoffinPersistenceService $persistenceService,
    ) {
    }

    /**
     * Purpose:
     * Create and queue a new asynchronous supplier lookup run.
     *
     * Inputs:
     * Sanitized supplier lookup payload and optional creator.
     *
     * Returns:
     * SupplierLookupRun
     *
     * Side effects:
     * Persists a queued run row and dispatches the prepare job.
     */
    public function startRun(array $payload, ?User $creator = null): SupplierLookupRun
    {
        $run = SupplierLookupRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => SupplierLookupRun::STATUS_QUEUED,
            'source_from_date' => $this->normalizeDateValue($payload['from'] ?? null),
            'source_to_date' => $this->normalizeDateValue($payload['to'] ?? null),
            'supplier_query' => $this->nullableString($payload['supplier_name'] ?? null),
            'notice_type_filters' => $this->normalizeNoticeTypes($payload['types'] ?? ['RESULT']),
            'total_items' => 0,
            'processed_items' => 0,
            'matched_items' => 0,
            'failed_items' => 0,
            'progress_percent' => 0,
            'created_by' => $creator?->getKey(),
        ]);

        Log::info('[PROCYNIA][SUPPLIER_LOOKUP][START] Queued supplier lookup run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'supplier_query' => $run->supplier_query,
            'from' => optional($run->source_from_date)?->toDateString(),
            'to' => optional($run->source_to_date)?->toDateString(),
            'types' => $run->notice_type_filters,
        ]);

        PrepareSupplierLookupRun::dispatch($run->id)->onQueue($this->queueName());

        return $run->fresh();
    }

    /**
     * Purpose:
     * Plan and queue all per-notice jobs for a supplier lookup run.
     *
     * Inputs:
     * Existing supplier lookup run.
     *
     * Returns:
     * SupplierLookupRun
     *
     * Side effects:
     * Resolves supplier winner candidate, fetches matching notice ids, stores run items,
     * and dispatches a queue batch of per-notice jobs.
     */
    public function prepareRun(SupplierLookupRun $run): SupplierLookupRun
    {
        $run->refresh();

        if ($run->isTerminal()) {
            return $run;
        }

        $run->forceFill([
            'status' => SupplierLookupRun::STATUS_PREPARING,
            'error_message' => null,
            'last_heartbeat_at' => now(),
        ])->save();

        Log::info('[PROCYNIA][SUPPLIER_LOOKUP][PREPARE] Preparing supplier lookup run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
        ]);

        try {
            $resolution = $this->supplierLookupService->resolveCandidate((string) $run->supplier_query);
            $selectedCandidate = $resolution['selected_candidate'];

            if (! is_array($selectedCandidate)) {
                return $this->failRun(
                    $run,
                    'No Doffin supplier candidate matched the supplier query.'
                );
            }

            $noticeIds = $this->collectNoticeIds(
                $run,
                (string) $selectedCandidate['id'],
            );

            $this->storeRunNotices($run, $noticeIds);

            $run->forceFill([
                'status' => empty($noticeIds)
                    ? SupplierLookupRun::STATUS_COMPLETED
                    : SupplierLookupRun::STATUS_RUNNING,
                'resolved_winner_id' => (string) $selectedCandidate['id'],
                'resolved_winner_label' => trim((string) ($selectedCandidate['value'] ?? '')),
                'total_items' => count($noticeIds),
                'processed_items' => 0,
                'matched_items' => 0,
                'failed_items' => 0,
                'progress_percent' => empty($noticeIds) ? 100 : 0,
                'started_at' => now(),
                'finished_at' => empty($noticeIds) ? now() : null,
                'last_heartbeat_at' => now(),
                'estimated_seconds_remaining' => null,
                'error_message' => null,
            ])->save();

            if (empty($noticeIds)) {
                Log::info('[PROCYNIA][SUPPLIER_LOOKUP][COMPLETE] Supplier lookup run completed with no notice matches.', [
                    'run_id' => $run->id,
                    'run_uuid' => $run->uuid,
                ]);

                return $run->fresh();
            }

            $runId = $run->id;
            $batch = Bus::batch(
                collect($noticeIds)
                    ->map(fn (string $noticeId): ProcessSupplierLookupNotice => new ProcessSupplierLookupNotice($runId, $noticeId))
                    ->all()
            )
                ->name("supplier_lookup_run_{$run->uuid}")
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

            Log::info('[PROCYNIA][SUPPLIER_LOOKUP][PREPARE] Queued supplier lookup notice jobs.', [
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
     * Process and persist a single notice inside a supplier lookup run.
     *
     * Inputs:
     * Existing supplier lookup run and the Doffin notice id to process.
     *
     * Returns:
     * SupplierLookupRun
     *
     * Side effects:
     * Fetches notice detail from Doffin, persists normalized supplier data,
     * updates per-notice state, and recalculates run progress plus ETA.
     */
    public function processNotice(SupplierLookupRun $run, string $noticeId): SupplierLookupRun
    {
        $run->refresh();

        if ($run->isTerminal()) {
            return $run;
        }

        $item = $this->markNoticeAsRunning($run, $noticeId);

        if (! $item instanceof SupplierLookupRunNotice || $item->status === SupplierLookupRunNotice::STATUS_COMPLETED) {
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

            $this->persistenceService->persist([$storedNotice], $records);

            $matched = $this->recordsMatchRun($run, $records);

            $this->completeNoticeItem($run, $noticeId, $matched);

            Log::info('[PROCYNIA][SUPPLIER_LOOKUP][PROCESS] Processed supplier lookup notice.', [
                'run_id' => $run->id,
                'run_uuid' => $run->uuid,
                'notice_id' => $noticeId,
                'matched' => $matched,
            ]);
        } catch (Throwable $throwable) {
            $this->failNoticeItem($run, $noticeId, $throwable->getMessage());

            Log::error('[PROCYNIA][SUPPLIER_LOOKUP][FAIL] Failed supplier lookup notice.', [
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
     * Refresh counters, progress percent, and ETA from the per-notice run rows.
     *
     * Inputs:
     * Existing supplier lookup run.
     *
     * Returns:
     * SupplierLookupRun
     *
     * Side effects:
     * Updates processed, matched, failed, progress, heartbeat, and ETA fields.
     */
    public function refreshRunProgress(SupplierLookupRun $run): SupplierLookupRun
    {
        $run->refresh();

        $counts = $run->notices()
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw("SUM(CASE WHEN status IN ('completed', 'failed') THEN 1 ELSE 0 END) as processed_items")
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND matched = true THEN 1 ELSE 0 END) as matched_items")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_items")
            ->first();

        $totalItems = (int) ($counts?->total_items ?? 0);
        $processedItems = (int) ($counts?->processed_items ?? 0);
        $matchedItems = (int) ($counts?->matched_items ?? 0);
        $failedItems = (int) ($counts?->failed_items ?? 0);
        $progressPercent = $this->progressPercent($run, $totalItems, $processedItems);
        $estimatedSecondsRemaining = $this->estimatedSecondsRemaining($run, $totalItems, $processedItems);

        $run->forceFill([
            'total_items' => $totalItems,
            'processed_items' => $processedItems,
            'matched_items' => $matchedItems,
            'failed_items' => $failedItems,
            'progress_percent' => $progressPercent,
            'estimated_seconds_remaining' => $estimatedSecondsRemaining,
            'last_heartbeat_at' => now(),
        ])->save();

        return $run->fresh();
    }

    /**
     * Purpose:
     * Mark a supplier lookup run as completed after its queue batch finishes.
     *
     * Inputs:
     * Run id and optional batch id from Laravel batching.
     *
     * Returns:
     * SupplierLookupRun|null
     *
     * Side effects:
     * Updates final status, timestamps, and terminal progress fields.
     */
    public function completeRun(int $runId, ?string $batchId = null): ?SupplierLookupRun
    {
        $run = SupplierLookupRun::query()->find($runId);

        if (! $run instanceof SupplierLookupRun) {
            return null;
        }

        $run = $this->refreshRunProgress($run);

        if ($run->isTerminal()) {
            return $run;
        }

        $run->forceFill([
            'status' => SupplierLookupRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'progress_percent' => 100,
            'estimated_seconds_remaining' => 0,
        ])->save();

        Log::info('[PROCYNIA][SUPPLIER_LOOKUP][COMPLETE] Completed supplier lookup run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'batch_id' => $batchId,
            'processed_items' => $run->processed_items,
            'matched_items' => $run->matched_items,
            'failed_items' => $run->failed_items,
        ]);

        return $run->fresh();
    }

    /**
     * Purpose:
     * Mark a supplier lookup run as failed with a user-visible error.
     *
     * Inputs:
     * Existing run and failure message.
     *
     * Returns:
     * SupplierLookupRun
     *
     * Side effects:
     * Updates failure status and stores the error in the database.
     */
    public function failRun(SupplierLookupRun $run, string $message): SupplierLookupRun
    {
        $run->refresh();
        $run->forceFill([
            'status' => SupplierLookupRun::STATUS_FAILED,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'estimated_seconds_remaining' => null,
            'error_message' => Str::limit($message, 65535, ''),
        ])->save();

        Log::error('[PROCYNIA][SUPPLIER_LOOKUP][FAIL] Failed supplier lookup run.', [
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'message' => $message,
        ]);

        return $run->fresh();
    }

    /**
     * Purpose:
     * Load a supplier lookup run by uuid and normalize it for UI/status consumers.
     *
     * Inputs:
     * Supplier lookup run uuid.
     *
     * Returns:
     * Array<string, mixed>|null
     *
     * Side effects:
     * None.
     */
    public function statusPayloadForUuid(?string $uuid): ?array
    {
        $normalizedUuid = $this->nullableString($uuid);

        if ($normalizedUuid === null) {
            return null;
        }

        $run = SupplierLookupRun::query()
            ->where('uuid', $normalizedUuid)
            ->first();

        if (! $run instanceof SupplierLookupRun) {
            return null;
        }

        return $this->statusPayload($run);
    }

    /**
     * Purpose:
     * Normalize a run model into the UI/status payload shape.
     *
     * Inputs:
     * Existing supplier lookup run.
     *
     * Returns:
     * Array<string, mixed>
     *
     * Side effects:
     * None.
     */
    public function statusPayload(SupplierLookupRun $run): array
    {
        $run->refresh();

        return [
            'run_uuid' => $run->uuid,
            'status' => $run->status,
            'status_label' => $this->statusLabel($run->status),
            'supplier_query' => $run->supplier_query,
            'resolved_winner_label' => $run->resolved_winner_label,
            'resolved_winner_id' => $run->resolved_winner_id,
            'total_items' => $run->total_items,
            'processed_items' => $run->processed_items,
            'matched_items' => $run->matched_items,
            'failed_items' => $run->failed_items,
            'progress_percent' => $run->progress_percent !== null ? (float) $run->progress_percent : null,
            'estimated_seconds_remaining' => $run->estimated_seconds_remaining,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'last_heartbeat_at' => $run->last_heartbeat_at?->toIso8601String(),
            'error_message' => $run->error_message,
            'is_terminal' => $run->isTerminal(),
        ];
    }

    /**
     * Purpose:
     * Build the queue name used by supplier lookup jobs.
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
    private function queueName(): string
    {
        return 'supplier-lookups';
    }

    /**
     * Purpose:
     * Collect unique Doffin notice ids for the selected supplier across the date range.
     *
     * Inputs:
     * Existing run and resolved Doffin winner id.
     *
     * Returns:
     * Array<int, string>
     *
     * Side effects:
     * Performs Doffin search requests through the public client.
     */
    private function collectNoticeIds(SupplierLookupRun $run, string $winnerId): array
    {
        $from = CarbonImmutable::parse((string) $run->source_from_date);
        $to = CarbonImmutable::parse((string) $run->source_to_date);
        $noticeIds = collect();

        foreach ($this->monthlyPartitions($from, $to) as $partition) {
            $noticeIds = $noticeIds->merge(
                $this->collectPartitionNoticeIds(
                    $partition['from'],
                    $partition['to'],
                    [
                        'types' => $run->notice_type_filters ?? ['RESULT'],
                        'winner_ids' => [$winnerId],
                    ],
                )
            );
        }

        return $noticeIds
            ->filter(fn (mixed $noticeId): bool => is_string($noticeId) && trim($noticeId) !== '')
            ->map(fn (string $noticeId): string => trim($noticeId))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Purpose:
     * Recursively collect notice ids for a bounded date partition.
     *
     * Inputs:
     * Partition start, partition end, and supplier lookup filters.
     *
     * Returns:
     * Array<int, string>
     *
     * Side effects:
     * Performs paginated Doffin search requests and recursive partition splits when needed.
     */
    private function collectPartitionNoticeIds(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        Log::info('[PROCYNIA][SUPPLIER_LOOKUP][PREPARE] Processing supplier lookup partition.', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        $firstPage = $this->publicClient->search(
            [
                ...$filters,
                'publication_from' => $from->toDateString(),
                'publication_to' => $to->toDateString(),
            ],
            1,
            $this->perPage(),
        );

        if ($this->isCapped($firstPage)) {
            if ($from->isSameDay($to)) {
                throw new RuntimeException('Supplier lookup partition exceeded the accessible result cap for a single day.');
            }

            [$leftStart, $leftEnd, $rightStart, $rightEnd] = $this->splitPartition($from, $to);

            return [
                ...$this->collectPartitionNoticeIds($leftStart, $leftEnd, $filters),
                ...$this->collectPartitionNoticeIds($rightStart, $rightEnd, $filters),
            ];
        }

        $responses = [$firstPage];
        $lastPage = $this->lastPage($firstPage);

        for ($page = 2; $page <= $lastPage; $page++) {
            $this->throttle();

            $responses[] = $this->publicClient->search(
                [
                    ...$filters,
                    'publication_from' => $from->toDateString(),
                    'publication_to' => $to->toDateString(),
                ],
                $page,
                $this->perPage(),
            );
        }

        return collect($responses)
            ->flatMap(fn (array $response): array => $response['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->map(fn (array $hit): string => trim((string) ($hit['id'] ?? '')))
            ->filter(fn (string $noticeId): bool => $noticeId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Purpose:
     * Persist the per-notice work items for a supplier lookup run.
     *
     * Inputs:
     * Existing run and the unique notice ids to track.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Inserts or updates supplier_lookup_run_notices rows.
     */
    private function storeRunNotices(SupplierLookupRun $run, array $noticeIds): void
    {
        if ($noticeIds === []) {
            return;
        }

        SupplierLookupRunNotice::query()->upsert(
            collect($noticeIds)
                ->map(fn (string $noticeId): array => [
                    'supplier_lookup_run_id' => $run->id,
                    'notice_id' => $noticeId,
                    'status' => SupplierLookupRunNotice::STATUS_QUEUED,
                    'matched' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all(),
            ['supplier_lookup_run_id', 'notice_id'],
            ['status', 'matched', 'error_message', 'processed_at', 'updated_at'],
        );
    }

    /**
     * Purpose:
     * Mark a per-notice run row as running in a retry-safe way.
     *
     * Inputs:
     * Existing run and notice id.
     *
     * Returns:
     * SupplierLookupRunNotice|null
     *
     * Side effects:
     * Updates the per-notice status and the run heartbeat.
     */
    private function markNoticeAsRunning(SupplierLookupRun $run, string $noticeId): ?SupplierLookupRunNotice
    {
        return DB::transaction(function () use ($run, $noticeId): ?SupplierLookupRunNotice {
            $item = SupplierLookupRunNotice::query()
                ->where('supplier_lookup_run_id', $run->id)
                ->where('notice_id', $noticeId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SupplierLookupRunNotice) {
                return null;
            }

            if ($item->status === SupplierLookupRunNotice::STATUS_COMPLETED) {
                return $item;
            }

            $item->forceFill([
                'status' => SupplierLookupRunNotice::STATUS_RUNNING,
                'error_message' => null,
            ])->save();

            $run->forceFill([
                'last_heartbeat_at' => now(),
            ])->save();

            return $item->fresh();
        });
    }

    /**
     * Purpose:
     * Mark a per-notice run row as completed.
     *
     * Inputs:
     * Existing run, notice id, and match flag.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates the per-notice row to completed with match metadata.
     */
    private function completeNoticeItem(SupplierLookupRun $run, string $noticeId, bool $matched): void
    {
        DB::transaction(function () use ($run, $noticeId, $matched): void {
            $item = SupplierLookupRunNotice::query()
                ->where('supplier_lookup_run_id', $run->id)
                ->where('notice_id', $noticeId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SupplierLookupRunNotice) {
                return;
            }

            $item->forceFill([
                'status' => SupplierLookupRunNotice::STATUS_COMPLETED,
                'matched' => $matched,
                'error_message' => null,
                'processed_at' => now(),
            ])->save();
        });
    }

    /**
     * Purpose:
     * Mark a per-notice run row as failed without aborting the entire run.
     *
     * Inputs:
     * Existing run, notice id, and failure message.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates the per-notice row with failed status and stores the error.
     */
    private function failNoticeItem(SupplierLookupRun $run, string $noticeId, string $message): void
    {
        DB::transaction(function () use ($run, $noticeId, $message): void {
            $item = SupplierLookupRunNotice::query()
                ->where('supplier_lookup_run_id', $run->id)
                ->where('notice_id', $noticeId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SupplierLookupRunNotice) {
                return;
            }

            $item->forceFill([
                'status' => SupplierLookupRunNotice::STATUS_FAILED,
                'matched' => false,
                'error_message' => Str::limit($message, 65535, ''),
                'processed_at' => now(),
            ])->save();
        });
    }

    /**
     * Purpose:
     * Determine whether the persisted supplier records match the selected supplier query.
     *
     * Inputs:
     * Existing run and parsed supplier records for one notice.
     *
     * Returns:
     * bool
     *
     * Side effects:
     * None.
     */
    private function recordsMatchRun(SupplierLookupRun $run, array $records): bool
    {
        $normalizedTarget = $this->normalizeSupplierName(
            $run->resolved_winner_label ?: (string) $run->supplier_query
        );

        if ($normalizedTarget === '') {
            return $records !== [];
        }

        return collect($records)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->contains(fn (array $record): bool => $this->normalizeSupplierName((string) ($record['supplier_name'] ?? '')) === $normalizedTarget);
    }

    /**
     * Purpose:
     * Split a capped supplier lookup partition into two smaller deterministic partitions.
     *
     * Inputs:
     * Partition start and end.
     *
     * Returns:
     * Array<int, CarbonImmutable>
     *
     * Side effects:
     * None.
     */
    private function splitPartition(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $diffDays = $from->diffInDays($to);
        $leftEnd = $from->addDays(max(0, intdiv($diffDays, 2)));
        $rightStart = $leftEnd->addDay();

        return [$from, $leftEnd, $rightStart, $to];
    }

    /**
     * Purpose:
     * Build fixed monthly partitions for a supplier lookup date range.
     *
     * Inputs:
     * Run start and end dates.
     *
     * Returns:
     * Array<int, array<string, CarbonImmutable>>
     *
     * Side effects:
     * None.
     */
    private function monthlyPartitions(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $partitions = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $partitionStart = $cursor->greaterThan($from) ? $cursor : $from;
            $partitionEnd = $cursor->endOfMonth()->greaterThan($to) ? $to : $cursor->endOfMonth();

            $partitions[] = [
                'from' => $partitionStart,
                'to' => $partitionEnd,
            ];

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $partitions;
    }

    /**
     * Purpose:
     * Check whether a Doffin search response is capped and incomplete.
     *
     * Inputs:
     * Public client search response.
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
     * Resolve the last search page from a public client response.
     *
     * Inputs:
     * Public client search response.
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

        return max(1, (int) ceil($accessible / $this->perPage()));
    }

    /**
     * Purpose:
     * Return the number of hits fetched per Doffin search page.
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
     * Sleep between public Doffin requests using existing throttle configuration.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Pauses execution briefly.
     */
    private function throttle(): void
    {
        $throttleMs = max(0, (int) config('doffin.public_client.throttle_ms', 100));

        if ($throttleMs > 0) {
            usleep($throttleMs * 1000);
        }
    }

    /**
     * Purpose:
     * Compute the progress percentage for a supplier lookup run.
     *
     * Inputs:
     * Existing run, total item count, and processed item count.
     *
     * Returns:
     * float
     *
     * Side effects:
     * None.
     */
    private function progressPercent(SupplierLookupRun $run, int $totalItems, int $processedItems): float
    {
        if ($totalItems === 0) {
            return $run->status === SupplierLookupRun::STATUS_COMPLETED ? 100.0 : 0.0;
        }

        return round(min(100, ($processedItems / $totalItems) * 100), 2);
    }

    /**
     * Purpose:
     * Estimate remaining seconds from observed throughput.
     *
     * Inputs:
     * Existing run, total item count, and processed item count.
     *
     * Returns:
     * int|null
     *
     * Side effects:
     * None.
     */
    private function estimatedSecondsRemaining(SupplierLookupRun $run, int $totalItems, int $processedItems): ?int
    {
        if (! $run->started_at instanceof Carbon || $processedItems <= 0) {
            return null;
        }

        $elapsedSeconds = max(0, $run->started_at->diffInSeconds(now()));

        if ($elapsedSeconds < 1) {
            return null;
        }

        $throughput = $processedItems / $elapsedSeconds;

        if ($throughput <= 0) {
            return null;
        }

        $remainingItems = max(0, $totalItems - $processedItems);

        if ($remainingItems === 0) {
            return 0;
        }

        return max(0, (int) ceil($remainingItems / $throughput));
    }

    /**
     * Purpose:
     * Convert persisted status codes into human-readable UI labels.
     *
     * Inputs:
     * Supplier lookup run status code.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            SupplierLookupRun::STATUS_QUEUED => 'Queued',
            SupplierLookupRun::STATUS_PREPARING => 'Preparing notices',
            SupplierLookupRun::STATUS_RUNNING => 'Running supplier lookup',
            SupplierLookupRun::STATUS_COMPLETED => 'Completed',
            SupplierLookupRun::STATUS_FAILED => 'Failed',
            SupplierLookupRun::STATUS_CANCELLED => 'Cancelled',
            default => Str::headline($status),
        };
    }

    /**
     * Purpose:
     * Normalize supplier names for deterministic comparisons.
     *
     * Inputs:
     * Supplier name candidate.
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
     * Normalize nullable strings for persistence and comparison.
     *
     * Inputs:
     * Mixed value from a payload or model.
     *
     * Returns:
     * string|null
     *
     * Side effects:
     * None.
     */
    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Purpose:
     * Normalize notice type filters into a deterministic array.
     *
     * Inputs:
     * Mixed notice type filter payload.
     *
     * Returns:
     * Array<int, string>
     *
     * Side effects:
     * None.
     */
    private function normalizeNoticeTypes(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(fn (mixed $type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Purpose:
     * Normalize date input values for run persistence.
     *
     * Inputs:
     * Mixed date payload.
     *
     * Returns:
     * string|null
     *
     * Side effects:
     * None.
     */
    private function normalizeDateValue(mixed $value): ?string
    {
        $normalized = $this->nullableString($value);

        return $normalized === null ? null : CarbonImmutable::parse($normalized)->toDateString();
    }
}
