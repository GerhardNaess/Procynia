<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avslutt KPI and Gjenåpne — the only way a KPI's status changes. The same arrangement as
 * ObjectiveLifecycleService: kpis.status is the current state, KpiStatusChange rows are the history,
 * and both are written in one transaction with the KPI row locked and its status checked again
 * inside the lock.
 *
 * Why a history and not just a status: retiring a KPI is the decision to stop measuring it. Once
 * measurements exist, whether a period's measurement is missing depends on whether the KPI was
 * active then — which only the history can tell after a reopening has overwritten the status.
 *
 * Authorization (objective.edit in the objective's area, objective active) is the caller's.
 */
class KpiLifecycleService
{
    public function retire(Kpi $kpi, User $actor, ?string $note): Kpi
    {
        $note = $this->normalized($note);

        return DB::transaction(function () use ($kpi, $actor, $note): Kpi {
            $locked = $this->lock($kpi);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'note' => __('procynia.objectives.kpi.validation.already_retired'),
                ]);
            }

            $this->record($locked, $actor, Kpi::STATUS_RETIRED, $note);

            return $locked;
        });
    }

    public function reopen(Kpi $kpi, User $actor, string $reason): Kpi
    {
        $reason = $this->normalized($reason);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('procynia.objectives.kpi.validation.reopen_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($kpi, $actor, $reason): Kpi {
            $locked = $this->lock($kpi);

            if ($locked->isActive()) {
                throw ValidationException::withMessages([
                    'reason' => __('procynia.objectives.kpi.validation.already_active'),
                ]);
            }

            $this->record($locked, $actor, Kpi::STATUS_ACTIVE, $reason);

            return $locked;
        });
    }

    private function record(Kpi $locked, User $actor, string $toStatus, ?string $note): void
    {
        KpiStatusChange::query()->create([
            'customer_id' => (int) $locked->customer_id,
            'kpi_id' => (int) $locked->id,
            'from_status' => $locked->status,
            'to_status' => $toStatus,
            'note' => $note,
            'changed_by_user_id' => (int) $actor->id,
            'changed_at' => now(),
        ]);

        $locked->forceFill([
            'status' => $toStatus,
            'updated_by' => (int) $actor->id,
        ])->save();
    }

    private function lock(Kpi $kpi): Kpi
    {
        return Kpi::query()->whereKey($kpi->id)->lockForUpdate()->firstOrFail();
    }

    private function normalized(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
