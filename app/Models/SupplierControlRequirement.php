<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et kontrollkrav: one requirement the customer sets for suppliers, and how it is controlled
 * (docs/supplier-assurance-v2-plan.md §5.1). Mutable current state — the catalogue — with a
 * lifecycle of active and retired. A retired requirement applies to no one and is never
 * controlled again; it is deleted only while unused (no overrides, no controls).
 *
 * supplier_id null: a catalogue requirement, applied by applies_when (§5.3). supplier_id set: a
 * requirement for that one supplier, which always applies to it, has no rule and is never
 * overridden.
 *
 * Which suppliers it applies to is never stored: SupplierRequirementApplicability computes it.
 *
 * compliance_requirement_id is an optional anchor in Etterlevelse og revisjon. It is never read
 * without ComplianceAccessService, and nothing of that requirement is copied here; basis_text is
 * the supplier side's own, always-visible basis.
 *
 * Written only by SupplierControlRequirementService. Has no access rules of its own — the catalogue
 * is customer-wide and reached through SupplierAccessService.
 */
class SupplierControlRequirement extends Model
{
    public const THEMES = [
        'human_rights',
        'labour_conditions',
        'environment',
        'information_security',
        'privacy',
        'quality',
        'continuity',
        'ethics',
        'financial',
    ];

    public const LEVEL_MANDATORY = 'mandatory';

    public const LEVEL_IMPORTANT = 'important';

    public const LEVEL_STANDARD = 'standard';

    /** Obligatorisk · Viktig · Oppfølging, in the order the page sorts them. */
    public const LEVELS = [self::LEVEL_MANDATORY, self::LEVEL_IMPORTANT, self::LEVEL_STANDARD];

    public const CONTROL_POINTS = ['before_contract', 'ongoing', 'on_change'];

    public const CONTROL_INTERVALS = [3, 6, 12, 24, 36];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETIRED];

    protected $fillable = [
        'customer_id',
        'title',
        'description',
        'guidance',
        'theme',
        'level',
        'control_point',
        'control_interval_months',
        'applies_when',
        'accepted_document_types',
        'basis_text',
        'compliance_requirement_id',
        'supplier_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'applies_when' => '[]',
        'accepted_document_types' => '[]',
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'applies_when' => 'array',
            'accepted_document_types' => 'array',
            'control_interval_months' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isMandatory(): bool
    {
        return $this->level === self::LEVEL_MANDATORY;
    }

    /** A requirement for one supplier, not a catalogue requirement. */
    public function isSupplierSpecific(): bool
    {
        return $this->supplier_id !== null;
    }

    /** Unused: nothing refers to it, so it may be deleted rather than retired. */
    public function isDeletable(): bool
    {
        return ! $this->overrides()->exists() && ! $this->evaluations()->exists();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(SupplierRequirementOverride::class, 'requirement_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierRequirementEvaluation::class, 'requirement_id');
    }
}
