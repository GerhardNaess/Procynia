<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One status change of a tiltak, as it was made — Start, Fullfør, Avbryt or Gjenåpne. Immutable: a
 * reopened tiltak keeps its earlier completion here, «Hva ble gjort?» and all. The database refuses
 * changes too (see the migration's trigger).
 *
 * Written only by ImprovementActionLifecycleService, in the same transaction as the change to the
 * tiltak's current state. Has no access rules of its own — reach it only through a case from
 * ImprovementCaseAccessService::visibleCases().
 */
class ImprovementActionStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'improvement_action_id',
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
            throw new LogicException('An improvement action status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An improvement action status change is history and cannot be deleted on its own.');
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ImprovementAction::class, 'improvement_action_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
