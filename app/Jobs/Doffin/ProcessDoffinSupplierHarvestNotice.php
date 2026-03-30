<?php

namespace App\Jobs\Doffin;

use App\Models\DoffinSupplierHarvestRun;
use App\Services\Doffin\DoffinSupplierHarvestService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Purpose:
 * Process one Doffin notice inside an asynchronous supplier harvest run.
 *
 * Inputs:
 * Supplier harvest run id and Doffin notice id.
 *
 * Returns:
 * None.
 *
 * Side effects:
 * Fetches one notice, upserts suppliers, and refreshes harvest progress.
 */
class ProcessDoffinSupplierHarvestNotice implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Purpose:
     * Create the per-notice supplier harvest job.
     *
     * Inputs:
     * Supplier harvest run id and Doffin notice id.
     *
     * Returns:
     * New ProcessDoffinSupplierHarvestNotice instance.
     *
     * Side effects:
     * Stores notice work identifiers for later queued execution.
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $noticeId,
    ) {
        $this->queue = (string) config('doffin.supplier_harvest.queue', 'supplier-harvests');
    }

    /**
     * Purpose:
     * Execute one notice inside a supplier harvest batch.
     *
     * Inputs:
     * Supplier harvest service from the container.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates one run-notice row and refreshes overall harvest progress.
     */
    public function handle(DoffinSupplierHarvestService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = DoffinSupplierHarvestRun::query()->find($this->runId);

        if (! $run instanceof DoffinSupplierHarvestRun) {
            return;
        }

        $service->processNotice($run, $this->noticeId);
    }
}
