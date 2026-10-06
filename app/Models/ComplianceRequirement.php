<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et krav i Etterlevelse og revisjon: one requirement from one kravkilde, with a reference, the
 * requirement text, one person responsible and how often it should be reassessed.
 *
 * What it does not carry, on purpose: whether it is met. Compliance is assessed in assessments(),
 * and the status and next review date derived from them are computed by ComplianceStatusResolver,
 * never stored here.
 *
 * Status is not a form field and not mass assignable: every requirement starts active, and moves
 * only through ComplianceRequirementLifecycleService (Sett som utgått, Gjenåpne). How it got there
 * is in statusChanges(), which nothing edits.
 *
 * Customer-wide. Never query this model for a user without going through
 * ComplianceAccessService::visibleRequirements().
 */
class ComplianceRequirement extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETIRED,
    ];

    /** Ingen fast intervall (null), Månedlig, Kvartalsvis, Halvårlig, Årlig. */
    public const REVIEW_INTERVALS = [1, 3, 6, 12];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'customer_id',
        'source_id',
        'reference',
        'title',
        'requirement_text',
        'owner_user_id',
        'review_interval_months',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'review_interval_months' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these too; this says so before the query is sent.
        static::saving(function (self $requirement): void {
            if (! in_array($requirement->status, self::STATUSES, true)) {
                throw new DomainException("Unknown compliance requirement status [{$requirement->status}].");
            }

            if ($requirement->review_interval_months !== null && ! in_array((int) $requirement->review_interval_months, self::REVIEW_INTERVALS, true)) {
                throw new DomainException("Unknown review interval [{$requirement->review_interval_months}].");
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the requirement may be deleted at all, before any permission is considered. Deleting
     * is for a requirement registered by mistake: still active, with no status change and no
     * assessment ever written. Once it has been retired or assessed it has a history, and is
     * handled through its lifecycle; the database refuses the delete as well.
     */
    public function isDeletable(): bool
    {
        return $this->isActive() && ! $this->statusChanges()->exists() && ! $this->assessments()->exists();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ComplianceSource::class, 'source_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Newest first: latest assessed_at, the highest id breaking a tie. */
    public function assessments(): HasMany
    {
        return $this->hasMany(ComplianceAssessment::class, 'requirement_id')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id');
    }

    /** Newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ComplianceRequirementStatusChange::class, 'requirement_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }
}
