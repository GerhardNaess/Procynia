<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One closing or reopening of an objective, as it was made. Immutable, like RiskAssessment: a
 * mistaken closing is undone by a reopening, which is itself a new row.
 *
 * Written only by ObjectiveLifecycleService, in the same transaction as the change to the
 * objective's current state. Has no access rules of its own — reach it only through an objective
 * from ObjectiveAccessService::visibleObjectives().
 */
class ObjectiveStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'objective_id',
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
            throw new LogicException('An objective status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An objective status change is history and cannot be deleted on its own.');
        });
    }

    public function isReopening(): bool
    {
        return $this->to_status === Objective::STATUS_ACTIVE;
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
