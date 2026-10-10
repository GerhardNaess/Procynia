<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En sak i Avvik og forbedringer: an avvik («noe er ikke som ønsket») or a forbedring («noe kan bli
 * bedre»), in one fagområde, with one person responsible.
 *
 * ONE MODEL FOR BOTH. The two types differ in what the person is asked when registering, not in
 * what happens afterwards: ansvar, behandling, lukking and historikk are the same workflow. So the
 * type is a column, and access, lifecycle and pages exist once.
 *
 * The fagområde decides who can reach the case. Never query this model for a user without going
 * through ImprovementCaseAccessService::visibleCases().
 *
 * Status is not a form field and not mass assignable: every case starts open, and the status moves
 * only through ImprovementCaseLifecycleService (Start behandling, Lukk, Avbryt, Gjenåpne). status
 * and closed_* are the current state; how it got there is in statusChanges(), which nothing edits.
 */
class ImprovementCase extends Model
{
    public const TYPE_DEVIATION = 'deviation';

    public const TYPE_IMPROVEMENT = 'improvement';

    public const TYPES = [
        self::TYPE_DEVIATION,
        self::TYPE_IMPROVEMENT,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /** The states a case is still being worked in: it can be edited, closed or cancelled. */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
    ];

    /** The states that end a case: it can only be reopened. */
    public const ENDED_STATUSES = [
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $fillable = [
        'customer_id',
        'business_area_id',
        'type',
        'title',
        'description',
        'cause_analysis',
        'owner_user_id',
        'reported_by_user_id',
        'occurred_at',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these too; this says so before the query is sent.
        static::saving(function (self $case): void {
            if (! in_array($case->type, self::TYPES, true)) {
                throw new DomainException("Unknown improvement case type [{$case->type}].");
            }

            if (! in_array($case->status, self::STATUSES, true)) {
                throw new DomainException("Unknown improvement case status [{$case->status}].");
            }
        });
    }

    public function isDeviation(): bool
    {
        return $this->type === self::TYPE_DEVIATION;
    }

    /** Open or in progress: still being worked, so it can be edited, closed or cancelled. */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Whether the case may be deleted at all, before any permission is considered. Deleting is for
     * a case registered by mistake that nobody has started on: still open, no status change ever
     * written, and no tiltak. Once it has been in progress, closed or cancelled — even if it was
     * reopened since — or has tiltak, or came from a revisjonsfunn, it is cancelled instead.
     */
    public function isDeletable(): bool
    {
        if (! $this->exists) {
            return true;
        }

        return $this->status === self::STATUS_OPEN
            && ! ImprovementCaseStatusChange::query()->where('improvement_case_id', $this->id)->exists()
            && ! ImprovementAction::query()->where('improvement_case_id', $this->id)->exists()
            // Created from a revisjonsfunn: the finding points here, so the case is cancelled, never
            // deleted. The database refuses it as well.
            && ! ComplianceAuditFinding::query()->where('improvement_case_id', $this->id)->exists()
            // A tiltak from Ledelsens gjennomgåelse is followed up here; the decision points at the case.
            && ! ManagementReviewDecision::query()->where('improvement_case_id', $this->id)->exists();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The case's fagområde — the scope that decides who can reach it. */
    public function businessArea(): BelongsTo
    {
        return $this->belongsTo(BusinessArea::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** Tiltak, in the order they were added. */
    public function actions(): HasMany
    {
        return $this->hasMany(ImprovementAction::class)->orderBy('id');
    }

    /** Every status change, newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ImprovementCaseStatusChange::class)->orderByDesc('changed_at')->orderByDesc('id');
    }
}
