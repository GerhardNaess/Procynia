<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One effektverifisering of a completed tiltak: did it work? Effekt bekreftet or Ikke effektivt,
 * with a comment, by whom and when.
 *
 * It judges one completion — the status change that set the tiltak to completed — not the tiltak
 * in general, so a reopened and again completed tiltak starts with no verification. Immutable: a
 * new judgement is a new row, and the newest for the completion is the current one
 * (ImprovementActionVerificationResolver). The database refuses changes too (see the migration).
 *
 * Written only by ImprovementActionLifecycleService::verify(). Has no access rules of its own —
 * reach it only through a case from ImprovementCaseAccessService::visibleCases().
 */
class ImprovementActionVerification extends Model
{
    public const RESULT_EFFECTIVE = 'effective';

    public const RESULT_NOT_EFFECTIVE = 'not_effective';

    public const RESULTS = [
        self::RESULT_EFFECTIVE,
        self::RESULT_NOT_EFFECTIVE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'improvement_action_id',
        'completion_status_change_id',
        'result',
        'note',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An improvement action verification is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An improvement action verification is history and cannot be deleted on its own.');
        });
    }

    public function isEffective(): bool
    {
        return $this->result === self::RESULT_EFFECTIVE;
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ImprovementAction::class, 'improvement_action_id');
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(ImprovementActionStatusChange::class, 'completion_status_change_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
