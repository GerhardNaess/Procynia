<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One retirement or reopening of a KPI, as it was made. Immutable, like ObjectiveStatusChange: a
 * mistaken retirement is undone by a reopening, which is itself a new row.
 *
 * Written only by KpiLifecycleService, in the same transaction as the change to the KPI's status.
 * Has no access rules of its own — reach it only through a KPI from ObjectiveAccessService.
 */
class KpiStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'kpi_id',
        'from_status',
        'to_status',
        'note',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A KPI status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A KPI status change is history and cannot be deleted on its own.');
        });
    }

    public function isReopening(): bool
    {
        return $this->to_status === Kpi::STATUS_ACTIVE;
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
