<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et krav i Etterlevelse og revisjon: one requirement from one kravkilde, with a reference, the
 * requirement text, optionally one person responsible and how often it should be reassessed.
 *
 * What it does not carry, on purpose: whether it is met. Compliance is assessed in assessments(),
 * and the status and next review date derived from them are computed by ComplianceStatusResolver,
 * never stored here.
 *
 * Status is not a form field and not mass assignable: every requirement starts active, and moves
 * only through ComplianceRequirementLifecycleService (Sett som utgått, Gjenåpne). How it got there
 * is in statusChanges(), which nothing edits.
 *
 * A requirement with framework_node_id came from Kravbiblioteket: its reference, title and text
 * follow the library node and are not form fields. applicability is the customer's relevance
 * judgement (Gjelder / Ikke relevant / Ikke vurdert), changed only through
 * ComplianceApplicabilityService and never mass assignable; a requirement registered by hand simply
 * applies.
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

    public const APPLICABILITY_APPLIES = 'applies';

    public const APPLICABILITY_NOT_APPLICABLE = 'not_applicable';

    public const APPLICABILITY_NOT_ASSESSED = 'not_assessed';

    public const APPLICABILITIES = [
        self::APPLICABILITY_APPLIES,
        self::APPLICABILITY_NOT_APPLICABLE,
        self::APPLICABILITY_NOT_ASSESSED,
    ];

    /** Ingen fast intervall (null), Månedlig, Kvartalsvis, Halvårlig, Årlig. */
    public const REVIEW_INTERVALS = [1, 3, 6, 12];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'applicability' => self::APPLICABILITY_APPLIES,
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
            'applicability_set_at' => 'datetime',
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

            if (! in_array($requirement->applicability, self::APPLICABILITIES, true)) {
                throw new DomainException("Unknown applicability [{$requirement->applicability}].");
            }
        });
    }

    /**
     * The register's order, shared by Kravregister and Etterlevelsesvurdering: aktive first; then by
     * source, and within it in the standard's order (4.1 before 10.1) — a requirement registered by
     * hand has no position and falls back to its reference.
     */
    public function scopeInRegisterOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE compliance_requirements.status WHEN ? THEN 0 ELSE 1 END', [self::STATUS_ACTIVE])
            ->orderBy(ComplianceSource::query()->select('name')->whereColumn('compliance_sources.id', 'compliance_requirements.source_id'))
            ->orderBy('compliance_requirements.source_id')
            ->orderByRaw('(SELECT position FROM compliance_framework_nodes WHERE compliance_framework_nodes.id = compliance_requirements.framework_node_id) ASC NULLS LAST')
            ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isFromLibrary(): bool
    {
        return $this->framework_node_id !== null;
    }

    /** Ikke relevant: outside the register's work — never assessed, never in Trenger oppmerksomhet. */
    public function isNotApplicable(): bool
    {
        return $this->applicability === self::APPLICABILITY_NOT_APPLICABLE;
    }

    public function frameworkNode(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkNode::class, 'framework_node_id');
    }

    public function applicabilitySetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicability_set_by');
    }

    /**
     * Whether the requirement may be deleted at all, before any permission is considered. Deleting
     * is for a requirement registered by mistake: still active, with no status change, no
     * assessment ever written, in no audit's scope and named by no revisjonsfunn. Once it has been
     * retired, assessed or taken into an audit it has a history, and is handled through its
     * lifecycle; the database refuses the delete as well.
     */
    public function isDeletable(): bool
    {
        return $this->isActive()
            && ! $this->statusChanges()->exists()
            && ! $this->applicabilityChanges()->exists()
            && ! $this->assessments()->exists()
            && ! ComplianceAuditRequirement::query()->where('requirement_id', $this->id)->exists()
            && ! ComplianceAuditFinding::query()->where('requirement_id', $this->id)->exists();
    }

    /**
     * Where the requirement comes from, in the parts every page shows the same way —
     * «ISO 14001:2026 · 6.1.2 · Miljøaspekter». The source must be loaded by the caller.
     *
     * @return array{source: string|null, reference: string|null, title: string}
     */
    public function origin(): array
    {
        return [
            'source' => $this->source?->originLabel(),
            'reference' => $this->reference,
            'title' => (string) $this->title,
        ];
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
    /** Relevans and Kontrollvalg as they were decided (A35); nothing edits them. */
    public function applicabilityChanges(): HasMany
    {
        return $this->hasMany(ComplianceRequirementApplicabilityChange::class, 'requirement_id')->orderBy('changed_at')->orderBy('id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ComplianceRequirementStatusChange::class, 'requirement_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }
}
