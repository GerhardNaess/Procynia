<?php

namespace App\Models;

use App\Services\Objectives\KpiTarget;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * One registered result of a KPI for one period. History: never edited, never deleted on its own.
 *
 *  - A mistake in the value is corrected by registering a new measurement for the same period. The
 *    old row stays; the newest one that is not withdrawn counts (KpiMeasurementResolver).
 *  - A measurement that should not count at all is withdrawn — once, with a reason. That is the only
 *    change a row ever takes (withdraw()), and a withdrawn row is final.
 *
 * target_min, target_max and tolerance are the KPI's målverdi as it was when the value was
 * registered, copied by KpiMeasurementService — never taken from a form. snapshotTarget() is what
 * the history judges this row against; the KPI's own target() is what today's status uses.
 *
 * Written only by KpiMeasurementService. Has no access rules of its own — reach it only through a
 * KPI from ObjectiveAccessService.
 */
class KpiMeasurement extends Model
{
    public $timestamps = false;

    /** The only columns a withdrawal may set. */
    private const WITHDRAWAL_COLUMNS = ['withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason'];

    protected $fillable = [
        'customer_id',
        'kpi_id',
        'period_start',
        'period_end',
        'value',
        'comment',
        'target_min',
        'target_max',
        'tolerance',
        'recorded_by_user_id',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            // Exact decimals as strings, like the KPI's target; never a float.
            'value' => 'decimal:4',
            'target_min' => 'decimal:4',
            'target_max' => 'decimal:4',
            'tolerance' => 'decimal:4',
            'recorded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $measurement): void {
            $measurement->guardNew();
        });

        static::updating(function (self $measurement): void {
            $measurement->guardWithdrawal();
        });

        static::deleting(function (): void {
            throw new LogicException('A KPI measurement is history and cannot be deleted. Withdraw it instead.');
        });
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /** The målverdi as it was when this value was registered. */
    public function snapshotTarget(): KpiTarget
    {
        return KpiTarget::of($this->target_min, $this->target_max, $this->tolerance);
    }

    /** The identity of the period, shared by a measurement and its corrections. */
    public function periodKey(): string
    {
        return $this->period_start->format('Y-m-d').'|'.$this->period_end->format('Y-m-d');
    }

    /**
     * Marks the measurement as withdrawn. The caller (KpiMeasurementService) checks the reason and
     * the access; this only refuses what can never be allowed.
     */
    public function withdraw(User $actor, string $reason): void
    {
        $this->forceFill([
            'withdrawn_at' => now(),
            'withdrawn_by_user_id' => (int) $actor->id,
            'withdrawal_reason' => $reason,
        ])->save();
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }

    private function guardNew(): void
    {
        if ($this->withdrawn_at !== null || $this->withdrawn_by_user_id !== null || $this->withdrawal_reason !== null) {
            throw new DomainException('A KPI measurement is registered first and withdrawn afterwards.');
        }

        if ($this->period_start === null || $this->period_end === null || $this->period_start->greaterThan($this->period_end)) {
            throw new DomainException('A KPI measurement needs a period that ends on or after its start.');
        }

        try {
            $this->snapshotTarget();
            KpiTarget::decimal($this->value) ?? throw new InvalidArgumentException('A KPI measurement needs a value.');
        } catch (InvalidArgumentException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }

        // The customer boundary, explicitly: a measurement belongs to a KPI of its own customer.
        $kpiCustomerId = Kpi::query()->whereKey($this->kpi_id)->value('customer_id');

        if ($kpiCustomerId === null || (int) $kpiCustomerId !== (int) $this->customer_id) {
            throw new DomainException('A KPI measurement belongs to a KPI of its own customer.');
        }
    }

    /** The one change allowed: withdrawing a row that is not withdrawn, with a reason. */
    private function guardWithdrawal(): void
    {
        $changed = array_keys($this->getDirty());

        if ($this->getOriginal('withdrawn_at') !== null) {
            throw new LogicException('A withdrawn KPI measurement is final.');
        }

        if (array_diff($changed, self::WITHDRAWAL_COLUMNS) !== []) {
            throw new LogicException('A KPI measurement is history and cannot be changed. Register a correction instead.');
        }

        if ($this->withdrawn_at === null || trim((string) $this->withdrawal_reason) === '') {
            throw new LogicException('A withdrawal needs a time and a reason.');
        }
    }
}
