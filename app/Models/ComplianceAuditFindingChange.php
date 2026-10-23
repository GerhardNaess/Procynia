<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One correction of a finding that was part of a completed audit (A34): the values it had before,
 * who changed them and when. Immutable: the database refuses changes and deletes too (see the
 * migration's trigger).
 *
 * Written only by ComplianceAuditFindingService, in the same transaction as the correction. Has no
 * access rules of its own — reach it only through an audit from ComplianceAccessService::visibleAudits().
 */
class ComplianceAuditFindingChange extends Model
{
    public $timestamps = false;

    /** The finding's own columns a correction can change, and so the ones kept as they were. */
    public const TRACKED = ['finding_type', 'title', 'description', 'requirement_id', 'check_id', 'quality_process_id', 'control_item_id'];

    protected $fillable = [
        'customer_id',
        'audit_id',
        'finding_id',
        'previous',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A compliance audit finding change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A compliance audit finding change is history and cannot be deleted.');
        });
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ComplianceAuditFinding::class, 'finding_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
