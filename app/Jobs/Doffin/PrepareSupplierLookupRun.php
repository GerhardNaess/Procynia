<?php

namespace App\Jobs\Doffin;

use App\Models\SupplierLookupRun;
use App\Services\Doffin\SupplierLookupRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purpose:
 * Prepare an asynchronous supplier lookup run and queue one job per matching notice.
 *
 * Inputs:
 * Supplier lookup run id.
 *
 * Returns:
 * None.
 *
 * Side effects:
 * Updates run status and dispatches a batch of ProcessSupplierLookupNotice jobs.
 */
class PrepareSupplierLookupRun implements ShouldQueue
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
     * Supplier lookup run id.
     *
     * Returns:
     * New PrepareSupplierLookupRun instance.
     *
     * Side effects:
     * Stores the run id for later queued execution.
     */
    public function __construct(public readonly int $runId)
    {
        $this->queue = 'supplier-lookups';
    }

    /**
     * Purpose:
     * Execute supplier lookup run preparation.
     *
     * Inputs:
     * Supplier lookup run service from the container.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Plans and queues child jobs or marks the run as failed.
     */
    public function handle(SupplierLookupRunService $service): void
    {
        $run = SupplierLookupRun::query()->find($this->runId);

        if (! $run instanceof SupplierLookupRun) {
            return;
        }

        try {
            $service->prepareRun($run);
        } catch (Throwable $throwable) {
            $service->failRun($run, $throwable->getMessage());

            Log::error('[PROCYNIA][SUPPLIER_LOOKUP][FAIL] Prepare job crashed.', [
                'run_id' => $this->runId,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
