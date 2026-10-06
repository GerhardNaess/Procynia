<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En revisjon i Etterlevelse og revisjon: a planned review of whether the virksomhet complies —
 * internal or external — with a responsible person, a planned period, an authoritative scope
 * description and, once it is done, a conclusion.
 *
 * Status is not a form field and not mass assignable: every audit starts planned and moves only
 * through ComplianceAuditLifecycleService (Start, Fullfør, Avbryt, Gjenåpne). How it got there is
 * in statusChanges(), which nothing edits.
 *
 * What may still be changed depends on the status — see editableFields(). A completed audit is
 * locked until it is reopened; a cancelled one is read-only for good.
 *
 * Customer-wide. Never query this model for a user without going through
 * ComplianceAccessService::visibleAudits().
 */
class ComplianceAudit extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    public const TYPES = [
        self::TYPE_INTERNAL,
        self::TYPE_EXTERNAL,
    ];

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    protected $fillable = [
        'customer_id',
        'title',
        'audit_type',
        'responsible_user_id',
        'auditor_name',
        'planned_start_date',
        'planned_end_date',
        'scope_description',
        'conclusion',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these too; this says so before the query is sent.
        static::saving(function (self $audit): void {
            if (! in_array($audit->status, self::STATUSES, true)) {
                throw new DomainException("Unknown compliance audit status [{$audit->status}].");
            }

            if (! in_array($audit->audit_type, self::TYPES, true)) {
                throw new DomainException("Unknown compliance audit type [{$audit->audit_type}].");
            }
        });
    }

    /**
     * The fields Rediger may change in the audit's current status, and nothing else.
     *
     *  - planned: everything that describes the audit; there is no conclusion before it has started.
     *  - in_progress: the same, except the type — internal or external is what was started — and
     *    now the conclusion as well.
     *  - completed and cancelled: nothing. A completed audit is reopened first.
     *
     * @return list<string>
     */
    public function editableFields(): array
    {
        return match ($this->status) {
            self::STATUS_PLANNED => ['title', 'audit_type', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description'],
            self::STATUS_IN_PROGRESS => ['title', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description', 'conclusion'],
            default => [],
        };
    }

    public function isEditable(): bool
    {
        return $this->editableFields() !== [];
    }

    /** The requirements and processes in scope change while the audit is planned or under way. */
    public function canChangeScope(): bool
    {
        return in_array($this->status, [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS], true);
    }

    /**
     * Whether the audit may be deleted at all, before any permission is considered: one registered by
     * mistake, still planned and never moved. Once it has a status history it is cancelled instead;
     * the database refuses the delete as well.
     */
    public function isDeletable(): bool
    {
        return $this->status === self::STATUS_PLANNED && ! $this->statusChanges()->exists();
    }

    /** Findings are recorded, changed and deleted while the audit is under way, and only then. */
    public function canRecordFindings(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * A finding can be handed off to Avvik og forbedringer while the audit is under way and after it
     * is completed — following up is often what happens once the audit is over. Never from a
     * cancelled audit, which is read-only for good.
     */
    public function canHandOffFindings(): bool
    {
        return in_array($this->status, [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function requirementLinks(): HasMany
    {
        return $this->hasMany(ComplianceAuditRequirement::class, 'audit_id');
    }

    public function processLinks(): HasMany
    {
        return $this->hasMany(ComplianceAuditProcess::class, 'audit_id');
    }

    /** In the order they were recorded. */
    public function findings(): HasMany
    {
        return $this->hasMany(ComplianceAuditFinding::class, 'audit_id')->orderBy('id');
    }

    /** Newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ComplianceAuditStatusChange::class, 'audit_id')
            ->orderByDesc('changed_at')
            ->orderByDesc('id');
    }
}
