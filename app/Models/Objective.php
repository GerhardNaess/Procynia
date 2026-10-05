<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Et mål — what the virksomhet wants to achieve, in one fagområde, with one person responsible.
 *
 * The fagområde decides who can reach the objective. Never query this model for a user without
 * going through ObjectiveAccessService::visibleObjectives(): the area scope is what keeps an
 * objective outside someone's areas from being discoverable at all.
 *
 * Status is not a form field. Every objective starts active, and status is not mass assignable, so
 * neither Nytt mål nor Rediger can set it. It changes only through ObjectiveLifecycleService (Lukk
 * mål / Gjenåpne). status and closed_* are the current state; how it got there is in
 * statusChanges(), which a reopening never touches.
 */
class Objective extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACHIEVED = 'achieved';

    public const STATUS_NOT_ACHIEVED = 'not_achieved';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ACHIEVED,
        self::STATUS_NOT_ACHIEVED,
        self::STATUS_CANCELLED,
    ];

    /** The outcomes an objective can be closed with — each one an explicit decision. */
    public const CLOSED_STATUSES = [
        self::STATUS_ACHIEVED,
        self::STATUS_NOT_ACHIEVED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'customer_id',
        'business_area_id',
        'title',
        'description',
        'owner_user_id',
        'target_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses an unknown status too; this says so before the query is sent.
        static::saving(function (self $objective): void {
            if (! in_array($objective->status, self::STATUSES, true)) {
                throw new DomainException("Unknown objective status [{$objective->status}].");
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the objective may be deleted at all, before any permission is considered. Deleting is
     * for an objective registered by mistake. KPIs alone do not stop it; once any of its KPIs has a
     * measurement — withdrawn or not — the objective carries history and is closed instead.
     */
    public function isDeletable(): bool
    {
        return ! $this->exists || ! KpiMeasurement::query()
            ->whereIn('kpi_id', Kpi::query()->where('objective_id', $this->id)->select('id'))
            ->exists();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The objective's fagområde — the scope that decides who can reach it. */
    public function businessArea(): BelongsTo
    {
        return $this->belongsTo(BusinessArea::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** How progress towards the objective is measured. A KPI has no fagområde of its own. */
    public function kpis(): HasMany
    {
        return $this->hasMany(Kpi::class);
    }

    /** Every closing and reopening, newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ObjectiveStatusChange::class)->orderByDesc('changed_at')->orderByDesc('id');
    }
}
