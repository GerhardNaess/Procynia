<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One status change of an audit, as it was made — Start, Fullfør, Avbryt or Gjenåpne, with its
 * begrunnelse where one was given (always for Avbryt and Gjenåpne). Immutable: the database refuses
 * changes and deletes too (see the migration's trigger).
 *
 * Written only by ComplianceAuditLifecycleService, in the same transaction as the change to the
 * audit's status. Has no access rules of its own — reach it only through an audit from
 * ComplianceAccessService::visibleAudits().
 */
class ComplianceAuditStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'audit_id',
        'from_status',
        'to_status',
        'reason',
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
            throw new LogicException('A compliance audit status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A compliance audit status change is history and cannot be deleted.');
        });
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ComplianceAudit::class, 'audit_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
