<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En kontrollhandling: what the auditor examined during an audit — what (title), what was actually
 * looked at and seen (observation) and on what evidence — optionally for one or more requirements in
 * the audit's scope.
 *
 * It documents an examination, not a result: being examined is not the same as conforming, and a
 * check carries no conformity verdict of its own. Results that need recording on their own — an
 * avvik, an observasjon, a forbedringsmulighet — are findings, which may name the check they came
 * from; one check can give rise to several. A check with findings cannot be deleted.
 *
 * Recorded, changed and deleted only while the audit is in progress (ComplianceAuditCheckService).
 * Reached only through an audit the user can see (ComplianceAccessService::visibleAudits()).
 */
class ComplianceAuditCheck extends Model
{
    protected $fillable = [
        'customer_id',
        'audit_id',
        'title',
        'observation',
        'evidence',
        'created_by',
        'updated_by',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ComplianceAudit::class, 'audit_id');
    }

    public function requirementLinks(): HasMany
    {
        return $this->hasMany(ComplianceAuditCheckRequirement::class, 'check_id');
    }

    /** In the order they were recorded. */
    public function findings(): HasMany
    {
        return $this->hasMany(ComplianceAuditFinding::class, 'check_id')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
