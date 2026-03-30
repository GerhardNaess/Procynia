<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose:
 * Store one notice work item inside an asynchronous Doffin supplier harvest run.
 *
 * Inputs:
 * Database attributes for one notice scheduled for supplier harvesting.
 *
 * Returns:
 * Model instances representing per-notice harvest work.
 *
 * Side effects:
 * Persists per-notice status, harvested supplier counts, and failures.
 */
class DoffinSupplierHarvestRunNotice extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'doffin_supplier_harvest_run_id',
        'notice_id',
        'status',
        'supplier_count',
        'error_message',
        'processed_at',
    ];

    /**
     * Purpose:
     * Cast persisted harvest notice fields to runtime types.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * array<string, string>
     *
     * Side effects:
     * None.
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Purpose:
     * Return the parent harvest run for this notice row.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * BelongsTo
     *
     * Side effects:
     * None.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DoffinSupplierHarvestRun::class, 'doffin_supplier_harvest_run_id');
    }
}
