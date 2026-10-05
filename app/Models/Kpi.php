<?php

namespace App\Models;

use App\Services\Objectives\KpiTarget;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A KPI — how progress towards one objective is measured.
 *
 * A KPI belongs to exactly one objective and has no fagområde of its own: who can reach it is
 * decided by its objective's area, through ObjectiveAccessService. Never query this model for a user
 * without going through ObjectiveAccessService::visibleKpis() or findVisibleKpi().
 *
 * owner_user_id is optional and never a copy of the objective's owner. When it is empty the
 * objective's owner answers for the KPI (responsible()); when the KPI owner is deleted the column is
 * nulled and the same fallback applies.
 *
 * Status is not a form field. Every KPI starts active and changes only through KpiLifecycleService
 * (Avslutt / Gjenåpne), which writes each change to statusChanges().
 *
 * The rules a KPI must keep — a target with at least one bound, min <= max, tolerance >= 0, a
 * currency code exactly for currency, a unit label only for counts and numbers, grace days >= 0 and
 * an objective of the same customer — are checked here before the row is written, and again by
 * CHECK constraints in the database.
 */
class Kpi extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETIRED];

    public const UNIT_PERCENT = 'percent';

    public const UNIT_COUNT = 'count';

    public const UNIT_NUMBER = 'number';

    public const UNIT_CURRENCY = 'currency';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    public const UNITS = [
        self::UNIT_PERCENT,
        self::UNIT_COUNT,
        self::UNIT_NUMBER,
        self::UNIT_CURRENCY,
        self::UNIT_HOURS,
        self::UNIT_DAYS,
    ];

    /** The units that take a free unit label («saker», «hendelser»). */
    public const LABELLED_UNITS = [self::UNIT_COUNT, self::UNIT_NUMBER];

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_YEARLY = 'yearly';

    /** A KPI may also have no fixed frequency (null). */
    public const FREQUENCIES = [
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_YEARLY,
    ];

    public const DEFAULT_REPORTING_GRACE_DAYS = 7;

    public const DEFAULT_CURRENCY_CODE = 'NOK';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'reporting_grace_days' => self::DEFAULT_REPORTING_GRACE_DAYS,
    ];

    protected $fillable = [
        'customer_id',
        'objective_id',
        'title',
        'description',
        'owner_user_id',
        'unit',
        'unit_label',
        'currency_code',
        'target_min',
        'target_max',
        'tolerance',
        'frequency',
        'reporting_grace_days',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // Exact decimals as strings; KpiTarget reads them without ever passing through a float.
            'target_min' => 'decimal:4',
            'target_max' => 'decimal:4',
            'tolerance' => 'decimal:4',
            'reporting_grace_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $kpi): void {
            $kpi->guardInvariants();
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the KPI may be deleted at all, before any permission is considered. Deleting is for a
     * KPI registered by mistake. When measurements exist, this is where a KPI with measurement
     * history is refused — it is retired instead.
     */
    public function isDeletable(): bool
    {
        return true;
    }

    public function target(): KpiTarget
    {
        return KpiTarget::of($this->target_min, $this->target_max, $this->tolerance);
    }

    /**
     * Who answers for the KPI: its own owner, or else the objective's owner. Presentation only —
     * the objective's owner is never written into the KPI.
     */
    public function responsible(): ?User
    {
        return $this->owner ?? $this->objective?->owner;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Every retirement and reopening, newest first. */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(KpiStatusChange::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    private function guardInvariants(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new DomainException("Unknown KPI status [{$this->status}].");
        }

        if (! in_array($this->unit, self::UNITS, true)) {
            throw new DomainException("Unknown KPI unit [{$this->unit}].");
        }

        if ($this->frequency !== null && ! in_array($this->frequency, self::FREQUENCIES, true)) {
            throw new DomainException("Unknown KPI frequency [{$this->frequency}].");
        }

        if (! is_int($this->reporting_grace_days) || $this->reporting_grace_days < 0) {
            throw new DomainException('Reporting grace days must be zero or more.');
        }

        if (($this->unit === self::UNIT_CURRENCY) !== ($this->currency_code !== null)) {
            throw new DomainException('A currency code belongs to a currency KPI, and a currency KPI needs one.');
        }

        if ($this->currency_code !== null && preg_match('/^[A-Z]{3}$/', $this->currency_code) !== 1) {
            throw new DomainException("Not a currency code [{$this->currency_code}].");
        }

        if ($this->unit_label !== null && ! in_array($this->unit, self::LABELLED_UNITS, true)) {
            throw new DomainException('Only a count or number KPI takes a unit label.');
        }

        try {
            $this->target();
        } catch (InvalidArgumentException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }

        // The customer boundary, explicitly: a KPI lives under an objective of its own customer.
        if ($this->exists && ! $this->isDirty(['objective_id', 'customer_id'])) {
            return;
        }

        $objectiveCustomerId = Objective::query()->whereKey($this->objective_id)->value('customer_id');

        if ($objectiveCustomerId === null || (int) $objectiveCustomerId !== (int) $this->customer_id) {
            throw new DomainException('A KPI belongs to an objective of its own customer.');
        }
    }
}
