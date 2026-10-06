<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Et revisjonsfunn: what a revisjon found — an avvik, an observasjon or a forbedringsmulighet —
 * optionally about a requirement, a Kvalitet process and a Kvalitet control.
 *
 * An observation, not a follow-up system: no status, severity, frist, owner or tiltak. It is
 * followed up by handing it off explicitly to one ImprovementCase in Avvik og forbedringer
 * (ComplianceAuditFindingHandoffService). Once handed off it is frozen for good — the database holds
 * that too — and the relation to the case is the only provenance.
 *
 * Recorded, changed and deleted only while the audit is in progress (ComplianceAuditFindingService).
 * Reached only through an audit the user can see (ComplianceAccessService::visibleAudits()).
 */
class ComplianceAuditFinding extends Model
{
    public const TYPE_NONCONFORMITY = 'nonconformity';

    public const TYPE_OBSERVATION = 'observation';

    public const TYPE_OPPORTUNITY = 'opportunity';

    public const TYPES = [
        self::TYPE_NONCONFORMITY,
        self::TYPE_OBSERVATION,
        self::TYPE_OPPORTUNITY,
    ];

    /**
     * What a finding becomes in Avvik og forbedringer. Fixed in v1: an avvik is followed up as an
     * avvik, everything else as a forbedring.
     */
    public const IMPROVEMENT_TYPES = [
        self::TYPE_NONCONFORMITY => ImprovementCase::TYPE_DEVIATION,
        self::TYPE_OBSERVATION => ImprovementCase::TYPE_IMPROVEMENT,
        self::TYPE_OPPORTUNITY => ImprovementCase::TYPE_IMPROVEMENT,
    ];

    protected $fillable = [
        'customer_id',
        'audit_id',
        'finding_type',
        'title',
        'description',
        'requirement_id',
        'quality_process_id',
        'control_item_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'handed_off_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses this too; this says so before the query is sent.
        static::saving(function (self $finding): void {
            if (! in_array($finding->finding_type, self::TYPES, true)) {
                throw new DomainException("Unknown compliance audit finding type [{$finding->finding_type}].");
            }
        });
    }

    public function isHandedOff(): bool
    {
        return $this->improvement_case_id !== null;
    }

    /** The ImprovementCase type this finding is followed up as. */
    public function improvementCaseType(): string
    {
        return self::IMPROVEMENT_TYPES[$this->finding_type];
    }

    /**
     * Avvik not yet handed off — what the coming revisjons-Attention reports for a completed audit.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNonconformitiesAwaitingHandoff(Builder $query): Builder
    {
        return $query
            ->where('compliance_audit_findings.finding_type', self::TYPE_NONCONFORMITY)
            ->whereNull('compliance_audit_findings.improvement_case_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ComplianceAudit::class, 'audit_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function handedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_off_by_user_id');
    }
}
