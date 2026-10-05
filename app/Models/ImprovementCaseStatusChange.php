<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One status change of an avvik or a forbedring, as it was made — Start behandling, Lukk, Avbryt or
 * Gjenåpne. Immutable: a mistaken closing is undone by a reopening, which is itself a new row. The
 * database refuses changes too (see the migration's trigger).
 *
 * Written only by ImprovementCaseLifecycleService, in the same transaction as the change to the
 * case's current state. Has no access rules of its own — reach it only through a case from
 * ImprovementCaseAccessService::visibleCases().
 */
class ImprovementCaseStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'improvement_case_id',
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
            throw new LogicException('An improvement case status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An improvement case status change is history and cannot be deleted on its own.');
        });
    }

    public function improvementCase(): BelongsTo
    {
        return $this->belongsTo(ImprovementCase::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
