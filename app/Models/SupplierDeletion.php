<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Slettede leverandører: that a supplier was deleted, by whom and when, with its id and name as they
 * were. supplier_id points at nothing any more, by design — the row outlives the supplier. Immutable;
 * the database refuses changes and deletes too (see the migration's trigger).
 *
 * Written only by SupplierLifecycleService::delete(), in the same transaction as the delete. Read
 * only through SupplierAccessService::deletionLog() — the customer's own, with supplier.delete.
 */
class SupplierDeletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'supplier_name',
        'deleted_by_user_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A supplier deletion is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A supplier deletion is history and cannot be deleted.');
        });
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
