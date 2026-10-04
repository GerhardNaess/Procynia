<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An explicit acceptance of the residual risk in one assessment. Not a status of the risk.
 *
 * Never edited: the only change a row allows is being revoked, once. A correction is a revocation
 * followed by a new acceptance. Whether it is the one that applies now, and whether it has expired,
 * is computed (RiskAcceptanceService, isExpired()), never stored.
 *
 * Reached only through its risk, and so only through RiskAccessService::visibleRisks().
 */
class RiskAcceptance extends Model
{
    protected $fillable = [
        'customer_id',
        'risk_id',
        'assessment_id',
        'accepted_by_user_id',
        'rationale',
        'accepted_at',
        'valid_until',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RiskAcceptance $acceptance): void {
            $changed = array_keys($acceptance->getDirty());
            $revoking = $acceptance->getOriginal('revoked_at') === null
                && $acceptance->revoked_at !== null
                && array_diff($changed, ['revoked_at', 'revoked_by_user_id', 'updated_at']) === [];

            if (! $revoking) {
                throw new LogicException('A risk acceptance cannot be changed. Revoke it and register a new one.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A risk acceptance is history and cannot be deleted on its own.');
        });
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'assessment_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * «Utløpt»: past the valid-until day. The acceptance holds through that whole day; without a
     * valid-until it never expires.
     */
    public function isExpired(?CarbonInterface $today = null): bool
    {
        $today ??= now();

        return $this->valid_until !== null
            && $this->valid_until->toDateString() < $today->toDateString();
    }
}
