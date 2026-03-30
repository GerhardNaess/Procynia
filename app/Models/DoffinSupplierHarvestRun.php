<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purpose:
 * Store the lifecycle and progress of an asynchronous Doffin supplier harvest run.
 *
 * Inputs:
 * Database attributes describing one supplier harvest execution.
 *
 * Returns:
 * Model instances representing supplier harvest runs.
 *
 * Side effects:
 * Persists status, counters, ETA, and ownership metadata for supplier harvesting.
 */
class DoffinSupplierHarvestRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'status',
        'source_from_date',
        'source_to_date',
        'notice_type_filters',
        'batch_id',
        'total_items',
        'processed_items',
        'harvested_suppliers',
        'failed_items',
        'progress_percent',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'estimated_seconds_remaining',
        'error_message',
        'created_by',
    ];

    /**
     * Purpose:
     * Cast persisted supplier harvest run fields to runtime types.
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
            'source_from_date' => 'date',
            'source_to_date' => 'date',
            'notice_type_filters' => 'array',
            'progress_percent' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * Purpose:
     * Return the user who created the supplier harvest run.
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Purpose:
     * Return the per-notice rows tracked for the harvest run.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * HasMany
     *
     * Side effects:
     * None.
     */
    public function notices(): HasMany
    {
        return $this->hasMany(DoffinSupplierHarvestRunNotice::class);
    }

    /**
     * Purpose:
     * Determine whether the harvest run is already in a terminal state.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * bool
     *
     * Side effects:
     * None.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
