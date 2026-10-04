<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tiltak on a risk: what is to be done, by whom, by when — open or completed.
 *
 * Reached only through its risk, and so only through RiskAccessService::visibleRisks(). Kvalitet,
 * search and reporting never read this model.
 */
class RiskTreatmentAction extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'customer_id',
        'risk_id',
        'title',
        'owner_user_id',
        'due_at',
        'status',
        'completed_at',
        'outcome_note',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * «Forfalt»: still open after the deadline day. The deadline day itself is not overdue.
     */
    public function isOverdue(?CarbonInterface $today = null): bool
    {
        $today ??= now();

        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->toDateString() < $today->toDateString();
    }
}
