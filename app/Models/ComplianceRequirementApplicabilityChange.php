<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Relevans or Kontrollvalg decision on a library requirement, as it was made (A35): from, to,
 * the begrunnelse, who and when. Immutable: the database refuses changes and deletes too (see the
 * migration's trigger).
 *
 * Written only by ComplianceApplicabilityService, in the same transaction as the change to the
 * requirement. Has no access rules of its own — reach it only through a requirement from
 * ComplianceAccessService::visibleRequirements().
 */
class ComplianceRequirementApplicabilityChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'requirement_id',
        'from_applicability',
        'to_applicability',
        'rationale',
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
            throw new LogicException('A compliance requirement applicability change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A compliance requirement applicability change is history and cannot be deleted.');
        });
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
