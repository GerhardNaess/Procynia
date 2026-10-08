<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One save of a supplier's profile, as it was made: the whole profile before (null the first time)
 * and after, and the begrunnelse (required for every save after the first). Immutable: a wrong
 * answer is corrected by a new save, which is itself a new row. The database refuses changes and
 * deletes too (see the migration's trigger).
 *
 * Written only by SupplierProfileService, in the same transaction as the profile. Has no access
 * rules of its own — reach it only through a supplier from SupplierAccessService::visibleSuppliers().
 */
class SupplierProfileChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'from_profile',
        'to_profile',
        'reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_profile' => 'array',
            'to_profile' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A supplier profile change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A supplier profile change is history and cannot be deleted.');
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
