<?php

namespace App\Models;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et tiltak on an avvik or a forbedring: what is to be done, by whom (Ansvarlig) and by when
 * (Frist), and — once done — what was actually done («Hva ble gjort?»), by whom and when.
 *
 * A tiltak has no fagområde of its own: it is always the case's. Never reach one except through a
 * case from ImprovementCaseAccessService::visibleCases().
 *
 * Status is not a form field and not mass assignable: every tiltak starts planned and moves only
 * through ImprovementActionLifecycleService (Start, Fullfør, Avbryt, Gjenåpne). status and
 * completed_* are the current state; how it got there is in statusChanges(), which nothing edits.
 */
class ImprovementAction extends Model
{
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

    /** Still to be done: it can be edited, completed or cancelled, and it keeps a case from closing. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
    ];

    /** Done with, one way or the other: it can only be reopened. */
    public const ENDED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    protected $fillable = [
        'customer_id',
        'improvement_case_id',
        'title',
        'description',
        'owner_user_id',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these too; this says so before the query is sent.
        static::saving(function (self $action): void {
            if (! in_array($action->status, self::STATUSES, true)) {
                throw new DomainException("Unknown improvement action status [{$action->status}].");
            }

            if ($action->isDirty(['improvement_case_id', 'customer_id'])) {
                $caseCustomerId = ImprovementCase::query()->whereKey($action->improvement_case_id)->value('customer_id');

                if ($caseCustomerId === null || (int) $caseCustomerId !== (int) $action->customer_id) {
                    throw new DomainException('A tiltak belongs to a case of its own customer.');
                }
            }
        });
    }

    /** Planned or under arbeid: still to be done. */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Frist passert: still to be done and the frist was before today. The frist day itself is not
     * passed. Computed, never stored.
     */
    public function isOverdue(?CarbonInterface $today = null): bool
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return $this->isActive() && $this->due_date !== null && $this->due_date->lt($today);
    }

    /**
     * Whether the tiltak may be deleted at all, before any permission is considered: one added by
     * mistake that nobody has touched. Still planned, and no status change ever written — once it
     * has been started, completed, cancelled or reopened, it carries history and is cancelled
     * instead.
     */
    public function isDeletable(): bool
    {
        if (! $this->exists) {
            return true;
        }

        return $this->status === self::STATUS_PLANNED
            && ! ImprovementActionStatusChange::query()->where('improvement_action_id', $this->id)->exists();
    }

    public function improvementCase(): BelongsTo
    {
        return $this->belongsTo(ImprovementCase::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** Every status change, newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ImprovementActionStatusChange::class)->orderByDesc('changed_at')->orderByDesc('id');
    }
}
