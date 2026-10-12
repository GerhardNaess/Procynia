<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement a kontrollhandling was relevant for. Only the relation is stored; the requirement is
 * read live. The database holds that it is in the audit's scope.
 */
class ComplianceAuditCheckRequirement extends Model
{
    protected $fillable = [
        'customer_id',
        'audit_id',
        'check_id',
        'requirement_id',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(ComplianceAuditCheck::class, 'check_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }
}
