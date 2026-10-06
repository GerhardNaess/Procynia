<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * En etterlevelsesvurdering: one judgement of whether the virksomhet meets a requirement, with its
 * begrunnelse, as it was made. Immutable: a wrong assessment is corrected by registering a new one.
 * The database refuses changes and deletes too (see the migration's trigger).
 *
 * There is no «not assessed» result; a requirement without assessments is Ikke vurdert.
 *
 * The requirement_* and source_* columns are the requirement as it read when it was assessed.
 *
 * Written only by ComplianceAssessmentService. Has no access rules of its own — reach it only
 * through a requirement from ComplianceAccessService::visibleRequirements().
 */
class ComplianceAssessment extends Model
{
    public const RESULT_COMPLIANT = 'compliant';

    public const RESULT_PARTIALLY_COMPLIANT = 'partially_compliant';

    public const RESULT_NON_COMPLIANT = 'non_compliant';

    public const RESULT_NOT_APPLICABLE = 'not_applicable';

    public const RESULTS = [
        self::RESULT_COMPLIANT,
        self::RESULT_PARTIALLY_COMPLIANT,
        self::RESULT_NON_COMPLIANT,
        self::RESULT_NOT_APPLICABLE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'requirement_id',
        'result',
        'rationale',
        'assessed_by_user_id',
        'assessed_at',
        'requirement_reference',
        'requirement_title',
        'requirement_text',
        'source_name',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $assessment): void {
            if (! in_array($assessment->result, self::RESULTS, true)) {
                throw new DomainException("Unknown compliance assessment result [{$assessment->result}].");
            }
        });

        static::updating(function (): void {
            throw new LogicException('A compliance assessment is history and cannot be changed. Register a new one.');
        });

        static::deleting(function (): void {
            throw new LogicException('A compliance assessment is history and cannot be deleted.');
        });
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }
}
