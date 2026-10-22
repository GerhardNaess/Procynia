<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
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
 * Retiring and reopening is ordinary register work: compliance.edit, in the requirement's own
 * customer with the module, checked here (phase 0 step 13). Slett is a decision a person takes:
 * HumanDecisionGuard, then compliance.delete. The controller asks the same first, for its own
 * status codes.
 */
class ComplianceRequirementLifecycleService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
    ) {}

    public function retire(ComplianceRequirement $requirement, User $actor, ?string $reason): ComplianceRequirement
    {
        return $this->transition($requirement, $actor, ComplianceRequirement::STATUS_ACTIVE, ComplianceRequirement::STATUS_RETIRED, $reason);
    }

    public function reopen(ComplianceRequirement $requirement, User $actor, ?string $reason): ComplianceRequirement
    {
        return $this->transition($requirement, $actor, ComplianceRequirement::STATUS_RETIRED, ComplianceRequirement::STATUS_ACTIVE, $reason);
    }

    /**
     * Slett: a requirement registered by mistake — active, never assessed, never moved, in no audit's
     * scope and named by no finding (isDeletable(), read again under the lock). Anything else is
     * retired instead; the history triggers and foreign keys refuse the delete too.
     */
    public function delete(ComplianceRequirement $requirement, User $actor): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_DELETE);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_DELETE, (int) $requirement->customer_id);

        DB::transaction(function () use ($requirement): void {
            $locked = ComplianceRequirement::query()->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDeletable()) {
                throw ValidationException::withMessages([
                    'requirement' => __('procynia.compliance.validation.not_deletable'),
                ]);
            }

            $locked->delete();
        });
    }

    private function transition(ComplianceRequirement $requirement, User $actor, string $from, string $to, ?string $reason): ComplianceRequirement
    {
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, (int) $requirement->customer_id);

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
