<?php

namespace App\Jobs\Doffin;

use App\Models\DoffinSupplierHarvestRun;
use App\Services\Doffin\DoffinSupplierHarvestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose:
 * Prepare a Doffin supplier harvest run and queue one job per matching notice.
 *
 * Inputs:
 * Supplier harvest run id.
 *
 * Returns:
 * None.
 *
 * Side effects:
 * Updates run status, creates per-notice rows, and dispatches a queue batch.
 */
class PrepareDoffinSupplierHarvestRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Purpose:
     * Create the prepare job.
     *
     * Inputs:
     * Supplier harvest run id.
     *
     * Returns:
     * New PrepareDoffinSupplierHarvestRun instance.
     *
     * Side effects:
     * Stores the run id for later queued execution.
     */
    public function __construct(public readonly int $runId)
    {
        $this->queue = (string) config('doffin.supplier_harvest.queue', 'supplier-harvests');
    }

    /**
     * Purpose:
     * Execute supplier harvest preparation.
     *
     * Inputs:
     * Supplier harvest service from the container.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Plans and queues child jobs or marks the run as failed.
     */
    public function handle(DoffinSupplierHarvestService $service): void
    {
        $run = DoffinSupplierHarvestRun::query()->find($this->runId);

        if (! $run instanceof DoffinSupplierHarvestRun) {
            return;
        }

        try {
            $service->prepareRun($run);
        } catch (Throwable $throwable) {
            $service->failRun($run, $throwable->getMessage());

            Log::error('[PROCYNIA][SUPPLIER_HARVEST][FAIL] Prepare job crashed.', [
                'run_id' => $this->runId,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
