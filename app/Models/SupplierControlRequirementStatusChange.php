<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One status change of a control requirement, as it was made — Sett som utgått or Ta i bruk igjen —
 * for a catalogue requirement or one for a single supplier. Immutable: a mistaken retirement is
 * undone by Ta i bruk igjen, which is itself a new row. The database refuses changes and deletes too
 * (see the migration's trigger).
 *
 * Written only by SupplierControlRequirementService, in the same transaction as the change to the
 * requirement's status. Has no access rules of its own — reach it only through a requirement the
 * person may read (SupplierRequirementPayload).
 */
class SupplierControlRequirementStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'requirement_id',
        'supplier_id',
        'from_status',
        'to_status',
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
            throw new LogicException('A control requirement status change is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A control requirement status change is history and cannot be deleted.');
        });
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SupplierControlRequirement::class, 'requirement_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
