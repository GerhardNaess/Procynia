<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One assessment of a risk, as it was made. Immutable: a correction is a new assessment.
 *
 * Holds the two values a person gave for inherent risk and, optionally, for residual risk. Score
 * and level are not stored; RiskScoringPolicy computes them from the values and criteria_key.
 *
 * risk_cause / risk_event / risk_consequence are the risk's description as it read when the
 * assessment was made, so the assessment still says what was assessed after the risk text changes.
 * Assessments from before the snapshot existed have none, and none is invented for them.
 *
 * Has no access rules of its own. Reach it only through a risk from
 * RiskAccessService::visibleRisks().
 */
class RiskAssessment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'risk_id',
        'assessed_by',
        'assessed_at',
        'rationale',
        'risk_cause',
        'risk_event',
        'risk_consequence',
        'criteria_key',
        'inherent_likelihood',
        'inherent_consequence',
        'residual_likelihood',
        'residual_consequence',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'inherent_likelihood' => 'integer',
            'inherent_consequence' => 'integer',
            'residual_likelihood' => 'integer',
            'residual_consequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A risk assessment is history and cannot be changed. Register a new one.');
        });

        static::deleting(function (): void {
            throw new LogicException('A risk assessment is history and cannot be deleted on its own.');
        });
    }

    public function hasResidual(): bool
    {
        return $this->residual_likelihood !== null && $this->residual_consequence !== null;
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
