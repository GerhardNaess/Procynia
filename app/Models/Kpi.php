<?php

namespace App\Models;

use App\Services\Objectives\KpiGrowth;
use App\Services\Objectives\KpiTarget;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

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
 * Once a measurement exists the KPI cannot be deleted (it is retired instead) and its unit,
 * unit label and currency code are fixed (UNIT_DEFINITION) — except between the two percent units.
 *
 * A KPI may carry a startverdi (baseline_value on baseline_date): its målverdi was then set as change
 * from that level (KpiGrowth), and the computed målverdi is its one bound.
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

    /** A share, 0–100 % (KpiUnitRules). */
    public const UNIT_PERCENT = 'percent';

    /** Change in per cent: may be negative or above 100. */
    public const UNIT_PERCENT_CHANGE = 'percent_change';

    public const UNIT_COUNT = 'count';

    public const UNIT_NUMBER = 'number';

    public const UNIT_CURRENCY = 'currency';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    public const UNITS = [
        self::UNIT_PERCENT,
        self::UNIT_PERCENT_CHANGE,
        self::UNIT_COUNT,
        self::UNIT_NUMBER,
        self::UNIT_CURRENCY,
        self::UNIT_HOURS,
        self::UNIT_DAYS,
    ];

    /**
     * Both written in per cent. A KPI with measurements may still move between them: the numbers
     * keep their meaning, and a share registered before the distinction existed can be corrected
     * to change (or back) without losing its history.
     */
    public const PERCENT_UNITS = [self::UNIT_PERCENT, self::UNIT_PERCENT_CHANGE];

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

    /**
     * What a measured number means. Locked once a measurement exists, or the history would change
     * meaning: 98 would read as a different thing. The target and tolerance stay editable — each
     * measurement keeps a snapshot of them.
     */
    public const UNIT_DEFINITION = ['unit', 'unit_label', 'currency_code'];

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
        'baseline_value',
        'baseline_date',
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
            'baseline_value' => 'decimal:4',
            'baseline_date' => 'date:Y-m-d',
            'reporting_grace_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $kpi): void {
            $kpi->guardInvariants();
        });

        // The database refuses the same (kpis_keep_measurement_history), also for SQL past the model.
        static::deleting(function (self $kpi): void {
            if (! $kpi->isDeletable()) {
                throw new LogicException('A KPI with measurements or a status change is history and cannot be deleted. Retire it instead.');
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the KPI may be deleted at all, before any permission is considered. Deleting is for a
     * KPI registered by mistake. Once it carries history it is retired instead, never deleted (A40):
     *  - a measurement, a withdrawn one included — it is still history;
     *  - a status change: it has been retired (and perhaps reopened) — a decision (A39).
     * Creating and editing a KPI leaves no such history; an unused KPI is deleted as before. The
     * database refuses the same (kpis_keep_measurement_history).
     */
    public function isDeletable(): bool
    {
        return ! $this->hasMeasurementHistory()
            && ! ($this->exists && KpiStatusChange::query()->where('kpi_id', $this->id)->exists());
    }

    /**
     * Whether any measurement was ever registered, withdrawn or not. From then on the KPI cannot be
     * deleted and what its numbers mean (UNIT_DEFINITION) cannot change.
     */
    public function hasMeasurementHistory(): bool
    {
        return $this->exists && KpiMeasurement::query()->where('kpi_id', $this->id)->exists();
    }

    public function target(): KpiTarget
    {
        return KpiTarget::of($this->target_min, $this->target_max, $this->tolerance);
    }

    /** Whether the målverdi was set as change from a startverdi (KpiGrowth). */
    public function hasBaseline(): bool
    {
        return $this->baseline_value !== null;
    }

    /**
     * Whether the unit definition would change. Moving between the two percent units is not a
     * change of what the numbers mean (PERCENT_UNITS).
     */
    public function unitDefinitionChanges(array $attributes): bool
    {
        foreach (self::UNIT_DEFINITION as $field) {
            $old = $this->getOriginal($field);
            $new = $attributes[$field] ?? null;

            if ($field === 'unit' && in_array($old, self::PERCENT_UNITS, true) && in_array($new, self::PERCENT_UNITS, true)) {
                continue;
            }

            if ($old !== $new) {
                return true;
            }
        }

        return false;
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

    /** Every registered measurement, corrections and withdrawals included. */
    public function measurements(): HasMany
    {
        return $this->hasMany(KpiMeasurement::class);
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

        if (($this->baseline_value === null) !== ($this->baseline_date === null)) {
            throw new DomainException('A startverdi needs its date, and a date its startverdi.');
        }

        if ($this->hasBaseline()) {
            if (! in_array($this->unit, KpiGrowth::UNITS, true)) {
                throw new DomainException("A KPI in [{$this->unit}] takes no startverdi.");
            }

            if (! KpiTarget::decimal($this->baseline_value)->isPositive()) {
                throw new DomainException('A startverdi must be above zero.');
            }

            if ($this->target()->type() === KpiTarget::TYPE_RANGE || $this->target()->type() === KpiTarget::TYPE_EXACT) {
                throw new DomainException('A målverdi set from a startverdi has exactly one bound.');
            }
        }

        if ($this->exists
            && $this->isDirty(self::UNIT_DEFINITION)
            && $this->unitDefinitionChanges($this->only(self::UNIT_DEFINITION))
            && $this->hasMeasurementHistory()) {
            throw new DomainException('The unit of a KPI with measurements cannot change.');
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
