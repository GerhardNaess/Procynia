<?php

namespace App\Services\Doffin;

use App\Models\DoffinImportRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates manual Doffin admin executions for Filament.
 */
class DoffinAdminExecutionService
{
    /**
     * Create the execution coordinator.
     */
    public function __construct(
        private readonly DoffinHarvestWindowService $harvestWindowService,
        private readonly DoffinSupplierLookupService $supplierLookupService,
        private readonly DoffinPersistenceService $persistenceService,
    ) {
    }

    /**
     * Run a manual Doffin harvest and persist the result.
     */
    public function runHarvest(array $payload): array
    {
        $run = $this->startRun('admin_public_harvest', $payload);

        try {
            Log::info('[DOFFIN][admin] Starting manual harvest.', $payload);

            $harvest = $this->harvestWindowService->harvest(
                $payload['from'],
                $payload['to'],
                [
                    'types' => $payload['types'] ?? ['RESULT'],
                ],
                $payload['window_days'] ?? null,
            );
            $persistence = $this->persistenceService->persist($harvest['notices'], $harvest['records']);
            $result = [
                'mode' => 'harvest',
                'harvest' => $harvest['stats'],
                'persistence' => $persistence,
                'selected_candidate' => null,
                'winner_candidates' => [],
                'run_id' => $run->id,
            ];

            $this->completeRun($run, $result);

            Log::info('[DOFFIN][admin] Finished manual harvest.', $result);

            return $result;
        } catch (Throwable $throwable) {
            $this->failRun($run, $throwable, $payload);

            throw $throwable;
        }
    }

    /**
     * Run a manual supplier lookup and persist the result.
     */
    public function runSupplierLookup(array $payload): array
    {
        $run = $this->startRun('admin_supplier_lookup', $payload);

        try {
            Log::info('[DOFFIN][admin] Starting manual supplier lookup.', $payload);

            $lookup = $this->supplierLookupService->lookup(
                $payload['supplier_name'],
                $payload['from'],
                $payload['to'],
                [
                    'types' => $payload['types'] ?? ['RESULT'],
                ],
                $payload['window_days'] ?? null,
            );
            $persistence = $this->persistenceService->persist($lookup['notices'], $lookup['records']);
            $result = [
                'mode' => 'supplier_lookup',
                'harvest' => $lookup['stats'],
                'persistence' => $persistence,
                'selected_candidate' => $lookup['selected_candidate'],
                'winner_candidates' => $lookup['winner_candidates'],
                'run_id' => $run->id,
            ];

            $this->completeRun($run, $result);

            Log::info('[DOFFIN][admin] Finished manual supplier lookup.', $result);

            return $result;
        } catch (Throwable $throwable) {
            $this->failRun($run, $throwable, $payload);

            throw $throwable;
        }
    }

    /**
     * Start and persist a Doffin admin run log row.
     */
    private function startRun(string $trigger, array $payload): DoffinImportRun
    {
        return DoffinImportRun::query()->create([
            'trigger' => $trigger,
            'started_at' => now(),
            'status' => 'running',
            'meta' => [
                'input' => $payload,
            ],
        ]);
    }

    /**
     * Mark a Doffin admin run as completed.
     */
    private function completeRun(DoffinImportRun $run, array $result): void
    {
        $persistence = $result['persistence'] ?? [];
        $harvest = $result['harvest'] ?? [];

        $run->fill([
            'finished_at' => now(),
            'status' => 'completed',
            'fetched_count' => (int) ($harvest['notices_seen'] ?? 0),
            'created_count' => (int) ($persistence['created_total'] ?? 0),
            'updated_count' => (int) ($persistence['updated_total'] ?? 0),
            'skipped_count' => 0,
            'failed_count' => 0,
            'error_message' => null,
            'meta' => [
                ...($run->meta ?? []),
                'result' => $result,
            ],
        ]);
        $run->save();
    }

    /**
     * Mark a Doffin admin run as failed.
     */
    private function failRun(DoffinImportRun $run, Throwable $throwable, array $payload): void
    {
        Log::error('[DOFFIN][admin] Manual execution failed.', [
            'trigger' => $run->trigger,
            'message' => $throwable->getMessage(),
            'payload' => $payload,
        ]);

        $run->fill([
            'finished_at' => now(),
            'status' => 'failed',
            'failed_count' => 1,
            'error_message' => $throwable->getMessage(),
            'meta' => [
                ...($run->meta ?? []),
                'error' => $throwable->getMessage(),
            ],
        ]);
        $run->save();
    }
}
