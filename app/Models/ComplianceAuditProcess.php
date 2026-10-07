<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Kvalitet process in an audit's scope. Only the relation is stored.
 *
 * Reached only through its audit, and shown only to someone who can read Kvalitet. Kvalitet never
 * reads this model. See ComplianceAuditScopeService.
 */
class ComplianceAuditProcess extends Model
{
    protected $fillable = [
        'customer_id',
        'audit_id',
        'quality_process_id',
        'created_by',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ComplianceAudit::class, 'audit_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_process_id');
    }
}
