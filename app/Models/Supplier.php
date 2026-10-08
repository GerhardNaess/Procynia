<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * En leverandør i Leverandøroppfølging: the supplier as a company or party — the master object
 * everything else in the module is attached to. Never one per contract, service or document; a
 * supplier that delivers several things describes them in deliverable_description.
 *
 * Status is not mass assignable. A supplier is registered as onboarding (Under vurdering) or
 * active (Aktiv), and from then on moves only through SupplierLifecycleService (Ta i bruk, Avslutt
 * leverandør, Gjenåpne leverandør). How it got there is in statusChanges(), which nothing edits.
 *
 * Criticality (Standard, Viktig, Kritisk), the review interval and the four ja/nei answers the
 * choice was made on are not mass assignable either. They are set when the supplier is registered
 * and from then on change only through SupplierCriticalityService, which writes every change to
 * criticalityChanges(). The values here are the current classification; there is no other.
 * A supplier registered before criticality existed has none of them until it is classified.
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

    public const CRITICALITY_STANDARD = 'standard';

    public const CRITICALITY_IMPORTANT = 'important';

    public const CRITICALITY_CRITICAL = 'critical';

    /** Chosen by the user, never computed (plan §4.2). */
    public const CRITICALITIES = [
        self::CRITICALITY_STANDARD,
        self::CRITICALITY_IMPORTANT,
        self::CRITICALITY_CRITICAL,
    ];

    /** The review intervals in months; Viktig and Kritisk require one, Standard may be without. */
    public const REVIEW_INTERVALS = [6, 12, 24, 36];

    /**
     * The four ja/nei questions shown as the basis for the choice, each its own boolean column:
     * personal data on our behalf, access to our systems or information, a critical delivery
     * stopping without them, hard to replace at short notice. Stored, never summed.
     */
    public const CRITICALITY_QUESTIONS = [
        'processes_personal_data',
        'has_system_access',
        'supports_critical_delivery',
        'hard_to_replace',
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

    protected function casts(): array
    {
        return [
            'review_interval_months' => 'integer',
            'processes_personal_data' => 'boolean',
            'has_system_access' => 'boolean',
            'supports_critical_delivery' => 'boolean',
            'hard_to_replace' => 'boolean',
        ];
    }

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

            if ($supplier->criticality !== null && ! in_array($supplier->criticality, self::CRITICALITIES, true)) {
                throw new DomainException("Unknown supplier criticality [{$supplier->criticality}].");
            }
        });
    }

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    public function isClassified(): bool
    {
        return $this->criticality !== null;
    }

    /**
     * The current classification as one value — level, interval and the four answers — or null when
     * the supplier has not been classified.
     *
     * @return array{criticality: string, review_interval_months: int|null, processes_personal_data: bool, has_system_access: bool, supports_critical_delivery: bool, hard_to_replace: bool}|null
     */
    public function classification(): ?array
    {
        if (! $this->isClassified()) {
            return null;
        }

        $classification = [
            'criticality' => $this->criticality,
            'review_interval_months' => $this->review_interval_months,
        ];

        foreach (self::CRITICALITY_QUESTIONS as $question) {
            $classification[$question] = (bool) $this->{$question};
        }

        return $classification;
    }

    /**
     * Whether the supplier may be deleted at all, before any permission is considered. Deleting is
     * for a supplier registered by mistake and never used: one that has changed status or
     * criticality, has been assessed, has documentation registered, has a leverandørprofil, concerns
     * a case in Avvik og forbedringer or a risk in Risiko, or has a requirement in Etterlevelse og
     * revisjon applying to it has a history — a real decision was made about it — and is ended instead. The
     * classification it was registered with is the supplier's own and does not count. The database
     * refuses the delete as well (NO ACTION from every child table).
     */
    public function isDeletable(): bool
    {
        return ! $this->statusChanges()->exists()
            && ! $this->criticalityChanges()->exists()
            && ! $this->assessments()->exists()
            && ! $this->documents()->exists()
            // Every profile save writes a history row, so the profile and its history go together.
            && ! $this->profile()->exists()
            && ! $this->profileChanges()->exists()
            // Leverandørkontroll (phase 2): a requirement for this supplier, and any override.
            && ! $this->controlRequirements()->exists()
            && ! $this->requirementOverrides()->exists()
            && ! $this->improvementCaseLinks()->exists()
            && ! $this->riskLinks()->exists()
            && ! $this->requirementLinks()->exists();
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

    /** Newest first. */
    public function criticalityChanges(): HasMany
    {
        return $this->hasMany(SupplierCriticalityChange::class, 'supplier_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }

    /** The leverandørprofil — absent until someone fills it in (no backfill). */
    public function profile(): HasOne
    {
        return $this->hasOne(SupplierProfile::class, 'supplier_id');
    }

    /** Newest first. */
    public function profileChanges(): HasMany
    {
        return $this->hasMany(SupplierProfileChange::class, 'supplier_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }

    /** Current first: the latest assessed_on, then the highest id (plan §4.3). */
    public function assessments(): HasMany
    {
        return $this->hasMany(SupplierAssessment::class, 'supplier_id')
            ->orderByDesc('assessed_on')
            ->orderByDesc('id');
    }

    /**
     * The dokumentasjonsoversikt: current rows before replaced ones, then by name. Every row,
     * replaced ones included — a renewal never removes the document it renewed.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class, 'supplier_id')
            ->orderByRaw('replaced_by_document_id IS NOT NULL')
            ->orderByRaw('lower(title)')
            ->orderBy('id');
    }

    /**
     * The cases in Avvik og forbedringer that concern the supplier — the rows only. Whether a case
     * may be shown is ImprovementCaseAccessService's to say.
     */
    public function improvementCaseLinks(): HasMany
    {
        return $this->hasMany(SupplierImprovementCase::class, 'supplier_id');
    }

    /**
     * The risks in Risiko that concern the supplier — ids only. Which of them a person may see is
     * RiskAccessService's to say.
     */
    public function riskLinks(): HasMany
    {
        return $this->hasMany(SupplierRisk::class, 'supplier_id');
    }

    /**
     * The requirements in Etterlevelse og revisjon that apply to the supplier — ids only. Which of
     * them a person may see is ComplianceAccessService's to say.
     */
    public function requirementLinks(): HasMany
    {
        return $this->hasMany(SupplierComplianceRequirement::class, 'supplier_id');
    }

    /** Kontrollkrav for this one supplier — never the catalogue, which is applied by rule. */
    public function controlRequirements(): HasMany
    {
        return $this->hasMany(SupplierControlRequirement::class, 'supplier_id');
    }

    /** Every manual override of the requirement profile, newest first. */
    public function requirementOverrides(): HasMany
    {
        return $this->hasMany(SupplierRequirementOverride::class, 'supplier_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
