<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose:
 * Track per-notice execution state inside an asynchronous supplier lookup run.
 *
 * Inputs:
 * Database attributes for a single notice inside a supplier lookup run.
 *
 * Returns:
 * Model instances representing run-specific notice work items.
 *
 * Side effects:
 * Persists per-notice processing status for retry-safe progress updates.
 */
class SupplierLookupRunNotice extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'supplier_lookup_run_id',
        'notice_id',
        'status',
        'matched',
        'error_message',
        'processed_at',
    ];

    /**
     * Purpose:
     * Cast persisted notice work item attributes to runtime types.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * Array<string, string>
     *
     * Side effects:
     * None.
     */
    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Purpose:
     * Return the parent supplier lookup run.
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
        return $this->belongsTo(SupplierLookupRun::class, 'supplier_lookup_run_id');
    }
}
