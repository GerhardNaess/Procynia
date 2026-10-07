<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change of a supplier's criticality, as it was made: the level, review interval and four ja/nei
 * answers before and after, and the begrunnelse. Immutable: a mistaken classification is corrected
 * by a new change, which is itself a new row. The database refuses changes and deletes too (see the
 * migration's trigger).
 *
 * Written only by SupplierCriticalityService, in the same transaction as the change to the supplier.
 * Has no access rules of its own — reach it only through a supplier from
 * SupplierAccessService::visibleSuppliers().
 */
class SupplierCriticalityChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'from_criticality',
        'to_criticality',
        'from_review_interval_months',
        'to_review_interval_months',
        'from_processes_personal_data',
        'to_processes_personal_data',
        'from_has_system_access',
        'to_has_system_access',
        'from_supports_critical_delivery',
        'to_supports_critical_delivery',
        'from_hard_to_replace',
        'to_hard_to_replace',
        'reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        $casts = [
            'from_review_interval_months' => 'integer',
            'to_review_interval_months' => 'integer',
            'changed_at' => 'datetime',
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $casts["from_{$question}"] = 'boolean';
            $casts["to_{$question}"] = 'boolean';
        }

        return $casts;
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A supplier criticality change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A supplier criticality change is history and cannot be deleted.');
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
