<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One status change of a supplier, as it was made — Ta i bruk, Avslutt leverandør or Gjenåpne
 * leverandør, the last two with their begrunnelse. Immutable: a mistaken ending is undone by a
 * reopening, which is itself a new row. The database refuses changes and deletes too (see the
 * migration's trigger).
 *
 * Written only by SupplierLifecycleService, in the same transaction as the change to the
 * supplier's status. Has no access rules of its own — reach it only through a supplier from
 * SupplierAccessService::visibleSuppliers().
 */
class SupplierStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
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
            throw new LogicException('A supplier status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A supplier status change is history and cannot be deleted.');
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
