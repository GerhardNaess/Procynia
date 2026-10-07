<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sett som utgått and Gjenåpne — the only way a requirement's status changes.
 *
 *   active → retired   retire()   begrunnelse required
 *   retired → active   reopen()   begrunnelse required
 *
 * Each writes the requirement's status and one immutable ComplianceRequirementStatusChange in one
 * transaction, with the requirement row locked and its status checked again inside the lock, so a
 * double submit or two people at once cannot write the same change twice.
 *
 * Authorization is the caller's (compliance.edit through ComplianceAccessService). This only
 * guards the transition itself.
 */
class ComplianceRequirementLifecycleService
{
    public function retire(ComplianceRequirement $requirement, User $actor, ?string $reason): ComplianceRequirement
    {
        return $this->transition($requirement, $actor, ComplianceRequirement::STATUS_ACTIVE, ComplianceRequirement::STATUS_RETIRED, $reason);
    }

    public function reopen(ComplianceRequirement $requirement, User $actor, ?string $reason): ComplianceRequirement
    {
        return $this->transition($requirement, $actor, ComplianceRequirement::STATUS_RETIRED, ComplianceRequirement::STATUS_ACTIVE, $reason);
    }

    private function transition(ComplianceRequirement $requirement, User $actor, string $from, string $to, ?string $reason): ComplianceRequirement
    {
        $note = trim((string) $reason);

        if ($note === '') {
            throw ValidationException::withMessages([
                'reason' => __('procynia.compliance.validation.reason_required'),
            ]);
        }

        return DB::transaction(function () use ($requirement, $actor, $from, $to, $note): ComplianceRequirement {
            $locked = ComplianceRequirement::query()->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw ValidationException::withMessages([
                    'reason' => __('procynia.compliance.validation.transition_not_allowed'),
                ]);
            }

            ComplianceRequirementStatusChange::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'requirement_id' => (int) $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'changed_by_user_id' => (int) $actor->id,
                'changed_at' => now(),
            ]);

            $locked->forceFill([
                'status' => $to,
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }
}
