<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One human decision about whether one control requirement applies to one supplier
 * (docs/supplier-assurance-v2-plan.md §5.5): include it although the rule does not, exclude it
 * although the rule does, or clear — back to the rule. Never a copy of the requirement profile.
 *
 * The override in force is the latest row for (supplier, requirement) by created_at, then id.
 * Immutable: a decision is undone by a new row (clear), never by changing or deleting this one. The
 * database refuses changes and deletes too (see the migration's trigger).
 *
 * The requirement's title and level are kept as they were, so the history reads the same after the
 * requirement is renamed or retired.
 *
 * Written only by SupplierRequirementOverrideService.
 */
class SupplierRequirementOverride extends Model
{
    public const ACTION_INCLUDE = 'include';

    public const ACTION_EXCLUDE = 'exclude';

    public const ACTION_CLEAR = 'clear';

    public const ACTIONS = [self::ACTION_INCLUDE, self::ACTION_EXCLUDE, self::ACTION_CLEAR];

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'supplier_id',
        'requirement_id',
        'action',
        'reason',
        'requirement_title',
        'requirement_level',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A requirement override is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A requirement override is history and cannot be deleted.');
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SupplierControlRequirement::class, 'requirement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
