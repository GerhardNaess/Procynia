<?php

namespace App\Jobs\Doffin;

use App\Models\SupplierLookupRun;
use App\Services\Doffin\SupplierLookupRunService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Purpose:
 * Process one Doffin notice inside an asynchronous supplier lookup run.
 *
 * Inputs:
 * Supplier lookup run id and Doffin notice id.
 *
 * Returns:
 * None.
 *
 * Side effects:
 * Fetches one notice, persists supplier data, and updates run progress in the database.
 */
class ProcessSupplierLookupNotice implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Purpose:
     * Create the per-notice supplier lookup job.
     *
     * Inputs:
     * Supplier lookup run id and Doffin notice id.
     *
     * Returns:
     * New ProcessSupplierLookupNotice instance.
     *
     * Side effects:
     * Stores notice work identifiers for later queued execution.
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $noticeId,
    ) {
        $this->queue = 'supplier-lookups';
    }

    /**
     * Purpose:
     * Execute one notice inside a supplier lookup batch.
     *
     * Inputs:
     * Supplier lookup run service from the container.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates one run-notice row and refreshes overall run progress.
     */
    public function handle(SupplierLookupRunService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = SupplierLookupRun::query()->find($this->runId);

        if (! $run instanceof SupplierLookupRun) {
            return;
        }

        $service->processNotice($run, $this->noticeId);
    }
}
