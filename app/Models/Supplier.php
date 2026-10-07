<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En leverandør i Leverandøroppfølging: the supplier as a company or party — the master object
 * everything else in the module is attached to. Never one per contract, service or document; a
 * supplier that delivers several things describes them in deliverable_description.
 *
 * Status is not mass assignable. A supplier is registered as onboarding (Under vurdering) or
 * active (Aktiv), and from then on moves only through SupplierLifecycleService (Ta i bruk, Avslutt
 * leverandør, Gjenåpne leverandør). How it got there is in statusChanges(), which nothing edits.
 *
 * Customer-wide. Never query this model for a user without going through
 * SupplierAccessService::visibleSuppliers().
 */
class Supplier extends Model
{
    public const STATUS_ONBOARDING = 'onboarding';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [
        self::STATUS_ONBOARDING,
        self::STATUS_ACTIVE,
        self::STATUS_ENDED,
    ];

    /** The two a supplier may be registered with: «Vi vurderer leverandøren» or «allerede i bruk». */
    public const INITIAL_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ONBOARDING,
    ];

    /** The fixed category list (plan §4.1), translated in supplier_management.categories. */
    public const CATEGORIES = [
        'it_cloud',
        'consulting',
        'goods',
        'construction',
        'transport_logistics',
        'operations_facilities',
        'other',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ONBOARDING,
    ];

    protected $fillable = [
        'customer_id',
        'name',
        'organization_number',
        'category',
        'deliverable_description',
        'owner_user_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'note',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // The database refuses these too; this says so before the query is sent.
        static::saving(function (self $supplier): void {
            if (! in_array($supplier->status, self::STATUSES, true)) {
                throw new DomainException("Unknown supplier status [{$supplier->status}].");
            }

            if (! in_array($supplier->category, self::CATEGORIES, true)) {
                throw new DomainException("Unknown supplier category [{$supplier->category}].");
            }
        });
    }

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    /**
     * Whether the supplier may be deleted at all, before any permission is considered. Deleting is
     * for a supplier registered by mistake and never used: one that has changed status has a
     * history and is ended instead. The database refuses the delete as well (NO ACTION from every
     * child table).
     *
     * Each later part of the module that attaches something to a supplier — criticality changes,
     * assessments, documentation, links to risks, requirements and cases — adds its check here,
     * so the rule stays «completely unused» without changing.
     */
    public function isDeletable(): bool
    {
        return ! $this->statusChanges()->exists();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(SupplierStatusChange::class, 'supplier_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }
}
