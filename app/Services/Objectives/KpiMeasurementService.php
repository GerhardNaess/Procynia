<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Registrer måling and Trekk tilbake — the only ways a measurement comes into being or stops
 * counting.
 *
 * REGISTERING. The period is chosen, never typed: a calendar period by its key for a KPI with a
 * frequency, one day for a KPI without. Either way it must have ended — a running week, month,
 * quarter or year has no result yet, and a day in the future has not happened. (The current
 * period is also never «due» in KpiPeriods; registering follows the same rule.) The KPI's målverdi
 * is copied onto the row; a form never sends it.
 *
 * A period that already has a current measurement is corrected, not overwritten: a new row, which
 * must say why (comment), becomes the current one; the old row stays as Erstattet.
 *
 * WITHDRAWING. Once, with a reason. The row stays in the history as Tilbaketrukket and stops
 * counting; the previous measurement of the period, if any, counts again.
 *
 * Both need the KPI and its objective active, checked again with the KPI row locked, so two people
 * registering the same period at once are serialized and the second one is seen as a correction.
 * Access (objective.measure in the objective's area) is the caller's.
 */
class KpiMeasurementService
{
    public function __construct(
        private readonly KpiPeriods $periods,
        private readonly KpiMeasurementResolver $resolver,
    ) {}

    /**
     * The period a person chose: a period key for a KPI with a frequency, a date (Y-m-d) for one
     * without. Refuses anything else — a malformed key, a period that has not ended, a future day.
     *
     * @throws ValidationException
     */
    public function periodFor(Kpi $kpi, ?string $periodKey, ?string $date, CarbonInterface $today): KpiPeriod
    {
        $today = CarbonImmutable::instance($today)->startOfDay();

        if ($kpi->frequency !== null) {
            try {
                $period = $this->periods->fromKey($kpi->frequency, trim((string) $periodKey));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['period' => __('procynia.objectives.measurement.validation.period_required')]);
            }

            if (! $this->periods->hasEnded($period, $today)) {
                throw ValidationException::withMessages(['period' => __('procynia.objectives.measurement.validation.period_not_ended')]);
            }

            return $period;
        }

        $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date)
            : false;

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['measured_on' => __('procynia.objectives.measurement.validation.date_required')]);
        }

        if ($day->greaterThan($today)) {
            throw ValidationException::withMessages(['measured_on' => __('procynia.objectives.measurement.validation.date_in_future')]);
        }

        return $this->periods->day($day);
    }

    /**
     * Registers a value for the period. A correction when the period already has a current
     * measurement — then the comment is required.
     *
     * @throws ValidationException
     */
    public function record(Kpi $kpi, User $actor, KpiPeriod $period, string $value, ?string $comment): KpiMeasurement
    {
        $comment = $this->normalized($comment);

        return DB::transaction(function () use ($kpi, $actor, $period, $value, $comment): KpiMeasurement {
            $locked = $this->lockMeasurable($kpi, 'value');

            $previous = $locked->measurements()
                ->where('period_start', $period->start->format('Y-m-d'))
                ->where('period_end', $period->end->format('Y-m-d'))
                ->get();

            if ($this->resolver->currentByPeriod($previous) !== [] && $comment === null) {
                throw ValidationException::withMessages([
                    'comment' => __('procynia.objectives.measurement.validation.correction_comment_required'),
                ]);
            }

            return KpiMeasurement::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'kpi_id' => (int) $locked->id,
                'period_start' => $period->start->format('Y-m-d'),
                'period_end' => $period->end->format('Y-m-d'),
                'value' => $value,
                'comment' => $comment,
                // The målverdi as it is now, so the history can say what it was.
                'target_min' => $locked->target_min,
                'target_max' => $locked->target_max,
                'tolerance' => $locked->tolerance,
                'recorded_by_user_id' => (int) $actor->id,
                'recorded_at' => now(),
            ]);
        });
    }

    /**
     * Withdraws a measurement: once, with a reason. It stays in the history and stops counting.
     *
     * @throws ValidationException
     */
    public function withdraw(KpiMeasurement $measurement, User $actor, ?string $reason): KpiMeasurement
    {
        $reason = $this->normalized($reason);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('procynia.objectives.measurement.validation.withdrawal_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($measurement, $actor, $reason): KpiMeasurement {
            $this->lockMeasurable($measurement->kpi, 'reason');
            $locked = KpiMeasurement::query()->whereKey($measurement->id)->lockForUpdate()->firstOrFail();

            if ($locked->isWithdrawn()) {
                throw ValidationException::withMessages([
                    'reason' => __('procynia.objectives.measurement.validation.already_withdrawn'),
                ]);
            }

            $locked->withdraw($actor, $reason);

            return $locked;
        });
    }

    /** The KPI, locked, when it and its objective still take measurements. */
    private function lockMeasurable(Kpi $kpi, string $errorKey): Kpi
    {
        $locked = Kpi::query()->whereKey($kpi->id)->lockForUpdate()->firstOrFail();
        $objectiveActive = Objective::query()->whereKey($locked->objective_id)->value('status') === Objective::STATUS_ACTIVE;

        if (! $objectiveActive || ! $locked->isActive()) {
            throw ValidationException::withMessages([
                $errorKey => __('procynia.objectives.measurement.validation.not_measurable'),
            ]);
        }

        return $locked;
    }

    private function normalized(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
