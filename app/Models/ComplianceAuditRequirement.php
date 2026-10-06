<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement in an audit's scope. Only the relation is stored; the requirement is read live.
 *
 * Reached only through its audit, and so only through ComplianceAccessService. See
 * ComplianceAuditScopeService.
 */
class ComplianceAuditRequirement extends Model
{
    protected $fillable = [
        'customer_id',
        'audit_id',
        'requirement_id',
        'created_by',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ComplianceAudit::class, 'audit_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }
}
